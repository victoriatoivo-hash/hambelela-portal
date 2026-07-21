<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = '4f517d02a1794c9ba422157cdf1b620a';
$providedToken = (string) ($_GET['token'] ?? '');
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

echo "KPI PHASE 1 DIAGNOSTIC\n";
echo 'Generated at: ' . date(DATE_ATOM) . "\n\n";

$logCandidates = [
    'portal root' => __DIR__ . '/error_log',
    'portal includes' => __DIR__ . '/includes/error_log',
    'auth directory' => dirname(__DIR__ . '/shared/auth.php') . '/error_log',
    'HR includes' => __DIR__ . '/apps/hr-portal/includes/error_log',
];

foreach ($logCandidates as $label => $path) {
    echo "=== ERROR LOG: {$label} ===\n";
    echo "Path: {$path}\n";
    if (!is_file($path)) {
        echo "NOT FOUND\n\n";
        continue;
    }
    if (!is_readable($path)) {
        echo "NOT READABLE\n\n";
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        echo "READ FAILED\n\n";
        continue;
    }
    echo implode("\n", array_slice($lines, -60)) . "\n\n";
}

echo "=== DATABASE ===\n";
try {
    $databaseName = (string) db()->query('SELECT DATABASE()')->fetchColumn();
    echo 'PHP version: ' . PHP_VERSION . "\n";
    echo 'Database name: ' . $databaseName . "\n";
    $tables = db()->query("SHOW TABLES LIKE 'kpi_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo 'KPI tables: ' . ($tables ? implode(', ', $tables) : 'NONE') . "\n";
} catch (Throwable $error) {
    echo 'DATABASE QUERY FAILED: ' . $error->getMessage() . "\n";
}
