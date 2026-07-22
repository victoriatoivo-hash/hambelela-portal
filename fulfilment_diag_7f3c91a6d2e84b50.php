<?php
declare(strict_types=1);
const DIAG_TOKEN = '8d14c0b97af3421ea6613f5c8092d74b';
if (!isset($_GET['token']) || !hash_equals(DIAG_TOKEN, (string) $_GET['token'])) { http_response_code(404); exit; }
require_once __DIR__ . '/shared/woocommerce.php';
require_once __DIR__ . '/shared/database.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $orders = wc_get('orders', ['per_page' => 75, 'orderby' => 'date', 'order' => 'desc', 'status' => 'any']);
    $ids = array_values(array_filter(array_map(static fn(array $order): int => (int) ($order['id'] ?? 0), $orders)));
    $stored = [];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT woo_order_id, order_number, order_type, fulfilment_mode FROM ops_orders WHERE woo_order_id IN ({$marks})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) $stored[(int) $row['woo_order_id']] = $row;
    }
    $result = [];
    foreach ($orders as $order) {
        $meta = [];
        foreach ((array) ($order['meta_data'] ?? []) as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if (!preg_match('/fulfil|fulfill|deliver|pickup|pick_up|collect|courier|shipping|service_type|order_type/i', $key)) continue;
            $value = $entry['value'] ?? null;
            if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            $meta[$key] = mb_substr((string) $value, 0, 300);
        }
        $shipping = [];
        foreach ((array) ($order['shipping_lines'] ?? []) as $line) $shipping[] = ['method_id'=>$line['method_id']??null,'method_title'=>$line['method_title']??null];
        $id = (int) ($order['id'] ?? 0);
        $result[] = ['woo_order_id'=>$id,'number'=>$order['number']??null,'status'=>$order['status']??null,'shipping_lines'=>$shipping,'fulfilment_meta'=>$meta,'portal'=>$stored[$id]??null];
    }
    echo json_encode(['ok'=>true,'orders'=>$result], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$error->getMessage()]); }
