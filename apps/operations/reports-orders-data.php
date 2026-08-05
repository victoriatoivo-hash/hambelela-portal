<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

$kpiSection = 'orders';
try {
    require __DIR__ . '/reports-section-data.php';
} catch (Throwable $error) {
    error_log(date(DATE_ATOM) . ' Orders report bootstrap: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
    $payload = [
        'ok' => false,
        'success' => false,
        'data' => null,
        'message' => 'The Orders report is temporarily unavailable.',
        'error' => 'ORDERS_REPORT_FAILED',
        'error_code' => 'ORDERS_REPORT_FAILED',
    ];
    // Owner-only diagnostic mode gives support the actual server failure while
    // normal requests never expose SQL, paths, or stack details.
    if ((string) ($_GET['diagnose'] ?? '') === '1') {
        $payload['diagnostic'] = $error->getMessage();
    }
    kpi_send_json($payload, 500);
}
