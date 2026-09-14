<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';

header('Content-Type: application/json');

if (current_role_key() === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please log in again.']);
    exit;
}

function ops_board_sync_log(string $message, array $context = []): void
{
    $dir = BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/operations-sync.log', $line . PHP_EOL, FILE_APPEND);
}

function ops_board_payment_status(array $order): string
{
    if (in_array((string) ($order['status'] ?? ''), ['cancelled', 'refunded', 'failed'], true)) {
        return 'refunded';
    }

    if (!empty($order['date_paid']) || (($order['status'] ?? '') === 'processing') || (($order['status'] ?? '') === 'completed')) {
        return 'paid';
    }

    return 'unpaid';
}

function ops_board_order_type(array $order): string
{
    $method = strtolower((string) (($order['shipping_lines'][0]['method_title'] ?? '') . ' ' . ($order['shipping_lines'][0]['method_id'] ?? '')));

    if (strpos($method, 'courier') !== false || strpos($method, 'pudo') !== false || strpos($method, 'ship') !== false) {
        return 'courier';
    }

    if (strpos($method, 'delivery') !== false || strpos($method, 'local') !== false) {
        return 'delivery';
    }

    return 'collection';
}

function ops_board_customer_name(array $order): string
{
    $billing = $order['billing'] ?? [];
    $name = trim((string) (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));

    return $name !== '' ? $name : 'Website customer';
}

function ops_board_order_notes(array $order): string
{
    $shipping = $order['shipping'] ?? [];
    $parts = [];

    if (!empty($order['customer_note'])) {
        $parts[] = 'Customer note: ' . $order['customer_note'];
    }

    $address = trim(implode(', ', array_filter([
        $shipping['address_1'] ?? '',
        $shipping['address_2'] ?? '',
        $shipping['city'] ?? '',
        $shipping['state'] ?? '',
        $shipping['postcode'] ?? '',
        $shipping['country'] ?? '',
    ])));

    if ($address !== '') {
        $parts[] = 'Shipping address: ' . $address;
    }

    return implode("\n", $parts);
}

function ops_board_order_breakdown(array $order): array
{
    $productTotal = 0.0;
    $taxTotal = 0.0;
    foreach (($order['line_items'] ?? []) as $line) {
        $productTotal += (float) ($line['total'] ?? 0);
        $taxTotal += (float) ($line['total_tax'] ?? 0);
    }

    $shippingTotal = 0.0;
    $shippingTaxTotal = 0.0;
    foreach (($order['shipping_lines'] ?? []) as $line) {
        $shippingTotal += (float) ($line['total'] ?? 0);
        $shippingTaxTotal += (float) ($line['total_tax'] ?? 0);
    }

    return [
        'product_total' => $productTotal + $taxTotal,
        'tax_total' => $taxTotal,
        'shipping_total' => $shippingTotal,
        'shipping_tax_total' => $shippingTaxTotal,
        'discount_total' => (float) ($order['discount_total'] ?? 0),
        'refund_total' => (float) ($order['refund_total'] ?? 0),
    ];
}

