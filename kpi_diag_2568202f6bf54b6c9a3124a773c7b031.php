<?php

declare(strict_types=1);

require_once __DIR__ . '/apps/operations/operations.php';
require_role('owner_admin');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!hash_equals('c06763f422fd49048e2781003ac9d69e', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found.');
}

echo "KPI error log\n";
$logFile = BASE_PATH . '/logs/kpi_errors.log';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
    echo implode(PHP_EOL, array_slice($lines, -60)) . PHP_EOL;
} else {
    echo "Unreadable or missing.\n";
}

foreach (['ops_orders', 'ops_packing_tasks', 'kpi_sessions', 'ops_board_presence'] as $table) {
    echo PHP_EOL . $table . " columns\n";
    try {
        foreach (ops_rows('SHOW COLUMNS FROM `' . $table . '`') as $column) {
            echo (string) ($column['Field'] ?? '') . "\n";
        }
    } catch (Throwable $error) {
        echo 'ERROR: ' . $error->getMessage() . "\n";
    }
}
