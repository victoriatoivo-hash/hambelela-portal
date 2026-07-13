<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();
$pageTitle = 'Notifications | ' . APP_NAME;
$activeApp = 'notifications';
$notificationsCssVersion = is_file(__DIR__ . '/assets/css/notifications-page.css') ? (int) filemtime(__DIR__ . '/assets/css/notifications-page.css') : 1;
$notificationsJsVersion = is_file(__DIR__ . '/assets/js/notifications-page.js') ? (int) filemtime(__DIR__ . '/assets/js/notifications-page.js') : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_read') notifications_mark_read();
    if ($action === 'clear') notifications_clear();
    header('Location: ' . BASE_URL . '/notifications.php');
    exit;
}

$payload = notifications_for_current_user(250);
$allItems = $payload['notifications'] ?? [];
$categoryMap = ['order' => 'orders', 'packing' => 'packing', 'task' => 'tasks', 'book' => 'bookkeeping', 'cash' => 'bookkeeping', 'error' => 'errors', 'hr' => 'hr'];
$categoryLabel = ['orders' => 'Orders', 'packing' => 'Packing', 'tasks' => 'Tasks', 'bookkeeping' => 'Bookkeeping', 'errors' => 'Errors', 'hr' => 'HR', 'system' => 'System'];
$categoryFor = static function (array $item) use ($categoryMap): string {
    $source = strtolower((string) ($item['module'] ?? '') . ' ' . (string) ($item['title'] ?? ''));
    foreach ($categoryMap as $needle => $category) if (str_contains($source, $needle)) return $category;
    return 'system';
};
$priorityFor = static fn(array $item): string => strtolower((string) ($item['priority'] ?? 'normal'));
$isAction = static function (array $item) use ($priorityFor): bool {
    $priority = $priorityFor($item);
    $text = strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['message'] ?? ''));
    return empty($item['read_at']) && (in_array($priority, ['urgent', 'critical', 'important', 'high'], true) || preg_match('/overdue|failed|unresolved|unassigned|action required|pending too long/', $text));
};

$filters = [
    'read' => (string) ($_GET['read'] ?? ''), 'category' => (string) ($_GET['category'] ?? ''),
    'priority' => (string) ($_GET['priority'] ?? ''), 'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''), 'search' => trim((string) ($_GET['search'] ?? '')),
];
$items = array_values(array_filter($allItems, static function (array $item) use ($filters, $categoryFor, $priorityFor): bool {
    $isRead = !empty($item['read_at']);
    $created = substr((string) ($item['created_at'] ?? ''), 0, 10);
    if ($filters['read'] === 'unread' && $isRead) return false;
    if ($filters['read'] === 'read' && !$isRead) return false;
    if ($filters['category'] !== '' && $categoryFor($item) !== $filters['category']) return false;
    if ($filters['priority'] !== '' && $priorityFor($item) !== $filters['priority']) return false;
    if ($filters['from'] !== '' && $created < $filters['from']) return false;
    if ($filters['to'] !== '' && $created > $filters['to']) return false;
    if ($filters['search'] !== '' && !str_contains(strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['message'] ?? '')), strtolower($filters['search']))) return false;
    return true;
}));

