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

function ops_board_normalise_walk_in_marker(?string $value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[\s\-_]+/', ' ', $value) ?? '';

    return trim($value);
}

function ops_board_initial_payment_status(?string $sourceMobile): string
{
    return ops_board_normalise_walk_in_marker($sourceMobile) === 'walk in customer'
        ? 'paid'
        : 'unpaid';
}

function ops_board_order_type(array $order): string
{
    return ops_pos_fulfilment_from_order($order)['mode'];
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

function ops_board_ensure_tools_columns(): void
{
    $columns = [
        'archived_at' => 'DATETIME NULL',
        'archived_by' => 'INT NULL',
        'archive_reason' => 'VARCHAR(255) NULL',
        'deleted_at' => 'DATETIME NULL',
        'deleted_by' => 'INT NULL',
        'delete_reason' => 'VARCHAR(255) NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (ops_column_exists('ops_orders', $name)) {
            continue;
        }
        db()->exec("ALTER TABLE ops_orders ADD COLUMN {$name} {$definition}");
    }
}

function ops_board_tools_ids(string $field = 'order_ids'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST[$field] ?? ''))))));
    if (!$ids || count($ids) > 200) {
        throw new RuntimeException($ids ? 'Please select 200 orders or fewer at once.' : 'No orders selected.');
    }
    return $ids;
}

function ops_board_tools_can_manage(): bool
{
    return user_has_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff');
}

function ops_board_can_move_to_trash(): bool
{
    return user_has_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff');
}

function ops_board_label_store_path(): string
{
    $dir = BASE_PATH . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/orders-board-labels.json';
}

function ops_board_column_label_store_path(): string
{
    $dir = BASE_PATH . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/orders-board-column-labels.json';
}

function ops_board_default_column_labels(): array
{
    return [
        'task' => 'Task',
        'updates' => '',
        'date' => 'DATE',
        'mobile' => 'Mobile number',
        'mode' => 'Mode',
        'amount' => 'AMOUNT',
        'payment' => 'PAYMENT',
        'paid' => 'PAID',
        'status' => 'Status',
        'packer' => 'Packed by',
        'text' => 'Text',
    ];
}

