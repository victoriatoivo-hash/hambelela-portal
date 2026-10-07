<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/apps/operations/operations.php';

require_login();

$pageTitle = 'Notifications | ' . APP_NAME;
$activeApp = 'notifications';
$notificationsCssPath = __DIR__ . '/assets/css/notifications-page.css';
$notificationsJsPath = __DIR__ . '/assets/js/notifications-page.js';

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<?php if (is_file($notificationsCssPath)): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/notifications-page.css?v=<?= (int) filemtime($notificationsCssPath) ?>">
<?php endif; ?>
<main class="workspace module notifications-page ess-notifications" data-notifications-page
      data-feed-endpoint="<?= htmlspecialchars(BASE_URL . '/api/notifications-feed.php', ENT_QUOTES, 'UTF-8') ?>"
      data-action-endpoint="<?= htmlspecialchars(BASE_URL . '/notifications-api.php', ENT_QUOTES, 'UTF-8') ?>">
    <header class="notifications-header">
        <div>
            <p class="notifications-kicker">Portal</p>
            <h1 class="notifications-title">Notifications</h1>
            <p class="notifications-subtitle">Stay informed. Keep things moving.</p>
        </div>
        <div class="nt-header-art" aria-hidden="true"><svg viewBox="0 0 160 100" fill="none" stroke="currentColor"><path d="M35 100Q60 45 145 5M70 65Q15 55 30 25Q75 28 70 65M96 40Q80 2 110 4Q135 14 96 40M50 87Q90 50 119 69Q108 95 50 87"/></svg><span>The right information<br>at the right time.</span></div>
        <div class="notifications-header-actions">
            <button class="notification-header-btn" type="button" data-page-mark-all-read disabled>Mark all read</button>
            <button class="notification-header-btn" type="button" data-page-clear-all disabled>Clear all</button>
        </div>
    </header>

    <section class="notifications-root" data-notifications-root aria-live="polite">
        <div class="notifications-loading">Loading notifications...</div>
    </section>
</main>
<?php if (is_file($notificationsJsPath)): ?>
<script src="<?= BASE_URL ?>/assets/js/notifications-page.js?v=<?= (int) filemtime($notificationsJsPath) ?>" defer></script>
<?php endif; ?>
<?php include BASE_PATH . '/shared/footer.php'; ?>
