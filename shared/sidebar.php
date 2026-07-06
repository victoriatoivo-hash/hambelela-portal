<?php

declare(strict_types=1);

$activeApp = $activeApp ?? 'dashboard';
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$currentPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? $scriptName), PHP_URL_PATH);
$isFrontDashboard = $scriptName === '/index.php' || preg_match('#/hambelela-portal/index\.php$#', $scriptName) === 1;
$isHrPortal = strpos($scriptName, '/apps/hr-portal/') !== false;

if (!empty($hidePortalSidebar) || $isFrontDashboard || $isHrPortal) {
    return;
}

$notificationUnread = (int) ($notificationUnread ?? 0);

$portalNavItems = [
    ['id' => 'operations-dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => BASE_URL . '/apps/operations/index.php', 'match' => ['/apps/operations/index.php']],
    ['id' => 'operations-orders', 'label' => 'Orders', 'icon' => 'orders', 'href' => BASE_URL . '/apps/operations/orders-board.php', 'match' => ['/apps/operations/orders-board.php']],
    ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'bookkeeping', 'href' => BASE_URL . '/apps/operations/bookkeeping.php', 'match' => ['/apps/operations/bookkeeping.php']],
    ['id' => 'operations-cash-tools', 'label' => 'Cash Tools', 'icon' => 'cash', 'href' => BASE_URL . '/apps/operations/bank-statement-processor.php', 'match' => ['/apps/operations/bank-statement-processor.php']],
    ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'packing', 'href' => BASE_URL . '/apps/operations/consignments.php', 'match' => ['/apps/operations/consignments.php']],
    ['id' => 'operations-inventory', 'label' => 'Inventory', 'icon' => 'inventory', 'href' => BASE_URL . '/apps/operations/orders.php?tab=inventory', 'match' => ['/apps/operations/orders.php']],
    ['id' => 'operations-pos-reports', 'label' => 'POS Reports', 'icon' => 'reports', 'href' => BASE_URL . '/apps/operations/orders.php', 'match' => ['/apps/operations/orders.php']],
    ['id' => 'kpi', 'label' => 'KPI Dashboard', 'icon' => 'kpi', 'href' => BASE_URL . '/apps/operations/reports.php', 'match' => ['/apps/operations/reports.php']],
    ['id' => 'operations-checklists', 'label' => 'Task Management', 'icon' => 'tasks', 'href' => BASE_URL . '/apps/operations/checklists.php', 'match' => ['/apps/operations/checklists.php']],
    ['id' => 'operations-errors', 'label' => 'Error Log', 'icon' => 'errors', 'href' => BASE_URL . '/apps/operations/errors.php', 'match' => ['/apps/operations/errors.php']],
    ['id' => 'notifications', 'label' => 'Notifications', 'icon' => 'notifications', 'href' => BASE_URL . '/notifications.php', 'match' => ['/notifications.php'], 'badge' => $notificationUnread],
    ['id' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'href' => BASE_URL . '/apps/operations/my-account.php', 'match' => ['/apps/operations/my-account.php']],
];

function portal_sidebar_icon(string $name): string
{
    $icons = [
        'chevron' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
        'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>',
        'bookkeeping' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H20v16H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M8 8h8"/><path d="M8 12h6"/><path d="M8 16h7"/></svg>',
        'cash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9h.01"/><path d="M18 15h.01"/></svg>',
        'packing' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 8-9-5-9 5 9 5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
        'inventory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/><path d="M7 4v16"/><path d="M17 4v16"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16V9"/><path d="M12 16V6"/><path d="M16 16v-4"/></svg>',
        'kpi' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17 9 11l4 4 8-8"/><path d="M21 7v6h-6"/></svg>',
        'tasks' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 11 2 2 4-4"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/><path d="M16 4h4v4"/></svg>',
        'errors' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 10 18H2z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.6 19a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 5 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.4 5a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.45.17.82.44 1.1.8.27.36.4.78.4 1.2h.1a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 0z"/></svg>',
        'moon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17 15 12l-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-5"/><path d="M14 21h5a2 2 0 0 0 2-2"/></svg>',
    ];

    return $icons[$name] ?? $icons['dashboard'];
}

$isActiveItem = static function (array $item) use ($currentPath, $activeApp): bool {
    foreach (($item['match'] ?? []) as $matchPath) {
        if ($currentPath === $matchPath) {
            if (($item['id'] ?? '') === 'operations-inventory') {
                return ($_GET['tab'] ?? '') === 'inventory';
            }
            if (($item['id'] ?? '') === 'operations-pos-reports') {
                return ($_GET['tab'] ?? '') !== 'inventory';
            }
            return true;
        }
    }

    return ($item['id'] ?? '') === $activeApp;
};
?>
<aside class="portal-sidebar" id="portal-sidebar" aria-label="Portal navigation">
    <button class="portal-sidebar-toggle" type="button" id="sidebarToggle" aria-label="Collapse sidebar" aria-pressed="false" data-sidebar-collapse>
        <?= portal_sidebar_icon('chevron') ?>
    </button>

    <a class="portal-brand" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/index.php" aria-label="Hambelela Business Portal">
        <span class="portal-brand-logo">HB</span>
        <span class="portal-brand-text">Hambelela Business Portal</span>
    </a>

    <nav class="portal-nav" aria-label="Main navigation">
        <?php foreach ($portalNavItems as $item): ?>
            <?php $isActive = $isActiveItem($item); ?>
            <a class="portal-nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>">
                <?= portal_sidebar_icon((string) $item['icon']) ?>
                <span class="portal-nav-label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="notification-dot portal-notification-badge"><?= (int) $item['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="portal-sidebar-footer">
        <button type="button" class="portal-dark-toggle" id="darkModeToggle">
            <span class="portal-mode-left"><?= portal_sidebar_icon('moon') ?><span class="portal-mode-text">Dark Mode</span></span>
            <span class="portal-toggle-switch" aria-hidden="true"></span>
        </button>
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/login.php?action=logout" class="portal-nav-link portal-logout">
            <?= portal_sidebar_icon('logout') ?>
            <span class="portal-nav-label">Logout</span>
        </a>
    </div>
</aside>
