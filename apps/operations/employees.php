<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin');

$pageTitle = 'Employees | ' . APP_NAME;
$activeApp = 'operations-employees';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';

function ops_employee_link_bootstrap(): void
{
    if (!ops_database_ready()) {
        return;
    }

    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS employee_user_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                portal_user_id INT NOT NULL,
                hr_employee_id INT NOT NULL,
                role VARCHAR(120) NULL,
                linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                linked_by INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_employee_user_link (portal_user_id),
                INDEX idx_hr_employee_link (hr_employee_id),
                FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
                FOREIGN KEY (linked_by) REFERENCES ops_employees(id) ON DELETE SET NULL
            )"
        );
    } catch (Throwable $e) {
        // Linking is helpful but should not block basic employee management.
    }

    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_login_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NULL,
                employee_name VARCHAR(160) NULL,
                role_key VARCHAR(60) NULL,
                login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(40) NOT NULL DEFAULT 'database',
                ip_address VARCHAR(80) NULL,
                user_agent VARCHAR(255) NULL,
                INDEX idx_login_employee_time (employee_id, login_at),
                INDEX idx_login_time (login_at)
            )"
        );
    } catch (Throwable $e) {
        // Login history is informational and should not block employee management.
    }
}

function ops_force_delete_employee(int $employeeId): void
{
    $nullableReferences = [
        'ops_orders' => ['assigned_packer_id', 'assigned_verifier_id', 'created_by'],
        'ops_order_items' => ['packed_by'],
        'ops_barcode_scans' => ['employee_id'],
        'ops_checklist_tasks' => ['assigned_employee_id', 'approved_by'],
        'ops_error_logs' => ['employee_id', 'logged_by'],
        'ops_packing_tasks' => ['assigned_employee_id', 'created_by'],
        'ops_internal_messages' => ['created_by'],
        'ops_messages' => ['created_by'],
        'ops_supplier_requests' => ['requested_by', 'approved_by'],
        'ops_petty_cash_entries' => ['created_by'],
        'ops_activity_logs' => ['employee_id'],
    ];

    foreach ($nullableReferences as $table => $columns) {
        if (!ops_table_exists($table)) {
            continue;
        }

        foreach ($columns as $column) {
            if (!ops_column_exists($table, $column)) {
                continue;
            }

            $stmt = db()->prepare("UPDATE {$table} SET {$column} = NULL WHERE {$column} = ?");
            $stmt->execute([$employeeId]);
        }
    }

    foreach (['ops_employee_availability', 'ops_board_presence'] as $table) {
        if (!ops_table_exists($table) || !ops_column_exists($table, 'employee_id')) {
            continue;
        }

        $stmt = db()->prepare("DELETE FROM {$table} WHERE employee_id = ?");
        $stmt->execute([$employeeId]);
    }

    if (ops_table_exists('employee_user_links') && ops_column_exists('employee_user_links', 'portal_user_id')) {
        $stmt = db()->prepare('DELETE FROM employee_user_links WHERE portal_user_id = ?');
        $stmt->execute([$employeeId]);
    }

    $stmt = db()->prepare('DELETE FROM ops_employees WHERE id = ?');
    $stmt->execute([$employeeId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Employee account was not found.');
    }
}

if ($ready) {
    ops_employee_link_bootstrap();
    ops_reconcile_core_staff();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 30) ?: 'save_employee';

        if ($action === 'reset_code') {
            $code = trim((string) ($_POST['login_code'] ?? ''));
            if (!preg_match('/^\d{4}$/', $code)) {
                throw new RuntimeException('Login code must be exactly 4 digits.');
            }

            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                throw new RuntimeException('Choose an employee to reset.');
            }

            $stmt = db()->prepare('UPDATE ops_employees SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $employeeId]);
            $message = 'Employee login code reset.';
        } elseif ($action === 'delete_employee') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                throw new RuntimeException('Choose an employee to delete.');
            }

            if ($employeeId === (int) (current_user()['id'] ?? 0)) {
                throw new RuntimeException('You cannot delete your own account while logged in.');
            }

            $stmt = db()->prepare('SELECT full_name FROM ops_employees WHERE id = ? LIMIT 1');
            $stmt->execute([$employeeId]);
            $employeeName = (string) ($stmt->fetchColumn() ?: 'Employee');

            ops_force_delete_employee($employeeId);
            $message = $employeeName . ' permanently deleted. Historical records were kept, but employee links were cleared.';
        } elseif ($action === 'save_hr_link') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $hrEmployeeId = (int) ($_POST['hr_employee_id'] ?? 0);
            if ($employeeId <= 0 || $hrEmployeeId <= 0) {
                throw new RuntimeException('Choose both a portal user and an HR employee profile.');
            }

            $stmt = db()->prepare(
                "INSERT INTO employee_user_links (portal_user_id, hr_employee_id, role, linked_by, active)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    hr_employee_id = VALUES(hr_employee_id),
                    role = VALUES(role),
                    linked_by = VALUES(linked_by),
                    active = 1,
                    linked_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                $employeeId,
                $hrEmployeeId,
                ops_post_string('link_role', 120),
                ops_current_employee_id(),
            ]);
            $message = 'Portal user linked to HR employee profile.';
        } else {
            $code = trim((string) ($_POST['login_code'] ?? ''));
            if (!preg_match('/^\d{4}$/', $code)) {
                throw new RuntimeException('Login code must be exactly 4 digits.');
            }

            $email = strtolower(ops_post_string('email', 190));
            if ($email === '') {
                throw new RuntimeException('Email is required.');
            }

            $stmt = db()->prepare(
                "INSERT INTO ops_employees (role_id, full_name, email, phone, password_hash, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
                . " ON DUPLICATE KEY UPDATE
                    role_id = VALUES(role_id),
                    full_name = VALUES(full_name),
                    phone = VALUES(phone),
                    password_hash = VALUES(password_hash),
                    status = VALUES(status),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                (int) ($_POST['role_id'] ?? 0),
                ops_post_string('full_name', 160),
                $email,
                ops_post_string('phone', 60),
                password_hash($code, PASSWORD_DEFAULT),
                ops_post_string('status', 20) ?: 'active',
            ]);
            $message = 'Employee account saved with a 4-digit login code.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$roles = $ready ? ops_rows("SELECT id, name FROM ops_roles WHERE role_key <> 'owner_admin' ORDER BY FIELD(role_key, 'front_desk_admin', 'packer', 'supervisor_manager'), name") : [];
