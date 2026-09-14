<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-service.php';

$ready = ops_database_ready() && wa_ensure_tables();
if (!$ready) {
    http_response_code(503);
    echo 'Meta communications webhook tables are not ready.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $expected = wa_setting('verify_token');

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        wa_log_webhook('verification', ['mode' => $mode], 'processed', null, null, null, 'meta');
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    wa_log_webhook('verification_failed', ['mode' => $mode], 'failed', 'Verify token mismatch.', null, null, 'meta');
    http_response_code(403);
    echo 'Verification failed.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    wa_log_webhook('invalid_payload', ['raw' => $raw], 'failed', 'Invalid JSON payload.', null, null, 'meta');
    http_response_code(400);
    echo 'Invalid JSON.';
    exit;
}

try {
    wa_log_webhook('raw_event', $payload, 'received', null, null, null, 'meta');
    $result = wa_process_webhook_payload($payload);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'messages' => $result['messages'], 'statuses' => $result['statuses']]);
} catch (Throwable $e) {
    wa_log_webhook('processing_error', $payload, 'failed', $e->getMessage(), null, null, 'meta');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Webhook processing failed.']);
}
