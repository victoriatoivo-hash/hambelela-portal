<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'My Account | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$employee = null;

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
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
