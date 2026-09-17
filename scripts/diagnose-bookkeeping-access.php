<?php
// Read-only simulation of the LIVE permission code downloaded by diagnose-bookkeeping-access.py.
// Runs only in CI against temporary copies; touches no server, database or session.
declare(strict_types=1);

$live = rtrim((string) getenv('LIVE_DIR'), '/');
$mode = $argv[1] ?? 'main';
$role = $argv[2] ?? '';

define('BASE_URL', '');
define('BASE_PATH', $live);
$GLOBALS['diagRole'] = $role;
function current_role_key(): string { return $GLOBALS['diagRole']; }
function current_user(): array { return ['id' => 0, 'role_key' => $GLOBALS['diagRole'], 'name' => 'diagnostic']; }
require $live . '/shared/employee-features.php';

if ($mode === 'route') {
    // Mirrors require_login()'s final step for one role and one script path.
    $_SERVER['SCRIPT_NAME'] = $argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    register_shutdown_function(static function (): void {
        $out = (string) ob_get_clean();
        $code = http_response_code();
        // headers_list() is empty under CLI, so a silent exit is reported as a redirect/stop.
        if (str_contains($out, 'Coming soon')) echo 'BLOCKED(coming-soon page)';
        elseif ($out !== '') echo 'BLOCKED(' . var_export($code, true) . ': ' . substr(strip_tags($out), 0, 80) . ')';
        elseif (empty($GLOBALS['diagReturned'])) echo 'STOPPED(redirect or exit before page)';
        else echo 'ALLOWED';
    });
    enforce_employee_feature_for_current_request();
    $GLOBALS['diagReturned'] = true;
    exit;
}

$roles = ['front_desk_admin', 'front_desk_admin_employee', 'marketing_sales', 'packer', 'supervisor_manager', 'accountant', 'owner_admin'];
$features = ['dashboard', 'orders', 'bookkeeping', 'cash_tools', 'packing_list', 'courier', 'hr', 'task_management',
    'notifications', 'system_issues', 'marketing', 'error_log', 'input_vat', 'accounts', 'inventory', 'pos_reports',
    'kpi_dashboard', 'settings', 'operations', 'barcode'];

echo "\n--- 4. LIVE portal_feature_permissions() for marketing_sales ---\n";
echo implode(', ', portal_feature_permissions()['marketing_sales'] ?? ['(role missing -> generic employee modules)']), "\n";
echo "employee-specific overrides: ", json_encode(portal_employee_feature_overrides()), "\n";

echo "\n--- LIVE feature matrix (portal_role_can_access_feature) ---\n";
printf("%-16s", 'feature');
foreach ($roles as $r) printf("%-12s", substr($r, 0, 11));
echo "\n";
foreach ($features as $f) {
    printf("%-16s", $f);
    foreach ($roles as $r) printf("%-12s", portal_role_can_access_feature($r, $f) ? 'YES' : '-');
    echo "\n";
}

echo "\n--- 5. LIVE sidebar/mobile nav: ess_shell_apps() per role ---\n";
$navSource = (string) @file_get_contents($live . '/shared/ess-navigation.php');
foreach (preg_split('/\R/', $navSource) as $i => $line) {
    if (str_contains($line, "'marketing_sales'")) echo 'ess-navigation.php:' . ($i + 1) . ': ' . trim($line) . "\n";
}
$php = PHP_BINARY;
$navProbe = <<<'PHP'
$live = getenv('LIVE_DIR'); define('BASE_URL', ''); define('BASE_PATH', $live);
function current_role_key(): string { return getenv('DIAG_ROLE'); }
function current_user(): array { return ['id' => 0, 'role_key' => getenv('DIAG_ROLE')]; }
require $live . '/shared/employee-features.php';
require $live . '/shared/ess-navigation.php';
echo implode(', ', array_column(ess_shell_apps(), 'name'));
PHP;
foreach (['front_desk_admin', 'marketing_sales', 'packer'] as $r) {
    $out = shell_exec('DIAG_ROLE=' . escapeshellarg($r) . ' ' . escapeshellarg($php) . ' -r ' . escapeshellarg($navProbe) . ' 2>&1');
    printf("%-18s %s\n", $r, trim((string) $out));
}
$legacySidebar = (string) @file_get_contents($live . '/shared/sidebar.php');
echo "legacy shared/sidebar.php mentions marketing_sales: ", str_contains($legacySidebar, 'marketing_sales') ? 'YES' : 'NO', "\n";

