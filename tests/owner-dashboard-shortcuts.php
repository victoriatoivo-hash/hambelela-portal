<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_URL', '/portal');
$source = file_get_contents(dirname(__DIR__).'/shared/ess-dashboard.php');
$start = strpos($source, '// Owner dashboard shortcuts:');
$end = strpos($source, '// End owner dashboard shortcuts.');
if ($start === false || $end === false) throw new RuntimeException('Shortcut block missing');
$block = substr($source, $start, $end-$start);
$original = [
    ['name'=>'Cost Workbook','href'=>'/portal/apps/cost-manager/workbook.php'],
    ['name'=>'Acknowledgments','href'=>'/portal/apps/acknowledgments/index.php'],
    ['name'=>'Existing card','href'=>'/portal/existing.php'],
];
foreach (['owner_admin','packer','marketing_sales_assistant','delivery_driver','guest'] as $roleKey) {
    $apps = $original;
    eval($block);
    if ($roleKey !== 'owner_admin') {
        if ($apps !== $original) throw new RuntimeException('Staff dashboard changed');
        continue;
    }
    $expected = ['Cost Workbook','Acknowledgments','Bookkeeping','Orders Board','Task Management','Product Pricing','Existing card'];
    if (array_column($apps,'name') !== $expected) throw new RuntimeException('Incorrect ordering');
    if (array_map(static fn($app) => $app['accent'] ?? $app['name'], array_slice($apps,2,4)) !== ['Accounts','Operations','Operations','Product Pricing']) throw new RuntimeException('Incorrect section colours');
    foreach (array_slice($apps,2,4) as $app) {
        if (!is_file(dirname(__DIR__).substr($app['href'],strlen(BASE_URL)))) throw new RuntimeException('Missing route');
        if (empty($app['icon']) || empty($app['desc'])) throw new RuntimeException('Missing card content');
    }
    $once = $apps;
    eval($block);
    if ($apps !== $once) throw new RuntimeException('Duplicate shortcuts');
}
echo "PASS: Owner-only shortcuts, order after Acknowledgments, existing cards retained, routes exist, no duplicates.\n";
