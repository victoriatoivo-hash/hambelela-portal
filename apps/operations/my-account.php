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
$employee = null;
$notificationPrefs = notifications_preferences();
$notificationModules = notifications_modules();
$canManagePortal = user_has_role('owner_admin') || in_array((string) ($_SESSION['user_role'] ?? ''), ['admin', 'Owner/Admin', 'owner_admin'], true);
$activeSettingsSection = 'profile';

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

        if (in_array($action, ['reset_code', 'delete_employee', 'save_hr_link', 'save_employee'], true)) {
            if (!$canManagePortal) {
                throw new RuntimeException('Only Owner/Admin can manage employee accounts.');
            }

            $activeSettingsSection = 'employees';

            if ($action === 'reset_code') {
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
                $validationError = access_secret_validation_error($code, (string) $targetEmployee['role_key']);
                if ($validationError !== null) {
                    throw new RuntimeException($validationError);
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
                if ($code !== $confirmCode) {
                    throw new RuntimeException('The access code and confirmation do not match.');
                }

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
                    throw new RuntimeException('That access code is already assigned to another account.');
                }

                $email = strtolower(ops_post_string('email', 190));
                if ($email === '') {
                    throw new RuntimeException('Email is required.');
                }

                $stmt = db()->prepare(
                    "INSERT INTO ops_employees (role_id, full_name, email, phone, password_hash, status, requires_code_reset)
                     VALUES (?, ?, ?, ?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE
                        role_id = VALUES(role_id),
                        full_name = VALUES(full_name),
                        phone = VALUES(phone),
                        password_hash = VALUES(password_hash),
                        status = VALUES(status),
                        requires_code_reset = 0,
                        failed_login_attempts = 0,
                        locked_until = NULL,
                        last_failed_login_at = NULL,
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    $roleId,
                    ops_post_string('full_name', 160),
                    $email,
                    ops_post_string('phone', 60),
                    password_hash($code, PASSWORD_DEFAULT),
                    ops_post_string('status', 20) ?: 'active',
                ]);
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
    }
}

$employeeRoles = $ready && $canManagePortal ? ops_rows("SELECT id, name FROM ops_roles WHERE role_key <> 'owner_admin' ORDER BY FIELD(role_key, 'front_desk_admin', 'packer', 'supervisor_manager'), name") : [];
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
$managedEmployees = $ready && $canManagePortal ? ops_rows(
    "SELECT e.*, r.name AS role_name, r.role_key
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
                                    <label>KPI role note</label>
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
                        <p class="card-sub">Active operational staff only. Owner/Admin is managed separately from employee tracking.</p>
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
                                        <th>Reset Code</th>
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
                                            <form class="settings-code-reset" method="post">
                                                <input type="hidden" name="action" value="reset_code">
                                                <input type="hidden" name="employee_id" value="<?= (int) $managedEmployee['id'] ?>">
                                                <input name="login_code" type="password" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" placeholder="New code" autocomplete="new-password" required>
                                                <input name="confirm_login_code" type="password" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" placeholder="Confirm" autocomplete="new-password" required>
                                                <button class="btn-secondary" type="submit">Reset</button>
                                            </form>
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
                                <?php if (!$managedEmployees): ?><tr><td colspan="9">No employee accounts recorded yet.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form class="settings-card" method="post">
                        <input type="hidden" name="action" value="save_employee">
                        <h2>New employee</h2>
                        <p class="card-sub">Add a staff login and set a unique 6 to 10 digit access code.</p>
                        <div class="form-row">
                            <div class="form-group"><label>Full name</label><input name="full_name" required autocomplete="name"></div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" required autocomplete="email"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Phone</label><input name="phone" autocomplete="tel"></div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role_id" required>
                                    <?php foreach ($employeeRoles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Status</label><select name="status"><?php ops_select_options(['active' => 'Active', 'inactive' => 'Inactive']); ?></select></div>
                            <div class="form-group"><label>Access code</label><input type="password" name="login_code" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" autocomplete="new-password" required placeholder="6 to 10 digits"></div>
                            <div class="form-group"><label>Confirm access code</label><input type="password" name="confirm_login_code" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" autocomplete="new-password" required placeholder="Repeat code"></div>
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
<script>
document.querySelectorAll('form[method="post"]').forEach((form) => {
    if (form.querySelector('input[name="csrf_token"]')) return;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value = <?= json_encode((string) $_SESSION['settings_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    form.prepend(token);
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
