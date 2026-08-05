<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/notifications.php';
require_once BASE_PATH . '/shared/login-security.php';

require_login();

$pageTitle = 'My Account | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$epiMode = 'disabled';
$epiTestResult = null;
$employeeFormValues = ['full_name' => '', 'email' => '', 'phone' => '', 'role_id' => '', 'status' => 'active'];
$employee = null;
$notificationPrefs = notifications_preferences();
$notificationModules = notifications_modules();
$canManagePortal = user_has_role('owner_admin') || in_array((string) ($_SESSION['user_role'] ?? ''), ['admin', 'Owner/Admin', 'owner_admin'], true);
$requestedSettingsSection = strtolower(trim((string) ($_GET['section'] ?? '')));
$activeSettingsSection = in_array($requestedSettingsSection, ['profile', 'security', 'notifications', 'appearance', 'employees', 'portal', 'help'], true)
    ? $requestedSettingsSection
    : 'profile';

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

function settings_employee_link_bootstrap(): void
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
        // Helpful metadata only; account settings should still load if this fails.
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
        // Login history is informational and should not block settings.
    }
}

function settings_force_delete_employee(int $employeeId): void
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

if ($ready && $canManagePortal) {
    settings_employee_link_bootstrap();
    ops_reconcile_core_staff();
}