$hrEmployees = $ready ? ops_hr_employee_options() : [];
$employeeLinks = [];
if ($ready && ops_table_exists('employee_user_links')) {
    foreach (ops_rows('SELECT * FROM employee_user_links WHERE active = 1') as $linkRow) {
        $employeeLinks[(int) $linkRow['portal_user_id']] = $linkRow;
    }
}
$hrLeaveByEmployee = [];
if ($ready && $employeeLinks) {
    $linkedHrIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['hr_employee_id'] ?? 0), $employeeLinks)));
    $linkedHrIds = array_values(array_filter($linkedHrIds));
    if ($linkedHrIds) {
        $placeholders = implode(',', array_fill(0, count($linkedHrIds), '?'));
        foreach (ops_hr_rows(
            "SELECT employee_id, leave_type, start_date, end_date, status
             FROM leave_requests
             WHERE employee_id IN ({$placeholders})
               AND end_date >= CURDATE()
               AND status IN ('approved', 'pending')
             ORDER BY FIELD(status, 'approved', 'pending'), start_date ASC",
            $linkedHrIds
        ) as $leaveRow) {
            $hrId = (int) ($leaveRow['employee_id'] ?? 0);
            if ($hrId > 0 && !isset($hrLeaveByEmployee[$hrId])) {
                $hrLeaveByEmployee[$hrId] = $leaveRow;
            }
        }
    }
}
$employees = $ready ? ops_rows(
    "SELECT e.*, r.name AS role_name, r.role_key
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND r.role_key <> 'owner_admin'
     ORDER BY e.created_at DESC
     LIMIT 50"
) : [];
$employees = ops_canonical_employee_rows($employees);
$loginRows = $ready && ops_table_exists('ops_login_events') ? ops_rows(
    "SELECT le.employee_id, COALESCE(e.full_name, le.employee_name, 'Unknown') AS employee_name,
            COALESCE(r.name, le.role_key, '-') AS role_name,
            COUNT(*) AS login_count,
            MAX(le.login_at) AS last_login_at,
            AVG(TIME_TO_SEC(TIME(le.login_at))) AS avg_login_seconds
     FROM ops_login_events le
     LEFT JOIN ops_employees e ON e.id = le.employee_id
     LEFT JOIN ops_roles r ON r.id = e.role_id
     WHERE le.login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       AND COALESCE(r.role_key, le.role_key, '') <> 'owner_admin'
     GROUP BY le.employee_id, COALESCE(e.full_name, le.employee_name, 'Unknown'), COALESCE(r.name, le.role_key, '-')
     ORDER BY last_login_at DESC
     LIMIT 20"
) : [];
foreach ($loginRows as &$loginRow) {
    $loginRow['employee_name'] = ops_staff_display_name([
        'id' => (int) ($loginRow['employee_id'] ?? 0),
        'full_name' => (string) ($loginRow['employee_name'] ?? ''),
    ]);
}
unset($loginRow);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module employees-wrap">
    <section class="module-header">
        <div>
            <p class="eyebrow page-eyebrow">Operations</p>
            <h1>Employees & Roles</h1>
            <p class="page-subtitle">Create individual employee logins and attach each person to the correct operational permission level.</p>
        </div>
    </section>
    <?php ops_nav('employees'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>
    <?php if ($ready): ?>
        <section class="panel section-card hr-link-panel">
            <div class="section-row">
                <div>
                    <h2>Link portal users to HR employee profiles</h2>
                    <p>HR is the employee source of truth for salary, department and approved leave. Link each operations login so KPI scoring uses the correct HR record.</p>
                </div>
            </div>
            <?php if (!$hrEmployees): ?>
                <p class="ops-muted">No HR employees could be loaded. Check the HR database connection in <code>config.local.php</code>.</p>
            <?php endif; ?>
            <form class="hr-link-form" method="post">
                <input type="hidden" name="action" value="save_hr_link">
                <label>Portal user
                    <select name="employee_id" required>
                        <option value="">Choose portal user</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['full_name'] . ' - ' . $employee['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>HR employee
                    <select name="hr_employee_id" required>
                        <option value="">Choose HR profile</option>
                        <?php foreach ($hrEmployees as $hr): ?>
                            <option value="<?= (int) $hr['id'] ?>"><?= htmlspecialchars(($hr['full_name'] ?: 'Employee #' . $hr['id']) . ' - ' . (($hr['job_title'] ?? '') ?: 'No job title'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>KPI role note<input name="link_role" placeholder="Packer, Front Desk/Admin, Owner/Admin"></label>
                <button class="button primary" type="submit">Save HR link</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel section-card">
        <div class="section-row">
            <div>
                <h2>Login times</h2>
                <p>Recent portal login activity for the last 30 days. New logins are recorded from this update onward.</p>
            </div>
        </div>
        <div class="table-scroll accounts-table-wrap">
            <table class="data-table ops-table login-times-table">
                <thead><tr><th>Employee</th><th>Role</th><th>Logins</th><th>Average Login Time</th><th>Last Login</th></tr></thead>
                <tbody>
                    <?php foreach ($loginRows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['employee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $row['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int) $row['login_count']) ?></td>
                            <td><?= $row['avg_login_seconds'] !== null ? htmlspecialchars(gmdate('H:i', (int) $row['avg_login_seconds']), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                            <td><?= !empty($row['last_login_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string) $row['last_login_at'])), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$loginRows): ?><tr><td colspan="5">No login activity recorded yet. Logins will appear here after staff sign in again.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

        <section class="panel section-card">
            <div class="section-row"><h2>Employee accounts</h2></div>
            <div class="table-scroll accounts-table-wrap">
                <table class="data-table ops-table accounts-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>HR Link</th><th>Leave</th><th>Status</th><th>Reset code</th><th>Created</th><th>Delete</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $link = $employeeLinks[(int) $employee['id']] ?? null;
                        $hr = $link && isset($hrEmployees[(int) $link['hr_employee_id']]) ? $hrEmployees[(int) $link['hr_employee_id']] : null;
                        $leave = $hr ? ($hrLeaveByEmployee[(int) $link['hr_employee_id']] ?? null) : null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $employee['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($employee['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ((string) ($employee['role_key'] ?? '') === 'owner_admin'): ?>
                                    <span class="status badge-none">not tracked</span><br><small>Owner/Admin is excluded from KPI scoring.</small>
                                <?php elseif ($hr): ?>
                                    <span class="status badge-linked">linked</span><br><small><a href="<?= htmlspecialchars(BASE_URL . '/apps/hr-portal/employees.php?view=' . (int) $link['hr_employee_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($hr['full_name'], ENT_QUOTES, 'UTF-8') ?></a></small>
                                <?php else: ?>
                                    <span class="status kpi-status-warning badge-pending">missing HR link</span><br><small>KPI and leave tracking may be inaccurate.</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($leave): ?>
                                    <span class="status <?= (string) ($leave['status'] ?? '') === 'pending' ? 'kpi-status-warning badge-pending' : 'badge-approved' ?>"><?= htmlspecialchars((string) $leave['status'], ENT_QUOTES, 'UTF-8') ?></span><br>
                                    <small><?= htmlspecialchars((string) ($leave['leave_type'] ?? 'Leave'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(date('d M', strtotime((string) $leave['start_date'])), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(date('d M', strtotime((string) $leave['end_date'])), ENT_QUOTES, 'UTF-8') ?></small>
                                <?php elseif ($hr): ?>
                                    <span class="status badge-none">none</span><br><small>No pending/approved leave.</small>
                                <?php else: ?>
                                    <span class="status kpi-status-warning badge-inactive">unknown</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="status <?= (string) ($employee['status'] ?? '') === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= htmlspecialchars($employee['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <form class="inline-code-reset" method="post">
                                    <input type="hidden" name="action" value="reset_code">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <input name="login_code" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="0000" required>
                                    <button class="button small" type="submit">Reset</button>
                                </form>
                            </td>
                            <td><?= htmlspecialchars((string) $employee['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ((int) $employee['id'] !== (int) (current_user()['id'] ?? 0)): ?>
                                    <form method="post" onsubmit="return confirm('Permanently delete this employee account? Old operational records will stay, but the employee link will be cleared.');">
                                        <input type="hidden" name="action" value="delete_employee">
                                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                        <button class="button danger small" type="submit">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="status badge-none">current user</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$employees): ?><tr><td colspan="9">No employee accounts recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <form class="panel section-card ops-form new-employee-form" method="post">
            <input type="hidden" name="action" value="save_employee">
            <div class="section-row"><h2>New employee</h2></div>
            <div class="form-grid">
                <label>Full name<input name="full_name" required autocomplete="name"></label>
                <label>Email<input type="email" name="email" required autocomplete="email"></label>
                <label>Phone<input name="phone" autocomplete="tel"></label>
                <label>Role
                    <select name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status<select name="status"><?php ops_select_options(['active' => 'Active', 'inactive' => 'Inactive']); ?></select></label>
                <label>4-digit login code<input type="text" name="login_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required placeholder="1234"></label>
            </div>
            <div class="ops-form-actions"><button class="button primary" type="submit">Create account</button></div>
        </form>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