function ops_board_sync_website_orders(?string $date = null): array
{
    ops_board_sync_log('sync started', ['date' => $date ?: 'all']);

    if (!wc_configured()) {
        throw new RuntimeException('WooCommerce is not configured.');
    }

    if (!ops_column_exists('ops_orders', 'woo_order_id') || !ops_column_exists('ops_order_items', 'woo_order_line_id')) {
        throw new RuntimeException('Import operations-woocommerce-sync-migration.sql first.');
    }

    $baseQuery = [
        'per_page' => 100,
        'orderby' => 'date',
        'order' => 'desc',
    ];

    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $baseQuery['after'] = date('Y-m-d\T00:00:00', strtotime($date . ' -1 day'));
        $baseQuery['before'] = date('Y-m-d\T23:59:59', strtotime($date . ' +1 day'));
    }

    $ordersById = [];
    $syncWarnings = [];
    $statuses = ['processing', 'on-hold', 'pending', 'completed', 'cancelled', 'refunded', 'failed'];
    $collectOrders = static function (array $query) use (&$ordersById): void {
        for ($page = 1; $page <= 5; $page++) {
            $batch = wc_get('orders', $query + ['page' => $page]);
            if (!is_array($batch) || !$batch) {
                break;
            }

            foreach ($batch as $order) {
                $wooId = (int) ($order['id'] ?? 0);
                if ($wooId > 0) {
                    $ordersById[$wooId] = $order;
                }
            }

            if (count($batch) < (int) ($query['per_page'] ?? 100)) {
                break;
            }
        }
    };

    try {
        $collectOrders($baseQuery + ['status' => 'any']);
    } catch (Throwable $e) {
        $syncWarnings[] = $e->getMessage();
    }

    if (!$ordersById) {
        foreach ($statuses as $status) {
            try {
                $collectOrders($baseQuery + ['status' => $status]);
            } catch (Throwable $e) {
                $syncWarnings[] = $status . ': ' . $e->getMessage();
            }
        }
    }

    $orders = array_values($ordersById);

    ops_board_sync_log('woocommerce orders fetched', ['count' => count($orders), 'warnings' => array_slice(array_unique($syncWarnings), 0, 3)]);

    $pdo = db();
    $hasTotalAmount = ops_column_exists('ops_orders', 'total_amount');
    $hasReportBreakdown = $hasTotalAmount
        && ops_column_exists('ops_orders', 'product_total')
        && ops_column_exists('ops_orders', 'tax_total')
        && ops_column_exists('ops_orders', 'shipping_total')
        && ops_column_exists('ops_orders', 'shipping_tax_total')
        && ops_column_exists('ops_orders', 'discount_total')
        && ops_column_exists('ops_orders', 'refund_total');
    $imported = 0;
    $updated = 0;
    $lineCount = 0;
    $assigned = 0;

    try {
        $pdo->beginTransaction();

        if ($hasTotalAmount) {
            $breakdownColumns = $hasReportBreakdown ? ', product_total, tax_total, shipping_total, shipping_tax_total, discount_total, refund_total' : '';
            $breakdownValues = $hasReportBreakdown ? ', ?, ?, ?, ?, ?, ?' : '';
            $breakdownUpdates = $hasReportBreakdown ? '
                    product_total = VALUES(product_total),
                    tax_total = VALUES(tax_total),
                    shipping_total = VALUES(shipping_total),
                    shipping_tax_total = VALUES(shipping_tax_total),
                    discount_total = VALUES(discount_total),
                    refund_total = VALUES(refund_total),' : '';
            $orderStmt = $pdo->prepare(
                "INSERT INTO ops_orders (
                    woo_order_id, order_number, customer_name, customer_contact, payment_method, total_amount{$breakdownColumns}, payment_status,
                    order_type, priority, complexity, assigned_packer_id, status, notes, workload_score, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?{$breakdownValues}, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    customer_name = VALUES(customer_name),
                    customer_contact = VALUES(customer_contact),
                    payment_method = VALUES(payment_method),
                    total_amount = VALUES(total_amount),
                    {$breakdownUpdates}
                    payment_status = VALUES(payment_status),
                    order_type = VALUES(order_type),
                    notes = VALUES(notes),
                    workload_score = VALUES(workload_score),
                    updated_at = CURRENT_TIMESTAMP"
            );
        } else {
            $orderStmt = $pdo->prepare(
                "INSERT INTO ops_orders (
                    woo_order_id, order_number, customer_name, customer_contact, payment_method, payment_status,
                    order_type, priority, complexity, assigned_packer_id, status, notes, workload_score, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    customer_name = VALUES(customer_name),
                    customer_contact = VALUES(customer_contact),
                    payment_method = VALUES(payment_method),
                    payment_status = VALUES(payment_status),
                    order_type = VALUES(order_type),
                    notes = VALUES(notes),
                    workload_score = VALUES(workload_score),
                    updated_at = CURRENT_TIMESTAMP"
            );
        }

        $itemStmt = $pdo->prepare(
            "INSERT INTO ops_order_items (
                order_id, woo_order_line_id, woo_product_id, woo_variation_id, product_name, sku, barcode, quantity
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                woo_product_id = VALUES(woo_product_id),
                woo_variation_id = VALUES(woo_variation_id),
                product_name = VALUES(product_name),
                sku = VALUES(sku),
                barcode = VALUES(barcode),
                quantity = VALUES(quantity)"
        );

        $orderIdStmt = $pdo->prepare('SELECT id FROM ops_orders WHERE woo_order_id = ? LIMIT 1');

        foreach ($orders as $order) {
            $wooOrderId = (int) ($order['id'] ?? 0);
            if ($wooOrderId <= 0) {
                continue;
            }

            $items = $order['line_items'] ?? [];
            $itemCount = 0;
            foreach ($items as $line) {
                $itemCount += max(1, (int) ($line['quantity'] ?? 1));
            }

            $orderType = ops_board_order_type($order);
            $complexity = count($items) >= 8 ? 3 : (count($items) >= 4 ? 2 : 1);
            $priority = ($order['status'] ?? '') === 'pending' ? 'normal' : 'urgent';
            $workload = ops_workload_score($itemCount, $orderType, $complexity, $priority);
            $packerId = null;
            $createdAt = date('Y-m-d H:i:s', strtotime((string) ($order['date_created'] ?? 'now')));
            $orderNumber = 'WEB-' . (string) ($order['number'] ?? $wooOrderId);

            $orderValues = [
                $wooOrderId,
                $orderNumber,
                ops_board_customer_name($order),
                (string) (($order['billing']['phone'] ?? '') ?: ($order['billing']['email'] ?? '')),
                (string) ($order['payment_method_title'] ?? $order['payment_method'] ?? ''),
            ];

            if ($hasTotalAmount) {
                $orderValues[] = (float) ($order['total'] ?? 0);
                if ($hasReportBreakdown) {
                    $breakdown = ops_board_order_breakdown($order);
                    array_push(
                        $orderValues,
                        $breakdown['product_total'],
                        $breakdown['tax_total'],
                        $breakdown['shipping_total'],
                        $breakdown['shipping_tax_total'],
                        $breakdown['discount_total'],
                        $breakdown['refund_total']
                    );
                }
            }

            $orderValues = array_merge($orderValues, [
                    ops_board_payment_status($order),
                    $orderType,
                    $priority,
                    $complexity,
                    $packerId,
                    'new_order',
                    ops_board_order_notes($order),
                    $workload,
                    $createdAt,
                ]);

            $orderStmt->execute($orderValues);

            $affected = $orderStmt->rowCount();
            if ($affected === 1) {
                $imported++;
            } elseif ($affected === 2) {
                $updated++;
            }

            $orderIdStmt->execute([$wooOrderId]);
            $orderId = (int) $orderIdStmt->fetchColumn();
            $orderIdStmt->closeCursor();

            foreach ($items as $line) {
                $sku = (string) ($line['sku'] ?? '');
                $itemStmt->execute([
                    $orderId,
                    (int) ($line['id'] ?? 0),
                    (int) ($line['product_id'] ?? 0),
                    (int) ($line['variation_id'] ?? 0),
                    (string) ($line['name'] ?? 'Website product'),
                    $sku,
                    $sku,
                    (float) ($line['quantity'] ?? 1),
                ]);
                $lineCount++;
            }
        }

        $pdo->commit();

        if (isset($orderStmt)) {
            $orderStmt->closeCursor();
        }
        if (isset($itemStmt)) {
            $itemStmt->closeCursor();
        }
        if (isset($orderIdStmt)) {
            $orderIdStmt->closeCursor();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ops_board_sync_log('sync import failed', ['error' => $e->getMessage()]);
        throw $e;
    }

    try {
        $assigned = ops_assign_unassigned_orders();
    } catch (Throwable $e) {
        ops_board_sync_log('post-sync assignment failed', ['error' => $e->getMessage()]);
        throw $e;
    }

    ops_board_sync_log('sync finished', [
        'seen' => count($orders),
        'imported' => $imported,
        'updated' => $updated,
        'lines' => $lineCount,
        'assigned' => $assigned,
    ]);

    return [
        'imported' => $imported,
        'updated' => $updated,
        'lines' => $lineCount,
        'assigned' => $assigned,
        'requested_date' => $date,
        'website_orders_seen' => count($orders),
        'warnings' => array_slice(array_unique($syncWarnings), 0, 3),
    ];
}

