<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/shared/epi/bootstrap.php';

use Hambelela\EPI\DeadlineEngine;

$enabled = db()->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_watchdog_enabled' LIMIT 1");
$enabled->execute();
if (!in_array(strtolower(trim((string)$enabled->fetchColumn())), ['1','true','yes','on','enabled'], true)) {
    fwrite(STDERR, "EPI V2 watchdog is disabled.\n");
    exit(2);
}

$result = (new DeadlineEngine(db()))->processDue(null, 1000);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