if ($ready) {
    try {
        \Hambelela\EPI\Performance::configure(db());
        $epiMode = \Hambelela\EPI\Performance::mode();
    } catch (Throwable $epiSetupError) {
        $epiMode = 'disabled';
    }
    $employeeId = ops_current_employee_id();
    if ($employeeId) {
        $rows = ops_rows(
            "SELECT e.*, r.name AS role_name, r.role_key
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.id = ?
             LIMIT 1",
            [$employeeId]
        );
        $employee = $rows[0] ?? null;
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isResetCodeAjax = (string) ($_POST['action'] ?? '') === 'reset_code'
        && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $sessionToken = (string) ($_SESSION['settings_csrf_token'] ?? '');
        if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            throw new RuntimeException('Invalid request. Reload the page and try again.');
        }
        if (!$employee) {
            throw new RuntimeException('Your employee account could not be found.');
        }

        $action = ops_post_string('action', 40) ?: 'change_code';

        if (in_array($action, ['save_epi_mode', 'run_epi_recovery_test'], true)) {
            if (!$canManagePortal) {
                throw new RuntimeException('Only Owner/Admin can manage EPI recovery settings.');
            }
            $activeSettingsSection = 'portal';
            if ($action === 'save_epi_mode') {
                $requestedMode = ops_post_string('epi_mode', 20);
                $reason = ops_post_string('epi_reason', 500);
                \Hambelela\EPI\Performance::setMode($requestedMode, (int) $employee['id'], $reason);
                $epiMode = \Hambelela\EPI\Performance::mode();
                $message = 'EPI recording mode updated to ' . ($epiMode === 'test' ? 'Recording Test Mode' : 'Disabled') . '.';
            } else {
                $verifier = new \Hambelela\EPI\RecoveryVerifier(db());
                $epiTestResult = $verifier->run((int) $employee['id'], (string) $employee['full_name']);
                $message = 'Controlled EPI verification completed. All records are marked TEST DATA and excluded from scoring.';
            }
        } elseif (in_array($action, ['reset_code', 'delete_employee', 'save_hr_link', 'save_employee', 'save_packing_eligibility'], true)) {
            if (!$canManagePortal) {
                throw new RuntimeException('Only Owner/Admin can manage employee accounts.');
            }

            $activeSettingsSection = 'employees';

            if ($action === 'save_packing_eligibility') {
                if (!ops_ensure_packing_auto_assignable_column()) {
                    throw new RuntimeException('Packing assignment eligibility is not available yet.');
                }
                $employeeId = (int) ($_POST['employee_id'] ?? 0);
                if ($employeeId <= 0) {
                    throw new RuntimeException('Choose an employee account.');
                }
                $field = (string) ($_POST['eligibility_field'] ?? 'packing_assignable');
                if (!in_array($field, ['packing_assignable', 'packing_auto_assignable'], true)) {
                    throw new RuntimeException('Choose a valid Packing eligibility setting.');
                }
                $eligible = (string) ($_POST['eligibility_value'] ?? '') === '1' ? 1 : 0;
                if ($field === 'packing_auto_assignable' && $eligible === 1) {
                    db()->prepare('UPDATE ops_employees SET packing_assignable = 1, packing_auto_assignable = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$employeeId]);
                } elseif ($field === 'packing_assignable' && $eligible === 0) {
                    db()->prepare('UPDATE ops_employees SET packing_assignable = 0, packing_auto_assignable = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$employeeId]);
                } else {
                    db()->prepare("UPDATE ops_employees SET {$field} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$eligible, $employeeId]);
                }
                $message = 'Packing assignment eligibility updated.';
            } elseif ($action === 'reset_code') {
                $code = trim((string) ($_POST['login_code'] ?? ''));
                $confirmCode = trim((string) ($_POST['confirm_login_code'] ?? ''));

                $employeeId = (int) ($_POST['employee_id'] ?? 0);
                if ($employeeId <= 0) {
                    throw new RuntimeException('Choose an employee to reset.');
                }

                $targetRows = ops_rows(
                    'SELECT e.id, r.role_key FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE e.id = ? LIMIT 1',
                    [$employeeId]
                );
                $targetEmployee = $targetRows[0] ?? null;
                if (!$targetEmployee) {
                    throw new RuntimeException('The selected employee account could not be found.');
                }
                if ($code !== $confirmCode) {
                    throw new RuntimeException('The new access code and confirmation do not match.');
                }
                if (!preg_match('/^\d{4}$/', $code)) {
                    throw new RuntimeException('Reset code must contain exactly 4 numbers.');
                }
                if (access_secret_is_shared($code, $employeeId)) {
                    throw new RuntimeException('That access code is already assigned to another account.');
                }

                $stmt = db()->prepare('UPDATE ops_employees SET password_hash = ?, requires_code_reset = 0, failed_login_attempts = 0, locked_until = NULL, last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $employeeId]);
                record_security_event('access_code_reset', $employeeId, ['performed_by' => (int) $employee['id']]);
                $message = 'Employee access code reset.';
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

                settings_force_delete_employee($employeeId);
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
                $confirmCode = trim((string) ($_POST['confirm_login_code'] ?? ''));
                if ($code === '') throw new RuntimeException('Enter an access code.');
                if (!preg_match('/^\d+$/', $code)) throw new RuntimeException('The access code may contain numbers only.');
                if (strlen($code) < 6) throw new RuntimeException('The access code must contain at least 6 digits.');
                if (strlen($code) > 10) throw new RuntimeException('The access code cannot exceed 10 digits.');
                if (!hash_equals($code, $confirmCode)) throw new RuntimeException('The access codes do not match.');

                $roleId = (int) ($_POST['role_id'] ?? 0);
                $roleRows = ops_rows('SELECT role_key FROM ops_roles WHERE id = ? LIMIT 1', [$roleId]);
                $roleKey = (string) ($roleRows[0]['role_key'] ?? '');
                if ($roleKey === '') {
                    throw new RuntimeException('Choose a valid role.');
                }
                $validationError = access_secret_validation_error($code, $roleKey);
                if ($validationError !== null) {
                    throw new RuntimeException($validationError);
                }
                if (access_secret_is_shared($code)) {
                    throw new RuntimeException('This access code is already in use. Choose another code.');
                }

                $email = strtolower(ops_post_string('email', 190));
                if ($email === '') {
                    throw new RuntimeException('Email is required.');
                }

                $existingEmployeeRows = ops_rows(
                    'SELECT id FROM ops_employees WHERE LOWER(email) = LOWER(?) LIMIT 1',
                    [$email]
                );
                if ($existingEmployeeRows) throw new RuntimeException('This email already belongs to another employee.');

                $stmt = db()->prepare(
                    "INSERT INTO ops_employees (role_id, full_name, email, phone, password_hash, status, requires_code_reset)
                     VALUES (?, ?, ?, ?, ?, ?, 0)"
                );
                $stmt->execute([
                    $roleId,
                    ops_post_string('full_name', 160),
                    $email,
                    ops_post_string('phone', 60),
                    password_hash($code, PASSWORD_DEFAULT),
                    ops_post_string('status', 20) ?: 'active',
                ]);
                if (ops_ensure_packing_auto_assignable_column()) {
                    $packingAssignable = in_array($roleKey, ['packer', 'supervisor_manager', 'front_desk_admin', 'owner_admin'], true) ? 1 : 0;
                    $packingAutoAssignable = in_array($roleKey, ['packer', 'supervisor_manager'], true) ? 1 : 0;
                    db()->prepare(
                        'UPDATE ops_employees SET packing_assignable = ?, packing_auto_assignable = ? WHERE LOWER(email) = LOWER(?)'
                    )->execute([$packingAssignable, $packingAutoAssignable, $email]);
                }
                $message = 'Employee account saved with a secure access code.';
            }
        } elseif ($action === 'update_notifications') {
            $activeSettingsSection = 'notifications';
            notifications_save_preferences((int) $employee['id'], [
                'desktop_enabled' => (int) ($_POST['desktop_enabled'] ?? 0) === 1,
                'sound_enabled' => (int) ($_POST['sound_enabled'] ?? 0) === 1,
                'muted_when_unavailable' => (int) ($_POST['muted_when_unavailable'] ?? 0) === 1,
                'modules' => array_values(array_filter(array_map('strval', $_POST['modules'] ?? []))),
            ]);
            $notificationPrefs = notifications_preferences((int) $employee['id']);
            $message = 'Notification preferences updated.';
        } else {
            $activeSettingsSection = 'security';
            $currentCode = trim((string) ($_POST['current_code'] ?? ''));
            $newCode = trim((string) ($_POST['new_code'] ?? ''));
            $confirmCode = trim((string) ($_POST['confirm_code'] ?? ''));

            if ($newCode !== $confirmCode) {
                throw new RuntimeException('The new code and confirmation do not match.');
            }

            $currentCodeVerified = !empty($employee['password_hash']) && password_verify($currentCode, (string) $employee['password_hash']);
            $rotationSessionVerified = !empty($_SESSION['credential_rotation_authorized']) && !empty($employee['requires_code_reset']);
            if (!$currentCodeVerified && !$rotationSessionVerified) {
                throw new RuntimeException('Current code is incorrect.');
            }

            $validationError = access_secret_validation_error($newCode, (string) ($employee['role_key'] ?? ''));
            if ($validationError !== null) {
                throw new RuntimeException($validationError);
            }
            if (access_secret_is_shared($newCode, (int) $employee['id'])) {
                throw new RuntimeException('That access code is already assigned to another account.');
            }

            $stmt = db()->prepare('UPDATE ops_employees SET password_hash = ?, requires_code_reset = 0, failed_login_attempts = 0, locked_until = NULL, last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([password_hash($newCode, PASSWORD_DEFAULT), (int) $employee['id']]);
            unset($_SESSION['credential_rotation_authorized']);
            $message = 'Your access code has been updated.';
            record_security_event('access_code_changed', (int) $employee['id']);
            ops_activity_log('employee_code_changed', 'ops_employee', (int) $employee['id']);
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        if (($action ?? '') === 'save_employee') {
            foreach (array_keys($employeeFormValues) as $field) $employeeFormValues[$field] = trim((string) ($_POST[$field] ?? $employeeFormValues[$field]));
        }
    }

    if ($isResetCodeAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $messageType === 'success',
            'message' => $messageType === 'success' ? 'Reset code updated successfully.' : $message,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$employeeRoles = $ready && $canManagePortal ? ops_rows("SELECT id, name FROM ops_roles WHERE role_key <> 'owner_admin' ORDER BY FIELD(role_key, 'front_desk_admin', 'packer', 'supervisor_manager'), name") : [];
$extraStylesheets = array_merge($extraStylesheets ?? [], [['path' => 'assets/css/settings-access-code.css', 'version' => is_file(BASE_PATH . '/assets/css/settings-access-code.css') ? (string) filemtime(BASE_PATH . '/assets/css/settings-access-code.css') : (string) time()]]);
$hrEmployees = $ready && $canManagePortal ? ops_hr_employee_options() : [];
$employeeLinks = [];
if ($ready && $canManagePortal && ops_table_exists('employee_user_links')) {
    foreach (ops_rows('SELECT * FROM employee_user_links WHERE active = 1') as $linkRow) {
        $employeeLinks[(int) $linkRow['portal_user_id']] = $linkRow;
    }
}
$hrLeaveByEmployee = [];
if ($ready && $canManagePortal && $employeeLinks) {
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
$hasPackingAssignable = $ready ? ops_ensure_packing_assignable_column() : false;
$hasPackingAutoAssignable = $ready ? ops_ensure_packing_auto_assignable_column() : false;
$packingAssignableSelect = $hasPackingAssignable ? 'e.packing_assignable' : "IF(r.role_key IN ('packer', 'supervisor_manager'), 1, 0) AS packing_assignable";
$packingAutoAssignableSelect = $hasPackingAutoAssignable ? 'e.packing_auto_assignable' : "IF(r.role_key IN ('packer', 'supervisor_manager'), 1, 0) AS packing_auto_assignable";
$managedEmployees = $ready && $canManagePortal ? ops_rows(
    "SELECT e.*, r.name AS role_name, r.role_key, {$packingAssignableSelect}, {$packingAutoAssignableSelect}
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND r.role_key <> 'owner_admin'
     ORDER BY FIELD(r.role_key, 'front_desk_admin', 'packer', 'supervisor_manager'), e.full_name ASC
     LIMIT 50"
) : [];
$managedEmployees = $canManagePortal ? ops_canonical_employee_rows($managedEmployees) : [];
$loginRows = $ready && $canManagePortal && ops_table_exists('ops_login_events') ? ops_rows(
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
$accountName = (string) ($employee['full_name'] ?? ($_SESSION['user_name'] ?? 'My account'));
$accountRole = (string) ($employee['role_name'] ?? ($_SESSION['user_role'] ?? 'Portal user'));
$accountEmail = (string) ($employee['email'] ?? ($_SESSION['user_email'] ?? ''));
$accountPhone = (string) ($employee['phone'] ?? ($_SESSION['user_phone'] ?? ''));
?>
<main class="workspace module settings-wrap">
    <section class="module-header">
        <div>
            <p class="page-eyebrow">Settings</p>
            <h1>My Account</h1>
            <p class="page-subtitle">Manage your profile, security, notifications and portal preferences.</p>
        </div>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <div class="settings-layout">
        <nav class="settings-nav" aria-label="Settings sections">
            <button class="settings-nav-item <?= $activeSettingsSection === 'profile' ? 'active' : '' ?>" type="button" data-section="profile"><i data-lucide="user-round"></i> Profile</button>
            <button class="settings-nav-item <?= $activeSettingsSection === 'security' ? 'active' : '' ?>" type="button" data-section="security"><i data-lucide="lock-keyhole"></i> Security</button>
            <button class="settings-nav-item <?= $activeSettingsSection === 'notifications' ? 'active' : '' ?>" type="button" data-section="notifications"><i data-lucide="bell"></i> Notifications</button>
            <button class="settings-nav-item <?= $activeSettingsSection === 'appearance' ? 'active' : '' ?>" type="button" data-section="appearance"><i data-lucide="palette"></i> Appearance</button>
            <span class="settings-nav-divider"></span>
            <?php if ($canManagePortal): ?>
                <button class="settings-nav-item <?= $activeSettingsSection === 'employees' ? 'active' : '' ?>" type="button" data-section="employees"><i data-lucide="users-round"></i> Employees & Roles</button>
            <?php endif; ?>
            <button class="settings-nav-item <?= $activeSettingsSection === 'portal' ? 'active' : '' ?>" type="button" data-section="portal"><i data-lucide="settings"></i> Portal Settings</button>
            <button class="settings-nav-item <?= $activeSettingsSection === 'help' ? 'active' : '' ?>" type="button" data-section="help"><i data-lucide="circle-help"></i> Help</button>
        </nav>

        <section class="settings-content">
            <div id="section-profile" class="settings-section <?= $activeSettingsSection === 'profile' ? 'active' : '' ?>">
                <div class="settings-card">
                    <h2>My Profile</h2>
                    <p class="card-sub">Your name, role and contact details as they appear in the portal.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" value="<?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <input class="readonly-input" type="text" value="<?= htmlspecialchars($accountRole, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" value="<?= htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" value="<?= htmlspecialchars($accountPhone, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                    </div>
                    <p class="settings-note">Profile changes are managed from Employees & Roles so staff records stay consistent.</p>
                </div>
            </div>

            <div id="section-security" class="settings-section <?= $activeSettingsSection === 'security' ? 'active' : '' ?>">
                <form class="settings-card" method="post">
                    <input type="hidden" name="action" value="change_code">
                    <h2>Access Code</h2>
                    <p class="card-sub">Change your portal access code. Owner/Admin credentials require letters and numbers; staff codes require 6 to 10 digits.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Current access code</label>
                            <input type="password" name="current_code" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label>New access code</label>
                            <input type="password" name="new_code" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm new code</label>
                        <input type="password" name="confirm_code" autocomplete="new-password" required>
                    </div>
                    <div class="btn-row"><button class="btn-primary" type="submit">Update my code</button></div>
                </form>
                <div class="settings-card">
                    <h2>Active Sessions</h2>
                    <p class="card-sub">You are currently logged in on this device.</p>
                    <div class="settings-session-row">
                        <i data-lucide="monitor"></i>
                        <span>This browser - <?= date('d M Y, H:i') ?></span>
                        <strong>Active now</strong>
                    </div>
                </div>
            </div>

            <div id="section-notifications" class="settings-section <?= $activeSettingsSection === 'notifications' ? 'active' : '' ?>">
                <form class="settings-card" id="notification-preferences" method="post">
                    <input type="hidden" name="action" value="update_notifications">
                    <h2>Notification Preferences</h2>
                    <p class="card-sub">Choose how the portal alerts you for work that belongs to your role.</p>

                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Desktop browser notifications</div>
                            <div class="toggle-sub">Browser pop-up alerts when new orders or tasks arrive</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="desktop_enabled" value="1" <?= !empty($notificationPrefs['desktop_enabled']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Notification sound</div>
                            <div class="toggle-sub">Soft chime when a new alert arrives</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="sound_enabled" value="1" <?= !empty($notificationPrefs['sound_enabled']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Mute when on lunch, away or offline</div>
                            <div class="toggle-sub">Suppress alerts while your status is not Available</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="muted_when_unavailable" value="1" <?= !empty($notificationPrefs['muted_when_unavailable']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="card-sub-divider"></div>
                    <h3>Notify me for these modules</h3>
                    <?php foreach ($notificationModules as $moduleKey => $moduleLabel): ?>
                        <div class="toggle-row">
                            <div class="toggle-label"><?= htmlspecialchars($moduleLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <label class="toggle-switch">
                                <input
                                    type="checkbox"
                                    name="modules[]"
                                    value="<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= in_array($moduleKey, $notificationPrefs['modules'] ?? [], true) ? 'checked' : '' ?>
                                >
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="btn-row"><button class="btn-primary" type="submit">Save notification settings</button></div>
                </form>
            </div>

            <div id="section-appearance" class="settings-section <?= $activeSettingsSection === 'appearance' ? 'active' : '' ?>">
                <div class="settings-card">
                    <h2>Display Preferences</h2>
                    <p class="card-sub">Personalise how the portal looks for you.</p>
                    <div class="toggle-row">
                        <div><div class="toggle-label">Compact table rows</div><div class="toggle-sub">Show more rows on screen at once</div></div>
                        <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label">Show Packed by column on Orders Board</div><div class="toggle-sub">Display packer name next to each order</div></div>
                        <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label">Collapse date groups by default</div><div class="toggle-sub">Board groups start collapsed until opened</div></div>
                        <label class="toggle-switch"><input type="checkbox"><span class="toggle-slider"></span></label>
                    </div>
                    <div class="btn-row"><button class="btn-secondary" type="button">Save preferences</button></div>
                </div>
            </div>

            <?php if ($canManagePortal): ?>
                <div id="section-employees" class="settings-section <?= $activeSettingsSection === 'employees' ? 'active' : '' ?>">
                    <div class="settings-card">
                        <h2>Employees & Roles</h2>
                        <p class="card-sub">Create staff logins, reset secure access codes and link portal users to HR employee profiles.</p>

                        <?php if (!$hrEmployees): ?>
                            <p class="settings-note">No HR employees could be loaded. Check the HR database connection in <code>config.local.php</code>.</p>
                        <?php endif; ?>

                        <form class="settings-hr-link-form" method="post">
                            <input type="hidden" name="action" value="save_hr_link">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Portal user</label>
                                    <select name="employee_id" required>
                                        <option value="">Choose portal user</option>
                                        <?php foreach ($managedEmployees as $managedEmployee): ?>
                                            <option value="<?= (int) $managedEmployee['id'] ?>"><?= htmlspecialchars($managedEmployee['full_name'] . ' - ' . $managedEmployee['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>HR employee</label>
                                    <select name="hr_employee_id" required>
                                        <option value="">Choose HR profile</option>
                                        <?php foreach ($hrEmployees as $hr): ?>
                                            <option value="<?= (int) $hr['id'] ?>"><?= htmlspecialchars(($hr['full_name'] ?: 'Employee #' . $hr['id']) . ' - ' . (($hr['job_title'] ?? '') ?: 'No job title'), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Performance role note</label>
                                    <input name="link_role" placeholder="Packer, Front Desk/Admin, Owner/Admin">
                                </div>
                                <div class="settings-inline-action">
                                    <button class="btn-secondary" type="submit">Save HR link</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="settings-card">
                        <h2>Employee accounts</h2>
                        <p class="card-sub">Manual assignment controls who appears in Packing selectors. Automatic distribution is a separate, narrower workload pool.</p>
                        <div class="settings-table-wrap">
                            <table class="settings-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>HR Link</th>
                                        <th>Leave</th>
                                        <th>Status</th>
                                        <th>Packing assignment</th>
                                        <th>Automatic distribution</th>
                                        <th>Temporary Code</th>
                                        <th>Created</th>
                                        <th>Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($managedEmployees as $managedEmployee): ?>
                                    <?php
                                    $link = $employeeLinks[(int) $managedEmployee['id']] ?? null;
                                    $hr = $link && isset($hrEmployees[(int) $link['hr_employee_id']]) ? $hrEmployees[(int) $link['hr_employee_id']] : null;
                                    $leave = $hr ? ($hrLeaveByEmployee[(int) $link['hr_employee_id']] ?? null) : null;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($managedEmployee['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $managedEmployee['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($managedEmployee['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if ($hr): ?>
                                                <span class="settings-pill is-linked">linked</span>
                                                <small><a href="<?= htmlspecialchars(BASE_URL . '/apps/hr-portal/employees.php?view=' . (int) $link['hr_employee_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($hr['full_name'], ENT_QUOTES, 'UTF-8') ?></a></small>
                                            <?php else: ?>
                                                <span class="settings-pill is-warning">missing HR link</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($leave): ?>
                                                <span class="settings-pill <?= (string) ($leave['status'] ?? '') === 'pending' ? 'is-warning' : 'is-linked' ?>"><?= htmlspecialchars((string) $leave['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <small><?= htmlspecialchars((string) ($leave['leave_type'] ?? 'Leave'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(date('d M', strtotime((string) $leave['start_date'])), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(date('d M', strtotime((string) $leave['end_date'])), ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php elseif ($hr): ?>
                                                <span class="settings-pill is-muted">none</span>
                                            <?php else: ?>
                                                <span class="settings-pill is-warning">unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="settings-pill <?= (string) ($managedEmployee['status'] ?? '') === 'active' ? 'is-linked' : 'is-muted' ?>"><?= htmlspecialchars($managedEmployee['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="action" value="save_packing_eligibility">
                                                <input type="hidden" name="employee_id" value="<?= (int) $managedEmployee['id'] ?>">
                                                <input type="hidden" name="eligibility_field" value="packing_assignable">
                                                <input type="hidden" name="eligibility_value" value="<?= !empty($managedEmployee['packing_assignable']) ? '0' : '1' ?>">
                                                <button class="btn-secondary" type="submit"><?= !empty($managedEmployee['packing_assignable']) ? 'Eligible' : 'Not eligible' ?></button>
                                            </form>
                                        </td>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="action" value="save_packing_eligibility">
                                                <input type="hidden" name="employee_id" value="<?= (int) $managedEmployee['id'] ?>">
                                                <input type="hidden" name="eligibility_field" value="packing_auto_assignable">
                                                <input type="hidden" name="eligibility_value" value="<?= !empty($managedEmployee['packing_auto_assignable']) ? '0' : '1' ?>">
                                                <button class="btn-secondary" type="submit"><?= !empty($managedEmployee['packing_auto_assignable']) ? 'Included' : 'Excluded' ?></button>
                                            </form>
                                        </td>
                                        <td>
                                            <button
                                                class="btn-secondary"
                                                type="button"
                                                data-create-temporary-code
                                                data-employee-id="<?= (int) $managedEmployee['id'] ?>"
                                                data-employee-name="<?= htmlspecialchars($managedEmployee['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                            >Create temporary code</button>
                                        </td>
                                        <td><?= htmlspecialchars((string) $managedEmployee['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if ((int) $managedEmployee['id'] !== (int) (current_user()['id'] ?? 0)): ?>
                                                <form method="post" onsubmit="return confirm('Permanently delete this employee account? Old operational records will stay, but the employee link will be cleared.');">
                                                    <input type="hidden" name="action" value="delete_employee">
                                                    <input type="hidden" name="employee_id" value="<?= (int) $managedEmployee['id'] ?>">
                                                    <button class="btn-danger" type="submit">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="settings-pill is-muted">current user</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$managedEmployees): ?><tr><td colspan="11">No employee accounts recorded yet.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form class="settings-card" method="post" id="newEmployeeForm" novalidate>
                        <input type="hidden" name="action" value="save_employee">
                        <h2>New employee</h2>
                        <p class="card-sub">Add a staff login and set a unique 6 to 10 digit access code.</p>
                        <div class="form-row">
                            <div class="form-group"><label>Full name</label><input name="full_name" value="<?= htmlspecialchars($employeeFormValues['full_name'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="name"></div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($employeeFormValues['email'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="email"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Phone</label><input name="phone" value="<?= htmlspecialchars($employeeFormValues['phone'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel"></div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role_id" required>
                                    <?php foreach ($employeeRoles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= (string) $employeeFormValues['role_id'] === (string) $role['id'] ? 'selected' : '' ?>><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Status</label><select name="status"><?php ops_select_options(['active' => 'Active', 'inactive' => 'Inactive'], $employeeFormValues['status']); ?></select></div>
                            <div class="form-group"><label for="accessCode">Access code</label><input type="password" id="accessCode" name="login_code" inputmode="numeric" autocomplete="new-password" minlength="6" maxlength="10" aria-describedby="accessCodeHelp accessCodeError" required placeholder="6 to 10 digits"><small id="accessCodeHelp" class="field-help">Enter a unique 6 to 10 digit access code.</small><p id="accessCodeError" class="field-error" role="alert" hidden></p></div>
                            <div class="form-group"><label for="confirmAccessCode">Confirm access code</label><input type="password" id="confirmAccessCode" name="confirm_login_code" inputmode="numeric" autocomplete="new-password" minlength="6" maxlength="10" aria-describedby="confirmAccessCodeError" required placeholder="Repeat code"><p id="confirmAccessCodeError" class="field-error" role="alert" hidden></p></div>
                        </div>
                        <div class="btn-row"><button class="btn-primary" type="submit">Create account</button></div>
                    </form>

                    <div class="settings-card">
                        <h2>Login times</h2>
                        <p class="card-sub">Recent portal login activity for the last 30 days.</p>
                        <div class="settings-table-wrap">
                            <table class="settings-table">
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
                    </div>
                </div>
            <?php endif; ?>

            <div id="section-portal" class="settings-section <?= $activeSettingsSection === 'portal' ? 'active' : '' ?>">
                <?php if ($canManagePortal): ?>
                    <div class="settings-card">
                        <h2>Employee Performance Intelligence</h2>
                        <p class="card-sub">Owner-only Recovery Step 1 control. Production recording remains unavailable until a later approval.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_epi_mode">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="epi-mode">Recording mode</label>
                                    <select id="epi-mode" name="epi_mode">
                                        <option value="disabled" <?= $epiMode === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                        <option value="test" <?= $epiMode === 'test' ? 'selected' : '' ?>>Recording Test Mode</option>
                                        <option value="enabled" disabled>Enabled — reserved for later approval</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="epi-reason">Reason for change</label>
                                    <input id="epi-reason" name="epi_reason" type="text" maxlength="500" required placeholder="Required audit reason">
                                </div>
                            </div>
                            <p class="settings-note">Current state: <strong><?= htmlspecialchars($epiMode === 'test' ? 'Recording Test Mode' : 'Disabled', ENT_QUOTES, 'UTF-8') ?></strong>. Test Mode accepts only explicitly marked TEST DATA; ordinary portal actions remain blocked.</p>
                            <div class="btn-row"><button class="btn-secondary" type="submit">Save EPI mode</button></div>
                        </form>
                        <form method="post" style="margin-top:12px">
                            <input type="hidden" name="action" value="run_epi_recovery_test">
                            <button class="btn-secondary" type="submit" <?= $epiMode !== 'test' ? 'disabled' : '' ?>>Run controlled foundation test</button>
                        </form>
                        <?php if (is_array($epiTestResult)): ?>
                            <div class="settings-note" style="margin-top:12px">
                                <strong>TEST DATA run <?= htmlspecialchars((string) $epiTestResult['run_id'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                Evidence: <?= (int) $epiTestResult['evidence_rows'] ?> · Activity: <?= (int) $epiTestResult['activity_rows'] ?> · Ownership: <?= !empty($epiTestResult['ownership_uuid']) ? 'recorded' : 'failed' ?> · Weekday minutes: <?= htmlspecialchars((string) $epiTestResult['business_minutes'], ENT_QUOTES, 'UTF-8') ?> · Sunday minutes: <?= htmlspecialchars((string) $epiTestResult['weekend_minutes'], ENT_QUOTES, 'UTF-8') ?> · Task grace due: <?= htmlspecialchars((string) $epiTestResult['grace_due_at'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="settings-card">
                        <h2>Portal Settings</h2>
                        <p class="card-sub">System-wide settings. Visible to Owner/Admin only.</p>
                        <div class="form-row">
                            <div class="form-group"><label>Shop closing time</label><input type="time" value="17:00"></div>
                            <div class="form-group"><label>Waybill same-day cutoff</label><input type="time" value="16:30"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Next-day SLA deadline</label><input type="time" value="09:00"></div>
                            <div class="form-group"><label>Portal name</label><input type="text" value="Hambelela Organic Operations"></div>
                        </div>
                        <div class="btn-row"><button class="btn-secondary" type="button">Save portal settings</button></div>
                    </div>
                <?php else: ?>
                    <div class="settings-card">
                        <h2>Portal Settings</h2>
                        <p class="card-sub">Portal settings are only accessible to Owner/Admin.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="section-help" class="settings-section <?= $activeSettingsSection === 'help' ? 'active' : '' ?>">
                <div class="settings-card">
                    <h2>Help & Support</h2>
                    <p class="card-sub">Guides and contacts for common portal questions.</p>
                    <div class="toggle-row"><div class="toggle-label">Forgot your access code?</div><span class="settings-help-text">Ask an Owner/Admin to reset it from Employees & Roles.</span></div>
                    <div class="toggle-row"><div class="toggle-label">Found a bug or customer issue?</div><span class="settings-help-text">Log operational issues under Operations Error Log.</span></div>
                    <div class="toggle-row"><div class="toggle-label">Portal version</div><span class="settings-help-text">Hambelela Operations Portal - <?= date('Y') ?></span></div>
                </div>
            </div>
        </section>
    </div>
</main>
<dialog class="temporary-code-dialog" data-temporary-code-dialog>
    <div class="temporary-code-dialog-card">
        <h2>Temporary access code created</h2>
        <p class="temporary-code-dialog-copy">This code is shown once. Share it securely; the employee must replace it after signing in.</p>
        <dl>
            <div><dt>Employee</dt><dd data-temporary-employee></dd></div>
            <div><dt>Code</dt><dd class="temporary-code-value" data-temporary-code></dd></div>
            <div><dt>Expires</dt><dd data-temporary-expiry></dd></div>
        </dl>
        <div class="temporary-code-dialog-actions">
            <button class="btn-primary" type="button" data-copy-temporary-code>Copy code</button>
            <button class="btn-secondary" type="button" data-close-temporary-code>Close</button>
        </div>
    </div>
</dialog>
<style>
.temporary-code-dialog{width:min(430px,calc(100vw - 32px));padding:0;border:1px solid #ede3d8;border-radius:14px;background:#fff;color:#2c211d;font-family:Figtree,sans-serif;box-shadow:0 22px 60px rgba(66,35,25,.2)}.temporary-code-dialog::backdrop{background:rgba(30,20,16,.42)}.temporary-code-dialog-card{padding:22px}.temporary-code-dialog h2{margin:0 0 6px;color:#721b1a;font-size:14px;line-height:1.2}.temporary-code-dialog-copy{margin:0 0 18px;color:#6f5a50;font-size:12px;line-height:1.5}.temporary-code-dialog dl{margin:0}.temporary-code-dialog dl div{display:grid;grid-template-columns:88px 1fr;gap:12px;padding:9px 0;border-top:1px solid #ede3d8}.temporary-code-dialog dt{color:#a08070;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}.temporary-code-dialog dd{margin:0;font-size:12px}.temporary-code-value{color:#721b1a!important;font-size:22px!important;font-weight:700!important;letter-spacing:.18em}.temporary-code-dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}.temporary-code-dialog-actions button{height:32px;font-weight:400}
</style>
<script>
window.portalRoutes = Object.assign({}, window.portalRoutes, {
    resetEmployeeCode: <?= json_encode(
        BASE_URL . '/apps/operations/reset-employee-code.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>,
    createTemporaryCode: <?= json_encode(
        BASE_URL . '/apps/operations/create-temporary-code.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>,
});

document.querySelectorAll('form[method="post"]').forEach((form) => {
    if (form.querySelector('input[name="csrf_token"]')) return;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value = <?= json_encode((string) $_SESSION['settings_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    form.prepend(token);
});

const showSettingsToast = (message, type = 'error') => {
    let container = document.querySelector('.portal-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'portal-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `portal-toast ${type === 'success' ? 'is-success' : 'is-error'}`;
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'portal-toast-close';
    closeButton.setAttribute('aria-label', 'Close notification');
    closeButton.textContent = '\u00d7';
    const title = document.createElement('p');
    title.className = 'portal-toast-title';
    title.textContent = type === 'success' ? 'Success' : 'Unable to reset code';
    const body = document.createElement('p');
    body.className = 'portal-toast-message';
    body.textContent = message;
    toast.append(closeButton, title, body);
    container.prepend(toast);

    const close = () => {
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 220);
    };
    closeButton.addEventListener('click', close);
    window.setTimeout(close, 5000);
};

const newEmployeeForm = document.querySelector('#newEmployeeForm');
const accessCodeInput = document.querySelector('#accessCode');
const confirmAccessCodeInput = document.querySelector('#confirmAccessCode');
const accessCodeError = document.querySelector('#accessCodeError');
const confirmAccessCodeError = document.querySelector('#confirmAccessCodeError');
const setAccessFieldError = (input, errorNode, message = '') => {
    input?.setCustomValidity(message);
    input?.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (errorNode) {
        errorNode.textContent = message;
        errorNode.hidden = !message;
    }
};
const validateNewEmployeeAccessCodes = () => {
    const code = String(accessCodeInput?.value || '').trim();
    const confirmation = String(confirmAccessCodeInput?.value || '').trim();
    let codeMessage = '';
    let confirmationMessage = '';
    if (code === '') codeMessage = 'Enter an access code.';
    else if (!/^\d+$/.test(code)) codeMessage = 'The access code may contain numbers only.';
    else if (code.length < 6) codeMessage = 'The access code must contain at least 6 digits.';
    else if (code.length > 10) codeMessage = 'The access code cannot exceed 10 digits.';
    else if (code !== confirmation) confirmationMessage = 'The access codes do not match.';
    setAccessFieldError(accessCodeInput, accessCodeError, codeMessage);
    setAccessFieldError(confirmAccessCodeInput, confirmAccessCodeError, confirmationMessage);
    return !codeMessage && !confirmationMessage;
};
accessCodeInput?.addEventListener('input', validateNewEmployeeAccessCodes);
confirmAccessCodeInput?.addEventListener('input', validateNewEmployeeAccessCodes);
newEmployeeForm?.addEventListener('submit', (event) => {
    const accessCodesValid = validateNewEmployeeAccessCodes();
    if (!accessCodesValid || !newEmployeeForm.checkValidity()) {
        event.preventDefault();
        newEmployeeForm.reportValidity();
        (accessCodeInput?.getAttribute('aria-invalid') === 'true' ? accessCodeInput : confirmAccessCodeInput)?.focus();
    }
});

const validateEmployeeResetCode = (value) => /^\d{4}$/.test(String(value).trim());

const parseSettingsJsonResponse = async (response) => {
    const contentType = response.headers.get('content-type') || '';
    const responseText = await response.text();
    if (!contentType.toLowerCase().includes('application/json')) {
        console.error('Reset code endpoint returned non-JSON.', {
            status: response.status,
            contentType,
            responseText: responseText.slice(0, 1000),
        });
        if (response.status === 404) {
            throw new Error('Reset Code endpoint was not found.');
        }
        throw new Error(`The server returned an invalid response (${response.status}).`);
    }
    try {
        return JSON.parse(responseText);
    } catch (error) {
        console.error('Reset code endpoint returned malformed JSON.', responseText.slice(0, 1000));
        throw new Error('The server returned malformed JSON.');
    }
};

document.querySelectorAll('[data-reset-code-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const codeInput = form.querySelector('[data-reset-code]');
        const confirmInput = form.querySelector('[data-reset-code-confirm]');
        const code = String(codeInput?.value || '').trim();
        const confirmation = String(confirmInput?.value || '').trim();

        if (!validateEmployeeResetCode(code)) {
            showSettingsToast('Reset code must contain exactly 4 numbers.');
            codeInput?.focus();
            return;
        }
        if (code !== confirmation) {
            showSettingsToast('The new reset code and confirmation do not match.');
            confirmInput?.focus();
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        try {
            const endpoint = window.portalRoutes?.resetEmployeeCode;
            if (!endpoint) {
                throw new Error('Reset Code endpoint is not configured.');
            }
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await parseSettingsJsonResponse(response);
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'The reset code could not be updated.');
            }
            form.reset();
            showSettingsToast(result.message || 'Reset code updated successfully.', 'success');
        } catch (error) {
            showSettingsToast(error.message || 'The reset code could not be updated.');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    });
});

const temporaryCodeDialog = document.querySelector('[data-temporary-code-dialog]');
const temporaryCodeValue = temporaryCodeDialog?.querySelector('[data-temporary-code]');
const closeTemporaryCodeDialog = () => {
    if (temporaryCodeValue) temporaryCodeValue.textContent = '';
    temporaryCodeDialog?.close();
};

document.querySelectorAll('[data-create-temporary-code]').forEach((button) => {
    button.addEventListener('click', async () => {
        const employeeName = button.dataset.employeeName || 'this employee';
        if (!window.confirm(`Create a 24-hour temporary access code for ${employeeName}? Any previous temporary code will stop working.`)) return;

        button.disabled = true;
        try {
            const formData = new FormData();
            formData.set('employee_id', button.dataset.employeeId || '');
            formData.set('csrf_token', <?= json_encode((string) $_SESSION['settings_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
            const response = await fetch(window.portalRoutes.createTemporaryCode, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            });
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.toLowerCase().includes('application/json')) {
                throw new Error(`The server returned an invalid response (${response.status}).`);
            }
            const result = await response.json();
            if (!response.ok || !result.success || !result.data?.temporary_code) {
                throw new Error(result.message || 'The temporary code could not be created.');
            }

            temporaryCodeDialog.querySelector('[data-temporary-employee]').textContent = result.data.employee_name || employeeName;
            temporaryCodeValue.textContent = result.data.temporary_code;
            temporaryCodeDialog.querySelector('[data-temporary-expiry]').textContent = new Date(result.data.expires_at.replace(' ', 'T')).toLocaleString();
            temporaryCodeDialog.showModal();
        } catch (error) {
            showSettingsToast(error.message || 'The temporary code could not be created.');
        } finally {
            button.disabled = false;
        }
    });
});

temporaryCodeDialog?.querySelector('[data-copy-temporary-code]')?.addEventListener('click', async () => {
    const code = temporaryCodeValue?.textContent || '';
    if (!code) return;
    await navigator.clipboard.writeText(code);
    showSettingsToast('Temporary code copied.', 'success');
});
temporaryCodeDialog?.querySelector('[data-close-temporary-code]')?.addEventListener('click', closeTemporaryCodeDialog);
temporaryCodeDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeTemporaryCodeDialog();
});
document.querySelectorAll('.settings-nav-item').forEach((item) => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.settings-nav-item').forEach((navItem) => navItem.classList.remove('active'));
        document.querySelectorAll('.settings-section').forEach((section) => section.classList.remove('active'));
        item.classList.add('active');
        const target = item.dataset.section;
        document.getElementById('section-' + target)?.classList.add('active');
        if (target) history.replaceState(null, '', '#section-' + target);
    });
});
let initialSettingsSection = window.location.hash.replace('#section-', '');
if (initialSettingsSection === 'notification-preferences') initialSettingsSection = 'notifications';
if (initialSettingsSection) {
    document.querySelectorAll('.settings-nav-item').forEach((item) => {
        if (item.dataset.section === initialSettingsSection) item.click();
    });
}
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
