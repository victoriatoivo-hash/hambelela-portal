<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/kpi-reporting.php';
    echo json_encode(array(
        'ok' => true,
        'php_version' => PHP_VERSION,
        'reporting_loaded' => function_exists('kpi_resolve_reporting_period'),
    ));
} catch (Throwable $error) {
    error_log(
        date(DATE_ATOM) . ' KPI diagnostic: ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL,
        3,
        BASE_PATH . '/logs/kpi_errors.log'
    );
    http_response_code(500);
    echo json_encode(array(
        'ok' => false,
        'php_version' => PHP_VERSION,
        'error_type' => get_class($error),
        'error_message' => $error->getMessage(),
    ));
}
