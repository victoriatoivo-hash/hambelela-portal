<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

if (current_role_key() === 'guest') {
    http_response_code(401);
    exit('Your session expired.');
}

$orderId = (int) ($_GET['order_id'] ?? 0);
$fileId = (int) ($_GET['file_id'] ?? 0);
$file = ($orderId > 0 && $fileId > 0 && ops_table_exists('ops_order_files'))
    ? ops_row(
        'SELECT f.* FROM ops_order_files f INNER JOIN ops_orders o ON o.id = f.order_id WHERE f.id = ? AND f.order_id = ? AND f.deleted_at IS NULL AND o.deleted_at IS NULL LIMIT 1',
        [$fileId, $orderId]
    )
    : null;

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$path = BASE_PATH . '/storage/order-files/' . basename((string) $file['stored_filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$disposition = ($_GET['disposition'] ?? '') === 'attachment' ? 'attachment' : 'inline';
$filename = preg_replace('/[\r\n"\\]+/', '_', (string) $file['original_filename']) ?: 'order-file';
if ($disposition === 'attachment') {
    ops_activity_log('order_file_downloaded', 'order', $orderId, ['file_id' => $fileId, 'filename' => $filename, 'changed_by' => current_user()['name'] ?? 'Unknown']);
}
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . (string) $file['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
