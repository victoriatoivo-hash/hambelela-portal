<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';

function orders_document_fail(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

function orders_document_source_url(array $sourceOrder, string $documentType): ?string
{
    $candidates = [];
    foreach ((array) ($sourceOrder['meta_data'] ?? []) as $meta) {
        $key = strtolower((string) ($meta['key'] ?? ''));
        $value = $meta['value'] ?? null;
        if (strpos($key, $documentType) === false || is_array($value) || is_object($value)) {
            continue;
        }
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $candidates[] = $value;
        }
    }

    foreach ([$documentType . '_url', $documentType . '_pdf', 'pos_' . $documentType . '_url'] as $field) {
        $value = trim((string) ($sourceOrder[$field] ?? ''));
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            array_unshift($candidates, $value);
        }
    }

    $storeHost = strtolower((string) parse_url(WC_STORE_URL, PHP_URL_HOST));
    foreach (array_unique($candidates) as $candidate) {
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if ($scheme === 'https' && $host !== '' && hash_equals($storeHost, $host)) {
            return $candidate;
        }
    }

    return null;
}

function orders_document_fetch(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Website document service is temporarily unavailable.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/pdf,text/html;q=0.9,*/*;q=0.8'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Source document request failed.');
    }
    $storeHost = strtolower((string) parse_url(WC_STORE_URL, PHP_URL_HOST));
    $effectiveHost = strtolower((string) parse_url($effectiveUrl, PHP_URL_HOST));
    if ($effectiveHost === '' || !hash_equals($storeHost, $effectiveHost)) {
        throw new RuntimeException('Source document redirected outside the configured website.');
    }

    $body = substr($response, $headerSize);
    $normalizedType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    if ($body === '') {
        throw new RuntimeException('Source document response was empty.');
    }
    if (!in_array($normalizedType, ['application/pdf', 'text/html'], true)) {
        throw new RuntimeException('Source document returned an unsupported content type.');
    }
    if ($normalizedType === 'text/html') {
        $sample = strtolower(substr($body, 0, 8192));
        $looksLikeLogin = strpos($sample, '<title>login') !== false
            || strpos($sample, 'wp-login.php') !== false
            || strpos($sample, 'name="log"') !== false
            || strpos($sample, 'name="pwd"') !== false;
        $looksLikeError = strpos($sample, '<title>error') !== false
            || strpos($sample, 'fatal error') !== false
            || strpos($sample, 'page not found') !== false;
        if ($looksLikeLogin || $looksLikeError) {
            throw new RuntimeException('Source document service returned a login or error page.');
        }
    }

    return [
        'headers' => substr($response, 0, $headerSize),
        'body' => $body,
        'content_type' => $contentType,
    ];
}

if (current_role_key() === 'guest') {
    orders_document_fail('Your session expired. Please log in again.', 401);
}
$roleKey = (string) (current_user()['role_key'] ?? '');
if (!in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager'], true)) {
    orders_document_fail('You do not have permission to access order documents.', 403);
}

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
$documentType = strtolower(trim((string) ($_GET['document_type'] ?? '')));
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
if (!$orderId || !in_array($documentType, ['receipt', 'invoice'], true) || !in_array($action, ['view', 'download', 'print'], true)) {
    orders_document_fail('Choose a valid order document and action.', 422);
}
if (!ops_database_ready() || !ops_column_exists('ops_orders', 'woo_order_id') || !wc_configured()) {
    orders_document_fail('Website document service is temporarily unavailable.', 503);
}

$order = ops_rows('SELECT id, order_number, woo_order_id FROM ops_orders WHERE id = ? LIMIT 1', [(int) $orderId])[0] ?? null;
if (!$order) {
    orders_document_fail('Order not found.', 404);
}
$sourceOrderId = (int) ($order['woo_order_id'] ?? 0);
if ($sourceOrderId <= 0) {
    orders_document_fail('Original POS receipt unavailable. This order was not created through the website POS.', 409);
}

try {
    $sourceOrder = wc_get('orders/' . $sourceOrderId);
    $sourceUrl = orders_document_source_url($sourceOrder, $documentType);
    if (!$sourceUrl) {
        orders_document_fail(
            $documentType === 'invoice'
                ? 'Original invoice is unavailable for this order.'
                : 'Unable to load the original POS receipt.',
            404
        );
    }
    $document = orders_document_fetch($sourceUrl);
} catch (Throwable $error) {
    orders_document_fail('Website document service is temporarily unavailable.', 502);
}

$sourcePath = (string) parse_url($sourceUrl, PHP_URL_PATH);
$originalName = basename($sourcePath);
$contentDisposition = '';
if (preg_match('/^Content-Disposition:\s*(.+)$/im', (string) $document['headers'], $match)) {
    $contentDisposition = trim($match[1]);
}
if ($contentDisposition !== '' && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";\r\n]+)"?/i', $contentDisposition, $match)) {
    $headerName = basename(rawurldecode(trim($match[1])));
    if ($headerName !== '' && $headerName !== '.') {
        $originalName = $headerName;
    }
}
if ($originalName === '' || $originalName === '/' || !preg_match('/\.[A-Za-z0-9]{2,5}$/', $originalName)) {
    $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($order['order_number'] ?? $sourceOrderId));
    $originalName = ucfirst($documentType) . '-' . $number . (stripos($document['content_type'], 'pdf') !== false ? '.pdf' : '.html');
}

ops_activity_log('original_pos_' . $documentType . '_' . $action, 'order', (int) $orderId, [
    'source' => 'website_pos',
    'source_order_id' => $sourceOrderId,
    'document_type' => $documentType,
    'message' => 'Original POS ' . $documentType . ' ' . $action . 'ed.',
]);

header('Content-Type: ' . $document['content_type']);
header('X-Portal-Original-Document: 1');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($action === 'download' ? 'attachment' : 'inline') . '; filename="' . addcslashes($originalName, '"\\') . '"');
header('Content-Length: ' . strlen($document['body']));
header('Cache-Control: private, no-store');
echo $document['body'];
