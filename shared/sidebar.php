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

if (!function_exists('notifications_summary_for_current_user')) {
    $notificationsPath = __DIR__ . '/notifications.php';
    if (is_file($notificationsPath)) {
        require_once $notificationsPath;
    }
}

$notificationUnread = max(0, (int) ($notificationUnread ?? 0));
if (function_exists('notifications_summary_for_current_user')) {
    try {
        $notificationSummary = notifications_summary_for_current_user(1);
        $notificationUnread = (int) ($notificationSummary['unread_count'] ?? $notificationUnread);
    } catch (Throwable $sidebarNotificationError) {
        error_log('Notification sidebar summary failed: ' . $sidebarNotificationError->getMessage());
    }
}
$notificationUnreadLabel = $notificationUnread > 99 ? '99+' : (string) $notificationUnread;
$sidebarUser = isset($user) && is_array($user)
    ? $user
    : (function_exists('current_user') ? current_user() : []);
$sidebarUserName = trim((string) ($sidebarUser['name'] ?? ($_SESSION['user']['name'] ?? ($_SESSION['user_name'] ?? 'User'))));
$sidebarUserRole = trim((string) ($sidebarUser['role'] ?? ($_SESSION['user']['role'] ?? ($_SESSION['user_role'] ?? ''))));
$sidebarUserInitial = strtoupper(substr($sidebarUserName !== '' ? $sidebarUserName : 'U', 0, 1));
$taskOutstandingCount = 0;
$systemIssueOpenCount = 0;
$systemIssueNeedsInformation = 0;
$packingAssignmentUnread = 0;
try {
    if (function_exists('notifications_packing_assignment_unread_count')) {
        $packingAssignmentUnread = notifications_packing_assignment_unread_count();
    }
} catch (Throwable $packingBadgeError) {
    error_log('Packing assignment sidebar badge failed: ' . $packingBadgeError->getMessage());
}
$sidebarNotificationCounts = function_exists('notifications_sidebar_counts_for_current_user') ? notifications_sidebar_counts_for_current_user() : [];
$packingSidebarRoleKey = function_exists('normalise_portal_role')
    ? normalise_portal_role((string) ($sidebarUser['role_key'] ?? $sidebarUserRole))
    : (string) ($sidebarUser['role_key'] ?? $sidebarUserRole);
