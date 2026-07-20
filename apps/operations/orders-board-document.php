<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

function orders_document_fail(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function orders_document_pdf_escape(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $value);
}

function orders_document_pdf(array $lines): string
{
    $content = "BT\n/F1 10 Tf\n50 790 Td\n";
    foreach ($lines as $index => $line) {
        $content .= ($index > 0 ? "0 -16 Td\n" : '')
            . '(' . orders_document_pdf_escape((string) $line) . ") Tj\n";
    }
    $content .= "ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
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
if (!$orderId || !in_array($documentType, ['receipt', 'invoice'], true) || !in_array($action, ['download', 'print'], true)) {
    orders_document_fail('Choose a valid order document and action.', 422);
}
if (!ops_database_ready() || !ops_column_exists('ops_orders', 'woo_order_id')) {
    orders_document_fail('Order documents are unavailable.', 503);
}

$amountSelect = ops_column_exists('ops_orders', 'total_amount')
    ? 'total_amount'
    : '0 AS total_amount';
$order = ops_rows(
    'SELECT id, order_number, woo_order_id, customer_name, customer_contact, payment_method,
            ' . $amountSelect . ', payment_status, status, order_type, created_at
       FROM ops_orders WHERE id = ? LIMIT 1',
    [(int) $orderId]
)[0] ?? null;
if (!$order) {
    orders_document_fail('Order not found.', 404);
}
if ((int) ($order['woo_order_id'] ?? 0) <= 0) {
    orders_document_fail('Documents are only available for website orders.', 409);
}

$items = ops_rows(
    'SELECT product_name, sku, quantity FROM ops_order_items WHERE order_id = ? ORDER BY id ASC',
    [(int) $orderId]
);
$title = $documentType === 'receipt' ? 'Receipt' : 'Invoice';
$number = trim((string) ($order['order_number'] ?? '')) ?: (string) $orderId;
$lines = [
    'Hambelela Organic',
    $title . ' - Order ' . $number,
    'Date: ' . (string) ($order['created_at'] ?? ''),
    'Customer: ' . (string) ($order['customer_name'] ?? ''),
    'Contact: ' . (string) ($order['customer_contact'] ?? ''),
    'Payment: ' . (string) ($order['payment_method'] ?? ''),
    'Payment status: ' . (string) ($order['payment_status'] ?? ''),
    '', 'Items',
];
foreach ($items as $item) {
    $sku = trim((string) ($item['sku'] ?? ''));
    $lines[] = (string) ($item['product_name'] ?? 'Item')
        . ($sku !== '' ? ' [' . $sku . ']' : '')
        . ' x ' . (string) ($item['quantity'] ?? 0);
}
$lines[] = '';
$lines[] = 'Total: N$' . number_format((float) ($order['total_amount'] ?? 0), 2);

ops_activity_log('order_document_' . $action, 'order', (int) $orderId, [
    'document_type' => $documentType,
    'order_number' => $number,
]);
$filename = $documentType . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $number);

if ($action === 'download') {
    $pdf = orders_document_pdf($lines);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
$safeTitle = htmlspecialchars($title . ' - Order ' . $number, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $safeTitle . '</title>';
echo '<style>body{font:14px/1.5 Arial,sans-serif;color:#1a1a1a;margin:40px}h1{color:#721b1a;margin:0 0 20px}.line{padding:5px 0;border-bottom:1px solid #ede3d8}@media print{button{display:none}}</style></head><body>';
echo '<button type="button" onclick="window.print()">Print</button><h1>' . $safeTitle . '</h1>';
foreach ($lines as $line) {
    echo '<div class="line">' . htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8') . '</div>';
}
echo '<script>window.addEventListener("load",function(){window.print();});</script></body></html>';
