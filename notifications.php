<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

$pageTitle = 'Notifications | ' . APP_NAME;
$activeApp = 'notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_read') {
        notifications_mark_read();
    } elseif ($action === 'clear') {
        notifications_clear();
    }
    header('Location: ' . BASE_URL . '/notifications.php');
    exit;
}

$payload = notifications_for_current_user(100);
$items = $payload['notifications'] ?? [];
$notificationsCssPath = __DIR__ . '/assets/css/notifications-page.css';

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<?php if (is_file($notificationsCssPath)): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/notifications-page.css?v=<?= (int) filemtime($notificationsCssPath) ?>">
<?php endif; ?>
<main class="notifications-page">
    <header class="notifications-header">
        <div>
            <p class="notifications-kicker">Portal</p>
            <h1 class="notifications-title">Notifications</h1>
            <p class="notifications-subtitle">Alerts from orders, packing, tasks, bookkeeping and errors.</p>
        </div>
        <form class="notifications-header-actions" method="post">
            <button class="notification-header-btn" type="submit" name="action" value="mark_read">Mark all read</button>
            <button class="notification-header-btn" type="submit" name="action" value="clear">Clear all</button>
        </form>
    </header>

    <section class="panel notification-page-list">
        <?php if (!$items): ?>
            <p>No notifications yet.</p>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php
                $link = (string) ($item['action_link'] ?? '');
                $tag = strtolower((string) ($item['priority'] ?? 'normal'));
            ?>
            <a class="notification-page-item <?= empty($item['read_at']) ? 'is-unread' : '' ?>" href="<?= htmlspecialchars($link ?: '#', ENT_QUOTES, 'UTF-8') ?>">
                <span class="notification-priority <?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>"></span>
                <span>
                    <strong><?= htmlspecialchars((string) ($item['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars((string) ($item['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                    <em><?= htmlspecialchars((string) ($item['module'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></em>
                </span>
            </a>
        <?php endforeach; ?>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
