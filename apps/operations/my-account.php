<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/notifications.php';

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

if ($ready) {
    $employeeId = ops_current_employee_id();
    if ($employeeId) {
        $rows = ops_rows(
            "SELECT e.*, r.name AS role_name
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
        if (!$employee) {
            throw new RuntimeException('Your employee account could not be found.');
        }

        $action = ops_post_string('action', 40) ?: 'change_code';

        if ($action === 'update_notifications') {
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

            if (!preg_match('/^\d{4}$/', $currentCode) || !preg_match('/^\d{4}$/', $newCode)) {
                throw new RuntimeException('Codes must be exactly 4 digits.');
            }

            if ($newCode !== $confirmCode) {
                throw new RuntimeException('The new code and confirmation do not match.');
            }

            if (empty($employee['password_hash']) || !password_verify($currentCode, (string) $employee['password_hash'])) {
                throw new RuntimeException('Current code is incorrect.');
            }

            $stmt = db()->prepare('UPDATE ops_employees SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([password_hash($newCode, PASSWORD_DEFAULT), (int) $employee['id']]);
            $message = 'Your login code has been updated.';
            ops_activity_log('employee_code_changed', 'ops_employee', (int) $employee['id']);
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

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
                    <h2>Login Code</h2>
                    <p class="card-sub">Change your 4-digit portal login code. You need your current code to set a new one.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Current 4-digit code</label>
                            <input type="password" name="current_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                        </div>
                        <div class="form-group">
                            <label>New 4-digit code</label>
                            <input type="password" name="new_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm new code</label>
                        <input type="password" name="confirm_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
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
                    <div class="toggle-row"><div class="toggle-label">Forgot your login code?</div><span class="settings-help-text">Ask Victoria to reset it from Employees & Roles.</span></div>
                    <div class="toggle-row"><div class="toggle-label">Found a bug or customer issue?</div><span class="settings-help-text">Log operational issues under Operations Error Log.</span></div>
                    <div class="toggle-row"><div class="toggle-label">Portal version</div><span class="settings-help-text">Hambelela Operations Portal - <?= date('Y') ?></span></div>
                </div>
            </div>
        </section>
    </div>
</main>
<script>
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
