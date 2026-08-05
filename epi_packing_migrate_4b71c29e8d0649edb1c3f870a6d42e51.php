<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('c7f415a198d64ed4862b43a731095fbe', (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit("Forbidden\n");
}
require_once __DIR__.'/config.php';
require_once BASE_PATH.'/shared/database.php';
try {
    $sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(__DIR__.'/operations-epi-packing-performance-migration.sql')) ?? '';
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [] as $statement){$statement=trim($statement);if($statement!=='')db()->exec($statement);}
    $settings=db()->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key LIKE 'packing_%' ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
    $grace=db()->query("SELECT grace_key,module,minutes,uses_business_time FROM epi_employee_grace_periods WHERE grace_key LIKE 'packing_%' ORDER BY grace_key")->fetchAll(PDO::FETCH_ASSOC);
    echo "EPI Packing migration verified\n".json_encode(['settings'=>$settings,'grace_periods'=>$grace],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} catch(Throwable $error){http_response_code(500);echo 'ERROR: '.$error->getMessage()."\n";}
