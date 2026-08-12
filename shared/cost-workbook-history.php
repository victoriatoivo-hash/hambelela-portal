<?php

declare(strict_types=1);

function cw_history_require_read_only_request(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        return;
    }

    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => 'historical_records_read_only',
        'message' => 'Historical Cost Records are read-only.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function cw_history_redirect(): void
{
    header('Location: ' . BASE_URL . '/apps/cost-manager/historical-cost-records.php', true, 302);
    exit;
}