echo "\n--- 6. LIVE dashboard (index.php) role-specific tile filters ---\n";
$index = (string) @file_get_contents($live . '/index.php');
foreach (preg_split('/\R/', $index) as $i => $line) {
    if (preg_match('/marketing_sales|front_desk_admin/', $line)) echo 'index.php:' . ($i + 1) . ': ' . substr(trim($line), 0, 260) . "\n";
}
foreach (['front_desk_admin', 'marketing_sales'] as $r) {
    $GLOBALS['diagRole'] = $r;
    $names = ['Marketing', 'Packing List', 'Courier Waybills', 'HR Portal', 'Orders', 'Tasks', 'Bookkeeping', 'Notifications', 'System Issues Log', 'Error Log'];
    $map = ['Packing List' => 'packing_list', 'Courier Waybills' => 'courier', 'HR Portal' => 'hr', 'Orders' => 'orders', 'Tasks' => 'task_management',
        'Bookkeeping' => 'bookkeeping', 'Notifications' => 'notifications', 'Error Log' => 'error_log', 'Marketing' => 'marketing', 'System Issues Log' => 'system_issues'];
    $apps = array_filter($names, static fn($n) => portal_user_can_access_feature($map[$n]));
    if (preg_match('/if \(\$roleKey === \'marketing_sales\'\) \$apps = .*?in_array\(\$app\[\'name\'\], (\[[^\]]*\])/', $index, $m) && $r === 'marketing_sales') {
        $allow = eval('return ' . $m[1] . ';');
        $apps = array_filter($apps, static fn($n) => in_array($n, $allow, true));
    }
    printf("%-18s tiles after permission+role filters: %s\n", $r, implode(', ', $apps));
}

echo "\n--- 7. LIVE route enforcement (enforce_employee_feature_for_current_request) ---\n";
$routes = ['/apps/operations/bookkeeping.php', '/apps/operations/bank-statement-processor.php', '/apps/marketing/index.php',
    '/apps/operations/reports.php', '/apps/operations/errors.php', '/apps/operations/my-account.php'];
foreach ($routes as $route) {
    foreach (['front_desk_admin', 'marketing_sales', 'accountant'] as $r) {
        $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' route ' . escapeshellarg($r) . ' ' . escapeshellarg($route) . ' 2>&1');
        printf("%-48s %-18s %s\n", $route, $r, trim((string) $out));
    }
}

echo "\n--- 7c. LIVE bookkeeping.php internal authorization lines ---\n";
$bk = (string) @file_get_contents($live . '/apps/operations/bookkeeping.php');
foreach (preg_split('/\R/', $bk) as $i => $line) {
    if (preg_match('/canOperateBookkeeping =|canManageBookkeeping =|&& portal_role_can_access_feature|marketing_sales|front_desk/', $line)) echo 'bookkeeping.php:' . ($i + 1) . ': ' . trim($line) . "\n";
}

echo "\n--- auth.php post-login / device rules mentioning roles ---\n";
$auth = (string) @file_get_contents($live . '/shared/auth.php');
foreach (preg_split('/\R/', $auth) as $i => $line) {
    if (preg_match('/marketing_sales|portal_render_employee_desktop_required\(\);|portal_request_is_phone_or_tablet\(\)\)/', $line)) echo 'auth.php:' . ($i + 1) . ': ' . trim($line) . "\n";
}
