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
            notifications_save_preferences((int) $employee['id'], [
                'desktop_enabled' => (int) ($_POST['desktop_enabled'] ?? 0) === 1,
                'sound_enabled' => (int) ($_POST['sound_enabled'] ?? 0) === 1,
                'muted_when_unavailable' => (int) ($_POST['muted_when_unavailable'] ?? 0) === 1,
                'modules' => array_values(array_filter(array_map('strval', $_POST['modules'] ?? []))),
            ]);
            $notificationPrefs = notifications_preferences((int) $employee['id']);
            $message = 'Notification preferences updated.';
        } else {
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
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>My Account</h1>
            <p>Change your own 4-digit login code. You need your current code to set a new one.</p>
        </div>
    </section>
    <?php ops_nav('account'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="ops-split">
        <form class="panel ops-form" method="post">
            <input type="hidden" name="action" value="change_code">
            <div class="section-row"><h2>Reset my login code</h2></div>
            <?php if ($employee): ?>
                <p><?= htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($employee['role_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <label>Current 4-digit code<input type="password" name="current_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required></label>
            <label>New 4-digit code<input type="password" name="new_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required></label>
            <label>Confirm new code<input type="password" name="confirm_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required></label>
            <div class="ops-form-actions"><button class="button primary" type="submit">Update my code</button></div>
        </form>
        <section class="panel">
            <div class="section-row"><h2>Need help?</h2></div>
            <p>If you forgot your current code, ask an Owner/Admin to reset it from Employees & Roles.</p>
        </section>
        <form class="panel ops-form" id="notification-preferences" method="post">
            <input type="hidden" name="action" value="update_notifications">
            <div class="section-row">
                <h2>Notification preferences</h2>
            </div>
            <p>Choose how the portal should alert you for work that belongs to your role.</p>
            <div class="notification-settings-grid">
                <label class="notification-check">
                    <input type="checkbox" name="desktop_enabled" value="1" <?= !empty($notificationPrefs['desktop_enabled']) ? 'checked' : '' ?>>
                    Desktop browser notifications
                </label>
                <label class="notification-check">
                    <input type="checkbox" name="sound_enabled" value="1" <?= !empty($notificationPrefs['sound_enabled']) ? 'checked' : '' ?>>
                    Soft notification sound
                </label>
                <label class="notification-check">
                    <input type="checkbox" name="muted_when_unavailable" value="1" <?= !empty($notificationPrefs['muted_when_unavailable']) ? 'checked' : '' ?>>
                    Mute when I am on lunch, away or offline
                </label>
            </div>
            <h3>Modules</h3>
            <div class="notification-module-grid">
                <?php foreach ($notificationModules as $moduleKey => $moduleLabel): ?>
                    <label class="notification-check">
                        <input
                            type="checkbox"
                            name="modules[]"
                            value="<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>"
                            <?= in_array($moduleKey, $notificationPrefs['modules'] ?? [], true) ? 'checked' : '' ?>
                        >
                        <?= htmlspecialchars($moduleLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="ops-form-actions"><button class="button primary" type="submit">Save notification settings</button></div>
        </form>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