$packingSidebarHref = BASE_URL . '/apps/operations/consignments.php?unread=1';
if (!in_array($packingSidebarRoleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager'], true)) {
    $packingSidebarHref .= '&assigned=me';
}
try {
    if (function_exists('ops_database_ready') && ops_database_ready() && function_exists('ops_table_exists') && ops_table_exists('ops_checklist_tasks')) {
        $taskScope = function_exists('ops_task_scope_for_current_user') ? ops_task_scope_for_current_user() : ['type' => 'assigned', 'employee_id' => function_exists('ops_current_employee_id') ? ops_current_employee_id() : null];
        $taskReleaseFilter = ops_column_exists('ops_checklist_tasks', 'scheduled_at') && ops_column_exists('ops_checklist_tasks', 'released_at') ? ' AND (scheduled_at IS NULL OR released_at IS NOT NULL)' : '';
        if (($taskScope['type'] ?? '') === 'all') {
            $taskOutstandingCount = (int) (db()->query("SELECT COUNT(*) FROM ops_checklist_tasks WHERE status <> 'complete'{$taskReleaseFilter} AND archived_at IS NULL AND deleted_at IS NULL")->fetchColumn() ?: 0);
        } elseif (!empty($taskScope['employee_id'])) {
            $taskCountStmt = db()->prepare("SELECT COUNT(*) FROM ops_checklist_tasks WHERE assigned_employee_id = ? AND employee_visible = 1{$taskReleaseFilter} AND status <> 'complete' AND archived_at IS NULL AND deleted_at IS NULL");
            $taskCountStmt->execute([(int) $taskScope['employee_id']]);
            $taskOutstandingCount = (int) ($taskCountStmt->fetchColumn() ?: 0);
        }
    }
} catch (Throwable $taskBadgeError) {
    $taskOutstandingCount = 0;
}
try {
    if (!function_exists('system_issue_attention_summary')) require_once __DIR__ . '/system-issues.php';
    $systemIssueSummary = system_issue_attention_summary();
    $systemIssueOpenCount = (int) ($systemIssueSummary['count'] ?? 0);
    $systemIssueNeedsInformation = (int) ($systemIssueSummary['needs_information'] ?? 0);
} catch (Throwable $systemIssueBadgeError) { $systemIssueOpenCount = 0; $systemIssueNeedsInformation = 0; }

$epiNavigationEnabled = false;
try {
    $epiNavigationEnabled = class_exists('Hambelela\\EPI\\Performance')
        && \Hambelela\EPI\Performance::enabled();
} catch (Throwable $epiNavigationError) {
    $epiNavigationEnabled = false;
}

$accountsNavItem = in_array($packingSidebarRoleKey, ['front_desk_admin', 'front_desk_admin_employee'], true)
    ? ['id' => 'input-vat', 'label' => 'Input VAT', 'icon' => 'accounts', 'href' => BASE_URL . '/apps/accounts/input-vat.php', 'match' => ['/apps/accounts/input-vat.php']]
    : ['id' => 'accounts', 'label' => 'Accounts', 'icon' => 'accounts', 'href' => BASE_URL . '/apps/accounts/index.php', 'match' => ['/apps/accounts/index.php', '/apps/accounts/input-vat.php', '/apps/accounts/output-vat.php', '/apps/accounts/import-vat.php', '/apps/accounts/vat-reconciliation.php', '/apps/accounts/sage-reconciliation.php']];

$portalNavItems = [
    ['id' => 'portal-dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/index.php', 'match' => ['/index.php']],
    ['id' => 'operations-orders', 'label' => 'Orders', 'icon' => 'orders', 'href' => BASE_URL . '/apps/operations/orders-board.php', 'match' => ['/apps/operations/orders-board.php']],
    ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'bookkeeping', 'href' => BASE_URL . '/apps/operations/bookkeeping.php', 'match' => ['/apps/operations/bookkeeping.php']],
    $accountsNavItem,
    ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'packing', 'href' => $packingSidebarHref, 'match' => ['/apps/operations/consignments.php'], 'badge' => $packingAssignmentUnread, 'badge_label' => $packingAssignmentUnread > 99 ? '99+' : (string) $packingAssignmentUnread, 'badge_kind' => 'packing'],
    ['id' => 'operations-courier', 'label' => 'Courier Waybills', 'icon' => 'courier', 'href' => BASE_URL . '/apps/operations/courier.php', 'match' => ['/apps/operations/courier.php']],
    ['id' => 'hr-portal', 'label' => 'HR Portal', 'icon' => 'hr', 'href' => BASE_URL . '/apps/hr-portal/portal-login.php', 'match' => ['/apps/hr-portal/portal-login.php', '/apps/hr-portal/index.php']],
    ['id' => 'operations-inventory', 'label' => 'Inventory', 'icon' => 'inventory', 'href' => BASE_URL . '/apps/operations/orders.php?tab=inventory', 'match' => ['/apps/operations/orders.php']],
    ['id' => 'operations-pos-reports', 'label' => 'POS Reports', 'icon' => 'reports', 'href' => BASE_URL . '/apps/operations/orders.php', 'match' => ['/apps/operations/orders.php']],
    ['id' => 'kpi', 'label' => 'Performance', 'icon' => 'kpi', 'href' => BASE_URL . ($epiNavigationEnabled ? '/apps/operations/epi-dashboard.php' : '/apps/operations/reports.php'), 'match' => $epiNavigationEnabled ? ['/apps/operations/epi-dashboard.php'] : ['/apps/operations/reports.php']],
    ['id' => 'operations-checklists', 'label' => 'Task Management', 'icon' => 'tasks', 'href' => BASE_URL . '/apps/operations/checklists.php', 'match' => ['/apps/operations/checklists.php'], 'badge' => $taskOutstandingCount, 'badge_label' => $taskOutstandingCount > 99 ? '99+' : (string) $taskOutstandingCount],
    ['id' => 'operations-errors', 'label' => 'Error Log', 'icon' => 'errors', 'href' => BASE_URL . '/apps/operations/errors.php', 'match' => ['/apps/operations/errors.php']],
    ['id' => 'system-issues', 'label' => 'System Issues Log', 'icon' => 'system-issues', 'href' => BASE_URL . '/apps/operations/system-issues.php', 'match' => ['/apps/operations/system-issues.php'], 'badge' => $systemIssueOpenCount, 'badge_label' => $systemIssueOpenCount > 99 ? '99+' : (string) $systemIssueOpenCount, 'badge_kind' => 'system-issues', 'needs_information' => $systemIssueNeedsInformation],
    ['id' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'href' => BASE_URL . '/apps/operations/my-account.php', 'match' => ['/apps/operations/my-account.php']],
];
if ($packingSidebarRoleKey === 'accountant') {
    $portalNavItems = [
        ['id'=>'accounts','label'=>'Finance Dashboard','icon'=>'accounts','href'=>BASE_URL.'/apps/accounts/index.php','match'=>['/apps/accounts/index.php']],
        ['id'=>'input-vat','label'=>'Input VAT','icon'=>'accounts','href'=>BASE_URL.'/apps/accounts/input-vat.php','match'=>['/apps/accounts/input-vat.php']],
        ['id'=>'output-vat','label'=>'Output VAT','icon'=>'accounts','href'=>BASE_URL.'/apps/accounts/output-vat.php','match'=>['/apps/accounts/output-vat.php']],
        ['id'=>'import-vat','label'=>'Import VAT','icon'=>'accounts','href'=>BASE_URL.'/apps/accounts/import-vat.php','match'=>['/apps/accounts/import-vat.php']],
        ['id'=>'vat-reconciliation','label'=>'VAT Reconciliation','icon'=>'accounts','href'=>BASE_URL.'/apps/accounts/vat-reconciliation.php','match'=>['/apps/accounts/vat-reconciliation.php']],
    ];
}

