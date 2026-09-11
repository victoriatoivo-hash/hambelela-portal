<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '/portal');
function renderDashboard(string $role, ?array $metrics): string {
    $roleKey = $role;
    $headerUserName = 'Alex <Test>';
    $headerNotificationLatest = [['title'=>'Review <request>','message'=>'A & B','action_link'=>'/portal/notifications.php?id=42']];
    $dashboardPackingUnread = 123;
    $dashboardMarketing = $metrics;
    $apps = [['name'=>'Packing List','desc'=>'Pack <carefully>','icon'=>'package-open','href'=>'/portal/apps/operations/consignments.php'],['name'=>'System Issues Log','desc'=>'Issues','icon'=>'bug','href'=>'/portal/apps/operations/system-issues.php','badge'=>4,'needs_information'=>2]];
    ob_start(); include BASE_PATH . '/shared/ess-dashboard.php'; return ob_get_clean();
}
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
verify(renderDashboard('guest', null) === '', 'Guests must not render the dashboard');
foreach (['packer','front_desk_admin','accountant'] as $role) {
    $staff = renderDashboard($role, null);
    verify(str_contains($staff, 'ess-sidebar'), 'Staff must receive the shared sidebar');
    verify(str_contains($staff, 'Pack &lt;carefully&gt;'), 'Staff must retain permitted app cards');
    verify(!str_contains($staff, 'ess-marketing-title'), 'Owner reports must remain private');
    verify(!str_contains($staff, '/apps/cost-manager/'), 'Staff must not receive owner destinations');
}
$markup = renderDashboard('owner_admin', ['published'=>17,'awaiting'=>3,'campaigns'=>2,'spend'=>1234.5,'sales'=>9876.54]);
foreach (['17','N$ 1,234.50','N$ 9,876.54','Review &lt;request&gt;','Pack &lt;carefully&gt;','data-packing-unread-badge','123 unread Packing List items','4 open system issues, information requested','/portal/notifications.php?id=42'] as $needle) verify(str_contains($markup, $needle), 'Must preserve dynamic output: ' . $needle);
verify(str_contains(renderDashboard('owner_admin', null), 'Marketing data is currently unavailable'), 'Unavailable data must not become invented zeros');
$unconnected = renderDashboard('owner_admin', ['published'=>0,'awaiting'=>0,'campaigns'=>0,'spend'=>0,'sales'=>null]);
verify(str_contains($unconnected, 'Not connected'), 'Preserve nullable sales connection state');
echo "Dashboard rendering: role boundary, escaped dynamic data, badges, links and unavailable states passed.\n";
