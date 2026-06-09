<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/hr-portal-gate.php';

require_login();

if (!user_has_role('owner_admin')) {
    http_response_code(403);
    exit('You do not have access to the HR Portal.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = trim((string) ($_POST['hr_pin'] ?? ''));
    if (!hr_portal_pin_is_configured()) {
        $error = 'HR Portal PIN is not configured yet.';
    } elseif (hr_portal_pin_matches($pin)) {
        hr_portal_set_unlocked_cookie((BASE_URL ?: '') . '/apps/hr-portal');
        header('Location: ' . BASE_URL . '/apps/hr-portal/index.php');
        exit;
    } else {
        $error = 'Incorrect HR PIN.';
    }
}

$pageTitle = 'HR Portal Access | ' . APP_NAME;
$activeApp = 'hr-portal';

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Protected app</p>
            <h1>HR Portal</h1>
            <p>Enter the HR PIN before opening confidential employee information.</p>
        </div>
    </section>

    <section class="panel" style="max-width:520px">
        <?php if (!hr_portal_pin_is_configured()): ?>
            <div class="notice warning">
                Add <strong>hr_portal_pin</strong> or <strong>hr_portal_pin_hash</strong> to <code>config.local.php</code> before using this test app.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
            <label class="field span-2">
                <span>HR PIN</span>
                <input type="password" name="hr_pin" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            </label>
            <div class="form-actions span-2">
                <button class="btn primary" type="submit">
                    <i data-lucide="lock-keyhole"></i>
                    Open HR Portal
                </button>
            </div>
        </form>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
