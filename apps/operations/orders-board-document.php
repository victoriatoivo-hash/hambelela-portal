<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';
require_once __DIR__ . '/lib/orders-documents.php';

function orders_document_fail(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

function orders_document_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function orders_document_fetch_pdf(string $url): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('POS document service is temporarily unavailable.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($response) || $status < 200 || $status >= 300) throw new RuntimeException($error ?: 'POS document request failed.');
    $storeHost = strtolower((string) parse_url(WC_STORE_URL, PHP_URL_HOST));
    $effectiveHost = strtolower((string) parse_url($effectiveUrl, PHP_URL_HOST));
    if ($effectiveHost === '' || !hash_equals($storeHost, $effectiveHost)) throw new RuntimeException('POS document redirected outside the configured store.');
    $body = substr($response, $headerSize);
    if (strpos($contentType, 'application/pdf') !== 0 || substr($body, 0, 5) !== '%PDF-') throw new RuntimeException('POS did not return an original PDF document.');
    return ['body' => $body, 'checksum' => hash('sha256', $body)];
}

function orders_document_cache(array $record): array
{
    $cachedPath = trim((string) ($record['cached_path'] ?? ''));
    $expected = strtolower(trim((string) ($record['cached_checksum'] ?? '')));
    if ($cachedPath !== '' && strpos($cachedPath, BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'order-documents') === 0 && is_file($cachedPath)) {
        $body = @file_get_contents($cachedPath);
        if (is_string($body) && substr($body, 0, 5) === '%PDF-' && ($expected === '' || hash_equals($expected, hash('sha256', $body)))) return ['body' => $body, 'checksum' => hash('sha256', $body)];
    }
    $document = orders_document_fetch_pdf((string) $record['source_url']);
    $sourceChecksum = strtolower(trim((string) ($record['source_checksum'] ?? '')));
    $sourceChecksum = preg_replace('/^sha256:/', '', $sourceChecksum);
    if ($sourceChecksum !== '' && preg_match('/^[a-f0-9]{64}$/', $sourceChecksum) && !hash_equals($sourceChecksum, $document['checksum'])) throw new RuntimeException('POS document checksum verification failed.');
    $dir = BASE_PATH . '/storage/private/order-documents';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Private document storage is unavailable.');
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    $path = $dir . '/' . (int) $record['order_id'] . '-' . (int) $record['id'] . '-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $record['document_id']) . '.pdf';
    if (@file_put_contents($path, $document['body'], LOCK_EX) === false) throw new RuntimeException('Unable to preserve the POS document.');
    db()->prepare('UPDATE ops_order_documents SET cached_path = ?, cached_checksum = ? WHERE id = ?')->execute([$path, $document['checksum'], (int) $record['id']]);
    return $document;
}

if (current_role_key() === 'guest') orders_document_fail('Your session expired. Please log in again.', 401);

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$documentType = strtolower(trim((string) ($_GET['document_type'] ?? '')));
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
if (!$orderId || !in_array($action, ['availability', 'view', 'download', 'print'], true)) orders_document_fail('Choose a valid order document and action.', 422);
if ($action !== 'availability' && !in_array($documentType, ['receipt', 'invoice'], true)) orders_document_fail('Choose a valid order document and action.', 422);
if (!ops_database_ready() || !ops_column_exists('ops_orders', 'woo_order_id') || !wc_configured()) orders_document_fail('POS document service is temporarily unavailable.', 503);
if (!ops_order_documents_ensure_table()) orders_document_fail('Unable to load document. Try again.', 503);

$order = ops_rows('SELECT id, order_number, woo_order_id FROM ops_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int) $orderId])[0] ?? null;
if (!$order) orders_document_fail('Order not found.', 404);
$sourceOrderId = (int) ($order['woo_order_id'] ?? 0);
if ($sourceOrderId <= 0) {
    if ($action === 'availability') orders_document_json(['portal_order_id' => (int) $orderId, 'source_order_id' => null, 'documents' => ['receipt' => ['available' => false], 'invoice' => ['available' => false]]]);
    orders_document_fail('Not generated in POS.', 404);
}

try {
    $receipt = ops_order_document_current((int) $orderId, 'receipt');
    $invoice = ops_order_document_current((int) $orderId, 'invoice');
    if (!$receipt || !$invoice) {
        $sourceOrder = wc_get('orders/' . $sourceOrderId);
        ops_order_document_sync_metadata((int) $orderId, $sourceOrder);
        $receipt = ops_order_document_current((int) $orderId, 'receipt');
        $invoice = ops_order_document_current((int) $orderId, 'invoice');
    }
    if ($action === 'availability') {
        orders_document_json([
            'portal_order_id' => (int) $orderId,
            'source_order_id' => (string) $sourceOrderId,
            'documents' => [
                'receipt' => ['available' => (bool) $receipt, 'document_id' => $receipt['document_id'] ?? null, 'generated_at' => $receipt['generated_at'] ?? null],
                'invoice' => ['available' => (bool) $invoice, 'document_id' => $invoice['document_id'] ?? null, 'generated_at' => $invoice['generated_at'] ?? null],
            ],
        ]);
    }
    $record = $documentType === 'receipt' ? $receipt : $invoice;
    if (!$record) orders_document_fail('Not generated in POS.', 404);
    $document = orders_document_cache($record);
} catch (Throwable $error) {
    ops_order_documents_log('Document request failed', ['order_id' => (int) $orderId, 'type' => $documentType, 'action' => $action, 'error' => $error->getMessage()]);
    orders_document_fail('Unable to load document. Try again.', 502);
}

$visible = preg_replace('/^WEB[-_\s#]*/i', '', (string) ($order['order_number'] ?? $sourceOrderId));
$filename = ucfirst($documentType) . '-INV-' . preg_replace('/[^A-Za-z0-9_-]/', '', $visible) . '.pdf';
ops_activity_log('original_pos_' . $documentType . '_' . $action, 'order', (int) $orderId, ['source_order_id' => (string) $sourceOrderId, 'document_id' => $record['document_id'], 'checksum' => $document['checksum'], 'message' => ucfirst($action) . 'ed original POS ' . $documentType . '.']);
header('Content-Type: application/pdf');
header('X-Portal-Original-Document: 1');
header('X-Document-SHA256: ' . $document['checksum']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($action === 'download' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($document['body']));
header('Cache-Control: private, no-store');
echo $document['body'];