$today = date('Y-m-d');
$groups = ['action-required' => [], 'today' => [], 'earlier' => [], 'read' => []];
foreach ($items as $item) {
    if ($isAction($item)) $groups['action-required'][] = $item;
    elseif (!empty($item['read_at'])) $groups['read'][] = $item;
    elseif (substr((string) ($item['created_at'] ?? ''), 0, 10) === $today) $groups['today'][] = $item;
    else $groups['earlier'][] = $item;
}
$stats = ['unread' => 0, 'action' => count($groups['action-required']), 'orders' => 0, 'packing' => 0, 'tasks' => 0, 'errors' => 0];
foreach ($allItems as $item) {
    if (empty($item['read_at'])) $stats['unread']++;
    $cat = $categoryFor($item); if (isset($stats[$cat])) $stats[$cat]++;
}
$groupMeta = [
    'action-required' => ['Action Required', 'Notifications needing attention'],
    'today' => ['Today', 'New portal activity today'],
    'earlier' => ['Earlier', 'Unread notifications from previous days'],
    'read' => ['Read / Archived', 'Notifications already reviewed'],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/notifications-page.css?v=<?= $notificationsCssVersion ?>">
<main class="notifications-page" data-notifications-page data-endpoint="<?= BASE_URL ?>/notifications-api.php">
  <header class="notifications-header">
    <div><p class="notifications-kicker">Portal</p><h1 class="notifications-title">Notifications</h1><p class="notifications-subtitle">Alerts from orders, packing, tasks, bookkeeping, errors and portal activity.</p></div>
    <div class="notifications-header-actions">
      <button type="button" class="nt-btn nt-btn--secondary" data-page-mark-all-read>Mark all read</button>
      <button type="button" class="nt-btn nt-btn--danger-outline" data-page-clear-all>Clear all</button>
    </div>
  </header>

  <section class="notification-stats-grid">
    <?php foreach ([['unread','bell','Unread','Needs review'],['action','circle-alert','Action Required','Needs attention'],['orders','shopping-bag','Orders','Order alerts'],['packing','package','Packing','Packing alerts'],['tasks','list-checks','Tasks','Task alerts'],['errors','triangle-alert','Errors','Error alerts']] as [$key,$icon,$label,$caption]): ?>
      <article class="notification-stat-card" data-stat="<?= $key ?>"><div class="notification-stat-icon"><i data-lucide="<?= $icon ?>"></i></div><div><p class="notification-stat-label"><?= $label ?></p><p class="notification-stat-value" data-stat-value="<?= $key ?>"><?= (int) $stats[$key] ?></p><p class="notification-stat-caption"><?= $caption ?></p></div></article>
    <?php endforeach; ?>
  </section>

  <section class="notification-filter-card is-collapsed">
    <button type="button" class="notification-filter-header" aria-expanded="false" data-notification-filter-toggle><span class="notification-filter-title"><i data-lucide="sliders-horizontal"></i> Filters</span><span class="notification-filter-state">Collapsed</span></button>
    <form class="notification-filter-body" method="get">
      <div class="notification-filter-grid">
        <?php $selects = ['read'=>['Read status',[''=>'All','unread'=>'Unread','read'=>'Read']], 'category'=>['Category',[''=>'All categories'] + $categoryLabel], 'priority'=>['Priority',[''=>'All priorities','urgent'=>'Critical','important'=>'High','normal'=>'Normal','info'=>'Information']]]; ?>
        <?php foreach ($selects as $name => [$label,$options]): ?><div class="notification-filter-field"><label><?= $label ?></label><select name="<?= $name ?>" data-portal-custom-select><?php foreach ($options as $value=>$text): ?><option value="<?= htmlspecialchars($value) ?>" <?= $filters[$name] === $value ? 'selected' : '' ?>><?= htmlspecialchars($text) ?></option><?php endforeach; ?></select></div><?php endforeach; ?>
        <div class="notification-filter-field"><label>Date from</label><input type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>"></div>
        <div class="notification-filter-field"><label>Date to</label><input type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>"></div>
        <div class="notification-filter-field"><label>Search</label><input type="search" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Search notifications..."></div>
      </div>
      <div class="notification-filter-actions"><a class="nt-btn nt-btn--secondary" href="<?= BASE_URL ?>/notifications.php">Clear</a><button class="nt-btn nt-btn--primary" type="submit">Apply filters</button></div>
    </form>
  </section>

  <div class="desktop-notification-setting"><div><strong>Desktop alerts</strong><span>Show important portal notifications on this device.</span></div><button type="button" class="nt-btn nt-btn--secondary" data-enable-desktop-alerts>Enable</button></div>

  <div class="notification-groups">
    <?php foreach ($groups as $groupKey => $groupItems): [$groupTitle,$groupDescription] = $groupMeta[$groupKey]; ?>
      <section class="notification-group" data-group="<?= $groupKey ?>">
        <button type="button" class="notification-group-header" aria-expanded="true"><span><span class="notification-group-title"><?= $groupTitle ?></span><span class="notification-group-description"><?= $groupDescription ?></span></span><span class="notification-group-header-right"><i class="notification-group-chevron" data-lucide="chevron-down"></i><span class="notification-group-count"><?= count($groupItems) ?></span></span></button>
        <div class="notification-group-body"><div class="notification-list">
          <?php if (!$groupItems): ?><div class="notification-empty-state"><i data-lucide="bell-off"></i><div><strong>No notifications here</strong><span>New alerts will appear in this section.</span></div></div><?php endif; ?>
          <?php foreach ($groupItems as $item): $cat=$categoryFor($item); $priority=$priorityFor($item); $created=(string)($item['created_at']??''); $link=(string)($item['action_link']??''); ?>
            <article class="notification-row <?= empty($item['read_at']) ? 'is-unread' : 'is-read' ?>" data-notification-id="<?= (int)$item['id'] ?>" data-category="<?= $cat ?>" data-priority="<?= htmlspecialchars($priority) ?>" data-target-url="<?= htmlspecialchars($link, ENT_QUOTES) ?>">
              <div class="notification-row-indicator"></div><div class="notification-row-icon"><i data-lucide="<?= $cat==='orders'?'shopping-bag':($cat==='packing'?'package':($cat==='tasks'?'list-checks':($cat==='errors'?'triangle-alert':'bell'))) ?>"></i></div>
              <div class="notification-row-content"><div class="notification-row-heading"><h3 class="notification-row-title"><?= htmlspecialchars((string)($item['title']??'Notification')) ?></h3><span class="notification-row-time"><?= htmlspecialchars(date('H:i', strtotime($created))) ?></span></div><p class="notification-row-message"><?= htmlspecialchars((string)($item['message']??'')) ?></p><div class="notification-row-meta"><span class="notification-category-chip"><?= $categoryLabel[$cat] ?></span><span><?= htmlspecialchars(date('j F Y', strtotime($created))) ?></span><span><?= htmlspecialchars((string)($item['module']??'System')) ?></span></div></div>
              <div class="notification-row-actions"><?php if ($link): ?><button class="notification-row-btn" type="button" data-open-notification>View</button><?php endif; ?><?php if (empty($item['read_at'])): ?><button class="notification-icon-btn" type="button" data-page-mark-read aria-label="Mark as read"><i data-lucide="check"></i></button><?php endif; ?><button class="notification-icon-btn" type="button" data-page-archive aria-label="Archive"><i data-lucide="archive"></i></button></div>
            </article>
          <?php endforeach; ?>
        </div></div>
      </section>
    <?php endforeach; ?>
  </div>
</main>
<script src="<?= BASE_URL ?>/assets/js/notifications-page.js?v=<?= $notificationsJsVersion ?>"></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