function ops_board_column_labels(): array
{
    $labels = ops_board_default_column_labels();
    $path = ops_board_column_label_store_path();
    if (is_file($path)) {
        $stored = json_decode((string) @file_get_contents($path), true);
        if (is_array($stored)) {
            foreach ($labels as $key => $default) {
                if ($key === 'updates') {
                    continue;
                }
                $value = trim((string) ($stored[$key] ?? ''));
                if ($value !== '') {
                    $labels[$key] = substr($value, 0, 80);
                }
            }
        }
    }

    return $labels;
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
        ['Swipe', '#333333'],
        ['EFT', '#7b4bd3'],
        ['FNB eWallet', '#1b5e20'],
        ['EasyWallet', '#a648d9'],
        ['Blue Wallet', '#00845f'],
        ['Nedbank', '#07c66b'],
        ['NetBank Wallet', '#2b5797'],
        ['Pay2Cell', '#c03456'],
        ['PayToday', '#4dc3bd'],
        ['DPO', '#2563EB'],
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
            // Payment methods are controlled by the website/POS. Do not let
            // stale locally edited labels drift away from those methods.
            unset($saved['payment_method']);
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
    ops_ensure_order_payment_schema();

    $baseQuery = [
        'per_page' => 100,
        'orderby' => 'date',
        'order' => 'desc',
    ];

    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        // WooCommerce compares these values in UTC. Build the recovery window in
        // the portal's business timezone first, then convert it explicitly.
        $windhoek = new DateTimeZone('Africa/Windhoek');
        $utc = new DateTimeZone('UTC');
        $windowStart = (new DateTimeImmutable($date . ' 00:00:00', $windhoek))
            ->modify('-1 day')
            ->setTimezone($utc);
        $windowEnd = (new DateTimeImmutable($date . ' 00:00:00', $windhoek))
            ->modify('+2 days')
            ->setTimezone($utc);
        $baseQuery['after'] = $windowStart->format('Y-m-d\TH:i:s\Z');
        $baseQuery['before'] = $windowEnd->format('Y-m-d\TH:i:s\Z');
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

    ops_board_sync_log('woocommerce orders fetched', [
        'count' => count($orders),
        'after' => $baseQuery['after'] ?? null,
        'before' => $baseQuery['before'] ?? null,
        'warnings' => array_slice(array_unique($syncWarnings), 0, 3),
    ]);

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
                    payment_method = CASE WHEN VALUES(payment_method) <> '' THEN VALUES(payment_method) ELSE payment_method END,
                    total_amount = VALUES(total_amount),
                    {$breakdownUpdates}
                    order_type = VALUES(order_type),
                    notes = VALUES(notes),
                    workload_score = VALUES(workload_score),
                    {$displayDateTimeUpdate}"
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
                    payment_method = CASE WHEN VALUES(payment_method) <> '' THEN VALUES(payment_method) ELSE payment_method END,
                    order_type = VALUES(order_type),
                    notes = VALUES(notes),
                    workload_score = VALUES(workload_score),
                    {$displayDateTimeUpdate}"
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
            $customerName = ops_board_customer_name($order);
            $customerContact = (string) (($order['billing']['phone'] ?? '') ?: ($order['billing']['email'] ?? ''));
            $packerId = ops_initial_order_packer_id($customerName, $customerContact, $orderType);
            $createdAt = date('Y-m-d H:i:s', strtotime((string) ($order['date_created'] ?? 'now')));
            $orderNumber = 'WEB-' . (string) ($order['number'] ?? $wooOrderId);

            $orderValues = [
                $wooOrderId,
                $orderNumber,
                $customerName,
                $customerContact,
                ops_wc_payment_method($order),
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
                    ops_board_initial_payment_status($customerContact),
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
            $paymentAllocations = ops_wc_payment_allocations($order);
            if ($paymentAllocations) {
                $paymentVersion = (string) (($order['date_modified_gmt'] ?? '') ?: ($order['date_modified'] ?? '') ?: date(DATE_ATOM));
                ops_sync_order_payment_allocations($orderId, $paymentAllocations, ops_wc_payment_source($order), $paymentVersion);
            }
            if (ops_column_exists('ops_orders', 'fulfilment_mode')) {
                $pdo->prepare('UPDATE ops_orders SET fulfilment_mode = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$orderType, $orderId]);
            }

            if ($affected === 1) {
                ops_log_order_stage_event($orderId, 'order_received', [
                    'source' => 'orders_board_woocommerce_sync',
                    'woo_order_id' => $wooOrderId,
                    'order_number' => $orderNumber,
                ]);
                ops_log_initial_order_assignment($orderId, $packerId, 'orders_board_woocommerce_sync');
                if (ops_board_initial_payment_status($customerContact) === 'paid') {
                    ops_activity_log('payment_status_auto_walk_in', 'order', $orderId, [
                        'previous_value' => 'unpaid',
                        'new_value' => 'paid',
                        'source' => 'first_import_walk_in_customer',
                        'message' => 'Paid automatically marked for Walk-in Customer order.',
                    ]);
                }
                if ($packerId) {
                    $assigned++;
                }
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

    ops_board_sync_log('sync finished', [
        'seen' => count($orders),
        'imported' => $imported,
        'updated' => $updated,
        'lines' => $lineCount,
        'walk_in_assigned' => $assigned,
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

    if ($action === 'save_payment_allocations') {
        if (!ops_can_update_order_payment_method()) throw new RuntimeException('You do not have permission to edit order payments.');
        $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
        $sessionCsrf = (string) ($_SESSION['orders_csrf_token'] ?? '');
        if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
            http_response_code(419);
            throw new RuntimeException('Your session token is invalid. Refresh Orders and try again.');
        }
        if (!ops_ensure_order_payment_schema()) throw new RuntimeException('Payment allocation storage is unavailable.');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = $orderId > 0 ? ops_row('SELECT id, woo_order_id, total_amount FROM ops_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$orderId]) : null;
        if (!$order) throw new RuntimeException('Order not found.');
        $rawAllocations = json_decode((string) ($_POST['payments'] ?? '[]'), true);
        if (!is_array($rawAllocations) || !$rawAllocations) throw new RuntimeException('Add at least one payment method.');
        $allocations = [];
        $seen = [];
        foreach ($rawAllocations as $rawAllocation) {
            if (!is_array($rawAllocation)) throw new RuntimeException('Invalid payment allocation.');
            $method = ops_normalize_payment_code((string) ($rawAllocation['method'] ?? ''));
            $amountCents = filter_var($rawAllocation['amount_cents'] ?? null, FILTER_VALIDATE_INT);
            if ($method === '' || !array_key_exists($method, ops_payment_method_map())) throw new RuntimeException('Choose a supported payment method.');
            if (isset($seen[$method])) throw new RuntimeException('A payment method cannot be selected twice.');
            if ($amountCents === false || $amountCents <= 0) throw new RuntimeException('Every payment amount must be greater than zero.');
            $seen[$method] = true;
            $allocations[] = ['method'=>$method, 'amount_cents'=>(int) $amountCents, 'transaction_reference'=>substr(trim((string) ($rawAllocation['transaction_reference'] ?? '')), 0, 190)];
        }
        if (count($allocations) > 1 && count($seen) < 2) throw new RuntimeException('A split payment requires two different methods.');
        $totalCents = (int) round((float) $order['total_amount'] * 100);
        $collectedCents = array_sum(array_column($allocations, 'amount_cents'));
        if ($collectedCents > $totalCents) throw new RuntimeException('Collected payment cannot exceed the order total. Correct the allocations before saving.');
        $submittedVersion = trim((string) ($_POST['version'] ?? ''));
        $currentAllocations = ops_order_payment_allocations($orderId);
        $currentVersion = $currentAllocations ? (string) ($currentAllocations[0]['version'] ?? '') : '';
        if ($currentVersion !== '' && $submittedVersion !== $currentVersion) {
            http_response_code(409);
            throw new RuntimeException('This payment was updated elsewhere. Reload the latest payment details before saving.');
        }
        $version = gmdate('Y-m-d\TH:i:s\Z') . '-' . bin2hex(random_bytes(4));
        db()->beginTransaction();
        try {
            if ((int) ($order['woo_order_id'] ?? 0) > 0) {
                $primary = $allocations[0]['method'];
                $posSplit = array_map(static fn(array $allocation): array => [
                    'method' => $allocation['method'],
                    'amount' => number_format((int) $allocation['amount_cents'] / 100, 2, '.', ''),
                ], $allocations);
                wc_put('orders/' . (int) $order['woo_order_id'], [
                    'payment_method' => $primary,
                    'payment_method_title' => count($allocations) > 1 ? 'Split Payment' : ops_payment_label($primary),
                    'meta_data' => [
                        ['key'=>'_hpos_split', 'value'=>json_encode(count($allocations) > 1 ? $posSplit : [], JSON_UNESCAPED_SLASHES)],
                        ['key'=>'_hpos_payment_allocations', 'value'=>json_encode($allocations, JSON_UNESCAPED_SLASHES)],
                        ['key'=>'_hpos_payment_source', 'value'=>'order_list'],
                        ['key'=>'_hpos_payment_version', 'value'=>$version],
                        ['key'=>'_hpos_payment_updated_by_employee_id', 'value'=>ops_current_employee_id()],
                    ],
                ]);
            }
            ops_replace_order_payment_allocations($orderId, $allocations, 'order_list', $version, ops_current_employee_id());
            db()->commit();
        } catch (Throwable $paymentError) {
            if (db()->inTransaction()) db()->rollBack();
            throw $paymentError;
        }
        $saved = ops_order_payment_allocations($orderId);
        $savedOrder = ops_row('SELECT payment_method, payment_status, updated_at FROM ops_orders WHERE id = ? LIMIT 1', [$orderId]);
        echo json_encode(['ok'=>true, 'message'=>'Payment saved.', 'payments'=>$saved, 'payment_method'=>$savedOrder['payment_method'], 'payment_status'=>$savedOrder['payment_status'], 'total_cents'=>$totalCents, 'collected_cents'=>$collectedCents, 'due_cents'=>max(0, $totalCents-$collectedCents), 'paid'=>$collectedCents >= $totalCents, 'source'=>'order_list', 'version'=>$version, 'updated_at'=>$savedOrder['updated_at']], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (in_array($action, ['orders_tools_data', 'trash_orders', 'restore_trashed_orders', 'archive_orders', 'restore_archived_orders', 'delete_orders_forever'], true)) {
        ops_board_ensure_tools_columns();
    }

    if ($action === 'orders_tools_data') {
        if (!ops_board_tools_can_manage()) {
            throw new RuntimeException('You do not have permission to open Orders tools.');
        }
        $trash = ops_rows(
            "SELECT o.id, o.order_number, o.customer_name, o.total_amount, o.status, o.deleted_at, o.delete_reason,
                    e.full_name AS deleted_by_name, o.woo_order_id
             FROM ops_orders o LEFT JOIN ops_employees e ON e.id = o.deleted_by
             WHERE o.deleted_at IS NOT NULL ORDER BY o.deleted_at DESC LIMIT 250"
        );
        $archived = ops_rows(
            "SELECT o.id, o.order_number, o.customer_name, o.total_amount, o.status, o.archived_at, o.archive_reason,
                    e.full_name AS archived_by_name, o.woo_order_id
             FROM ops_orders o LEFT JOIN ops_employees e ON e.id = o.archived_by
             WHERE o.archived_at IS NOT NULL AND o.deleted_at IS NULL ORDER BY o.archived_at DESC LIMIT 250"
        );
        $activity = ops_table_exists('ops_activity_logs') ? ops_rows(
            "SELECT al.id, al.entity_id AS order_id, al.action, al.metadata, al.created_at,
                    e.full_name AS actor_name, r.name AS actor_role, o.order_number, o.customer_name
             FROM ops_activity_logs al
             LEFT JOIN ops_employees e ON e.id = al.employee_id
             LEFT JOIN ops_roles r ON r.id = e.role_id
             LEFT JOIN ops_orders o ON o.id = al.entity_id
             WHERE al.entity_type = 'order'
             ORDER BY al.created_at DESC, al.id DESC LIMIT 250"
        ) : [];
        echo json_encode([
            'ok' => true,
            'trash' => $trash,
            'archived' => $archived,
            'activity' => $activity,
            'permissions' => [
                'can_manage' => true,
                'can_delete_forever' => user_has_role('owner_admin'),
            ],
        ]);
        exit;
    }

    if (in_array($action, ['trash_orders', 'restore_trashed_orders', 'archive_orders', 'restore_archived_orders', 'delete_orders_forever'], true)) {
        if (!ops_board_tools_can_manage()) {
            throw new RuntimeException('You do not have permission to manage Orders records.');
        }
        $ids = ops_board_tools_ids();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $actorId = ops_current_employee_id();
        $actorName = current_user()['name'] ?? 'Unknown';

        if ($action === 'trash_orders') {
            if (!ops_board_can_move_to_trash()) throw new RuntimeException('You do not have permission to move orders to Trash.');
            $reason = trim(ops_post_string('reason', 255)) ?: 'Moved from Orders Board';
            db()->beginTransaction();
            try {
                $records = ops_rows("SELECT id, assigned_packer_id FROM ops_orders WHERE id IN ({$placeholders}) AND deleted_at IS NULL FOR UPDATE", $ids);
                if (count($records) !== count($ids)) throw new RuntimeException('One or more selected orders are unavailable or already in Trash.');
                if (user_has_role('packer', 'packer_production_staff')) {
                    $employeeId = ops_current_employee_id();
                    foreach ($records as $record) if ((int) ($record['assigned_packer_id'] ?? 0) !== $employeeId) throw new RuntimeException('Packers may move only orders assigned to them to Trash.');
                }
                $stmt = db()->prepare("UPDATE ops_orders SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute(array_merge([$actorId, $reason], $ids));
                if ($stmt->rowCount() !== count($ids)) throw new RuntimeException('Not every selected order could be moved to Trash.');
                foreach ($ids as $id) ops_activity_log('order_moved_to_trash', 'order', $id, ['reason' => $reason, 'changed_by' => $actorName]);
                db()->commit();
            } catch (Throwable $trashError) {
                if (db()->inTransaction()) db()->rollBack();
                throw $trashError;
            }
            echo json_encode(['ok' => true, 'success' => true, 'message' => count($ids) . ' order(s) moved to Trash.', 'trashedIds' => $ids]);
            exit;
        }
        if ($action === 'restore_trashed_orders') {
            $stmt = db()->prepare("UPDATE ops_orders SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL");
            $stmt->execute($ids);
            foreach ($ids as $id) ops_activity_log('order_restored_from_trash', 'order', $id, ['changed_by' => $actorName]);
            echo json_encode(['ok' => true, 'message' => 'Restored ' . $stmt->rowCount() . ' order(s).']);
            exit;
        }
        if ($action === 'archive_orders') {
            $reason = trim(ops_post_string('reason', 255)) ?: 'Archived from Orders Board';
            $stmt = db()->prepare("UPDATE ops_orders SET archived_at = NOW(), archived_by = ?, archive_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND archived_at IS NULL AND deleted_at IS NULL");
            $stmt->execute(array_merge([$actorId, $reason], $ids));
            foreach ($ids as $id) ops_activity_log('order_archived', 'order', $id, ['reason' => $reason, 'changed_by' => $actorName]);
            echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' order(s).']);
            exit;
        }
        if ($action === 'restore_archived_orders') {
            $stmt = db()->prepare("UPDATE ops_orders SET archived_at = NULL, archived_by = NULL, archive_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND archived_at IS NOT NULL");
            $stmt->execute($ids);
            foreach ($ids as $id) ops_activity_log('order_restored_from_archive', 'order', $id, ['changed_by' => $actorName]);
            echo json_encode(['ok' => true, 'message' => 'Restored ' . $stmt->rowCount() . ' archived order(s).']);
            exit;
        }
        if ($action === 'delete_orders_forever') {
            if (!user_has_role('owner_admin')) {
                throw new RuntimeException('Only Owner/Admin can delete an order forever.');
            }
            $records = ops_rows("SELECT id, order_number, customer_name, woo_order_id FROM ops_orders WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL", $ids);
            if (count($records) !== count($ids)) {
                throw new RuntimeException('Only records already in Trash may be deleted forever.');
            }
            foreach ($records as $record) {
                ops_activity_log('order_permanently_deleted', 'order', (int) $record['id'], [
                    'order_number' => $record['order_number'], 'customer_name' => $record['customer_name'],
                    'source' => $record['woo_order_id'] ? 'woocommerce_portal_representation' : 'portal', 'changed_by' => $actorName,
                ]);
            }
            db()->prepare("DELETE FROM ops_order_items WHERE order_id IN ({$placeholders})")->execute($ids);
            $stmt = db()->prepare("DELETE FROM ops_orders WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL");
            $stmt->execute($ids);
            echo json_encode(['ok' => true, 'message' => 'Permanently removed ' . $stmt->rowCount() . ' portal record(s). WooCommerce source orders were not changed.']);
            exit;
        }
    }

    if ($action === 'list_column_labels') {
        echo json_encode(['ok' => true, 'labels' => ops_board_column_labels()]);
        exit;
    }

    if ($action === 'save_column_label') {
        $columnId = ops_post_string('column_id', 40);
        $label = ops_post_string('label', 80);
        $labels = ops_board_column_labels();

        if ($columnId === 'updates' || !array_key_exists($columnId, $labels)) {
            throw new RuntimeException('Unsupported board column.');
        }

        $label = preg_replace('/\s+/', ' ', strip_tags($label)) ?? '';
        $label = trim($label);
        if ($label === '') {
            $label = ops_board_default_column_labels()[$columnId] ?? '';
        }
        if ($label === '') {
            throw new RuntimeException('Column label cannot be blank.');
        }

        $labels[$columnId] = substr($label, 0, 80);
        @file_put_contents(ops_board_column_label_store_path(), json_encode($labels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'labels' => $labels]);
        exit;
    }

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
        if ($field === 'payment_method') {
            echo json_encode(['ok' => true, 'labels' => ops_board_default_payment_labels()]);
            exit;
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

        echo json_encode(['ok' => true, 'message' => 'Availability updated.']);
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

    if ($action === 'reorder_orders') {
        if (!user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can rearrange orders.');
        }

        $groupDate = trim((string) ($_POST['group_date'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['order_ids'] ?? ''))))));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $groupDate) || count($ids) < 2) {
            throw new RuntimeException('Invalid row order.');
        }

        if (count($ids) > 500) {
            throw new RuntimeException('Please rearrange 500 orders or fewer at once.');
        }

        ops_ensure_order_manual_order_table();

        $displayDateTimeExpr = ops_order_display_datetime_expr();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orderRows = ops_rows(
            "SELECT id FROM ops_orders WHERE id IN ({$placeholders}) AND DATE({$displayDateTimeExpr}) = ?",
            array_merge($ids, [$groupDate])
        );
        $valid = array_fill_keys(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $orderRows), true);
        $validIds = array_values(array_filter($ids, static fn (int $id): bool => isset($valid[$id])));

        if (count($validIds) < 2) {
            throw new RuntimeException('No matching orders were found for this date group.');
        }

        $db = db();
        $db->beginTransaction();
        try {
            $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            $delete = $db->prepare("DELETE FROM ops_order_manual_order WHERE group_date = ? AND order_id IN ({$deletePlaceholders})");
            $delete->execute(array_merge([$groupDate], $validIds));

            $insert = $db->prepare(
                "INSERT INTO ops_order_manual_order (group_date, order_id, sort_index, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sort_index = VALUES(sort_index), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP"
            );
            $userId = (int) (current_user()['id'] ?? 0);
            foreach ($validIds as $index => $id) {
                $insert->execute([$groupDate, $id, $index + 1, $userId ?: null]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        echo json_encode(['ok' => true, 'message' => 'Manual row order saved.', 'group_date' => $groupDate, 'order_ids' => $validIds]);
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
            'order_type' => ops_column_exists('ops_orders', 'fulfilment_mode') ? 'fulfilment_mode' : 'order_type',
            'total_amount' => 'total_amount',
            'status' => 'status',
            'payment_status' => 'payment_status',
            'notes' => 'notes',
            'assigned_packer_id' => 'assigned_packer_id',
        ];

        if ($orderId <= 0 || !isset($allowed[$field])) {
            throw new RuntimeException('Invalid order update.');
        }

        if ($field === 'payment_method') {
            throw new RuntimeException('Use the Payment editor to update payment methods and amounts.');
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

        $previousRows = ops_rows(
            'SELECT customer_name, customer_contact, payment_method, order_type, fulfilment_mode, total_amount, status, payment_status, notes, assigned_packer_id FROM ops_orders WHERE id = ? LIMIT 1',
            [$orderId]
        );
        if (!$previousRows) {
            throw new RuntimeException('Order not found. Refresh and try again.');
        }
        $previousOrder = $previousRows[0];

        if ($field === 'assigned_packer_id') {
            $user = current_user();
            $roleKey = (string) ($user['role_key'] ?? '');
            if (!in_array($roleKey, ['owner_admin', 'front_desk_admin', 'supervisor_manager', 'packer'], true)) {
                throw new RuntimeException('You do not have permission to change Packed by.');
            }
            $packerId = $value === '' ? null : (int) $value;
            if ($packerId) {
                $hasPackingAssignable = ops_ensure_packing_assignable_column();
                $eligibilityWhere = $hasPackingAssignable ? "(e.packing_assignable = 1 OR r.role_key = 'front_desk_admin')" : "r.role_key IN ('packer', 'supervisor_manager', 'front_desk_admin')";
                $eligible = ops_rows(
                    "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE e.id = ? AND e.status = 'active' AND {$eligibilityWhere} LIMIT 1",
                    [$packerId]
                );
                if (!$eligible) {
                    throw new RuntimeException('Choose an active employee eligible for Packing assignment.');
                }
            }
            $previousPackerId = (int) ($previousOrder['assigned_packer_id'] ?? 0);
            $nameRows = ops_rows('SELECT id, full_name FROM ops_employees WHERE id IN (?, ?)', [
                $previousPackerId ?: -1,
                $packerId ?: -1,
            ]);
            $packerNames = [];
            foreach ($nameRows as $nameRow) {
                $packerNames[(int) $nameRow['id']] = (string) $nameRow['full_name'];
            }
            $previousPackerName = $previousPackerId ? ($packerNames[$previousPackerId] ?? ('Employee ' . $previousPackerId)) : 'Unassigned';
            $packerName = $packerId ? ($packerNames[$packerId] ?? ('Employee ' . $packerId)) : 'Unassigned';
            $assignedAt = ops_column_exists('ops_orders', 'assigned_at') ? ', assigned_at = CASE WHEN ? IS NULL THEN NULL ELSE COALESCE(assigned_at, NOW()) END' : '';
            $stmt = db()->prepare('UPDATE ops_orders SET assigned_packer_id = ?' . $assignedAt . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if ($assignedAt !== '') {
                $stmt->execute([$packerId, $packerId, $orderId]);
            } else {
                $stmt->execute([$packerId, $orderId]);
            }
            ops_log_order_stage_event($orderId, $packerId ? 'assigned' : 'assignment_cleared', [
                'source' => 'orders_board_field',
                'previous_packer_id' => $previousPackerId ?: null,
                'assigned_packer_id' => $packerId,
                'previous_value' => $previousPackerName,
                'new_value' => $packerName,
                'changed_by' => current_user()['name'] ?? 'Unknown',
                'message' => 'Packed By changed from ' . $previousPackerName . ' to ' . $packerName . '.',
            ]);
            ops_activity_log($packerId ? 'packed_by_changed' : 'packed_by_cleared', 'order', $orderId, [
                'previous_packer_id' => $previousPackerId ?: null,
                'assigned_packer_id' => $packerId,
                'previous_value' => $previousPackerName,
                'new_value' => $packerName,
                'changed_by' => current_user()['name'] ?? 'Unknown',
                'message' => 'Packed By changed from ' . $previousPackerName . ' to ' . $packerName . '.',
            ]);
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
            try {
                $oldKpiStatus = (string) ($previousOrder['status'] ?? '');
                if ($oldKpiStatus !== $value) {
                    db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['order', $orderId, $oldKpiStatus, $value, ops_current_employee_id() ?: null]);
                }
            } catch (Throwable $kpiError) {
                error_log(date(DATE_ATOM) . ' order status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
            }
            ops_activity_log($value === 'completed' ? 'order_completed' : 'status_changed', 'order', $orderId, [
                'field' => 'status',
                'old_value' => (string) ($previousOrder['status'] ?? ''),
                'new_value' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
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
            $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
            $sessionCsrf = (string) ($_SESSION['orders_csrf_token'] ?? '');
            if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
                http_response_code(419);
                throw new RuntimeException('Your session token expired. Refresh the Order List and try again.');
            }
            if (!ops_can_update_order_paid_status()) {
                throw new RuntimeException('You do not have permission to change Paid.');
            }
            if ($value === 'paid') {
                $allocations = ops_order_payment_allocations($orderId);
                $allocatedCents = array_sum(array_map(static fn(array $payment): int => (int) ($payment['amount_cents'] ?? 0), $allocations));
                $orderTotalCents = (int) round((float) ($previousOrder['total_amount'] ?? 0) * 100);
                if ($allocations && $allocatedCents < $orderTotalCents) {
                    throw new RuntimeException('Allocated payments do not cover the order total. Add the remaining payment before marking this order paid.');
                }
            }
            $paidAuditSet = ops_column_exists('ops_orders', 'paid_updated_at') && ops_column_exists('ops_orders', 'paid_updated_by_employee_id')
                ? ', paid_updated_at = CURRENT_TIMESTAMP, paid_updated_by_employee_id = ?' : '';
            $stmt = db()->prepare('UPDATE ops_orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP' . $paidAuditSet . ' WHERE id = ?');
            $paidParams = [$value];
            if ($paidAuditSet !== '') $paidParams[] = ops_current_employee_id();
            $paidParams[] = $orderId;
            $stmt->execute($paidParams);
            $actor = current_user();
            $oldPaid = (string) ($previousOrder['payment_status'] ?? 'unpaid') === 'paid';
            $newPaid = $value === 'paid';
            ops_activity_log('payment_status_updated', 'order', $orderId, [
                'field' => 'payment_status',
                'old_value' => (string) ($previousOrder['payment_status'] ?? ''),
                'new_value' => $value,
                'previous_value' => $oldPaid ? 'Yes' : 'No',
                'changed_by' => $actor['name'] ?? 'Unknown',
                'actor_role' => $actor['role_key'] ?? current_role_key(),
                'source' => 'manual_orders_board',
                'message' => 'Paid changed from ' . ($oldPaid ? 'Yes' : 'No') . ' to ' . ($newPaid ? 'Yes' : 'No') . ' by ' . ($actor['name'] ?? 'Unknown') . '.',
            ]);
        } else {
            $stmt = db()->prepare('UPDATE ops_orders SET ' . $allowed[$field] . ' = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$value, $orderId]);
            $activityActions = [
                'customer_name' => 'customer_updated',
                'customer_contact' => 'mobile_changed',
                'payment_method' => 'payment_changed',
                'order_type' => 'mode_changed',
                'total_amount' => 'amount_changed',
                'notes' => 'update_added',
            ];
            if (isset($activityActions[$field])) {
                ops_activity_log($activityActions[$field], 'order', $orderId, [
                    'field' => $field,
                    'old_value' => (string) ($field === 'order_type' ? ($previousOrder['fulfilment_mode'] ?: $previousOrder['order_type']) : ($previousOrder[$field] ?? '')),
                    'new_value' => $value,
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
            }
        }

        $response = ['ok' => true, 'message' => 'Order updated.'];
        if ($field === 'order_type') {
            $current = ops_rows('SELECT fulfilment_mode, order_type, updated_at FROM ops_orders WHERE id = ? LIMIT 1', [$orderId])[0] ?? [];
            $resolved = ops_resolve_order_fulfilment($current);
            $response += ['fulfilmentMode'=>$resolved['mode'],'fulfilmentLabel'=>$resolved['label'],'fulfilmentSource'=>$resolved['source'],'fulfilmentUpdatedAt'=>$resolved['updated_at']];
        }
        echo json_encode($response);
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
            'customer_name' => 'customer_name',
            'customer_contact' => 'customer_contact',
            'total_amount' => 'total_amount',
            'payment_status' => 'payment_status',
            'order_type' => ops_column_exists('ops_orders', 'fulfilment_mode') ? 'fulfilment_mode' : 'order_type',
            'payment_method' => 'payment_method',
            'status' => 'status',
            'assigned_packer_id' => 'assigned_packer_id',
            'notes' => 'notes',
            'created_at' => ops_order_display_datetime_update_column(),
        ];

        if (!isset($allowed[$field])) {
            throw new RuntimeException('Invalid bulk update.');
        }

        if ($field === 'payment_method') {
            throw new RuntimeException('Use each order\'s Payment editor to update payment methods and amounts.');
        }

        if ($field === 'assigned_packer_id' && !user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can bulk assign orders.');
        }

        if ($field === 'created_at' && !user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only admin, front desk or supervisor can bulk change order dates.');
        }

        if ($field === 'payment_status' && !ops_can_update_order_paid_status()) {
            throw new RuntimeException('You do not have permission to change Paid.');
        }

        if ($field === 'payment_status') {
            $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
            $sessionCsrf = (string) ($_SESSION['orders_csrf_token'] ?? '');
            if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
                http_response_code(419);
                throw new RuntimeException('Your session token expired. Refresh the Order List and try again.');
            }
        }

        if ($field === 'payment_status' && !in_array($value, ['paid', 'unpaid', 'partial', 'refunded'], true)) {
            throw new RuntimeException('Invalid payment status.');
        }

        if ($field === 'status' && !array_key_exists($value, OPS_ORDER_STATUSES)) {
            throw new RuntimeException('Invalid status.');
        }

        if ($field === 'created_at') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
                throw new RuntimeException('Invalid order date/time.');
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                throw new RuntimeException('Invalid order date/time.');
            }
            $value = date('Y-m-d H:i:s', $timestamp);
        }

        if ($field === 'total_amount') {
            $value = preg_replace('/[^\d.]/', '', $value) ?? '';
            if ($value === '' || !is_numeric($value)) {
                throw new RuntimeException('Enter a valid amount.');
            }
            $value = number_format((float) $value, 2, '.', '');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($field === 'payment_status' && $value === 'paid' && ops_ensure_order_payment_schema()) {
            $underAllocated = ops_rows(
                "SELECT o.id
                 FROM ops_orders o
                 JOIN order_payment_allocations p ON p.order_id = o.id
                 WHERE o.id IN ({$placeholders})
                 GROUP BY o.id, o.total_amount
                 HAVING SUM(p.amount_cents) < ROUND(o.total_amount * 100)
                 LIMIT 1",
                $ids
            );
            if ($underAllocated) {
                throw new RuntimeException('One or more selected orders have payments that do not cover their totals. Complete the allocations before marking them paid.');
            }
        }
        $previousKpiStatuses = [];
        if ($field === 'status') {
            foreach (ops_rows("SELECT id, status FROM ops_orders WHERE id IN ({$placeholders})", $ids) as $previousKpiRow) {
                $previousKpiStatuses[(int) $previousKpiRow['id']] = (string) ($previousKpiRow['status'] ?? '');
            }
        }
        $params = [];
        $set = $allowed[$field] . ' = ?';
        if ($field === 'assigned_packer_id') {
            $value = $value === '' ? null : (int) $value;
            if ($value) {
                $hasPackingAssignable = ops_ensure_packing_assignable_column();
                $eligibilityWhere = $hasPackingAssignable ? 'e.packing_assignable = 1' : "r.role_key IN ('packer', 'supervisor_manager')";
                $eligible = ops_rows(
                    "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE e.id = ? AND e.status = 'active' AND {$eligibilityWhere} LIMIT 1",
                    [$value]
                );
                if (!$eligible) {
                    throw new RuntimeException('Choose an active employee eligible for Packing assignment.');
                }
            }
            if (ops_column_exists('ops_orders', 'assigned_at')) {
                $set .= ', assigned_at = CASE WHEN ? IS NULL THEN NULL ELSE COALESCE(assigned_at, NOW()) END';
                $params[] = $value;
            }
        } elseif ($field === 'status') {
            if ($value === 'in_progress' && ops_column_exists('ops_orders', 'packing_started_at')) {
                $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
            }
            if ($value === 'completed') {
                if (ops_column_exists('ops_orders', 'packing_started_at')) {
                    $set .= ', packing_started_at = COALESCE(packing_started_at, NOW())';
                }
                $set .= ', packed_at = COALESCE(packed_at, NOW()), completed_at = COALESCE(completed_at, NOW())';
            }
        } elseif ($field === 'payment_status' && ops_column_exists('ops_orders', 'paid_updated_at') && ops_column_exists('ops_orders', 'paid_updated_by_employee_id')) {
            $set .= ', paid_updated_at = CURRENT_TIMESTAMP, paid_updated_by_employee_id = ?';
            $params[] = ops_current_employee_id();
        }

        array_unshift($params, $value);
        $params = array_merge($params, $ids);
        $stmt = db()->prepare("UPDATE ops_orders SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
        $stmt->execute($params);
        $changed = $stmt->rowCount();

        foreach ($ids as $id) {
            if ($field === 'status') {
                try {
                    $oldKpiStatus = $previousKpiStatuses[(int) $id] ?? '';
                    if ($oldKpiStatus !== (string) $value) {
                        db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['order', (int) $id, $oldKpiStatus, (string) $value, ops_current_employee_id() ?: null]);
                    }
                } catch (Throwable $kpiError) {
                    error_log(date(DATE_ATOM) . ' order bulk status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
                }
                ops_log_order_stage_event($id, (string) $value, [
                    'source' => 'orders_board_bulk',
                    'status' => $value,
                ]);
            } elseif ($field === 'assigned_packer_id') {
                ops_log_order_stage_event($id, $value ? 'assigned' : 'assignment_cleared', [
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
        $canDelete = ops_board_can_move_to_trash();
        if (!$canBulkManage && !($action === 'bulk_delete' && $canDelete)) {
            throw new RuntimeException('You do not have permission to use this bulk action.');
        }
        if ($action === 'bulk_delete' && !$canDelete) {
            throw new RuntimeException('You do not have permission to move orders to Trash.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'bulk_archive') {
            ops_board_ensure_tools_columns();
            $params = array_merge([ops_current_employee_id()], $ids);
            $stmt = db()->prepare("UPDATE ops_orders SET archived_at = NOW(), archived_by = ?, archive_reason = 'Bulk action', updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND archived_at IS NULL AND deleted_at IS NULL");
            $stmt->execute($params);
            foreach ($ids as $id) {
                ops_activity_log('order_archived', 'order', $id, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            }
            echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' selected orders.']);
            exit;
        }

        if ($action === 'bulk_delete') {
            ops_board_ensure_tools_columns();
            $actorId = ops_current_employee_id();
            db()->beginTransaction();
            try {
                $records = ops_rows("SELECT id, assigned_packer_id FROM ops_orders WHERE id IN ({$placeholders}) AND deleted_at IS NULL FOR UPDATE", $ids);
                if (count($records) !== count($ids)) throw new RuntimeException('One or more selected orders are unavailable or already in Trash.');
                if (user_has_role('packer', 'packer_production_staff')) {
                    foreach ($records as $record) if ((int) ($record['assigned_packer_id'] ?? 0) !== $actorId) throw new RuntimeException('Packers may move only orders assigned to them to Trash.');
                }
                $stmt = db()->prepare("UPDATE ops_orders SET deleted_at = NOW(), deleted_by = ?, delete_reason = 'Bulk action', updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute(array_merge([$actorId], $ids));
                if ($stmt->rowCount() !== count($ids)) throw new RuntimeException('Not every selected order could be moved to Trash.');
                foreach ($ids as $id) ops_activity_log('order_moved_to_trash', 'order', $id, ['source' => 'bulk_action', 'changed_by' => current_user()['name'] ?? 'Unknown']);
                db()->commit();
            } catch (Throwable $trashError) {
                if (db()->inTransaction()) db()->rollBack();
                throw $trashError;
            }
            echo json_encode(['ok' => true, 'success' => true, 'message' => count($ids) . ' selected orders moved to Trash.', 'trashedIds' => $ids]);
            exit;
        }

        if ($action === 'bulk_duplicate') {
            $orders = ops_rows("SELECT * FROM ops_orders WHERE id IN ({$placeholders})", $ids);
            $created = 0;
            foreach ($orders as $order) {
                $copyNumber = 'COPY-' . date('His') . '-' . (int) $order['id'];
                $initialPackerId = ops_initial_order_packer_id(
                    (string) ($order['customer_name'] ?? ''),
                    (string) ($order['customer_contact'] ?? ''),
                    (string) ($order['order_type'] ?? '')
                );
                $stmt = db()->prepare(
                    "INSERT INTO ops_orders
                     (woo_order_id, order_number, customer_name, customer_contact, payment_method, total_amount, product_total, tax_total,
                      shipping_total, shipping_tax_total, discount_total, refund_total, payment_status, order_type, priority, complexity,
                      assigned_packer_id, assigned_at, assigned_verifier_id, status, notes, workload_score, created_by, created_at)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'new_order', ?, ?, ?, NOW())"
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
                    $initialPackerId,
                    $order['assigned_verifier_id'] ?? null,
                    trim("Duplicated from {$order['order_number']}\n" . (string) ($order['notes'] ?? '')),
                    (float) ($order['workload_score'] ?? 0),
                    ops_current_employee_id(),
                ]);
                $newId = (int) db()->lastInsertId();
                ops_log_initial_order_assignment($newId, $initialPackerId, 'orders_board_duplicate');
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
    $permissionFailure = stripos($e->getMessage(), 'permission') !== false || stripos($e->getMessage(), 'Packers may') !== false || stripos($e->getMessage(), 'Only Owner/Admin') !== false;
    $existingStatus = http_response_code();
    http_response_code($permissionFailure ? 403 : (in_array($existingStatus, [409, 419], true) ? $existingStatus : 400));
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