$featureByNavId = [
    'portal-dashboard' => 'dashboard',
    'operations-orders' => 'orders',
    'operations-bookkeeping' => 'bookkeeping',
    'accounts' => 'accounts',
    'input-vat' => 'input_vat',
    'output-vat' => 'output_vat',
    'import-vat' => 'import_vat',
    'vat-reconciliation' => 'vat_reconciliation',
    'operations-consignments' => 'packing_list',
    'operations-courier' => 'courier',
    'hr-portal' => 'hr',
    'operations-inventory' => 'inventory',
    'operations-pos-reports' => 'pos_reports',
    'kpi' => 'kpi_dashboard',
    'operations-checklists' => 'task_management',
    'operations-errors' => 'error_log',
    'system-issues' => 'system_issues',
    'settings' => 'settings',
];
$sidebarRoleKey = normalise_portal_role((string) ($sidebarUser['role_key'] ?? $sidebarUserRole));
$isEmployeeSidebar = !in_array($sidebarRoleKey, ['owner_admin', 'guest'], true);
$employeeBadgeByNavId = [
    'operations-orders' => 'orders', 'operations-bookkeeping' => 'bookkeeping',
    'operations-consignments' => 'packing_list', 'operations-courier' => 'courier_waybills',
    'hr-portal' => 'hr_portal', 'operations-inventory' => 'inventory',
    'operations-checklists' => 'task_management', 'operations-errors' => 'error_log',
    'system-issues' => 'system_issues',
];
if ($isEmployeeSidebar) {
    foreach ($portalNavItems as &$portalNavItem) {
        $moduleKey = $employeeBadgeByNavId[$portalNavItem['id']] ?? null;
        if ($moduleKey === null) continue;
        $count = max(0, (int) ($sidebarNotificationCounts[$moduleKey] ?? 0));
        $portalNavItem['badge'] = $count;
        $portalNavItem['badge_label'] = $count > 99 ? '99+' : (string) $count;
        $portalNavItem['badge_kind'] = 'notification';
        $portalNavItem['badge_module'] = $moduleKey;
    }
    unset($portalNavItem);
}
$employeePortalNavItems = [];
foreach ($portalNavItems as $portalNavItem) {
    $featureKey = $featureByNavId[$portalNavItem['id']] ?? '';
    if (portal_role_can_access_feature($sidebarRoleKey, $featureKey)) {
        $employeePortalNavItems[] = $portalNavItem;
    }
}
$portalNavItems = $employeePortalNavItems;
if ($isEmployeeSidebar) {
    $employeeNavOrder = [
        'portal-dashboard' => 0,
        'operations-consignments' => 10,
        'operations-courier' => 20,
        'hr-portal' => 30,
        'operations-orders' => 40,
        'operations-checklists' => 50,
        'operations-bookkeeping' => 60,
        'accounts' => 65,
        'input-vat' => 65,
        'notifications' => 80,
        'operations-errors' => 90,
        'system-issues' => 95,
    ];
    usort($portalNavItems, static fn(array $left, array $right): int =>
        ($employeeNavOrder[$left['id']] ?? 999) <=> ($employeeNavOrder[$right['id']] ?? 999)
    );
}

