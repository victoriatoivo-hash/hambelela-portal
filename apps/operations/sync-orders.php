<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/woocommerce.php';

require_login();

$pageTitle = 'Website Sync Health | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$hasWooColumns = $ready && ops_column_exists('ops_orders', 'woo_order_id') && ops_column_exists('ops_order_items', 'woo_order_line_id');
$error = null;
$imported = 0;
$updated = 0;
$lineCount = 0;
$orders = [];

function ops_sync_log_path(): string
{
    return BASE_PATH . '/storage/logs/operations-sync.log';
}

function ops_sync_write_log(string $message, array $context = []): void
{
    $path = ops_sync_log_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
}

function ops_sync_recent_logs(int $limit = 30): array
{
    $path = ops_sync_log_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_slice(array_reverse($lines), 0, $limit);
}

function ops_wc_payment_status(array $order): string
{
    if (in_array((string) ($order['status'] ?? ''), ['cancelled', 'refunded', 'failed'], true)) {
        return 'refunded';
    }

    if (!empty($order['date_paid']) || (($order['status'] ?? '') === 'processing') || (($order['status'] ?? '') === 'completed')) {
        return 'paid';
    }

    return 'unpaid';
}

function ops_wc_order_type(array $order): string
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

function ops_wc_customer_name(array $order): string
{
    $billing = $order['billing'] ?? [];
    $name = trim((string) (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));

    return $name !== '' ? $name : 'Website customer';
}

function ops_wc_notes(array $order): string
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

function ops_wc_order_breakdown(array $order): array
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

