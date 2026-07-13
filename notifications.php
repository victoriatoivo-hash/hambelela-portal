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
$items = is_array($payload['notifications'] ?? null) ? $payload['notifications'] : [];
$stats = ['unread' => 0, 'action' => 0, 'today' => 0, 'packing' => 0, 'tasks' => 0, 'errors' => 0];
$groups = ['action' => [], 'today' => [], 'earlier' => [], 'read' => []];
$today = date('Y-m-d');
foreach ($items as $item) {
    $module = strtolower((string) ($item['module'] ?? 'system'));
    $priority = strtolower((string) ($item['priority'] ?? 'normal'));
    $createdDate = substr((string) ($item['created_at'] ?? ''), 0, 10);
    $isRead = !empty($item['read_at']);
    $needsAction = !$isRead && in_array($priority, ['urgent', 'critical', 'important', 'high'], true);
    if (!$isRead) $stats['unread']++;
    if ($needsAction) $stats['action']++;
    if ($createdDate === $today) $stats['today']++;
    if (str_contains($module, 'pack')) $stats['packing']++;
    if (str_contains($module, 'task')) $stats['tasks']++;
    if (str_contains($module, 'error')) $stats['errors']++;
    if ($needsAction) $groups['action'][] = $item;
    elseif ($isRead) $groups['read'][] = $item;
    elseif ($createdDate === $today) $groups['today'][] = $item;
    else $groups['earlier'][] = $item;
}
$groupLabels = [
    'action' => ['Action required', 'Notifications needing attention'],
    'today' => ['Today', 'New portal activity today'],
    'earlier' => ['Earlier', 'Unread notifications from previous days'],
    'read' => ['Read', 'Notifications already reviewed'],
];
$notificationsCssPath = __DIR__ . '/assets/css/notifications-page.css';
$notificationsJsPath = __DIR__ . '/assets/js/notifications-page.js';

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<?php if (is_file($notificationsCssPath)): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/notifications-page.css?v=<?= (int) filemtime($notificationsCssPath) ?>">
<?php endif; ?>
<main class="notifications-page" data-notifications-page data-endpoint="<?= htmlspecialchars(BASE_URL . '/notifications-api.php', ENT_QUOTES, 'UTF-8') ?>">
    <header class="notifications-header">
        <div>
            <p class="notifications-kicker">Portal</p>
            <h1 class="notifications-title">Notifications</h1>
            <p class="notifications-subtitle">Alerts from orders, packing, tasks, bookkeeping and errors.</p>
        </div>
        <div class="notifications-header-actions">
            <button class="notification-header-btn" type="button" data-page-mark-all-read>Mark all read</button>
            <button class="notification-header-btn" type="button" data-page-clear-all>Clear all</button>
        </div>
    </header>

    <div class="notifications-error" data-notifications-error hidden><span data-notifications-error-message>Unable to update notifications.</span><button type="button" class="nt-btn nt-btn--secondary" data-retry-notifications>Retry</button></div>

    <section class="notification-stats-grid">
        <?php foreach ([['unread','bell','Unread'],['action','circle-alert','Action required'],['today','calendar-days','Today'],['packing','package','Packing'],['tasks','list-checks','Tasks'],['errors','triangle-alert','Errors']] as $stat): ?>
            <article class="notification-stat-card" data-stat="<?= $stat[0] ?>"><div class="notification-stat-icon"><i data-lucide="<?= $stat[1] ?>"></i></div><div><p class="notification-stat-label"><?= $stat[2] ?></p><p class="notification-stat-value" data-stat-value="<?= $stat[0] ?>"><?= (int) $stats[$stat[0]] ?></p></div></article>
        <?php endforeach; ?>
    </section>

    <section class="notification-filter-card is-collapsed">
        <button type="button" class="notification-filter-header" aria-expanded="false" data-notification-filter-toggle><span class="notification-filter-title"><i data-lucide="sliders-horizontal"></i> Filters</span><span class="notification-filter-state">Collapsed</span></button>
        <form class="notification-filter-body" method="get">
            <div class="notification-filter-grid">
                <div class="notification-filter-field"><label>Read status</label><select name="read"><option value="">All</option><option value="unread">Unread</option><option value="read">Read</option></select></div>
                <div class="notification-filter-field"><label>Category</label><select name="category"><option value="">All categories</option><option value="packing">Packing</option><option value="tasks">Tasks</option><option value="errors">Errors</option><option value="orders">Orders</option></select></div>
                <div class="notification-filter-field"><label>Priority</label><select name="priority"><option value="">All priorities</option><option value="high">High</option><option value="normal">Normal</option></select></div>
                <div class="notification-filter-field"><label>Date from</label><input type="date" name="from"></div>
                <div class="notification-filter-field"><label>Date to</label><input type="date" name="to"></div>
                <div class="notification-filter-field"><label>Search</label><input type="search" name="search" placeholder="Search notifications..."></div>
            </div>
            <div class="notification-filter-actions"><button class="nt-btn nt-btn--secondary" type="reset">Clear</button><button class="nt-btn nt-btn--primary" type="submit">Apply filters</button></div>
        </form>
    </section>

    <div class="notification-groups" data-notification-groups>
      <?php foreach ($groups as $groupKey => $groupItems): ?>
      <section class="notification-group" data-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="notification-group-header" aria-expanded="true"><span><span class="notification-group-title"><?= htmlspecialchars($groupLabels[$groupKey][0], ENT_QUOTES, 'UTF-8') ?></span><span class="notification-group-description"><?= htmlspecialchars($groupLabels[$groupKey][1], ENT_QUOTES, 'UTF-8') ?></span></span><span class="notification-group-header-right"><i class="notification-group-chevron" data-lucide="chevron-down"></i><span class="notification-group-count"><?= count($groupItems) ?></span></span></button>
        <div class="notification-group-body"><div class="notification-list">
        <?php if (!$groupItems): ?><div class="notification-empty-state"><i data-lucide="bell-off"></i><div><strong>No notifications</strong><span>New alerts will appear in this section.</span></div></div><?php endif; ?>
        <?php foreach ($groupItems as $item): ?>
            <?php
                $link = (string) ($item['action_link'] ?? '');
                $tag = strtolower((string) ($item['priority'] ?? 'normal'));
                $module = strtolower((string) ($item['module'] ?? 'system'));
                $category = str_contains($module, 'order') ? 'orders' : (str_contains($module, 'pack') ? 'packing' : (str_contains($module, 'task') ? 'tasks' : (str_contains($module, 'book') || str_contains($module, 'cash') ? 'bookkeeping' : (str_contains($module, 'error') ? 'errors' : 'system'))));
                $createdAt = (string) ($item['created_at'] ?? '');
                $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
            ?>
            <article class="notification-row <?= empty($item['read_at']) ? 'is-unread' : 'is-read' ?>" data-notification-id="<?= (int) ($item['id'] ?? 0) ?>" data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" data-priority="<?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>" data-target-url="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
                <span class="notification-row-indicator"></span>
                <span class="notification-row-icon"><i data-lucide="<?= $category === 'orders' ? 'shopping-bag' : ($category === 'packing' ? 'package' : ($category === 'tasks' ? 'list-checks' : ($category === 'errors' ? 'triangle-alert' : 'bell'))) ?>"></i></span>
                <span class="notification-row-content">
                    <span class="notification-row-heading"><strong class="notification-row-title"><?= htmlspecialchars((string) ($item['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong><time class="notification-row-time"><?= $createdTs ? htmlspecialchars(date('H:i', $createdTs), ENT_QUOTES, 'UTF-8') : '' ?></time></span>
                    <span class="notification-row-message"><?= htmlspecialchars((string) ($item['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="notification-row-meta"><?= htmlspecialchars((string) ($item['module'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?><?= $createdTs ? ' · ' . htmlspecialchars(date('j F Y', $createdTs), ENT_QUOTES, 'UTF-8') : '' ?></span>
                </span>
                <span class="notification-row-actions"><?php if ($link): ?><button class="notification-row-btn" type="button" data-open-notification>View</button><?php endif; ?><?php if (empty($item['read_at'])): ?><button class="notification-icon-btn" type="button" data-page-mark-read aria-label="Mark as read"><i data-lucide="check"></i></button><?php endif; ?><button class="notification-icon-btn" type="button" data-page-archive aria-label="Archive"><i data-lucide="archive"></i></button></span>
            </article>
        <?php endforeach; ?>
        </div></div>
      </section>
      <?php endforeach; ?>
    </div>
</main>
<?php if (is_file($notificationsJsPath)): ?><script src="<?= BASE_URL ?>/assets/js/notifications-page.js?v=<?= (int) filemtime($notificationsJsPath) ?>"></script><?php endif; ?>
<?php include BASE_PATH . '/shared/footer.php'; ?>
