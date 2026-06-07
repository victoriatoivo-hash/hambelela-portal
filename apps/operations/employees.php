<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin');

$pageTitle = 'Employees | ' . APP_NAME;
$activeApp = 'operations-employees';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';

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

    $stmt = db()->prepare('DELETE FROM ops_employees WHERE id = ?');
    $stmt->execute([$employeeId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Employee account was not found.');
    }
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

$roles = $ready ? ops_rows('SELECT id, name FROM ops_roles ORDER BY id') : [];
$employees = $ready ? ops_rows(
    "SELECT e.*, r.name AS role_name
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     ORDER BY e.created_at DESC
     LIMIT 50"
) : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>Employees & Roles</h1>
            <p>Create individual employee logins and attach each person to the correct operational permission level.</p>
        </div>
    </section>
    <?php ops_nav('employees'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="ops-split">
        <form class="panel ops-form" method="post">
            <input type="hidden" name="action" value="save_employee">
            <div class="section-row"><h2>New employee</h2></div>
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
            <div class="ops-form-actions"><button class="button primary" type="submit">Create account</button></div>
        </form>

        <section class="panel">
            <div class="section-row"><h2>Employee accounts</h2></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Reset code</th><th>Created</th><th>Delete</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><?= htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $employee['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($employee['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status"><?= htmlspecialchars($employee['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
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
                                    <span class="status">current user</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$employees): ?><tr><td colspan="7">No employee accounts recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