if ($ready && $hasWooColumns && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ops_sync_write_log('manual force sync started');
        if (!wc_configured()) {
            throw new RuntimeException('WooCommerce is not configured. Add wc_store_url, wc_consumer_key and wc_consumer_secret to config.local.php.');
        }

        $orders = wc_get('orders', [
            'status' => 'processing,on-hold,pending',
            'per_page' => 50,
            'orderby' => 'date',
            'order' => 'desc',
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
        $pdo->beginTransaction();

        $amountColumns = $hasTotalAmount ? ', total_amount' : '';
        $amountValues = $hasTotalAmount ? ', ?' : '';
        $amountUpdates = $hasTotalAmount ? ', total_amount = VALUES(total_amount)' : '';
        $breakdownColumns = $hasReportBreakdown ? ', product_total, tax_total, shipping_total, shipping_tax_total, discount_total, refund_total' : '';
        $breakdownValues = $hasReportBreakdown ? ', ?, ?, ?, ?, ?, ?' : '';
        $breakdownUpdates = $hasReportBreakdown ? ',
                product_total = VALUES(product_total),
                tax_total = VALUES(tax_total),
                shipping_total = VALUES(shipping_total),
                shipping_tax_total = VALUES(shipping_tax_total),
                discount_total = VALUES(discount_total),
                refund_total = VALUES(refund_total)' : '';

        $orderStmt = $pdo->prepare(
            "INSERT INTO ops_orders (
                woo_order_id, order_number, customer_name, customer_contact, payment_method{$amountColumns}{$breakdownColumns}, payment_status,
                order_type, priority, complexity, assigned_packer_id, status, notes, workload_score, created_at
             ) VALUES (?, ?, ?, ?, ?{$amountValues}{$breakdownValues}, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                customer_name = VALUES(customer_name),
                customer_contact = VALUES(customer_contact),
                payment_method = VALUES(payment_method){$amountUpdates}{$breakdownUpdates},
                payment_status = VALUES(payment_status),
                order_type = VALUES(order_type),
                notes = VALUES(notes),
                workload_score = VALUES(workload_score),
                updated_at = CURRENT_TIMESTAMP"
        );

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

            $orderType = ops_wc_order_type($order);
            $complexity = count($items) >= 8 ? 3 : (count($items) >= 4 ? 2 : 1);
            $priority = ($order['status'] ?? '') === 'pending' ? 'normal' : 'urgent';
            $workload = ops_workload_score($itemCount, $orderType, $complexity, $priority);
            $packerId = null;
            $createdAt = date('Y-m-d H:i:s', strtotime((string) ($order['date_created'] ?? 'now')));
            $orderNumber = 'WEB-' . (string) ($order['number'] ?? $wooOrderId);
            $breakdown = ops_wc_order_breakdown($order);

            $orderValues = [
                $wooOrderId,
                $orderNumber,
                ops_wc_customer_name($order),
                (string) (($order['billing']['phone'] ?? '') ?: ($order['billing']['email'] ?? '')),
                (string) ($order['payment_method_title'] ?? $order['payment_method'] ?? ''),
            ];
            if ($hasTotalAmount) {
                $orderValues[] = (float) ($order['total'] ?? 0);
            }
            if ($hasReportBreakdown) {
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
            $orderValues = array_merge($orderValues, [
                ops_wc_payment_status($order),
                $orderType,
                $priority,
                $complexity,
                $packerId,
                'new_order',
                ops_wc_notes($order),
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

            $orderIdStmt = $pdo->query('SELECT id FROM ops_orders WHERE woo_order_id = ' . $wooOrderId);
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
        ops_sync_write_log('manual force sync finished', [
            'imported' => $imported,
            'updated' => $updated,
            'lines' => $lineCount,
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        ops_sync_write_log('manual force sync failed', ['error' => $error]);
    }
}

$syncedToday = ($ready && $hasWooColumns) ? ops_count('ops_orders', "woo_order_id IS NOT NULL AND DATE(created_at) = CURDATE()") : 0;
$logs = ops_sync_recent_logs(30);
$failedCount = count(array_filter($logs, static fn (string $line): bool => stripos($line, 'failed') !== false || stripos($line, 'error') !== false));
$lastSyncTime = 'Never';
foreach ($logs as $line) {
    if (preg_match('/^\[([^\]]+)\]/', $line, $matches) && stripos($line, 'finished') !== false) {
        $lastSyncTime = $matches[1];
        break;
    }
}
$duplicateWarnings = 0;
if ($ready && $hasWooColumns) {
    $duplicates = ops_rows(
        "SELECT COUNT(*) AS duplicate_count
         FROM (
            SELECT woo_order_id
            FROM ops_orders
            WHERE woo_order_id IS NOT NULL
            GROUP BY woo_order_id
            HAVING COUNT(*) > 1
         ) dupes"
    );
    $duplicateWarnings = (int) ($duplicates[0]['duplicate_count'] ?? 0);
}
$connectionLabel = 'Not configured';
$connectionClass = 'warning';
if ($ready && $hasWooColumns && wc_configured()) {
    $connectionLabel = $failedCount > 0 ? 'Connected with warnings' : 'Connected';
    $connectionClass = $failedCount > 0 ? 'warning' : 'success';
} elseif (!$ready || !$hasWooColumns) {
    $connectionLabel = 'Setup needed';
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Admin utility</p>
            <h1>Website Sync Health</h1>
            <p>Monitor WooCommerce/POS syncing, retry imports when needed, and review technical sync logs.</p>
        </div>
        <div class="actions">
            <a class="button" href="orders-board.php"><i data-lucide="table-2"></i> Orders board</a>
            <a class="button" href="orders.php"><i data-lucide="chart-no-axes-combined"></i> Customer orders</a>
        </div>
    </section>
    <?php ops_nav(''); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>

    <section class="metric-grid order-kpi-grid">
        <article class="metric"><span>Connection status</span><strong><?= htmlspecialchars($connectionLabel, ENT_QUOTES, 'UTF-8') ?></strong><small><?= wc_configured() ? 'WooCommerce API keys present' : 'API keys missing' ?></small></article>
        <article class="metric"><span>Last successful sync</span><strong><?= htmlspecialchars($lastSyncTime, ENT_QUOTES, 'UTF-8') ?></strong><small>Manual or board-triggered sync</small></article>
        <article class="metric"><span>Synced today</span><strong><?= number_format($syncedToday) ?></strong><small>Website/POS orders in operations</small></article>
        <article class="metric"><span>Failed log entries</span><strong><?= number_format($failedCount) ?></strong><small>Recent technical log scan</small></article>
    </section>

    <section class="report-grid order-report-grid">
        <section class="panel">
            <div class="section-row">
                <h2>Sync controls</h2>
                <span class="status <?= htmlspecialchars($connectionClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($connectionLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if (!$hasWooColumns): ?>
                <p>Import <code>operations-woocommerce-sync-migration.sql</code> in phpMyAdmin first. This adds WooCommerce order IDs and line IDs so website orders do not duplicate.</p>
            <?php elseif (!wc_configured()): ?>
                <p>WooCommerce API is not configured yet. Add <code>wc_store_url</code>, <code>wc_consumer_key</code> and <code>wc_consumer_secret</code> to <code>config.local.php</code>.</p>
            <?php elseif ($error): ?>
                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <p>Force sync complete: imported <?= (int) $imported ?> new orders, refreshed <?= (int) $updated ?> existing orders, and synced <?= (int) $lineCount ?> order lines.</p>
            <?php else: ?>
                <p>Background/order-board sync should handle daily importing. Use force sync only when orders appear delayed, WooCommerce reconnects, or troubleshooting is needed.</p>
            <?php endif; ?>
            <form method="post" class="save-bar">
                <button class="button primary" type="submit" <?= (!$ready || !$hasWooColumns || !wc_configured()) ? 'disabled' : '' ?>>Force sync now</button>
            </form>
        </section>

        <section class="panel">
            <div class="section-row"><h2>Health indicators</h2><span class="status">Admin only</span></div>
            <div class="breakdown-list compact">
                <div><span>API connection</span><strong><?= wc_configured() ? 'Configured' : 'Missing keys' ?></strong></div>
                <div><span>Order duplicate protection</span><strong><?= $hasWooColumns ? 'Enabled' : 'Setup needed' ?></strong></div>
                <div><span>Duplicate warnings</span><strong><?= number_format($duplicateWarnings) ?></strong></div>
                <div><span>Webhook status</span><strong>Not configured yet</strong></div>
                <div><span>Retry failed syncs</span><strong>Use force sync</strong></div>
            </div>
        </section>
    </section>

    <section class="panel">
        <div class="section-row"><h2>Sync logs</h2><span class="status">Latest technical events</span></div>
        <div class="sync-log-list">
            <?php foreach ($logs as $line): ?>
                <code><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></code>
            <?php endforeach; ?>
            <?php if (!$logs): ?><p>No sync log entries recorded yet.</p><?php endif; ?>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
