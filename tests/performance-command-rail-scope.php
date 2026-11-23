<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '');
function current_role_key(): string { return 'owner_admin'; }
foreach (['business-health','employees','marketing','drivers','work-quality','recognition','business-activity','settings'] as $requestedTab) {
    $tab = $requestedTab;
    $title = 'Caller title';
    $_GET['tab'] = $tab;
    $_SERVER['SCRIPT_NAME'] = '/apps/operations/reports.php';
    ob_start();
    require BASE_PATH.'/shared/performance-command-rail.php';
    $html = ob_get_clean();
    if ($tab !== $requestedTab || $title !== 'Caller title') throw new RuntimeException('Navigation changed caller route/title');
    if (substr_count($html, 'aria-current="page"') !== 1) throw new RuntimeException('Expected one current route');
}
echo "PASS: navigation preserves every report route and caller title\n";
