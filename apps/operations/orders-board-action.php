<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';
require_once BASE_PATH . '/shared/board-columns.php';

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

function ops_board_sync_runtime_dir(): string
{
    $dir = BASE_PATH . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return is_dir($dir) ? $dir : BASE_PATH . '/storage/logs';
}

function ops_board_recent_sync_result(?string $date, int $maxAgeSeconds): ?array
{
    $key = preg_replace('/[^a-z0-9_-]+/i', '_', $date ?: 'all');
    $path = ops_board_sync_runtime_dir() . '/orders-board-sync-' . $key . '.json';
    if (!is_file($path) || (time() - (int) @filemtime($path)) > $maxAgeSeconds) {
        return null;
    }

    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function ops_board_run_guarded_sync(?string $date, bool $force = false): array
{
    $minAge = $force ? 15 : 45;
    $recent = ops_board_recent_sync_result($date, $minAge);
    if ($recent) {
        $recent['skipped'] = true;
        $recent['skip_reason'] = 'recent_sync';
        return $recent;
    }

    $dir = ops_board_sync_runtime_dir();
    $key = preg_replace('/[^a-z0-9_-]+/i', '_', $date ?: 'all');
    $lock = @fopen($dir . '/orders-board-sync-' . $key . '.lock', 'c');
    if (!$lock) {
        return ops_board_sync_website_orders($date);
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        $recent = ops_board_recent_sync_result($date, 300);
        if ($recent) {
            $recent['skipped'] = true;
            $recent['skip_reason'] = 'sync_in_progress';
            fclose($lock);
            return $recent;
        }

        fclose($lock);
        return [
            'imported' => 0,
            'updated' => 0,
            'lines' => 0,
            'assigned' => 0,
            'requested_date' => $date,
            'website_orders_seen' => 0,
            'warnings' => ['Website sync already running.'],
            'skipped' => true,
            'skip_reason' => 'sync_in_progress',
        ];
    }

    try {
        $result = ops_board_sync_website_orders($date);
        $result['synced_at'] = date('Y-m-d H:i:s');
        @file_put_contents($dir . '/orders-board-sync-' . $key . '.json', json_encode($result, JSON_UNESCAPED_SLASHES));
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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

function ops_board_label_store_path(): string
{
    $dir = BASE_PATH . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/orders-board-labels.json';
}

function ops_board_default_mode_labels(): array
{
    return [
        ['collection', 'Collection', '#d49382'],
        ['delivery', 'Delivery', '#bca98d'],
        ['courier', 'Courier', '#895749'],
        ['western_courier', 'western courier', '#008456'],
        ['coastal_courier', 'coastal courier', '#579bfc'],
        ['easy_parcel', 'Easy Parcel', '#ff007f'],
        ['hardap_courier', 'hardap courier', '#cab641'],
        ['seven_seaters', '7 seaters', '#ffc400'],
        ['yango', 'Yango', '#0b88b4'],
        ['jet_x', 'Jet X', '#333333'],
        ['formula_courier', 'Formula courier', '#a648d9'],
        ['express_courier', 'express courier', '#c03456'],
    ];
}

function ops_board_default_payment_labels(): array
{
    return [
        ['Cash', '#bdbdbd'],
        ['EFT', '#7b4bd3'],
        ['Ewallet', '#9b95b9'],
        ['Bluewallet', '#00845f'],
        ['Swipe', '#333333'],
        ['Pay2Cell', '#c03456'],
        ['EFT & Cash', '#3d1784'],
        ['Ewallet & Cash', '#2b5797'],
        ['Swipe & Ewallet', '#ffc400'],
        ['Bluewallet & Swipe', '#ed4aa5'],
        ['Coupon', '#57413d'],
        ['DPO', '#0876d8'],
        ['EasyWallet', '#a648d9'],
        ['Nedbank', '#07c66b'],
        ['Post Pay', '#4dc3bd'],
    ];
}

function ops_board_default_status_labels(): array
{
    return [
        ['new_order', 'NEW ORDER', '#bdbdbd'],
        ['assigned', 'NEW ORDER', '#bdbdbd'],
        ['in_progress', 'IN PROGRESS', '#fdab3d'],
        ['completed', 'COMPLETE', '#e2445c'],
    ];
}

function ops_board_default_label_options(string $field): array
{
    if ($field === 'payment_method') {
        return ops_board_default_payment_labels();
    }
    if ($field === 'status') {
        return ops_board_default_status_labels();
    }

    return ops_board_default_mode_labels();
}

function ops_board_label_options(): array
{
    $labels = [
        'order_type' => ops_board_default_mode_labels(),
        'payment_method' => ops_board_default_payment_labels(),
        'status' => ops_board_default_status_labels(),
    ];
    $path = ops_board_label_store_path();
    if (is_file($path)) {
        $saved = json_decode((string) @file_get_contents($path), true);
        if (is_array($saved)) {
            $labels = array_replace($labels, $saved);
        }
    }

    return $labels;
}

function ops_board_normalize_label_options(array $labels, string $field = 'order_type'): array
{
    $clean = [];
    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        if ($field === 'payment_method') {
            $name = trim((string) ($label[0] ?? ''));
            $colour = trim((string) ($label[1] ?? '#579bfc'));
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
                $colour = '#579bfc';
            }
            $clean[] = [$name, $colour];
            continue;
        }

        $id = trim((string) ($label[0] ?? ''));
        $name = trim((string) ($label[1] ?? ''));
        $colour = trim((string) ($label[2] ?? '#579bfc'));
        if ($name === '') {
            continue;
        }
        if ($id === '') {
            $id = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name));
            $id = trim($id, '_') ?: 'label_' . count($clean);
        }
        if (!preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
            $colour = '#579bfc';
        }
        if ($field === 'status' && !array_key_exists($id, OPS_ORDER_STATUSES)) {
            continue;
        }
        $clean[] = [$id, $name, $colour];
    }

    return $clean ?: ops_board_default_label_options($field);
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
    $displayDateTimeColumn = ops_order_display_datetime_update_column();
    $displayDateTimeInsertColumn = $displayDateTimeColumn !== 'created_at' ? ', ' . $displayDateTimeColumn : '';
    $displayDateTimeInsertValue = $displayDateTimeColumn !== 'created_at' ? ', ?' : '';
    $displayDateTimeUpdate = $displayDateTimeColumn . ' = VALUES(' . $displayDateTimeColumn . ')';
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
                    order_type, priority, complexity, assigned_packer_id, status, notes, workload_score, created_at{$displayDateTimeInsertColumn}
                 ) VALUES (?, ?, ?, ?, ?, ?{$breakdownValues}, ?, ?, ?, ?, ?, ?, ?, ?, ?{$displayDateTimeInsertValue})
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
                    {$displayDateTimeUpdate},
                    updated_at = CURRENT_TIMESTAMP"
            );
        } else {
            $orderStmt = $pdo->prepare(
                "INSERT INTO ops_orders (
                    woo_order_id, order_number, customer_name, customer_contact, payment_method, payment_status,
                    order_type, priority, complexity, assigned_packer_id, status, notes, workload_score, created_at{$displayDateTimeInsertColumn}
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$displayDateTimeInsertValue})
                 ON DUPLICATE KEY UPDATE
                    customer_name = VALUES(customer_name),
                    customer_contact = VALUES(customer_contact),
                    payment_method = VALUES(payment_method),
                    payment_status = VALUES(payment_status),
                    order_type = VALUES(order_type),
                    notes = VALUES(notes),
                    workload_score = VALUES(workload_score),
                    {$displayDateTimeUpdate},
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
            if ($displayDateTimeColumn !== 'created_at') {
                $orderValues[] = $createdAt;
            }

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

            if ($affected === 1) {
                ops_log_order_stage_event($orderId, 'order_received', [
                    'source' => 'orders_board_woocommerce_sync',
                    'woo_order_id' => $wooOrderId,
                    'order_number' => $orderNumber,
                ]);
            }

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

    if ($action === 'list_custom_columns') {
        echo json_encode(['ok' => true, 'columns' => board_columns_list('orders')]);
        exit;
    }

    if ($action === 'save_custom_column') {
        $column = board_columns_save(
            'orders',
            ops_post_string('col_key', 100),
            ops_post_string('col_name', 100),
            ops_post_string('col_type', 50),
            (int) (current_user()['id'] ?? 0)
        );
        echo json_encode(['ok' => true, 'column' => $column]);
        exit;
    }

    if ($action === 'list_label_options') {
        echo json_encode(['ok' => true, 'labels' => ops_board_label_options()]);
        exit;
    }

    if ($action === 'save_label_options') {
        $field = ops_post_string('field', 50);
        if (!in_array($field, ['order_type', 'payment_method', 'status'], true)) {
            throw new RuntimeException('Unsupported label field.');
        }
        $raw = (string) ($_POST['labels'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid label data.');
        }
        $allLabels = ops_board_label_options();
        $allLabels[$field] = ops_board_normalize_label_options($decoded, $field);
        @file_put_contents(ops_board_label_store_path(), json_encode($allLabels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'labels' => $allLabels[$field]]);
        exit;
    }

    if ($action === 'availability') {
        if (!ops_table_exists('ops_employee_availability')) {
            throw new RuntimeException('Availability table is missing. Import operations-live-board-migration.sql first.');
        }

        try {
            db()->exec(
                "CREATE TABLE IF NOT EXISTS ops_employee_availability_history (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT NOT NULL,
                    availability_status VARCHAR(40) NOT NULL,
                    unavailable_until DATETIME NULL,
                    note VARCHAR(255) NULL,
                    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_availability_history_employee (employee_id, changed_at),
                    INDEX idx_availability_history_status (availability_status, changed_at)
                )"
            );
        } catch (Throwable $e) {
            // History is useful for KPI reports but should not block live availability.
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

        if (ops_table_exists('ops_employee_availability_history')) {
            $stmt = db()->prepare(
                "INSERT INTO ops_employee_availability_history (employee_id, availability_status, unavailable_until, note)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$employeeId, $status, $until, ops_post_string('note', 255)]);
        }

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

    if ($action === 'set_in_progress') {
        if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager', 'packer')) {
            throw new RuntimeException('You do not have permission to start this order.');
        }

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $packerName = trim(ops_post_string('packer_name', 160));
        if ($orderId <= 0 || $packerName === '') {
            throw new RuntimeException('Choose an order and a packer.');
        }

        $nameLike = '%' . strtolower($packerName) . '%';
        $employeeRows = ops_rows(
            "SELECT id, full_name
             FROM ops_employees
             WHERE status = 'active'
               AND (LOWER(full_name) = LOWER(?) OR LOWER(full_name) LIKE ?)
             ORDER BY CASE WHEN LOWER(full_name) = LOWER(?) THEN 0 ELSE 1 END, id
             LIMIT 1",
            [$packerName, $nameLike, $packerName]
        );
        $packer = $employeeRows[0] ?? null;
        if (!$packer) {
            throw new RuntimeException('Could not find that employee profile.');
        }

        $packerId = (int) $packer['id'];
        $set = 'assigned_packer_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP';
        if (ops_column_exists('ops_orders', 'assigned_at')) {
            $set .= ', assigned_at = COALESCE(assigned_at, NOW())';
        }
        if (ops_column_exists('ops_orders', 'packing_started_at')) {
            $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
        }
        $stmt = db()->prepare('UPDATE ops_orders SET ' . $set . ' WHERE id = ?');
        $stmt->execute([$packerId, 'in_progress', $orderId]);

        ops_log_order_stage_event($orderId, 'in_progress', [
            'source' => 'packer_kpi_modal',
            'assigned_packer_id' => $packerId,
            'assigned_packer_name' => (string) ($packer['full_name'] ?? $packerName),
        ], $packerId);

        notifications_notify_order_assigned($orderId, $packerId);

        echo json_encode([
            'ok' => true,
            'message' => 'Order moved to In Progress.',
            'order_id' => $orderId,
            'assigned_packer_id' => $packerId,
        ]);
        exit;
    }

    if ($action === 'sync') {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : null;
        $force = (string) ($_POST['force'] ?? '') === '1';
        $result = ops_board_run_guarded_sync($date, $force);
        if ((int) ($result['imported'] ?? 0) > 0) {
            notifications_create_for_roles([
                'title' => 'New website orders synced',
                'message' => (int) $result['imported'] . ' new order(s) were imported from the website.',
                'module' => 'operations',
                'priority' => 'normal',
                'related_type' => 'order_sync',
                'related_id' => null,
                'action_link' => BASE_URL . '/apps/operations/orders-board.php',
            ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
        }
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
        ops_log_order_stage_event($orderId, $status, [
            'source' => 'orders_board_status',
            'status' => $status,
        ]);
        if ($status === 'completed') {
            $order = notifications_order_summary($orderId);
            notifications_create_for_roles([
                'title' => 'Order completed',
                'message' => ((string) ($order['order_number'] ?? ('Order #' . $orderId))) . ' was marked complete.',
                'module' => 'operations',
                'priority' => 'info',
                'related_type' => 'order',
                'related_id' => $orderId,
                'action_link' => BASE_URL . '/apps/operations/orders-board.php?order_id=' . $orderId,
            ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
        } elseif ($status === 'correction_required') {
            $order = notifications_order_summary($orderId);
            notifications_create([
                'title' => 'Order needs correction',
                'message' => ((string) ($order['order_number'] ?? ('Order #' . $orderId))) . ' needs correction.',
                'module' => 'operations',
                'priority' => 'important',
                'related_type' => 'order',
                'related_id' => $orderId,
                'action_link' => BASE_URL . '/apps/operations/orders-board.php?order_id=' . $orderId,
            ], [(int) ($order['assigned_packer_id'] ?? 0)]);
        }

        echo json_encode(['ok' => true, 'message' => 'Order status updated.']);
        exit;
    }

    if ($action === 'update_group_date') {
        $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['from_date'] ?? '')) ? (string) $_POST['from_date'] : '';
        $toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['to_date'] ?? '')) ? (string) $_POST['to_date'] : '';
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['order_ids'] ?? '')))));

        if ($fromDate === '' || $toDate === '' || !$ids) {
            throw new RuntimeException('Invalid group date update.');
        }

        if (count($ids) > 500) {
            throw new RuntimeException('Please update 500 orders or fewer at once.');
        }

        if ($fromDate === $toDate) {
            echo json_encode(['ok' => true, 'message' => 'Group date unchanged.', 'updated' => 0]);
            exit;
        }

        if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can change a group date.');
        }

        $displayDateTimeColumn = ops_order_display_datetime_update_column();
        $displayDateTimeExpr = ops_order_display_datetime_expr();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$toDate], $ids, [$fromDate]);
        $stmt = db()->prepare("UPDATE ops_orders SET {$displayDateTimeColumn} = CONCAT(?, ' ', TIME({$displayDateTimeExpr})), updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND DATE({$displayDateTimeExpr}) = ?");
        $stmt->execute($params);
        $changed = $stmt->rowCount();

        if ($changed <= 0) {
            throw new RuntimeException('No orders were updated. Refresh and try again.');
        }

        foreach ($ids as $id) {
            ops_activity_log('group_date_updated', 'order', $id, [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        }

        echo json_encode(['ok' => true, 'message' => 'Group date updated.', 'updated' => $changed, 'from_date' => $fromDate, 'to_date' => $toDate]);
        exit;
    }

    if ($action === 'update_order_datetime') {
        if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can change an order date/time.');
        }

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $rawDateTime = trim((string) ($_POST['date_time'] ?? ''));
        if ($orderId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $rawDateTime)) {
            throw new RuntimeException('Invalid order date/time.');
        }

        $timestamp = strtotime($rawDateTime);
        if ($timestamp === false) {
            throw new RuntimeException('Invalid order date/time.');
        }

        $dateTime = date('Y-m-d H:i:s', $timestamp);
        $existsStmt = db()->prepare('SELECT id FROM ops_orders WHERE id = ? LIMIT 1');
        $existsStmt->execute([$orderId]);
        if (!$existsStmt->fetchColumn()) {
            throw new RuntimeException('Order not found. Refresh and try again.');
        }

        $displayDateTimeColumn = ops_order_display_datetime_update_column();
        $stmt = db()->prepare("UPDATE ops_orders SET {$displayDateTimeColumn} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$dateTime, $orderId]);

        ops_activity_log('order_datetime_updated', 'order', $orderId, [
            'date_time' => $dateTime,
            'changed_by' => current_user()['name'] ?? 'Unknown',
        ]);

        echo json_encode(['ok' => true, 'message' => 'Order date/time updated.', 'order_id' => $orderId, 'date_time' => $dateTime]);
        exit;
    }

    if ($action === 'update_field') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $field = ops_post_string('field', 40);
        $value = ops_post_string('value', 1000);

        $allowed = [
            'customer_name' => 'customer_name',
            'customer_contact' => 'customer_contact',
            'payment_method' => 'payment_method',
            'order_type' => 'order_type',
            'total_amount' => 'total_amount',
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

        if ($field === 'total_amount') {
            $value = preg_replace('/[^\d.]/', '', $value) ?? '';
            if ($value === '' || !is_numeric($value)) {
                throw new RuntimeException('Enter a valid amount.');
            }
            $value = number_format((float) $value, 2, '.', '');
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
            if ($packerId) {
                ops_log_order_stage_event($orderId, 'assigned', [
                    'source' => 'orders_board_field',
                    'assigned_packer_id' => $packerId,
                ]);
            }
            notifications_notify_order_assigned($orderId, $packerId);
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
            ops_log_order_stage_event($orderId, $value, [
                'source' => 'orders_board_field',
                'status' => $value,
            ]);
            if ($value === 'completed') {
                $order = notifications_order_summary($orderId);
                notifications_create_for_roles([
                    'title' => 'Order completed',
                    'message' => ((string) ($order['order_number'] ?? ('Order #' . $orderId))) . ' was marked complete.',
                    'module' => 'operations',
                    'priority' => 'info',
                    'related_type' => 'order',
                    'related_id' => $orderId,
                    'action_link' => BASE_URL . '/apps/operations/orders-board.php?order_id=' . $orderId,
                ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
            }
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
            if ($field === 'status') {
                ops_log_order_stage_event($id, (string) $value, [
                    'source' => 'orders_board_bulk',
                    'status' => $value,
                ]);
            } elseif ($field === 'assigned_packer_id' && $value) {
                ops_log_order_stage_event($id, 'assigned', [
                    'source' => 'orders_board_bulk',
                    'assigned_packer_id' => $value,
                ]);
            }
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
