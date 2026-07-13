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

    <section class="notifications-list">
        <?php if (!$items): ?>
            <p>No notifications yet.</p>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php
                $link = (string) ($item['action_link'] ?? '');
                $tag = strtolower((string) ($item['priority'] ?? 'normal'));
                $module = strtolower((string) ($item['module'] ?? 'system'));
                $category = str_contains($module, 'order') ? 'orders' : (str_contains($module, 'pack') ? 'packing' : (str_contains($module, 'task') ? 'tasks' : (str_contains($module, 'book') || str_contains($module, 'cash') ? 'bookkeeping' : (str_contains($module, 'error') ? 'errors' : 'system'))));
                $createdAt = (string) ($item['created_at'] ?? '');
                $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
            ?>
            <a class="notification-row <?= empty($item['read_at']) ? 'is-unread' : 'is-read' ?>" data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" data-priority="<?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($link ?: '#', ENT_QUOTES, 'UTF-8') ?>">
                <span class="notification-row-indicator"></span>
                <span class="notification-row-icon"><i data-lucide="<?= $category === 'orders' ? 'shopping-bag' : ($category === 'packing' ? 'package' : ($category === 'tasks' ? 'list-checks' : ($category === 'errors' ? 'triangle-alert' : 'bell'))) ?>"></i></span>
                <span class="notification-row-content">
                    <span class="notification-row-heading"><strong class="notification-row-title"><?= htmlspecialchars((string) ($item['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong><time class="notification-row-time"><?= $createdTs ? htmlspecialchars(date('H:i', $createdTs), ENT_QUOTES, 'UTF-8') : '' ?></time></span>
                    <span class="notification-row-message"><?= htmlspecialchars((string) ($item['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="notification-row-meta"><?= htmlspecialchars((string) ($item['module'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?><?= $createdTs ? ' · ' . htmlspecialchars(date('j F Y', $createdTs), ENT_QUOTES, 'UTF-8') : '' ?></span>
                </span>
                <span class="notification-row-btn">View</span>
            </a>
        <?php endforeach; ?>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>