try {
    if (!ops_database_ready()) {
        throw new RuntimeException('Operations database is not ready.');
    }

    $action = ops_post_string('action', 40);

    if ($action === 'availability') {
        if (!ops_table_exists('ops_employee_availability')) {
            throw new RuntimeException('Availability table is missing. Import operations-live-board-migration.sql first.');
        }

        $employeeId = ops_current_employee_id();
        if (!$employeeId) {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
        }

        if ($employeeId <= 0) {
            throw new RuntimeException('Could not identify the employee account.');
        }

        $status = ops_post_string('status', 30);
        $allowed = ['available', 'on_lunch', 'offline'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Invalid availability status.');
        }

        $until = null;
        if ($status === 'on_lunch') {
            $minutes = max(15, min(180, (int) ($_POST['minutes'] ?? 60)));
            $until = date('Y-m-d H:i:s', time() + ($minutes * 60));
        }

        $stmt = db()->prepare(
            "INSERT INTO ops_employee_availability (employee_id, availability_status, unavailable_until, note)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                availability_status = VALUES(availability_status),
                unavailable_until = VALUES(unavailable_until),
                note = VALUES(note),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$employeeId, $status, $until, ops_post_string('note', 255)]);

        $assigned = ops_assign_unassigned_orders();
        echo json_encode(['ok' => true, 'message' => 'Availability updated.', 'assigned' => $assigned]);
        exit;
    }

    if ($action === 'presence') {
        $employeeId = ops_current_employee_id();
        if ($employeeId && ops_table_exists('ops_board_presence')) {
            $stmt = db()->prepare(
                "INSERT INTO ops_board_presence (employee_id, page, last_seen_at)
                 VALUES (?, 'orders_board', NOW())
                 ON DUPLICATE KEY UPDATE page = VALUES(page), last_seen_at = VALUES(last_seen_at)"
            );
            $stmt->execute([$employeeId]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'assign') {
        if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can assign orders.');
        }

        $assigned = ops_assign_unassigned_orders();
        echo json_encode(['ok' => true, 'message' => 'Assigned ' . $assigned . ' orders.', 'assigned' => $assigned]);
        exit;
    }

    if ($action === 'sync') {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : null;
        $result = ops_board_sync_website_orders($date);
        echo json_encode([
            'ok' => true,
            'message' => 'Website orders synced.',
            'result' => $result,
        ]);
        exit;
    }

    if ($action === 'status') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status = ops_post_string('status', 40);

        if ($orderId <= 0 || !array_key_exists($status, OPS_ORDER_STATUSES)) {
            throw new RuntimeException('Invalid order status update.');
        }

        $set = 'status = ?, updated_at = CURRENT_TIMESTAMP';
        if ($status === 'in_progress' && ops_column_exists('ops_orders', 'packing_started_at')) {
            $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
        }
        if ($status === 'completed') {
            if (ops_column_exists('ops_orders', 'packing_started_at')) {
                $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
            }
            $set .= ', packed_at = COALESCE(packed_at, NOW()), completed_at = COALESCE(completed_at, NOW())';
        }

        $stmt = db()->prepare('UPDATE ops_orders SET ' . $set . ' WHERE id = ?');
        $stmt->execute([$status, $orderId]);

        echo json_encode(['ok' => true, 'message' => 'Order status updated.']);
        exit;
    }

    if ($action === 'update_field') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $field = ops_post_string('field', 40);
        $value = ops_post_string('value', 1000);

        $allowed = [
            'payment_method' => 'payment_method',
            'order_type' => 'order_type',
            'status' => 'status',
            'payment_status' => 'payment_status',
            'notes' => 'notes',
            'assigned_packer_id' => 'assigned_packer_id',
        ];

        if ($orderId <= 0 || !isset($allowed[$field])) {
            throw new RuntimeException('Invalid order update.');
        }

        if ($field === 'status' && !array_key_exists($value, OPS_ORDER_STATUSES)) {
            throw new RuntimeException('Invalid status.');
        }

        if ($field === 'payment_status' && !in_array($value, ['paid', 'unpaid', 'partial', 'refunded'], true)) {
            throw new RuntimeException('Invalid payment status.');
        }

        if ($field === 'assigned_packer_id') {
            $user = current_user();
            $roleKey = (string) ($user['role_key'] ?? '');
            if (!in_array($roleKey, ['owner_admin', 'front_desk_admin', 'supervisor_manager'], true)) {
                throw new RuntimeException('Only front desk, supervisor or admin can change Packed by.');
            }
            $packerId = $value === '' ? null : (int) $value;
            $assignedAt = ops_column_exists('ops_orders', 'assigned_at') ? ', assigned_at = CASE WHEN ? IS NULL THEN NULL ELSE COALESCE(assigned_at, NOW()) END' : '';
            $stmt = db()->prepare('UPDATE ops_orders SET assigned_packer_id = ?' . $assignedAt . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if ($assignedAt !== '') {
                $stmt->execute([$packerId, $packerId, $orderId]);
            } else {
                $stmt->execute([$packerId, $orderId]);
            }
        } elseif ($field === 'status') {
            $set = 'status = ?, updated_at = CURRENT_TIMESTAMP';
            if ($value === 'in_progress' && ops_column_exists('ops_orders', 'packing_started_at')) {
                $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
            }
            if ($value === 'completed') {
                if (ops_column_exists('ops_orders', 'packing_started_at')) {
                    $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
                }
                $set .= ', packed_at = COALESCE(packed_at, NOW()), completed_at = COALESCE(completed_at, NOW())';
            }
            $stmt = db()->prepare('UPDATE ops_orders SET ' . $set . ' WHERE id = ?');
            $stmt->execute([$value, $orderId]);
        } elseif ($field === 'payment_status') {
            $stmt = db()->prepare('UPDATE ops_orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$value, $orderId]);
            ops_activity_log('payment_status_updated', 'order', $orderId, [
                'payment_status' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        } else {
            $stmt = db()->prepare('UPDATE ops_orders SET ' . $allowed[$field] . ' = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$value, $orderId]);
        }

        echo json_encode(['ok' => true, 'message' => 'Order updated.']);
        exit;
    }

    if ($action === 'bulk_update') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['order_ids'] ?? '')))));
        $field = ops_post_string('field', 40);
        $value = ops_post_string('value', 1000);

        if (!$ids) {
            throw new RuntimeException('No orders selected.');
        }

        if (count($ids) > 200) {
            throw new RuntimeException('Please select 200 orders or fewer at once.');
        }

        $allowed = [
            'payment_status' => 'payment_status',
            'order_type' => 'order_type',
            'payment_method' => 'payment_method',
            'status' => 'status',
            'assigned_packer_id' => 'assigned_packer_id',
        ];

        if (!isset($allowed[$field])) {
            throw new RuntimeException('Invalid bulk update.');
        }

        if ($field === 'assigned_packer_id' && !user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can bulk assign orders.');
        }

        if ($field === 'payment_status' && !in_array($value, ['paid', 'unpaid', 'partial', 'refunded'], true)) {
            throw new RuntimeException('Invalid payment status.');
        }

        if ($field === 'status' && !array_key_exists($value, OPS_ORDER_STATUSES)) {
            throw new RuntimeException('Invalid status.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [];
        $set = $allowed[$field] . ' = ?';
        if ($field === 'assigned_packer_id') {
            $value = $value === '' ? null : (int) $value;
            if (ops_column_exists('ops_orders', 'assigned_at')) {
                $set .= ', assigned_at = CASE WHEN ? IS NULL THEN NULL ELSE COALESCE(assigned_at, NOW()) END';
                $params[] = $value;
            }
        }

        array_unshift($params, $value);
        $params = array_merge($params, $ids);
        $stmt = db()->prepare("UPDATE ops_orders SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
        $stmt->execute($params);
        $changed = $stmt->rowCount();

        foreach ($ids as $id) {
            ops_activity_log('bulk_' . $field . '_updated', 'order', $id, [
                'field' => $field,
                'value' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        }

        echo json_encode(['ok' => true, 'message' => 'Updated ' . $changed . ' selected orders.', 'updated' => $changed]);
        exit;
    }

    if (in_array($action, ['bulk_archive', 'bulk_delete', 'bulk_duplicate'], true)) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['order_ids'] ?? '')))));
        if (!$ids) {
            throw new RuntimeException('No orders selected.');
        }
        if (count($ids) > 200) {
            throw new RuntimeException('Please select 200 orders or fewer at once.');
        }

        $canBulkManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
        $canDelete = user_has_role('owner_admin', 'supervisor_manager');
        if (!$canBulkManage) {
            throw new RuntimeException('You do not have permission to use this bulk action.');
        }
        if ($action === 'bulk_delete' && !$canDelete) {
            throw new RuntimeException('Only owner/admin or supervisor can delete orders.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'bulk_archive') {
            if (!ops_column_exists('ops_orders', 'archived_at')) {
                throw new RuntimeException('Import operations-bulk-actions-migration.sql first.');
            }
            $params = array_merge([ops_current_employee_id()], $ids);
            $stmt = db()->prepare("UPDATE ops_orders SET archived_at = NOW(), archived_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
            $stmt->execute($params);
            foreach ($ids as $id) {
                ops_activity_log('order_archived', 'order', $id, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            }
            echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' selected orders.']);
            exit;
        }

        if ($action === 'bulk_delete') {
            $itemStmt = db()->prepare("DELETE FROM ops_order_items WHERE order_id IN ({$placeholders})");
            $itemStmt->execute($ids);
            $stmt = db()->prepare("DELETE FROM ops_orders WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            echo json_encode(['ok' => true, 'message' => 'Deleted ' . $stmt->rowCount() . ' selected orders.']);
            exit;
        }

        if ($action === 'bulk_duplicate') {
            $orders = ops_rows("SELECT * FROM ops_orders WHERE id IN ({$placeholders})", $ids);
            $created = 0;
            foreach ($orders as $order) {
                $copyNumber = 'COPY-' . date('His') . '-' . (int) $order['id'];
                $stmt = db()->prepare(
                    "INSERT INTO ops_orders
                     (woo_order_id, order_number, customer_name, customer_contact, payment_method, total_amount, product_total, tax_total,
                      shipping_total, shipping_tax_total, discount_total, refund_total, payment_status, order_type, priority, complexity,
                      assigned_packer_id, assigned_at, assigned_verifier_id, status, notes, workload_score, created_by, created_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, 'new_order', ?, ?, ?, NOW())"
                );
                $stmt->execute([
                    $copyNumber,
                    (string) $order['customer_name'],
                    (string) ($order['customer_contact'] ?? ''),
                    (string) ($order['payment_method'] ?? ''),
                    (float) ($order['total_amount'] ?? 0),
                    (float) ($order['product_total'] ?? 0),
                    (float) ($order['tax_total'] ?? 0),
                    (float) ($order['shipping_total'] ?? 0),
                    (float) ($order['shipping_tax_total'] ?? 0),
                    (float) ($order['discount_total'] ?? 0),
                    (float) ($order['refund_total'] ?? 0),
                    (string) ($order['payment_status'] ?? 'unpaid'),
                    (string) ($order['order_type'] ?? 'collection'),
                    (string) ($order['priority'] ?? 'normal'),
                    (int) ($order['complexity'] ?? 1),
                    $order['assigned_verifier_id'] ?? null,
                    trim("Duplicated from {$order['order_number']}\n" . (string) ($order['notes'] ?? '')),
                    (float) ($order['workload_score'] ?? 0),
                    ops_current_employee_id(),
                ]);
                $newId = (int) db()->lastInsertId();
                $items = ops_rows('SELECT * FROM ops_order_items WHERE order_id = ?', [(int) $order['id']]);
                foreach ($items as $item) {
                    $itemStmt = db()->prepare(
                        "INSERT INTO ops_order_items
                         (order_id, woo_order_line_id, woo_product_id, woo_variation_id, product_id, product_name, sku, barcode, quantity, packed_quantity, status, packed_by, packed_at)
                         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', NULL, NULL)"
                    );
                    $itemStmt->execute([
                        $newId,
                        $item['woo_product_id'] ?? null,
                        $item['woo_variation_id'] ?? null,
                        $item['product_id'] ?? null,
                        (string) $item['product_name'],
                        $item['sku'] ?? null,
                        $item['barcode'] ?? null,
                        (float) ($item['quantity'] ?? 1),
                    ]);
                }
                $created++;
            }
            echo json_encode(['ok' => true, 'message' => 'Duplicated ' . $created . ' selected orders.']);
            exit;
        }
    }

    throw new RuntimeException('Unknown board action.');
} catch (Throwable $e) {
    http_response_code(400);
    ops_board_sync_log('board action failed', [
        'action' => $action ?? 'unknown',
        'error' => $e->getMessage(),
    ]);

    $raw = $e->getMessage();
    $message = (stripos($raw, 'SQLSTATE') !== false || stripos($raw, 'General error') !== false)
        ? 'Order sync could not finish. The technical details were logged in storage/logs/operations-sync.log.'
        : $raw;

    echo json_encode(['ok' => false, 'message' => $message]);
}
