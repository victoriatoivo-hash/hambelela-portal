<?php
declare(strict_types=1);
$expectedToken = '7f30a8b2652c41efa196d37b84ce9051';
if (!hash_equals($expectedToken, (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
echo "KPI JSON FAILURE DIAGNOSTIC\n";
echo 'Generated: ' . date(DATE_ATOM) . "\n";
echo 'PHP version: ' . PHP_VERSION . "\n\n";
$paths = [__DIR__.'/error_log',__DIR__.'/includes/error_log',__DIR__.'/shared/error_log',__DIR__.'/logs/kpi_errors.log'];
foreach ($paths as $path) {
    echo "=== {$path} ===\n";
    if (!is_file($path)) { echo "NOT FOUND\n\n"; continue; }
    if (!is_readable($path)) { echo "NOT READABLE\n\n"; continue; }
    $lines=file($path,FILE_IGNORE_NEW_LINES);echo $lines===false?"READ FAILED\n\n":implode("\n",array_slice($lines,-120))."\n\n";
}