function getSidebarIcon(string $id): string
{
    $icons = [
        'portal-dashboard' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'operations-dashboard' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'operations-orders' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>',
        'operations-bookkeeping' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'accounts' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 15h2"/></svg>',
        'operations-consignments' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'operations-courier' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M14 9h4l4 4v4h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="17.5" r="2.5"/></svg>',
        'operations-inventory' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg>',
        'operations-pos-reports' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'kpi' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'operations-checklists' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'operations-errors' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'system-issues' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.5 2h5L16 2"/><rect x="5" y="4" width="14" height="16" rx="3"/><path d="M9 9h6M9 13h6M9 17h3"/></svg>',
        'hr-portal' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>',
        'notifications' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'settings' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    ];

    return $icons[$id] ?? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
}

$isActiveItem = static function (array $item) use ($currentPath, $activeApp): bool {
    foreach (($item['match'] ?? []) as $matchPath) {
        if ($currentPath === $matchPath) {
            if (($item['id'] ?? '') === 'operations-bookkeeping') {
                return true;
            }
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
<style id="portal-sidebar-v2-styles">
.portal-sidebar{width:260px;min-height:100vh;background:#fff;border-right:1px solid #EDE3D8;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:50;transition:width .25s cubic-bezier(.4,0,.2,1);overflow:hidden;font-family:Figtree,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;font-weight:400}.portal-sidebar.collapsed{width:78px}.portal-sidebar.collapsed .ps-nav-label,.portal-sidebar.collapsed .ps-logo-text,.portal-sidebar.collapsed .ps-user-info,.portal-sidebar.collapsed .ps-nav-badge,.portal-sidebar.collapsed .ps-toggle-switch{opacity:0;pointer-events:none;width:0;overflow:hidden}.portal-sidebar.collapsed .ps-collapse-btn svg{transform:rotate(180deg)}.portal-sidebar.collapsed .ps-logo-mark{margin:0 auto}.ps-header{display:flex;align-items:center;justify-content:space-between;padding:16px 14px 12px;border-bottom:1px solid #EDE3D8;flex-shrink:0}.ps-logo{display:flex;align-items:center;gap:10px;overflow:hidden;min-width:0}.ps-logo-mark{width:32px;height:32px;border-radius:8px;background:#AB3619;color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:Figtree,system-ui,sans-serif}.ps-logo-text{display:flex;flex-direction:column;min-width:0;transition:opacity .2s,width .25s}.ps-logo-name{font-size:13px;font-weight:700;color:#721B1A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ps-logo-sub{font-size:10px;color:#A08070;white-space:nowrap}.ps-collapse-btn{width:24px;height:24px;background:none;border:1px solid #EDE3D8;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#A08070;flex-shrink:0;transition:background .15s,color .15s}.ps-collapse-btn:hover{background:#FDF6EE;color:#AB3619}.ps-collapse-btn svg{transition:transform .25s ease}.ps-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 0;scrollbar-width:thin;scrollbar-color:#EDE3D8 transparent}.ps-nav::-webkit-scrollbar{width:3px}.ps-nav::-webkit-scrollbar-thumb{background:#EDE3D8;border-radius:2px}.ps-nav-item{display:flex;align-items:center;gap:10px;padding:8px 14px;color:#6B4C3B;text-decoration:none;border-radius:0;cursor:pointer;transition:background .15s,color .15s;white-space:nowrap;position:relative;border-left:3px solid transparent}.ps-nav-item:hover{background:#FDF6EE;color:#AB3619}.ps-nav-item--active{background:#FDF6EE;color:#AB3619;border-left-color:#AB3619;font-weight:600}.ps-nav-item--active .ps-nav-icon svg{stroke:#AB3619}.ps-nav-icon{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0}.ps-nav-icon svg{stroke:currentColor;transition:stroke .15s}.ps-nav-label{font-size:13px;transition:opacity .2s,width .25s;overflow:hidden;white-space:nowrap}.ps-nav-badge{margin-left:auto;background:#AB3619;color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0;transition:opacity .2s}.ps-bottom{border-top:1px solid #EDE3D8;padding:8px 0;flex-shrink:0}.ps-nav-item--logout{color:#BB1B21}.ps-nav-item--logout:hover{background:#fdeaea;color:#721B1A}.ps-user{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #EDE3D8;margin-bottom:4px;overflow:hidden}.ps-user-avatar{width:28px;height:28px;border-radius:50%;background:#AB3619;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:Figtree,system-ui,sans-serif}.ps-user-info{display:flex;flex-direction:column;min-width:0;transition:opacity .2s,width .25s;overflow:hidden}.ps-user-name{font-size:12px;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ps-user-role{font-size:10px;color:#A08070;white-space:nowrap}.ps-dark-toggle{cursor:pointer;user-select:none}.ps-toggle-switch{margin-left:auto;width:32px;height:18px;background:#EDE3D8;border-radius:9px;position:relative;transition:background .2s,opacity .2s;flex-shrink:0}.ps-toggle-switch::after{content:"";position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;top:2px;left:2px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}.dark-mode .ps-toggle-switch{background:#AB3619}.dark-mode .ps-toggle-switch::after{transform:translateX(14px)}.portal-sidebar.collapsed .ps-nav-item:hover::after{content:attr(title);position:absolute;left:82px;background:#2C1810;color:#fff;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;z-index:100;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.15)}.shell:has(.portal-sidebar){padding-left:260px!important;transition:padding-left .25s cubic-bezier(.4,0,.2,1)}.shell:has(.portal-sidebar.collapsed),body.sidebar-collapsed .shell:has(.portal-sidebar){padding-left:78px!important}.shell:has(.portal-sidebar) .workspace,.portal-main,.workspace,main.workspace{margin-left:0!important;transition:margin-left .25s cubic-bezier(.4,0,.2,1)}body.dark-mode{background:#1a1008;color:#f0e8e0}body.dark-mode .portal-sidebar{background:#1e1108;border-right-color:#3a2010}body.dark-mode .ps-header,body.dark-mode .ps-bottom,body.dark-mode .ps-user{border-color:#3a2010}body.dark-mode .ps-logo-name{color:#f0a070}body.dark-mode .ps-logo-sub{color:#8a6050}body.dark-mode .ps-nav-item{color:#c0a090}body.dark-mode .ps-nav-item:hover,body.dark-mode .ps-nav-item--active{background:#2a1508;color:#F07420;border-left-color:#F07420}body.dark-mode .ps-collapse-btn{border-color:#3a2010;color:#8a6050}body.dark-mode .ps-collapse-btn:hover{background:#2a1508;color:#F07420}body.dark-mode .ps-user-name{color:#f0e8e0}body.dark-mode .ps-nav-item--logout{color:#f09595}body.dark-mode .ps-nav-item--logout:hover{background:#2a0808}@media (max-width:768px){.portal-sidebar{transform:translateX(-100%);transition:transform .25s ease,width .25s ease}.portal-sidebar.mobile-open{transform:translateX(0)}.shell:has(.portal-sidebar),.shell:has(.portal-sidebar.collapsed){padding-left:0!important}.portal-main,main.workspace{margin-left:0!important}}
</style>
<style id="portal-sidebar-task-badge">
.portal-sidebar.collapsed .ps-nav-item[data-nav-id="operations-checklists"] { overflow: visible; }
.portal-sidebar.collapsed .ps-nav-item[data-nav-id="operations-checklists"] .ps-nav-badge { position: absolute; top: 3px; right: 7px; width: auto; min-width: 15px; height: 15px; padding: 0 4px; opacity: 1; font-size: 9px; font-weight: 400; line-height: 15px; pointer-events: none; }
.portal-sidebar.collapsed .ps-nav-item[data-sidebar-module] { overflow: visible; }
.portal-sidebar.collapsed .ps-nav-item[data-sidebar-module] .ps-nav-badge { position:absolute;top:3px;right:7px;width:auto;min-width:15px;height:15px;padding:0 4px;opacity:1;font-size:9px;font-weight:400;line-height:15px;pointer-events:none; }
.ps-nav-badge.is-information-requested{background:#BB1B21;box-shadow:0 0 0 2px rgba(187,27,33,.16)}
.ps-nav-badge[hidden],.ps-nav-badge.is-hidden,.ps-nav-badge:empty{display:none!important}
</style>
<style id="portal-sidebar-notification-badge">
.ps-notification-badge{margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#AB3619;color:#fff;font-size:11px;font-weight:400;line-height:20px;text-align:center;box-shadow:0 6px 14px rgba(171,54,25,.18);box-sizing:border-box;flex-shrink:0}.ps-notification-badge.is-hidden{display:none}.portal-sidebar.collapsed .ps-nav-item--notify{position:relative;width:44px;height:44px;padding:0;margin:0 auto;justify-content:center;overflow:visible}.portal-sidebar.collapsed .ps-nav-item--notify .ps-notification-badge{display:inline-flex;align-items:center;justify-content:center;position:absolute;top:4px;right:6px;min-width:15px;width:auto;height:15px;padding:0 4px;border-radius:999px;font-size:9px;line-height:15px;z-index:5;opacity:1;transform:translate(35%,-20%);pointer-events:none}.portal-sidebar.collapsed .ps-nav-item--notify .ps-notification-badge.is-hidden,.portal-sidebar.collapsed .ps-nav-item--notify .ps-notification-badge:empty{display:none}
</style>
<style id="portal-shared-full-width-layout">
.portal-mobile-header{display:none}
.shell:has(.portal-sidebar){width:100%;max-width:none;box-sizing:border-box}
.shell:has(.portal-sidebar)>main.workspace:not(.digital-task-page),
.shell:has(.portal-sidebar)>main.ledger-page,
.shell:has(.portal-sidebar)>.workspace:not(.digital-task-page),
.shell:has(.portal-sidebar)>.ledger-page{flex:1 1 auto;width:100%;max-width:none!important;min-width:0;margin-left:0!important;padding:28px;box-sizing:border-box}
.shell:has(.portal-sidebar)>main.workspace.module{align-content:start}
.shell:has(.portal-sidebar)>main.workspace.module>.module-header{width:100%;max-width:none}
@media (max-width:1023px){.portal-mobile-header{display:grid}}
@media (max-width:760px){.shell:has(.portal-sidebar)>main.workspace:not(.digital-task-page),.shell:has(.portal-sidebar)>main.ledger-page,.shell:has(.portal-sidebar)>.workspace:not(.digital-task-page),.shell:has(.portal-sidebar)>.ledger-page{padding:18px}}
</style>
<?php $portalMobileTitle = 'Hambelela'; foreach ($portalNavItems as $portalMobileItem) { if ($isActiveItem($portalMobileItem)) { $portalMobileTitle = (string) $portalMobileItem['label']; break; } } ?>
<header class="portal-mobile-header" data-portal-mobile-header>
<button class="portal-mobile-nav-toggle portal-mobile-menu" type="button" data-sidebar-open aria-controls="portalSidebar" aria-expanded="false" aria-label="Open navigation menu">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"></line>
        <line x1="4" y1="12" x2="20" y2="12"></line>
        <line x1="4" y1="17" x2="20" y2="17"></line>
    </svg>
</button>
<strong class="portal-mobile-page-title"><?= htmlspecialchars($portalMobileTitle, ENT_QUOTES, 'UTF-8') ?></strong>
<span class="portal-mobile-header-actions portal-mobile-actions"><a class="portal-mobile-notifications" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.php" aria-label="Notifications, <?= $notificationUnread ?> unread"><?= getSidebarIcon('notifications') ?><span class="portal-mobile-notification-count<?= $notificationUnread ? '' : ' is-hidden' ?>" data-notification-count><?= $notificationUnread ? htmlspecialchars($notificationUnreadLabel, ENT_QUOTES, 'UTF-8') : '' ?></span></a><a class="portal-mobile-user" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/apps/operations/my-account.php" aria-label="Open profile for <?= htmlspecialchars($sidebarUserName, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($sidebarUserName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sidebarUserInitial, ENT_QUOTES, 'UTF-8') ?></a></span>
</header>
<button class="portal-sidebar-backdrop" type="button" data-sidebar-backdrop aria-label="Close navigation menu" tabindex="-1"></button>
<aside class="portal-sidebar" id="portalSidebar" data-portal-sidebar aria-label="Portal navigation" aria-hidden="false">
    <div class="ps-header portal-sidebar-mobile-heading">
        <div class="ps-logo">
            <div class="ps-logo-mark">H</div>
            <div class="ps-logo-text">
                <span class="ps-logo-name">Hambelela</span>
                <span class="ps-logo-sub">Business Portal</span>
            </div>
        </div>
        <button class="ps-collapse-btn" id="psCollapseBtn" onclick="toggleSidebar()" aria-label="Collapse sidebar" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
    </div>

    <nav class="ps-nav" aria-label="Main navigation">
        <div class="ps-nav-group">
            <?php foreach ($portalNavItems as $item): ?>
                <?php $isActive = $isActiveItem($item); ?>
                <a href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>" class="ps-nav-item<?= $isActive ? ' ps-nav-item--active' : '' ?>" data-nav-id="<?= htmlspecialchars((string) $item['id'], ENT_QUOTES, 'UTF-8') ?>"<?= !empty($item['badge_module']) ? ' data-sidebar-module="'.htmlspecialchars((string)$item['badge_module'], ENT_QUOTES, 'UTF-8').'"' : '' ?> title="<?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="ps-nav-icon"><?= getSidebarIcon((string) $item['id']) ?></span>
                    <span class="ps-nav-label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($item['badge']) || !empty($item['badge_module']) || ($item['badge_kind'] ?? '') === 'packing'): ?>
                        <span class="ps-nav-badge<?= empty($item['badge']) ? ' is-hidden' : '' ?><?= !empty($item['needs_information']) ? ' is-information-requested' : '' ?>"<?= !empty($item['badge_module']) ? ' data-sidebar-notification-badge="'.htmlspecialchars((string)$item['badge_module'], ENT_QUOTES, 'UTF-8').'" data-count="'.(int)$item['badge'].'"' : (($item['badge_kind'] ?? '') === 'packing' ? ' data-packing-unread-badge' : '') ?><?= empty($item['badge']) ? ' hidden' : '' ?> aria-label="<?= (int) $item['badge'] ?> <?= !empty($item['badge_module']) ? 'unread '.htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8').' '.((int)$item['badge']===1?'notification':'notifications') : (($item['badge_kind'] ?? '') === 'packing' ? 'unread Packing List items' : (($item['badge_kind'] ?? '') === 'system-issues' ? ('open system issues' . (!empty($item['needs_information']) ? ', information requested' : '')) : 'outstanding tasks')) ?>"><?= !empty($item['badge']) ? htmlspecialchars((string) ($item['badge_label'] ?? $item['badge']), ENT_QUOTES, 'UTF-8') : '' ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <div class="ps-bottom">
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.php" class="ps-nav-item ps-nav-item--notify" title="Notifications">
            <span class="ps-nav-icon"><?= getSidebarIcon('notifications') ?></span>
            <span class="ps-nav-label">Notifications</span>
            <span class="ps-notification-badge<?= $notificationUnread > 0 ? '' : ' is-hidden' ?>" data-notification-count><?= $notificationUnread > 0 ? htmlspecialchars($notificationUnreadLabel, ENT_QUOTES, 'UTF-8') : '' ?></span>
        </a>

        <?php if (!$isEmployeeSidebar): ?>
        <div class="ps-nav-item ps-dark-toggle" onclick="toggleDarkMode()" title="Dark mode">
            <span class="ps-nav-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
            <span class="ps-nav-label">Dark mode</span>
            <span class="ps-toggle-switch" id="darkToggleSwitch"></span>
        </div>
        <?php endif; ?>

        <div class="ps-user">
            <div class="ps-user-avatar"><?= htmlspecialchars($sidebarUserInitial, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="ps-user-info">
                <span class="ps-user-name"><?= htmlspecialchars($sidebarUserName !== '' ? $sidebarUserName : 'User', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="ps-user-role"><?= htmlspecialchars($sidebarUserRole, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/login.php?action=logout" class="ps-nav-item ps-nav-item--logout" title="Logout">
            <span class="ps-nav-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
            <span class="ps-nav-label">Logout</span>
        </a>
    </div>
</aside>
<script>
function toggleSidebar(){const sidebar=document.getElementById('portalSidebar');if(!sidebar)return;if(window.matchMedia('(max-width: 1023px)').matches){window.portalSidebarNavigation?.toggle();return}const collapsed=sidebar.classList.toggle('collapsed');localStorage.setItem('sidebarCollapsed',collapsed?'1':'0');document.body.classList.toggle('sidebar-collapsed',collapsed)}
(function initialisePortalMobileSidebar(){
    const sidebar=document.querySelector('[data-portal-sidebar]');
    const mobileToggle=document.querySelector('[data-sidebar-open]');
    const mobileBackdrop=document.querySelector('[data-sidebar-backdrop]');
    if(!sidebar||!mobileToggle||sidebar.dataset.mobileDrawerInitialised==='true')return;
    sidebar.dataset.mobileDrawerInitialised='true';
    const mobileQuery=window.matchMedia('(max-width: 1023px)');
    let lastFocused=null;
    const focusable=()=>Array.from(sidebar.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])'));
    const setOpen=(open)=>{
        if(!mobileQuery.matches)open=false;
        const wasOpen=sidebar.classList.contains('is-mobile-open');
        sidebar.classList.toggle('is-mobile-open',open);
        mobileBackdrop?.classList.toggle('is-visible',open);
        document.body.classList.toggle('portal-mobile-sidebar-open',open);
        sidebar.setAttribute('aria-hidden',mobileQuery.matches&&!open?'true':'false');
        mobileToggle?.setAttribute('aria-expanded',open?'true':'false');
        if(mobileBackdrop)mobileBackdrop.tabIndex=open?0:-1;
        if(open){lastFocused=document.activeElement;requestAnimationFrame(()=>focusable()[0]?.focus({preventScroll:true}))}
        else if(wasOpen&&lastFocused instanceof HTMLElement){lastFocused.focus({preventScroll:true});lastFocused=null}
    };
    window.portalSidebarNavigation={open:()=>setOpen(true),close:()=>setOpen(false),toggle:()=>setOpen(!sidebar.classList.contains('is-mobile-open'))};
    mobileToggle.addEventListener('click',()=>setOpen(!sidebar.classList.contains('is-mobile-open')));
    mobileBackdrop?.addEventListener('click',()=>setOpen(false));
    if(!mobileQuery.matches&&localStorage.getItem('sidebarCollapsed')==='1'){sidebar.classList.add('collapsed');document.body.classList.add('sidebar-collapsed')}
    sidebar.addEventListener('click',(event)=>{if(mobileQuery.matches&&event.target.closest('a[href]'))setOpen(false)});
    document.addEventListener('keydown',(event)=>{
        if(!sidebar.classList.contains('is-mobile-open'))return;
        if(event.key==='Escape'){event.preventDefault();setOpen(false);return}
        if(event.key!=='Tab')return;
        const items=focusable();if(!items.length)return;
        const first=items[0],last=items[items.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
    });
    const syncViewport=()=>{
        if(!mobileQuery.matches){setOpen(false);sidebar.setAttribute('aria-hidden','false')}
        else{sidebar.classList.remove('collapsed');document.body.classList.remove('sidebar-collapsed');sidebar.setAttribute('aria-hidden',sidebar.classList.contains('is-mobile-open')?'false':'true')}
    };
    mobileQuery.addEventListener?.('change',syncViewport);
    syncViewport();
})();
function toggleDarkMode(){const dark=document.body.classList.toggle('dark-mode');localStorage.setItem('darkMode',dark?'1':'0')}
(function(){if(localStorage.getItem('darkMode')==='1'){document.body.classList.add('dark-mode')}})();
document.addEventListener('click',(event)=>{if(!event.target.closest('[data-employee-nav-coming-soon]'))return;event.preventDefault();if(typeof employeeSoonMessage==='function'){employeeSoonMessage();return}window.alert('This section is coming soon.')});
</script>
