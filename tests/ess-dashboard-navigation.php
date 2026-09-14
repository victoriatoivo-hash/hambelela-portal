<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_URL', '/portal');
require_once __DIR__.'/../shared/ess-navigation.php';
$count = 0;
foreach (['Cost Workbook','Operations','Accounts','Employee Performance','Marketing'] as $name) {
    $links = ess_navigation_children($name);
    if (!$links) throw new RuntimeException('Missing group: '.$name);
    foreach ($links as $link) {
        $path = substr(parse_url($link['href'], PHP_URL_PATH), strlen(BASE_URL));
        if (!is_file(dirname(__DIR__).$path)) throw new RuntimeException('Missing destination: '.$path);
        $count++;
    }
    ob_start(); ess_render_navigation([['name'=>$name,'href'=>'/portal/index.php','icon'=>'folder']]); $html = ob_get_clean();
    if (!str_contains($html, 'data-ess-subnav-toggle aria-expanded="false" aria-controls=') || !str_contains($html, ' overview</a>') || !str_contains($html, 'class="ess-nav-item" href="/portal/index.php"')) throw new RuntimeException('Missing separate disclosure button or module link');
}
echo "Navigation: five disclosure groups and $count existing destinations verified.\n";
