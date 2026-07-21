<?php

declare(strict_types=1);

$expectedToken = '900a3e3402cc42f3ba56c06b3dc3d9d7';
if (!hash_equals($expectedToken, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

echo "KPI V2 PHASE 0 DIAGNOSTIC\n";
echo 'Generated: ' . date(DATE_ATOM) . "\n\n";

$logCandidates = [
    'portal root' => __DIR__ . '/error_log',
    'includes' => __DIR__ . '/includes/error_log',
    'auth' => __DIR__ . '/shared/error_log',
];
foreach ($logCandidates as $label => $path) {
    echo "=== {$label}: {$path} ===\n";
    if (!is_file($path)) {
        echo "NOT FOUND\n\n";
        continue;
    }
    if (!is_readable($path)) {
        echo "NOT READABLE\n\n";
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    echo $lines === false ? "READ FAILED\n\n" : implode("\n", array_slice($lines, -60)) . "\n\n";
}

echo "=== DATABASE ===\n";
try {
    echo 'PHP version: ' . PHP_VERSION . "\n";
    echo 'Database name: ' . (string) db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
    $tables = db()->query("SHOW TABLES LIKE 'kpi_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo 'KPI tables: ' . ($tables ? implode(', ', $tables) : 'NONE') . "\n";
} catch (Throwable $error) {
    echo 'DATABASE QUERY FAILED: ' . $error->getMessage() . "\n";
}
