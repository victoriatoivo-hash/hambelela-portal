<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('4ab6130fc24c42b2997619a44e23cf07', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/woocommerce.php';
$orders = wc_get('orders', ['per_page' => 100, 'orderby' => 'date', 'order' => 'desc']);
$result = [];
foreach ($orders as $order) {
    $matched = [];
    foreach ((array) ($order['meta_data'] ?? []) as $meta) {
        $key = (string) ($meta['key'] ?? '');
        if (!preg_match('/payment|tender|split|gateway|cash|card|eft|wallet|dpo|paygate/i', $key)) continue;
        $value = $meta['value'] ?? null;
        if (is_string($value) && strlen($value) > 500) $value = substr($value, 0, 500) . '...';
        $matched[] = ['key' => $key, 'value' => $value];
    }
    if ($matched || preg_match('/dpo|paygate|cash|card|eft|wallet|split/i', (string) ($order['payment_method'] ?? '') . ' ' . (string) ($order['payment_method_title'] ?? ''))) {
        $result[] = [
            'id' => (int) ($order['id'] ?? 0),
            'payment_method' => (string) ($order['payment_method'] ?? ''),
            'payment_method_title' => (string) ($order['payment_method_title'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'total' => (string) ($order['total'] ?? ''),
            'date_paid_gmt' => $order['date_paid_gmt'] ?? null,
            'payment_meta' => $matched,
        ];
    }
}
echo json_encode(['success' => true, 'orders' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
