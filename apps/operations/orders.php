<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Customer Orders Report | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();

function cor_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cor_money($amount): string
{
    return 'N$' . number_format((float) $amount, 2);
}

function cor_pct($value): string
{
    return number_format((float) $value, 1) . '%';
}

function cor_short_text($value, int $limit = 30): string
{
    $text = trim((string) $value);
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
}

function cor_slug($value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'other';

    return trim($value, '-');
}

function cor_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function cor_order_status_label(string $status): string
{
    return OPS_ORDER_STATUSES[$status] ?? ucwords(str_replace(['_', '-'], ' ', $status));
}

function cor_period_dates(string $range, string $from, string $to): array
{
    $today = new DateTimeImmutable('today');
    if ($range === 'today') {
        return [$today->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($range === 'week') {
        return [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($range === 'year') {
        return [$today->format('Y-01-01'), $today->format('Y-m-d')];
    }
    if ($range === 'custom') {
        $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today->modify('first day of this month')->format('Y-m-d');
        $toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $today->format('Y-m-d');
        return [$fromDate, $toDate];
    }

    return [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')];
}

function cor_filtered_where(array $filters, array &$params, string $alias = 'o'): string
{
    $where = ["DATE({$alias}.created_at) BETWEEN ? AND ?"];
    $params[] = $filters['from'];
    $params[] = $filters['to'];

    if ($filters['status'] !== 'all') {
        $where[] = "{$alias}.status = ?";
        $params[] = $filters['status'];
    }
    if ($filters['mode'] !== 'all') {
        $where[] = "{$alias}.order_type = ?";
        $params[] = $filters['mode'];
    }
    if ($filters['payment'] !== 'all') {
        $where[] = "{$alias}.payment_method LIKE ?";
        $params[] = '%' . $filters['payment'] . '%';
    }

    return implode(' AND ', $where);
}

function cor_query_rows(string $sql, array $params = []): array
{
    return ops_rows($sql, $params);
}

function cor_query_one(string $sql, array $params = []): array
{
    $rows = cor_query_rows($sql, $params);

    return $rows[0] ?? [];
}

function cor_export(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $rows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, o.order_type, o.payment_method,
                o.total_amount, o.payment_status, o.status, COALESCE(e.full_name, 'Unassigned') AS packer_name
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         ORDER BY o.created_at DESC",
        $params
    );

    $filename = 'customer-orders-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order ID', 'Date', 'Customer', 'Mobile', 'Mode', 'Payment', 'Amount', 'Payment Status', 'Order Status', 'Packed By'], "\t");
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['order_number'],
                $row['created_at'],
                $row['customer_name'],
                $row['customer_contact'],
                ucwords((string) $row['order_type']),
                $row['payment_method'],
                number_format((float) $row['total_amount'], 2, '.', ''),
                $row['payment_status'],
                cor_order_status_label((string) $row['status']),
                $row['packer_name'],
            ], "\t");
        }
        fclose($out);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Date', 'Customer', 'Mobile', 'Mode', 'Payment', 'Amount', 'Payment Status', 'Order Status', 'Packed By']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['order_number'],
            $row['created_at'],
            $row['customer_name'],
            $row['customer_contact'],
            ucwords((string) $row['order_type']),
            $row['payment_method'],
            number_format((float) $row['total_amount'], 2, '.', ''),
            $row['payment_status'],
            cor_order_status_label((string) $row['status']),
            $row['packer_name'],
        ]);
    }
    fclose($out);
    exit;
}

function cor_export_products(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $rows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(SUM(oi.quantity), 0) AS qty,
                COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS rev
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         LEFT JOIN (
            SELECT order_id, SUM(quantity) AS qty
            FROM ops_order_items
            GROUP BY order_id
         ) order_qty ON order_qty.order_id = o.id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY rev DESC",
        $params
    );

    $total = array_reduce($rows, static fn(float $carry, array $row): float => $carry + (float) $row['rev'], 0.0);
    $filename = 'product-sales-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Product', 'Units Sold', 'Revenue (N$)', '% of Total'], $format === 'excel' ? "\t" : ',');
    foreach ($rows as $index => $row) {
        $pct = $total > 0 ? ((float) $row['rev'] / $total) * 100 : 0;
        fputcsv($out, [
            $index + 1,
            $row['product_name'],
            number_format((float) $row['qty'], 2, '.', ''),
            number_format((float) $row['rev'], 2, '.', ''),
            cor_pct($pct),
        ], $format === 'excel' ? "\t" : ',');
    }
    fclose($out);
    exit;
}

function cor_export_vat(array $filters, string $format, string $section): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $delimiter = $format === 'excel' ? "\t" : ',';
    $filename = 'vat-' . ($section ?: 'summary') . '-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');

    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    if ($section === 'daily') {
        $rows = cor_query_rows(
            "SELECT DATE(o.created_at) AS d,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_sales,
                    COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
             FROM ops_orders o
             WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
             GROUP BY DATE(o.created_at)
             ORDER BY d ASC",
            $params
        );
        fputcsv($out, ['Date', 'Orders', 'Total Sales', 'Shipping', 'VATable Sales', 'Ex-VAT', 'VAT (15%)'], $delimiter);
        foreach ($rows as $row) {
            $vatable = max(0.0, (float) $row['total_sales'] - (float) $row['shipping']);
            fputcsv($out, [
                $row['d'],
                (int) $row['orders'],
                number_format((float) $row['total_sales'], 2, '.', ''),
                number_format((float) $row['shipping'], 2, '.', ''),
                number_format($vatable, 2, '.', ''),
                number_format($vatable * (100 / 115), 2, '.', ''),
                number_format($vatable * (15 / 115), 2, '.', ''),
            ], $delimiter);
        }
        fclose($out);
        exit;
    }

    if ($section === 'payment') {
        $rows = cor_query_rows(
            "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_incl,
                    COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
             FROM ops_orders o
             WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
             GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')
             ORDER BY total_incl DESC",
            $params
        );
        fputcsv($out, ['Payment Method', 'Orders', 'Total Incl VAT', 'VAT Portion'], $delimiter);
        foreach ($rows as $row) {
            $vatable = max(0.0, (float) $row['total_incl'] - (float) $row['shipping']);
            fputcsv($out, [$row['method'], (int) $row['orders'], number_format((float) $row['total_incl'], 2, '.', ''), number_format($vatable * (15 / 115), 2, '.', '')], $delimiter);
        }
        fclose($out);
        exit;
    }

    if ($section === 'products') {
        $rows = cor_query_rows(
            "SELECT oi.product_name,
                    COALESCE(SUM(oi.quantity), 0) AS qty,
                    COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS total_incl
             FROM ops_order_items oi
             JOIN ops_orders o ON o.id = oi.order_id
             LEFT JOIN (
                SELECT order_id, SUM(quantity) AS qty
                FROM ops_order_items
                GROUP BY order_id
             ) order_qty ON order_qty.order_id = o.id
             WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
             GROUP BY oi.product_name
             ORDER BY total_incl DESC",
            $params
        );
        fputcsv($out, ['Product', 'Qty Sold', 'Total Sales Incl VAT', 'VAT Portion'], $delimiter);
        foreach ($rows as $row) {
            fputcsv($out, [$row['product_name'], number_format((float) $row['qty'], 2, '.', ''), number_format((float) $row['total_incl'], 2, '.', ''), number_format((float) $row['total_incl'] * (15 / 115), 2, '.', '')], $delimiter);
        }
        fclose($out);
        exit;
    }

    $rows = cor_query_rows(
        "SELECT COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COUNT(*) AS orders,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')",
        $params
    );
    $row = $rows[0] ?? ['total_sales' => 0, 'orders' => 0, 'shipping' => 0];
    $vatable = max(0.0, (float) $row['total_sales'] - (float) $row['shipping']);
    fputcsv($out, ['Metric', 'Amount'], $delimiter);
    fputcsv($out, ['Period', $filters['from'] . ' - ' . $filters['to']], $delimiter);
    fputcsv($out, ['Total Orders', (int) $row['orders']], $delimiter);
    fputcsv($out, ['Total Invoice Sales Incl VAT', number_format((float) $row['total_sales'], 2, '.', '')], $delimiter);
    fputcsv($out, ['Shipping Excluded', number_format((float) $row['shipping'], 2, '.', '')], $delimiter);
    fputcsv($out, ['VATable Sales', number_format($vatable, 2, '.', '')], $delimiter);
    fputcsv($out, ['Sales Ex-VAT', number_format($vatable * (100 / 115), 2, '.', '')], $delimiter);
    fputcsv($out, ['VAT Collected', number_format($vatable * (15 / 115), 2, '.', '')], $delimiter);
    fclose($out);
    exit;
}

function cor_payment_bucket(string $method): string
{
    $method = strtolower($method);
    if (cor_contains($method, 'cash') && !cor_contains($method, 'wallet') && !cor_contains($method, 'blue')) {
        return 'cash';
    }
    if (cor_contains($method, 'card') || cor_contains($method, 'swipe')) {
        return 'card';
    }
    if ($method === 'eft' || cor_contains($method, 'eft')) {
        return 'eft';
    }

    return 'wallet';
}

function cor_export_monthly(array $filters, string $format): void
{
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $delimiter = $format === 'excel' ? "\t" : ',';
    $filename = 'monthly-summary-' . $filters['from'] . '-to-' . $filters['to'] . ($format === 'excel' ? '.xls' : '.csv');
    $summary = cor_query_one(
        "SELECT COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')",
        $params
    );
    $refundRows = cor_query_rows(
        "SELECT o.refund_total, o.total_amount
         FROM ops_orders o
         WHERE {$where} AND (o.payment_status = 'refunded' OR o.refund_total > 0)",
        $params
    );
    $payRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                COALESCE(SUM(o.total_amount), 0) AS total
         FROM ops_orders o
         WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
         GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')",
        $params
    );
    $cash = $card = $eft = $wallet = 0.0;
    foreach ($payRows as $row) {
        $bucket = cor_payment_bucket((string) $row['method']);
        if ($bucket === 'cash') {
            $cash += (float) $row['total'];
        } elseif ($bucket === 'card') {
            $card += (float) $row['total'];
        } elseif ($bucket === 'eft') {
            $eft += (float) $row['total'];
        } else {
            $wallet += (float) $row['total'];
        }
    }
    $totalSales = (float) ($summary['total_sales'] ?? 0);
    $orders = (int) ($summary['orders'] ?? 0);
    $shipping = (float) ($summary['shipping'] ?? 0);
    $refunds = array_reduce($refundRows, static fn(float $carry, array $row): float => $carry + (float) ($row['refund_total'] ?: $row['total_amount']), 0.0);
    $netSales = $totalSales - $refunds;
    $vatable = max(0.0, $totalSales - $shipping);
    $vat = round($vatable * (15 / 115), 2);
    $exVat = round($vatable * (100 / 115), 2);
    $cogs = 0.0;
    $grossProfit = $netSales - $shipping - $cogs;
    $margin = $netSales > 0 ? round(($grossProfit / $netSales) * 100) : 0;
    $stockCost = 0.0;
    $stockRetail = 0.0;
    $potentialMargin = $stockRetail - $stockCost;

    header($format === 'excel' ? 'Content-Type: application/vnd.ms-excel; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Amount', 'Notes'], $delimiter);
    fputcsv($out, ['--- SALES ---', '', ''], $delimiter);
    fputcsv($out, ['Total Invoice Sales', cor_money($totalSales), $orders . ' orders'], $delimiter);
    fputcsv($out, ['Cash Sales', cor_money($cash), ''], $delimiter);
    fputcsv($out, ['Card / Swipe Sales', cor_money($card), ''], $delimiter);
    fputcsv($out, ['EFT Sales', cor_money($eft), ''], $delimiter);
    fputcsv($out, ['Other / Wallet Sales', cor_money($wallet), ''], $delimiter);
    fputcsv($out, ['Delivery Fees', cor_money($shipping), ''], $delimiter);
    fputcsv($out, ['Refunds Issued', '-' . cor_money($refunds), ''], $delimiter);
    fputcsv($out, ['Net Sales', cor_money($netSales), ''], $delimiter);
    fputcsv($out, ['--- VAT ---', '', ''], $delimiter);
    fputcsv($out, ['VATable Sales', cor_money($vatable), ''], $delimiter);
    fputcsv($out, ['VAT Amount (15%)', cor_money($vat), ''], $delimiter);
    fputcsv($out, ['Sales Excl. VAT', cor_money($exVat), ''], $delimiter);
    fputcsv($out, ['--- PROFIT (ESTIMATED) ---', '', ''], $delimiter);
    fputcsv($out, ['Net Sales', cor_money($netSales), ''], $delimiter);
    fputcsv($out, ['Shipping Deducted', '-' . cor_money($shipping), ''], $delimiter);
    fputcsv($out, ['Cost of Goods Sold (est.)', '-' . cor_money($cogs), ''], $delimiter);
    fputcsv($out, ['Gross Profit (est.)', cor_money($grossProfit), $margin . '% margin'], $delimiter);
    fputcsv($out, ['--- INVENTORY ---', '', ''], $delimiter);
    fputcsv($out, ['Stock Cost Value', cor_money($stockCost), ''], $delimiter);
    fputcsv($out, ['Stock Retail Value', cor_money($stockRetail), ''], $delimiter);
    fputcsv($out, ['Potential Margin', cor_money($potentialMargin), ''], $delimiter);
    fclose($out);
    exit;
}

$range = trim((string) ($_GET['range'] ?? 'month'));
if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
    $range = 'month';
}

[$dateFrom, $dateTo] = cor_period_dates($range, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$filters = [
    'range' => $range,
    'from' => $dateFrom,
    'to' => $dateTo,
    'status' => trim((string) ($_GET['status'] ?? 'all')) ?: 'all',
    'mode' => trim((string) ($_GET['mode'] ?? 'all')) ?: 'all',
    'payment' => trim((string) ($_GET['payment'] ?? 'all')) ?: 'all',
    'tab' => trim((string) ($_GET['tab'] ?? 'sales')) ?: 'sales',
];

$validStatuses = array_merge(['all'], array_keys(OPS_ORDER_STATUSES));
if (!in_array($filters['status'], $validStatuses, true)) {
    $filters['status'] = 'all';
}
if (!in_array($filters['mode'], ['all', 'collection', 'delivery', 'courier'], true)) {
    $filters['mode'] = 'all';
}
$validTabs = ['sales', 'products', 'delivery', 'vat', 'monthly', 'inventory', 'orders'];
if (!in_array($filters['tab'], $validTabs, true)) {
    $filters['tab'] = 'sales';
}

if ($ready && isset($_GET['export']) && in_array((string) $_GET['export'], ['csv', 'excel'], true)) {
    if ($filters['tab'] === 'products') {
        cor_export_products($filters, (string) $_GET['export']);
    }
    if ($filters['tab'] === 'vat') {
        cor_export_vat($filters, (string) $_GET['export'], (string) ($_GET['section'] ?? 'summary'));
    }
    if ($filters['tab'] === 'monthly') {
        cor_export_monthly($filters, (string) $_GET['export']);
    }
    cor_export($filters, (string) $_GET['export']);
}

$paymentOptions = $ready ? array_map(
    static fn(array $row): string => (string) $row['payment_method'],
    cor_query_rows("SELECT DISTINCT payment_method FROM ops_orders WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method")
) : [];

$summary = [
    'total_orders' => 0,
    'total_revenue' => 0.0,
    'aov' => 0.0,
    'completed' => 0,
    'processing' => 0,
    'pending' => 0,
    'tax_collected' => 0.0,
    'discounts' => 0.0,
];
$statusRows = [];
$paymentRows = [];
$modeRows = [];
$staffRows = [];
$productRows = [];
$refundRows = [];
$deliveryRows = [];
$deliveryDayRows = [];
$deliveryOrderRows = [];
$vatRows = [];
$vatDailyRows = [];
$vatPaymentRows = [];
$vatProductRows = [];
$inventoryRows = [];
$daily14Rows = [];
$orderRows = [];
$refundSummary = ['count' => 0, 'amount' => 0.0, 'pct' => 0.0];
$vatSummary = ['gross' => 0.0, 'vat' => 0.0, 'net' => 0.0];
$vatMetrics = [
    'total_sales' => 0.0,
    'order_count' => 0,
    'shipping' => 0.0,
    'refunds' => 0.0,
    'vatable_sales' => 0.0,
    'vat_collected' => 0.0,
    'sales_ex_vat' => 0.0,
    'vat_on_refunds' => 0.0,
    'net_vat_payable' => 0.0,
];
$vatDailyTotals = ['orders' => 0, 'total_sales' => 0.0, 'shipping' => 0.0, 'vatable' => 0.0, 'ex_vat' => 0.0, 'vat_amount' => 0.0];
$vatPaymentTotals = ['orders' => 0, 'total_incl' => 0.0, 'vat_portion' => 0.0];
$vatProductTotals = ['qty' => 0.0, 'total_incl' => 0.0, 'vat_portion' => 0.0];
$monthlySummary = [
    'total_sales' => 0.0,
    'order_count' => 0,
    'cash' => 0.0,
    'card' => 0.0,
    'eft' => 0.0,
    'wallet' => 0.0,
    'shipping' => 0.0,
    'refunds' => 0.0,
    'net_sales' => 0.0,
    'vatable' => 0.0,
    'vat' => 0.0,
    'ex_vat' => 0.0,
    'cogs' => 0.0,
    'gross_profit' => 0.0,
    'margin_pct' => 0,
    'stock_cost' => 0.0,
    'stock_retail' => 0.0,
    'potential_margin' => 0.0,
];
$deliverySummary = ['fees' => 0.0, 'orders' => 0, 'avg' => 0.0, 'courier' => 0, 'total_cost' => 0.0, 'total_orders' => 0, 'breakdown_avg' => 0.0];
$deliveryOrdersCount = 0;
$deliverySearch = trim((string) ($_GET['del_search'] ?? ''));
$deliveryStatus = trim((string) ($_GET['del_status'] ?? 'all')) ?: 'all';
$totalPages = 1;
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($ready) {
    $params = [];
    $where = cor_filtered_where($filters, $params);
    $summaryRow = cor_query_one(
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN payment_status <> 'refunded' THEN total_amount ELSE 0 END), 0) AS total_revenue,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status IN ('in_progress','packed','verified','ready_for_collection','ready_for_courier','ready_for_delivery') THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status IN ('new_order','assigned') THEN 1 ELSE 0 END) AS pending,
                COALESCE(SUM(tax_total + shipping_tax_total), 0) AS tax_collected,
                COALESCE(SUM(discount_total), 0) AS discounts
         FROM ops_orders o
         WHERE {$where}",
        $params
    );
    $summary['total_orders'] = (int) ($summaryRow['total_orders'] ?? 0);
    $summary['total_revenue'] = (float) ($summaryRow['total_revenue'] ?? 0);
    $summary['aov'] = $summary['total_orders'] > 0 ? $summary['total_revenue'] / $summary['total_orders'] : 0.0;
    $summary['completed'] = (int) ($summaryRow['completed'] ?? 0);
    $summary['processing'] = (int) ($summaryRow['processing'] ?? 0);
    $summary['pending'] = (int) ($summaryRow['pending'] ?? 0);
    $summary['tax_collected'] = (float) ($summaryRow['tax_collected'] ?? 0);
    $summary['discounts'] = (float) ($summaryRow['discounts'] ?? 0);

    $statusRows = cor_query_rows(
        "SELECT status AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY status
         ORDER BY cnt DESC",
        $params
    );
    $paymentRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(payment_method, ''), 'Other') AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY COALESCE(NULLIF(payment_method, ''), 'Other')
         ORDER BY rev DESC",
        $params
    );
    $modeRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(order_type, ''), 'collection') AS label, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY COALESCE(NULLIF(order_type, ''), 'collection')
         ORDER BY rev DESC",
        $params
    );
    $staffRows = cor_query_rows(
        "SELECT COALESCE(e.full_name, 'Unassigned') AS label, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount), 0) AS rev
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         GROUP BY COALESCE(e.full_name, 'Unassigned')
         ORDER BY cnt DESC",
        $params
    );
    $productRows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(SUM(oi.quantity), 0) AS qty,
                COALESCE(SUM(CASE WHEN order_qty.qty > 0 THEN o.total_amount * (oi.quantity / order_qty.qty) ELSE 0 END), 0) AS rev
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         LEFT JOIN (
            SELECT order_id, SUM(quantity) AS qty
            FROM ops_order_items
            GROUP BY order_id
         ) order_qty ON order_qty.order_id = o.id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY rev DESC",
        $params
    );
    $refundRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.refund_total, o.total_amount, o.payment_status, o.status
         FROM ops_orders o
         WHERE {$where} AND (o.payment_status = 'refunded' OR o.refund_total > 0)
         ORDER BY o.created_at DESC",
        $params
    );
    $refundSummary['count'] = count($refundRows);
    $refundSummary['amount'] = array_reduce($refundRows, static fn(float $carry, array $row): float => $carry + (float) ($row['refund_total'] ?: $row['total_amount']), 0.0);
    $refundSummary['pct'] = $summary['total_revenue'] > 0 ? ($refundSummary['amount'] / $summary['total_revenue']) * 100 : 0.0;

    $deliveryWhere = $where . " AND ((o.shipping_total + o.shipping_tax_total) > 0 OR o.order_type IN ('delivery','courier'))";
    $deliveryRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.order_type, ''), 'collection') AS label,
                COUNT(*) AS cnt,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS total_cost,
                COALESCE(AVG(o.shipping_total + o.shipping_tax_total), 0) AS avg_cost
         FROM ops_orders o
         WHERE {$deliveryWhere}
         GROUP BY COALESCE(NULLIF(o.order_type, ''), 'collection')
         ORDER BY total_cost DESC, cnt DESC",
        $params
    );
    $deliverySummary['fees'] = array_reduce($deliveryRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_cost'], 0.0);
    $deliverySummary['orders'] = array_reduce($deliveryRows, static fn(int $carry, array $row): int => $carry + (int) $row['cnt'], 0);
    $deliverySummary['avg'] = $deliverySummary['orders'] > 0 ? $deliverySummary['fees'] / $deliverySummary['orders'] : 0.0;
    $deliverySummary['courier'] = array_reduce($deliveryRows, static function (int $carry, array $row): int {
        return $carry + (strtolower((string) $row['label']) === 'courier' ? (int) $row['cnt'] : 0);
    }, 0);
    $deliverySummary['total_cost'] = $deliverySummary['fees'];
    $deliverySummary['total_orders'] = $deliverySummary['orders'];
    $deliverySummary['breakdown_avg'] = $deliverySummary['avg'];
    $deliveryDayRows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d,
                COUNT(*) AS orders,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS fees
         FROM ops_orders o
         WHERE {$deliveryWhere}
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC",
        $params
    );
    $deliveryOrderParams = $params;
    $deliveryOrderWhere = $deliveryWhere;
    if ($deliveryStatus !== 'all' && in_array($deliveryStatus, $validStatuses, true)) {
        $deliveryOrderWhere .= " AND o.status = ?";
        $deliveryOrderParams[] = $deliveryStatus;
    }
    if ($deliverySearch !== '') {
        $deliveryOrderWhere .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_contact LIKE ?)";
        $needle = '%' . $deliverySearch . '%';
        $deliveryOrderParams[] = $needle;
        $deliveryOrderParams[] = $needle;
        $deliveryOrderParams[] = $needle;
    }
    $deliveryCountRow = cor_query_one("SELECT COUNT(*) AS cnt FROM ops_orders o WHERE {$deliveryOrderWhere}", $deliveryOrderParams);
    $deliveryOrdersCount = (int) ($deliveryCountRow['cnt'] ?? 0);
    $deliveryOrderRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, o.order_type,
                COALESCE(o.shipping_total + o.shipping_tax_total, 0) AS delivery_cost,
                o.status
         FROM ops_orders o
         WHERE {$deliveryOrderWhere}
         ORDER BY o.created_at DESC
         LIMIT 50",
        $deliveryOrderParams
    );

    $vatRows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_sales,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC",
        $params
    );
    foreach ($vatRows as &$vatRow) {
        $vatRow['orders'] = (int) $vatRow['orders'];
        $vatRow['total_sales'] = (float) $vatRow['total_sales'];
        $vatRow['shipping'] = (float) $vatRow['shipping'];
        $vatRow['vatable'] = max(0.0, $vatRow['total_sales'] - $vatRow['shipping']);
        $vatRow['ex_vat'] = round($vatRow['vatable'] * (100 / 115), 2);
        $vatRow['vat_amount'] = round($vatRow['vatable'] * (15 / 115), 2);
    }
    unset($vatRow);
    $vatDailyRows = $vatRows;
    $vatDailyTotals = [
        'orders' => array_reduce($vatDailyRows, static fn(int $carry, array $row): int => $carry + (int) $row['orders'], 0),
        'total_sales' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_sales'], 0.0),
        'shipping' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['shipping'], 0.0),
        'vatable' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['vatable'], 0.0),
        'ex_vat' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['ex_vat'], 0.0),
        'vat_amount' => array_reduce($vatDailyRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_amount'], 0.0),
    ];
    $vatMetrics['total_sales'] = $vatDailyTotals['total_sales'];
    $vatMetrics['order_count'] = $vatDailyTotals['orders'];
    $vatMetrics['shipping'] = $vatDailyTotals['shipping'];
    $vatMetrics['refunds'] = $refundSummary['amount'];
    $vatMetrics['vatable_sales'] = $vatDailyTotals['vatable'];
    $vatMetrics['vat_collected'] = $vatDailyTotals['vat_amount'];
    $vatMetrics['sales_ex_vat'] = $vatDailyTotals['ex_vat'];
    $vatMetrics['vat_on_refunds'] = round($vatMetrics['refunds'] * (15 / 115), 2);
    $vatMetrics['net_vat_payable'] = $vatMetrics['vat_collected'] - $vatMetrics['vat_on_refunds'];
    $vatSummary['gross'] = $vatMetrics['total_sales'];
    $vatSummary['vat'] = $vatMetrics['vat_collected'];
    $vatSummary['net'] = $vatMetrics['sales_ex_vat'];

    $vatPaymentRows = cor_query_rows(
        "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') AS method,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS total_incl,
                COALESCE(SUM(o.shipping_total + o.shipping_tax_total), 0) AS shipping
         FROM ops_orders o
         WHERE {$where} AND o.payment_status <> 'refunded' AND o.status NOT IN ('cancelled','failed')
         GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Other')
         ORDER BY total_incl DESC",
        $params
    );
    foreach ($vatPaymentRows as &$vatPaymentRow) {
        $vatablePayment = max(0.0, (float) $vatPaymentRow['total_incl'] - (float) $vatPaymentRow['shipping']);
        $vatPaymentRow['vat_portion'] = round($vatablePayment * (15 / 115), 2);
    }
    unset($vatPaymentRow);
    $vatPaymentTotals = [
        'orders' => array_reduce($vatPaymentRows, static fn(int $carry, array $row): int => $carry + (int) $row['orders'], 0),
        'total_incl' => array_reduce($vatPaymentRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_incl'], 0.0),
        'vat_portion' => array_reduce($vatPaymentRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_portion'], 0.0),
    ];
    $vatProductRows = array_map(static function (array $row): array {
        $totalIncl = (float) $row['rev'];
        return [
            'product' => (string) $row['product_name'],
            'qty_sold' => (float) $row['qty'],
            'total_incl' => $totalIncl,
            'vat_portion' => round($totalIncl * (15 / 115), 2),
        ];
    }, $productRows);
    $vatProductTotals = [
        'qty' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['qty_sold'], 0.0),
        'total_incl' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['total_incl'], 0.0),
        'vat_portion' => array_reduce($vatProductRows, static fn(float $carry, array $row): float => $carry + (float) $row['vat_portion'], 0.0),
    ];
    $monthlySummary['total_sales'] = $vatMetrics['total_sales'];
    $monthlySummary['order_count'] = $vatMetrics['order_count'];
    $monthlySummary['shipping'] = $vatMetrics['shipping'];
    $monthlySummary['refunds'] = $refundSummary['amount'];
    foreach ($paymentRows as $row) {
        $bucket = cor_payment_bucket((string) $row['label']);
        $monthlySummary[$bucket] += (float) $row['rev'];
    }
    $monthlySummary['net_sales'] = $monthlySummary['total_sales'] - $monthlySummary['refunds'];
    $monthlySummary['vatable'] = $vatMetrics['vatable_sales'];
    $monthlySummary['vat'] = $vatMetrics['vat_collected'];
    $monthlySummary['ex_vat'] = $vatMetrics['sales_ex_vat'];
    $monthlySummary['cogs'] = 0.0;
    $monthlySummary['gross_profit'] = $monthlySummary['net_sales'] - $monthlySummary['shipping'] - $monthlySummary['cogs'];
    $monthlySummary['margin_pct'] = $monthlySummary['net_sales'] > 0 ? (int) round(($monthlySummary['gross_profit'] / $monthlySummary['net_sales']) * 100) : 0;
    $monthlySummary['stock_cost'] = 0.0;
    $monthlySummary['stock_retail'] = 0.0;
    $monthlySummary['potential_margin'] = $monthlySummary['stock_retail'] - $monthlySummary['stock_cost'];

    $inventoryRows = cor_query_rows(
        "SELECT oi.product_name,
                COALESCE(NULLIF(MAX(oi.sku), ''), '-') AS sku,
                COALESCE(SUM(oi.quantity), 0) AS sold_qty,
                COUNT(DISTINCT oi.order_id) AS order_count,
                MAX(o.created_at) AS last_sold_at
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         WHERE {$where}
         GROUP BY oi.product_name
         ORDER BY sold_qty DESC
         LIMIT 100",
        $params
    );
    $daily14Rows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS rev
         FROM ops_orders o
         WHERE DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
           AND o.payment_status <> 'refunded'
           AND o.status NOT IN ('cancelled','failed')
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC"
    );

    $countRow = cor_query_one("SELECT COUNT(*) AS cnt FROM ops_orders o WHERE {$where}", $params);
    $totalPages = max(1, (int) ceil(((int) ($countRow['cnt'] ?? 0)) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $orderRows = cor_query_rows(
        "SELECT o.order_number, o.created_at, o.customer_name, o.customer_contact, o.order_type, o.payment_method,
                o.total_amount, o.payment_status, o.status, COALESCE(e.full_name, 'Unassigned') AS packer_name
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$where}
         ORDER BY o.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
}

$maxPayment = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $paymentRows ?: [['rev' => 0]]));
$maxMode = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $modeRows ?: [['rev' => 0]]));
$maxStaff = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $staffRows ?: [['rev' => 0]]));
$maxProduct = max(1.0, ...array_map(static fn(array $row): float => (float) ($row['rev'] ?? 0), $productRows ?: [['rev' => 0]]));
$productsSold = count($productRows);
$totalProductUnits = array_reduce($productRows, static fn(float $carry, array $row): float => $carry + (float) $row['qty'], 0.0);
$totalProductRevenue = array_reduce($productRows, static fn(float $carry, array $row): float => $carry + (float) $row['rev'], 0.0);
$avgProductRevenue = $productsSold > 0 ? $totalProductRevenue / $productsSold : 0.0;
$topProduct = $productRows[0] ?? null;
$queryBase = [
    'range' => $filters['range'],
    'from' => $filters['from'],
    'to' => $filters['to'],
    'status' => $filters['status'],
    'mode' => $filters['mode'],
    'payment' => $filters['payment'],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cor-wrap">
    <style>
        :root {
            --cor-olive: #A8CA19;
            --cor-amber: #F07420;
            --cor-orange-red: #AB3619;
            --cor-red: #BB1B21;
            --cor-burgundy: #721B1A;
            --cor-cream: #FDF6EE;
            --cor-surface: #FFFFFF;
            --cor-border: #EDE3D8;
            --cor-text: #2C1810;
            --cor-text-mid: #6B4C3B;
            --cor-text-light: #A08070;
        }
        .cor-wrap {
            background: #FDF6EE;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            color: #2C1810;
            --cor-olive: #A8CA19;
            --cor-amber: #F07420;
            --cor-orange-red: #AB3619;
            --cor-red: #BB1B21;
            --cor-burgundy: #721B1A;
            --cor-cream: #FDF6EE;
            --cor-surface: #FFFFFF;
            --cor-border: #EDE3D8;
            --cor-text: #2C1810;
            --cor-text-mid: #6B4C3B;
            --cor-text-light: #A08070;
        }
        .cor-wrap .module-header { margin-bottom: 14px; }
        .cor-wrap .page-eyebrow { margin: 0; color: var(--cor-orange-red); font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .cor-wrap h1 { margin: 2px 0 6px; color: var(--cor-burgundy); font-size: 26px; line-height: 1.05; }
        .cor-wrap .page-subtitle { margin: 0; color: var(--cor-text-mid); font-size: 13px; }
        .cor-filter-panel { margin: 24px 0 18px; border: 1px solid var(--cor-border); border-radius: 8px; background: var(--cor-surface); box-shadow: 0 2px 8px rgba(44,24,16,.04); overflow: hidden; }
        .cor-filter-panel summary { list-style: none; min-height: 36px; padding: 0 14px; display: flex; align-items: center; gap: 9px; color: var(--cor-red); font-size: 13px; font-weight: 800; cursor: pointer; user-select: none; }
        .cor-filter-panel summary::-webkit-details-marker { display: none; }
        .cor-filter-panel summary .filter-icon { width: 16px; height: 16px; color: var(--cor-red); flex: 0 0 auto; }
        .cor-filter-panel summary::after { content: 'Open'; margin-left: auto; color: var(--cor-text-light); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .cor-filter-panel[open] summary { border-bottom: 1px solid var(--cor-border); }
        .cor-filter-panel[open] summary::after { content: 'Close'; }
        .cor-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0; padding: 12px 16px; background: #fffaf6; }
        .cor-quick-ranges, .cor-filter-selects, .cor-date-inputs { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .cor-range-btn, .cor-btn-apply, .cor-btn-export { height: 30px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .cor-range-btn { padding: 0 12px; border: 1px solid var(--cor-border); background: var(--cor-surface); color: var(--cor-text-mid); }
        .cor-range-btn.active, .cor-range-btn:hover { background: var(--cor-orange-red); border-color: var(--cor-orange-red); color: #fff; }
        .cor-filter-bar select, .cor-filter-bar input[type="date"] { height: 30px; min-width: 135px; padding: 0 8px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text); font-size: 12px; }
        .cor-filter-bar select:focus, .cor-filter-bar input:focus { outline: none; border-color: var(--cor-orange-red); box-shadow: 0 0 0 2px rgba(171,54,25,.15); }
        .cor-btn-apply { border: 0; padding: 0 16px; background: var(--cor-orange-red); color: #fff; }
        .cor-btn-apply:hover { background: var(--cor-burgundy); }
        .cor-btn-export { padding: 0 12px; border: 1px solid var(--cor-border); background: var(--cor-surface); color: var(--cor-text-mid); }
        .cor-btn-export:hover { border-color: var(--cor-orange-red); background: #f5ece8; }
        .cor-stat-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 16px; }
        .cor-stat-card { border: 1px solid var(--cor-border); border-radius: 10px; padding: 14px 16px; background: var(--cor-surface); box-shadow: 0 2px 8px rgba(44,24,16,.06); transition: transform 180ms ease; }
        .cor-stat-card:hover { transform: translateY(-2px); }
        .cor-stat-card.total-orders, .cor-stat-card.completed { border-left: 4px solid var(--cor-olive); }
        .cor-stat-card.total-revenue { border-left: 4px solid var(--cor-burgundy); }
        .cor-stat-card.aov, .cor-stat-card.processing { border-left: 4px solid var(--cor-orange-red); }
        .cor-stat-card.pending-stat, .cor-stat-card.vat { border-left: 4px solid var(--cor-amber); }
        .cor-stat-card.refunds { border-left: 4px solid var(--cor-red); }
        .cor-stat-card.monthly { border-left: 4px solid var(--cor-burgundy); }
        .cor-stat-card .s-title { margin-bottom: 6px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .cor-stat-card .s-num { color: var(--cor-text); font-size: 24px; font-weight: 800; line-height: 1; }
        .cor-stat-card .s-sub { margin-top: 4px; color: var(--cor-text-light); font-size: 11px; }
        .cor-summary-stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
        .cor-top-card { padding: 16px 18px; border: 1px solid var(--cor-border); border-radius: 10px; background: var(--cor-surface); box-shadow: 0 2px 8px rgba(44,24,16,.06); }
        .cor-top-card .tc-label { margin-bottom: 6px; color: var(--cor-text-mid); font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .cor-top-card .tc-value { color: var(--cor-orange-red); font-size: 22px; font-weight: 800; line-height: 1; }
        .cor-top-card .tc-sub { margin-top: 4px; color: var(--cor-text-light); font-size: 11px; }
        .cor-summary-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 16px; }
        .cor-btn-daily { height: 32px; padding: 0 16px; border: 0; border-radius: 7px; background: var(--cor-burgundy); color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .cor-btn-daily:hover { background: var(--cor-orange-red); }
        .cor-btn-export-sm { height: 32px; padding: 0 12px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; }
        .cor-btn-export-sm:hover { border-color: var(--cor-orange-red); background: #f5ece8; }
        .cor-pay-cards { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .cor-pay-card { min-width: 130px; padding: 10px 14px; border: 1px solid var(--cor-border); border-radius: 8px; background: var(--cor-surface); box-shadow: 0 1px 4px rgba(44,24,16,.05); text-decoration: none; transition: border-color 150ms ease, box-shadow 150ms ease; }
        .cor-pay-card:hover { border-color: var(--cor-orange-red); box-shadow: 0 4px 12px rgba(44,24,16,.10); }
        .cor-pay-card .pc-method { margin-bottom: 4px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; }
        .cor-pay-card .pc-amount { color: var(--cor-text); font-size: 18px; font-weight: 800; line-height: 1; }
        .cor-pay-card .pc-link { margin-top: 3px; color: var(--cor-orange-red); font-size: 10px; }
        .cor-pay-card.refunds-card { cursor: default; }
        .cor-pay-card.refunds-card .pc-amount { color: var(--cor-red); }
        .cor-tabs-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 16px; border-bottom: 2px solid var(--cor-border); }
        .cor-tabs { display: flex; gap: 0; min-width: max-content; border-bottom: 0; white-space: nowrap; }
        .cor-tab { flex: 0 0 auto; height: 38px; margin-bottom: -2px; padding: 0 16px; border: 0; border-bottom: 3px solid transparent; background: transparent; color: var(--cor-text-mid); font-size: 12px; font-weight: 600; cursor: pointer; }
        .cor-tab:hover { color: var(--cor-orange-red); }
        .cor-tab.active { border-bottom-color: var(--cor-orange-red); color: var(--cor-burgundy); }
        .cor-tab-content { display: none; }
        .cor-tab-content.active { display: block; }
        .cor-report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .cor-sales-breakdowns { margin-top: 14px; grid-template-columns: 1fr; }
        .cor-two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
        .cor-report-card, .cor-chart-card { overflow: hidden; margin-bottom: 14px; border: 1px solid var(--cor-border); border-radius: 10px; background: var(--cor-surface); box-shadow: 0 2px 6px rgba(44,24,16,.05); }
        .cor-report-card .card-head, .cor-chart-card .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--cor-border); }
        .cor-report-card h3, .cor-chart-card h3 { margin: 0; color: var(--cor-burgundy); font-size: 13px; font-weight: 700; }
        .cor-report-card table { width: 100%; border-collapse: collapse; }
        .cor-table-wrap { overflow-x: auto; }
        .cor-report-card th, .cor-orders-table th { height: 34px; padding: 0 14px; border-bottom: 2px solid var(--cor-border); background: var(--cor-cream); color: var(--cor-burgundy); font-size: 11px; font-weight: 700; letter-spacing: .05em; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .cor-report-card td, .cor-orders-table td { height: 38px; padding: 0 14px; border-bottom: 1px solid var(--cor-border); color: var(--cor-text); font-size: 13px; vertical-align: middle; }
        .cor-report-card tr:last-child td, .cor-orders-table tr:last-child td { border-bottom: 0; }
        .cor-report-card tr:hover td, .cor-orders-table tr:hover td { background: #fdf3ea; }
        .cor-bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 160px; }
        .cor-bar-track { flex: 1; min-width: 60px; height: 6px; overflow: hidden; border-radius: 3px; background: var(--cor-border); }
        .cor-bar-fill { display: block; height: 100%; border-radius: 3px; background: var(--cor-orange-red); }
        .cor-pct { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; text-align: right; }
        .cor-pill { display: inline-flex; align-items: center; height: 21px; padding: 0 8px; border-radius: 5px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .cor-status-completed { background: var(--cor-olive); color: #2C1810; }
        .cor-status-in-progress, .cor-status-packed, .cor-status-processing { background: var(--cor-amber); color: #fff; }
        .cor-status-new-order, .cor-status-assigned, .cor-status-pending { background: #c8c1b8; color: #fff; }
        .cor-status-error-logged, .cor-status-correction-required, .cor-status-refunded { background: var(--cor-red); color: #fff; }
        .pay-eft { background: #5c4ab0; color: #fff; }
        .pay-cash { background: #7a7a7a; color: #fff; }
        .pay-easywallet { background: #7B68EE; color: #fff; }
        .pay-swipe, .pay-card { background: #2C1810; color: #fff; }
        .pay-bluewallet { background: #00838F; color: #fff; }
        .pay-fnb { background: #1B5E20; color: #fff; }
        .pay-pay2cell { background: var(--cor-red); color: #fff; }
        .pay-other { background: var(--cor-border); color: var(--cor-text-mid); }
        .mode-delivery { background: #b5a280; color: #fff; }
        .mode-collection { background: #7a8c4f; color: #fff; }
        .mode-courier { background: #5c3a1e; color: #fff; }
        .mode-walk-in, .mode-walkin { background: var(--cor-border); color: var(--cor-text-mid); }
        .cor-refund-amount, .cor-profit-negative, .cor-mom-down { color: var(--cor-red); font-weight: 700; }
        .cor-vat-amount { color: var(--cor-amber); font-weight: 700; }
        .cor-profit-positive, .cor-mom-up { color: var(--cor-olive); font-weight: 700; }
        .inv-instock, .inv-lowstock, .inv-outstock { height: 20px; padding: 0 8px; border-radius: 4px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; }
        .inv-instock { border: 1px solid var(--cor-olive); background: #f3fae0; color: #6f8c10; }
        .inv-lowstock { border: 1px solid var(--cor-amber); background: #fef5e7; color: var(--cor-amber); }
        .inv-outstock { border: 1px solid var(--cor-red); background: #fde8e8; color: var(--cor-red); }
        .cor-rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: var(--cor-border); color: var(--cor-text-mid); font-size: 11px; font-weight: 800; }
        .cor-rank-1 { background: var(--cor-amber); color: #fff; }
        .cor-rank-2 { background: var(--cor-orange-red); color: #fff; }
        .cor-rank-3 { background: var(--cor-red); color: #fff; }
        .cor-products-table th { height: 36px !important; padding: 0 14px !important; border-bottom: 2px solid var(--cor-border) !important; background: var(--cor-cream) !important; color: var(--cor-burgundy) !important; font-size: 11px !important; font-weight: 800 !important; letter-spacing: .05em !important; text-transform: uppercase !important; white-space: nowrap; }
        .cor-products-table td { height: 40px !important; padding: 0 14px !important; border-bottom: 1px solid var(--cor-border) !important; color: var(--cor-text) !important; font-size: 13px !important; vertical-align: middle !important; }
        .cor-products-table tr:last-child td { border-bottom: 0 !important; }
        .cor-products-table tr:hover td { background: #fdf3ea !important; }
        .cor-products-table tr.top-rank td:first-child { border-left: 3px solid var(--cor-amber); }
        .prod-name { font-weight: 400; }
        .prod-top { color: var(--cor-burgundy) !important; font-weight: 700 !important; }
        .prod-revenue { color: var(--cor-text) !important; font-weight: 800 !important; }
        .cor-pct-cell { display: flex; align-items: center; gap: 8px; }
        .cor-pct-bar-wrap { flex: 1; min-width: 80px; max-width: 200px; height: 8px; overflow: hidden; border-radius: 4px; background: var(--cor-border); }
        .cor-pct-bar { display: block; height: 100%; min-width: 3px; border-radius: 4px; background: var(--cor-olive); transition: width 600ms ease; }
        .cor-pct-label { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; text-align: right; }
        .cor-top-card .tc-value { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .del-breakdown-top { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 30px; }
        .del-pie-wrap { flex: 0 0 auto; }
        .del-legend { min-width: 260px; flex: 1; }
        .del-legend-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 12px; }
        .del-legend-dot { width: 12px; height: 12px; border-radius: 50%; flex: 0 0 auto; }
        .del-legend-name { flex: 1; color: var(--cor-text); }
        .del-legend-amt { min-width: 90px; color: var(--cor-text); font-weight: 700; text-align: right; }
        .del-legend-pct { min-width: 36px; color: var(--cor-text-mid); font-size: 11px; text-align: right; }
        .del-hbar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .del-hbar-label { min-width: 130px; color: var(--cor-text); font-size: 12px; text-align: right; }
        .del-hbar-track { flex: 1; height: 18px; overflow: hidden; border-radius: 4px; background: var(--cor-border); }
        .del-hbar-fill { height: 100%; min-width: 4px; border-radius: 4px; transition: width 600ms ease; }
        .del-hbar-val { min-width: 90px; color: var(--cor-text); font-size: 12px; font-weight: 800; }
        .del-table-tools { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--cor-border); }
        .del-table-tools input, .del-table-tools select { height: 32px; padding: 0 10px; border: 1px solid var(--cor-border); border-radius: 6px; background: var(--cor-surface); color: var(--cor-text); font-size: 12px; }
        .del-table-tools input { flex: 1; min-width: 180px; }
        .del-count { color: var(--cor-text-mid); font-size: 12px; white-space: nowrap; }
        .vat-period-heading { margin: 0 0 14px; color: var(--cor-burgundy); font-size: 15px; font-weight: 800; }
        .vat-second-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .vat-breakdown-card { display: flex; flex-wrap: wrap; align-items: center; gap: 30px; padding: 18px; }
        .vat-net-row td { background: #f3fae0 !important; font-weight: 800; }
        .mon-group-header td { padding: 8px 14px !important; border-top: 2px solid var(--cor-border) !important; border-bottom: 1px solid var(--cor-border) !important; background: var(--cor-cream) !important; }
        .mon-group-label { color: var(--cor-orange-red) !important; font-size: 11px !important; font-weight: 800 !important; letter-spacing: .06em !important; text-transform: uppercase !important; }
        .mon-notes { color: var(--cor-text-light) !important; font-size: 11px !important; text-align: right !important; }
        .mon-highlight td { padding-top: 10px !important; padding-bottom: 10px !important; background: #f3fae0 !important; }
        .cor-chart-body { height: 260px; padding: 16px; }
        .cor-orders-wrap { max-height: 520px; overflow: auto; }
        .cor-orders-table { width: 100%; border-collapse: collapse; }
        .cor-orders-table th { position: sticky; top: 0; z-index: 2; }
        .cor-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 4px; padding: 12px 16px; border-top: 1px solid var(--cor-border); }
        .cor-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 8px; border: 1px solid var(--cor-border); border-radius: 5px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; text-decoration: none; }
        .cor-page-btn.active { border-color: var(--cor-orange-red); background: var(--cor-orange-red); color: #fff; }
        .cor-empty { padding: 16px 18px; color: var(--cor-text-mid); font-size: 13px; }
        @media (max-width: 1100px) { .cor-stat-grid { grid-template-columns: repeat(3, 1fr); } .cor-report-grid, .cor-two-col { grid-template-columns: 1fr; } }
        @media (max-width: 900px) { .cor-summary-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 767px) { .cor-stat-grid, .cor-summary-stat-grid { grid-template-columns: repeat(2, 1fr); } .cor-tab { padding: 0 12px; font-size: 11px; } .cor-filter-panel { margin-top: 18px; } .cor-filter-bar { align-items: stretch; flex-direction: column; } .cor-filter-selects, .cor-date-inputs, .cor-quick-ranges { align-items: stretch; } .cor-filter-bar select, .cor-filter-bar input[type="date"], .cor-btn-apply, .cor-btn-export, .cor-btn-daily, .cor-btn-export-sm { width: 100%; } .del-breakdown-top, .vat-breakdown-card { flex-direction: column; align-items: flex-start; } .del-hbar-label { min-width: 80px; font-size: 11px; } .del-table-tools { align-items: stretch; flex-direction: column; } .del-table-tools input, .del-table-tools select { width: 100%; } }
        @media (max-width: 500px) { .cor-summary-stat-grid, .vat-second-stats { grid-template-columns: 1fr; } }
    </style>

    <section class="module-header">
        <div>
            <p class="page-eyebrow">Sales Operations</p>
            <h1>Customer Orders Report</h1>
            <p class="page-subtitle">Live POS and website order reporting by sales, payment, mode, staff, products and daily trends.</p>
        </div>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php endif; ?>

    <?php $filterPanelOpen = $filters['range'] !== 'month' || $filters['status'] !== 'all' || $filters['mode'] !== 'all' || $filters['payment'] !== 'all'; ?>
    <details class="cor-filter-panel" <?= $filterPanelOpen ? 'open' : '' ?>>
        <summary>
            <i data-lucide="sliders-horizontal" class="filter-icon" aria-hidden="true"></i>
            <span>Filters</span>
        </summary>
    <form class="cor-filter-bar" method="get" data-cor-filter>
        <input type="hidden" name="range" value="<?= cor_e($filters['range']) ?>" data-cor-range>
        <input type="hidden" name="tab" value="<?= cor_e($filters['tab']) ?>" data-cor-tab-input>
        <div class="cor-quick-ranges">
            <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'Year', 'custom' => 'Custom'] as $key => $label): ?>
                <button class="cor-range-btn <?= $filters['range'] === $key ? 'active' : '' ?>" type="button" data-range="<?= cor_e($key) ?>"><?= cor_e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="cor-date-inputs" data-custom-dates style="<?= $filters['range'] !== 'custom' ? 'display:none' : '' ?>">
            <input type="date" name="from" value="<?= cor_e($filters['from']) ?>">
            <span style="color:var(--cor-text-mid)">to</span>
            <input type="date" name="to" value="<?= cor_e($filters['to']) ?>">
        </div>
        <div class="cor-filter-selects">
            <select name="status">
                <option value="all">All Statuses</option>
                <?php foreach (OPS_ORDER_STATUSES as $key => $label): ?>
                    <option value="<?= cor_e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= cor_e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="mode">
                <option value="all">All Modes</option>
                <option value="delivery" <?= $filters['mode'] === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                <option value="collection" <?= $filters['mode'] === 'collection' ? 'selected' : '' ?>>Collection</option>
                <option value="courier" <?= $filters['mode'] === 'courier' ? 'selected' : '' ?>>Courier</option>
            </select>
            <select name="payment">
                <option value="all">All Payments</option>
                <?php foreach ($paymentOptions as $payment): ?>
                    <option value="<?= cor_e($payment) ?>" <?= $filters['payment'] === $payment ? 'selected' : '' ?>><?= cor_e($payment) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="cor-btn-apply" type="submit">Apply</button>
    </form>
    </details>

    <div class="cor-tabs-wrap">
    <nav class="cor-tabs" aria-label="Report tabs">
        <?php
        $tabs = [
            'sales' => 'Sales Summary',
            'products' => 'Products',
            'delivery' => 'Delivery',
            'vat' => 'VAT',
            'monthly' => 'Monthly',
            'inventory' => 'Inventory',
            'orders' => 'All Orders',
        ];
        foreach ($tabs as $key => $label):
        ?>
            <button class="cor-tab <?= $filters['tab'] === $key ? 'active' : '' ?>" type="button" data-tab="<?= cor_e($key) ?>"><?= cor_e($label) ?></button>
        <?php endforeach; ?>
    </nav>
    </div>

    <section id="tab-sales" class="cor-tab-content <?= $filters['tab'] === 'sales' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Sales summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Sales</div>
                <div class="tc-value"><?= cor_money($summary['total_revenue']) ?></div>
                <div class="tc-sub"><?= number_format($summary['total_orders']) ?> orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Tax Collected</div>
                <div class="tc-value"><?= cor_money($summary['tax_collected'] > 0 ? $summary['tax_collected'] : ($summary['total_revenue'] * (0.15 / 1.15))) ?></div>
                <div class="tc-sub">15% VAT included</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Discounts</div>
                <div class="tc-value"><?= cor_money($summary['discounts']) ?></div>
                <div class="tc-sub">&nbsp;</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Order</div>
                <div class="tc-value"><?= cor_money($summary['aov']) ?></div>
                <div class="tc-sub">per transaction</div>
            </article>
        </section>

        <section class="cor-pay-cards" aria-label="Payment quick cards">
            <?php foreach ($paymentRows as $row): ?>
                <a class="cor-pay-card" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'orders', 'payment' => $row['label']]))) ?>">
                    <div class="pc-method"><?= cor_e($row['label']) ?></div>
                    <div class="pc-amount"><?= cor_money($row['rev']) ?></div>
                    <div class="pc-link">Click to see orders</div>
                </a>
            <?php endforeach; ?>
            <article class="cor-pay-card refunds-card">
                <div class="pc-method">Refunds</div>
                <div class="pc-amount"><?= cor_money($refundSummary['amount']) ?></div>
                <div class="pc-link">&nbsp;</div>
            </article>
        </section>

        <section class="cor-summary-actions" aria-label="Summary actions">
            <a class="cor-btn-daily" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'sales']))) ?>">Daily Sales Summary</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'csv'])) ?>">CSV</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'excel'])) ?>">Excel</a>
            <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
        </section>

        <div class="cor-two-col">
            <article class="cor-report-card">
                <div class="card-head"><h3>By Payment Method</h3><span><?= number_format(count($paymentRows)) ?> methods</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Method</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentRows as $row): $pct = $summary['total_revenue'] > 0 ? ((float) $row['rev'] / $summary['total_revenue']) * 100 : 0; ?>
                            <tr><td><span class="cor-pill pay-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e($row['label']) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="cor-report-card">
                <div class="card-head"><h3>By Cashier</h3><span>Packed by / assigned staff</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Cashier</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($staffRows as $row): $pct = $summary['total_orders'] > 0 ? ((int) $row['cnt'] / $summary['total_orders']) * 100 : 0; ?>
                            <tr><td><?= cor_e($row['label']) ?></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$staffRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>

        <div class="cor-two-col">
            <article class="cor-report-card">
                <div class="card-head"><h3>By Status</h3><span><?= number_format(count($statusRows)) ?> statuses</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Status</th><th>Orders</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($statusRows as $row): ?>
                            <tr><td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e(cor_order_status_label((string) $row['label'])) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$statusRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="cor-chart-card">
                <div class="card-head"><h3>Daily Sales (last 14 days)</h3><span>Revenue trend</span></div>
                <div class="cor-chart-body"><canvas id="daily14Chart"></canvas></div>
                <?php if (!$daily14Rows): ?><div class="cor-empty">No daily sales found.</div><?php endif; ?>
            </article>
        </div>
    </section>

    <section id="tab-products" class="cor-tab-content <?= $filters['tab'] === 'products' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Product sales summary">
            <article class="cor-top-card">
                <div class="tc-label">Products Sold</div>
                <div class="tc-value"><?= number_format($productsSold) ?></div>
                <div class="tc-sub">unique SKUs</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Top Product</div>
                <div class="tc-value" style="font-size:16px;"><?= $topProduct ? cor_e(cor_short_text($topProduct['product_name'], 30)) : '-' ?></div>
                <div class="tc-sub"><?= $topProduct ? cor_money($topProduct['rev']) : '&nbsp;' ?></div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Total Units</div>
                <div class="tc-value"><?= number_format($totalProductUnits, 2) ?></div>
                <div class="tc-sub">&nbsp;</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Revenue</div>
                <div class="tc-value"><?= cor_money($avgProductRevenue) ?></div>
                <div class="tc-sub">per product</div>
            </article>
        </section>

        <section class="cor-summary-actions" aria-label="Product export actions">
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'products', 'export' => 'csv']))) ?>">↓CSV</a>
            <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'products', 'export' => 'excel']))) ?>">↓Excel</a>
            <button class="cor-btn-export-sm" type="button" onclick="window.print()">↓PDF</button>
        </section>

        <article class="cor-report-card">
            <div class="card-head"><h3>Revenue per Product</h3><span><?= cor_e($filters['from']) ?> &rarr; <?= cor_e($filters['to']) ?></span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th style="width:44px;">#</th><th>Product</th><th style="width:120px;">Units Sold</th><th style="width:140px;">Revenue</th><th style="width:280px;">% of Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($productRows as $index => $row): $rank = $index + 1; $pct = $totalProductRevenue > 0 ? ((float) $row['rev'] / $totalProductRevenue) * 100 : 0; ?>
                        <tr class="<?= $rank <= 3 ? 'top-rank' : '' ?>">
                            <td><span class="cor-rank cor-rank-<?= $rank <= 3 ? $rank : 'n' ?>"><?= number_format($rank) ?></span></td>
                            <td class="prod-name <?= $rank <= 3 ? 'prod-top' : '' ?>"><?= cor_e($row['product_name']) ?></td>
                            <td><?= number_format((float) $row['qty'], 2) ?></td>
                            <td class="prod-revenue"><?= cor_money($row['rev']) ?></td>
                            <td>
                                <div class="cor-pct-cell">
                                    <div class="cor-pct-bar-wrap"><span class="cor-pct-bar" style="width:<?= cor_e($pct) ?>%"></span></div>
                                    <span class="cor-pct-label"><?= cor_pct($pct) ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$productRows): ?><tr><td colspan="5" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">No product sales found for this period.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-delivery" class="cor-tab-content <?= $filters['tab'] === 'delivery' ? 'active' : '' ?>">
        <?php $deliveryColours = ['#A8CA19', '#AB3619', '#F07420', '#BB1B21', '#721B1A', '#6B4C3B', '#EDE3D8']; ?>
        <section class="cor-summary-stat-grid" aria-label="Delivery summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Delivery Fees</div>
                <div class="tc-value"><?= cor_money($deliverySummary['fees']) ?></div>
                <div class="tc-sub">collected this period</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Orders With Delivery</div>
                <div class="tc-value"><?= number_format($deliverySummary['orders']) ?></div>
                <div class="tc-sub">shipped orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Avg Delivery Fee</div>
                <div class="tc-value"><?= cor_money($deliverySummary['avg']) ?></div>
                <div class="tc-sub">per order</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Courier Orders</div>
                <div class="tc-value"><?= number_format($deliverySummary['courier']) ?></div>
                <div class="tc-sub">this period</div>
            </article>
        </section>

        <article class="cor-report-card">
            <div class="card-head"><h3>Delivery Type Breakdown</h3><span><?= number_format(count($deliveryRows)) ?> types</span></div>
            <div style="padding:18px;">
                <div class="del-breakdown-top">
                    <div class="del-pie-wrap">
                        <canvas id="delPieChart" width="160" height="160"></canvas>
                    </div>
                    <div class="del-legend">
                        <div style="font-size:13px;font-weight:700;color:var(--cor-burgundy);margin-bottom:10px;">Delivery by Type</div>
                        <?php foreach ($deliveryRows as $index => $row): $colour = $deliveryColours[$index % count($deliveryColours)]; $pct = $deliverySummary['total_cost'] > 0 ? ((float) $row['total_cost'] / $deliverySummary['total_cost']) * 100 : 0; ?>
                            <div class="del-legend-row">
                                <span class="del-legend-dot" style="background:<?= cor_e($colour) ?>"></span>
                                <span class="del-legend-name"><?= cor_e(ucwords((string) $row['label'])) ?></span>
                                <span class="del-legend-amt"><?= cor_money($row['total_cost']) ?></span>
                                <span class="del-legend-pct"><?= cor_pct($pct) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$deliveryRows): ?><div class="cor-empty" style="padding:0;">No delivery records found.</div><?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <?php foreach ($deliveryRows as $index => $row): $colour = $deliveryColours[$index % count($deliveryColours)]; $barPct = $deliverySummary['total_cost'] > 0 ? ((float) $row['total_cost'] / $deliverySummary['total_cost']) * 100 : 0; ?>
                        <div class="del-hbar-row">
                            <div class="del-hbar-label"><?= cor_e(ucwords((string) $row['label'])) ?></div>
                            <div class="del-hbar-track"><div class="del-hbar-fill" style="width:<?= cor_e($barPct) ?>%;background:<?= cor_e($colour) ?>"></div></div>
                            <div class="del-hbar-val"><?= cor_money($row['total_cost']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Delivery Type</th><th>Orders</th><th>Total Cost</th><th>Avg Per Order</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveryRows as $row): ?>
                        <tr><td style="font-weight:600;"><?= cor_e(ucwords((string) $row['label'])) ?></td><td style="color:var(--cor-orange-red);font-weight:700;"><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['total_cost']) ?></td><td><?= cor_money($row['avg_cost']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td style="color:var(--cor-orange-red);"><?= number_format($deliverySummary['total_orders']) ?></td><td><?= cor_money($deliverySummary['total_cost']) ?></td><td><?= cor_money($deliverySummary['breakdown_avg']) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'delivery', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'delivery', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-chart-card">
            <div class="card-head"><h3>Delivery Orders by Day</h3><span>Selected period</span></div>
            <div style="height:180px;padding:16px;"><canvas id="delDayChart"></canvas></div>
            <?php if (!$deliveryDayRows): ?><div class="cor-empty">No delivery day records found.</div><?php endif; ?>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3>Delivery Orders</h3><span><?= number_format($deliveryOrdersCount) ?> orders</span></div>
            <form class="del-table-tools" method="get">
                <?php foreach ($queryBase as $key => $value): ?><input type="hidden" name="<?= cor_e($key) ?>" value="<?= cor_e($value) ?>"><?php endforeach; ?>
                <input type="hidden" name="tab" value="delivery">
                <input type="text" name="del_search" value="<?= cor_e($deliverySearch) ?>" placeholder="Search customer or order #...">
                <select name="del_status" onchange="this.form.submit()">
                    <option value="all">All</option>
                    <?php foreach (OPS_ORDER_STATUSES as $key => $label): ?>
                        <option value="<?= cor_e($key) ?>" <?= $deliveryStatus === $key ? 'selected' : '' ?>><?= cor_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="cor-btn-apply" type="submit">Search</button>
                <span class="del-count"><?= number_format($deliveryOrdersCount) ?> orders</span>
            </form>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Order #</th><th>Customer</th><th>Mobile</th><th>Delivery Type</th><th>Cost</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveryOrderRows as $row): ?>
                        <tr>
                            <td style="font-weight:800;color:var(--cor-burgundy);"><?= cor_e($row['order_number']) ?></td>
                            <td style="color:var(--cor-orange-red);"><?= cor_e($row['customer_name'] ?: 'Customer') ?></td>
                            <td><?= cor_e($row['customer_contact']) ?></td>
                            <td><?= cor_e(ucwords((string) $row['order_type'])) ?></td>
                            <td><?= cor_money($row['delivery_cost']) ?></td>
                            <td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['status'])) ?>"><?= cor_e(cor_order_status_label((string) $row['status'])) ?></span></td>
                            <td><?= cor_e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$deliveryOrderRows): ?><tr><td colspan="7" style="height:auto;padding:24px !important;text-align:center;color:var(--cor-text-light) !important;">No delivery orders found for this period.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-vat" class="cor-tab-content <?= $filters['tab'] === 'vat' ? 'active' : '' ?>">
        <h2 class="vat-period-heading">VAT Period Summary - <?= cor_e($filters['from']) ?> to <?= cor_e($filters['to']) ?></h2>

        <section class="cor-summary-stat-grid" aria-label="VAT period summary">
            <article class="cor-top-card">
                <div class="tc-label">Total Invoice Sales</div>
                <div class="tc-value"><?= cor_money($vatMetrics['total_sales']) ?></div>
                <div class="tc-sub"><?= number_format((int) $vatMetrics['order_count']) ?> orders</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">Shipping Excluded</div>
                <div class="tc-value"><?= cor_money($vatMetrics['shipping']) ?></div>
                <div class="tc-sub">not subject to VAT</div>
            </article>
            <article class="cor-top-card">
                <div class="tc-label">VATable Sales</div>
                <div class="tc-value"><?= cor_money($vatMetrics['vatable_sales']) ?></div>
                <div class="tc-sub">products only</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-amber);">
                <div class="tc-label">VAT Collected (15%)</div>
                <div class="tc-value" style="color:var(--cor-amber);"><?= cor_money($vatMetrics['vat_collected']) ?></div>
                <div class="tc-sub">on product sales</div>
            </article>
        </section>

        <section class="vat-second-stats" aria-label="VAT net summary">
            <article class="cor-top-card">
                <div class="tc-label">Sales Ex-VAT</div>
                <div class="tc-value"><?= cor_money($vatMetrics['sales_ex_vat']) ?></div>
                <div class="tc-sub">ex-VAT revenue</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive);">
                <div class="tc-label">Net VAT Payable</div>
                <div class="tc-value" style="color:var(--cor-olive);"><?= cor_money($vatMetrics['net_vat_payable']) ?></div>
                <div class="tc-sub"><?= $vatMetrics['refunds'] > 0 ? 'after refunds' : 'no refunds' ?></div>
            </article>
        </section>

        <article class="cor-report-card">
            <?php
            $vatBreakdownItems = [
                ['label' => 'Ex-VAT Revenue', 'amount' => $vatMetrics['sales_ex_vat'], 'colour' => '#AB3619'],
                ['label' => 'VAT Collected', 'amount' => $vatMetrics['vat_collected'], 'colour' => '#A8CA19'],
                ['label' => 'Shipping', 'amount' => $vatMetrics['shipping'], 'colour' => '#F07420'],
            ];
            $vatBreakdownTotal = array_reduce($vatBreakdownItems, static fn(float $carry, array $item): float => $carry + (float) $item['amount'], 0.0);
            ?>
            <div class="vat-breakdown-card">
                <canvas id="vatPieChart" width="160" height="160" style="flex:0 0 auto;"></canvas>
                <div class="del-legend">
                    <div style="margin-bottom:14px;color:var(--cor-burgundy);font-size:14px;font-weight:800;">VAT Breakdown</div>
                    <?php foreach ($vatBreakdownItems as $item): $pct = $vatBreakdownTotal > 0 ? ((float) $item['amount'] / $vatBreakdownTotal) * 100 : 0; ?>
                        <div class="del-legend-row">
                            <span class="del-legend-dot" style="background:<?= cor_e($item['colour']) ?>"></span>
                            <span class="del-legend-name"><?= cor_e($item['label']) ?></span>
                            <span class="del-legend-amt"><?= cor_money($item['amount']) ?></span>
                            <span class="del-legend-pct"><?= cor_pct($pct) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Metric</th><th>Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Period</td><td><?= cor_e($filters['from']) ?> - <?= cor_e($filters['to']) ?></td></tr>
                        <tr><td>Total Orders</td><td><?= number_format((int) $vatMetrics['order_count']) ?></td></tr>
                        <tr><td>Total Invoice Sales (Incl. VAT)</td><td><?= cor_money($vatMetrics['total_sales']) ?></td></tr>
                        <tr><td>Shipping / Delivery Amount</td><td><?= cor_money($vatMetrics['shipping']) ?></td></tr>
                        <tr><td>VATable Sales (Sales - Shipping)</td><td><?= cor_money($vatMetrics['vatable_sales']) ?></td></tr>
                        <tr><td>VAT Amount (15/115 of VATable)</td><td class="cor-vat-amount"><?= cor_money($vatMetrics['vat_collected']) ?></td></tr>
                        <tr><td>Sales Excluding VAT</td><td><?= cor_money($vatMetrics['sales_ex_vat']) ?></td></tr>
                        <tr><td>Refunds Issued</td><td><?= cor_money($vatMetrics['refunds']) ?></td></tr>
                        <tr><td>VAT on Refunds</td><td><?= cor_money($vatMetrics['vat_on_refunds']) ?></td></tr>
                        <tr class="vat-net-row"><td>Net VAT Payable</td><td class="cor-profit-positive"><?= cor_money($vatMetrics['net_vat_payable']) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3>Daily VAT Breakdown</h3><span>VATable sales exclude shipping</span></div>
            <div style="padding:14px 18px;">
                <?php $maxDailyVat = max(array_merge([1], array_map(static fn(array $row): float => (float) $row['vat_amount'], $vatDailyRows))); ?>
                <?php foreach ($vatDailyRows as $row): $barWidth = $maxDailyVat > 0 ? ((float) $row['vat_amount'] / $maxDailyVat) * 100 : 0; ?>
                    <div class="del-hbar-row" style="margin-bottom:6px;">
                        <div class="del-hbar-label" style="min-width:100px;font-size:11px;"><?= cor_e(date('d M', strtotime((string) $row['d']))) ?></div>
                        <div class="del-hbar-track" style="height:22px;"><div class="del-hbar-fill" style="width:<?= cor_e((string) $barWidth) ?>%;background:var(--cor-olive);"></div></div>
                        <div class="del-hbar-val" style="color:var(--cor-olive);"><?= cor_money($row['vat_amount']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$vatDailyRows): ?><div class="cor-empty" style="padding-left:0;">No daily VAT records found.</div><?php endif; ?>
            </div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Date</th><th>Orders</th><th>Total Sales</th><th>Shipping</th><th>VATable Sales</th><th>Ex-VAT</th><th>VAT (15%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatDailyRows as $row): ?>
                        <tr>
                            <td><?= cor_e(date('d M Y', strtotime((string) $row['d']))) ?></td>
                            <td><?= number_format((int) $row['orders']) ?></td>
                            <td><?= cor_money($row['total_sales']) ?></td>
                            <td><?= cor_money($row['shipping']) ?></td>
                            <td><?= cor_money($row['vatable']) ?></td>
                            <td><?= cor_money($row['ex_vat']) ?></td>
                            <td class="cor-profit-positive"><?= cor_money($row['vat_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($vatDailyRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((int) $vatDailyTotals['orders']) ?></td><td><?= cor_money($vatDailyTotals['total_sales']) ?></td><td><?= cor_money($vatDailyTotals['shipping']) ?></td><td><?= cor_money($vatDailyTotals['vatable']) ?></td><td><?= cor_money($vatDailyTotals['ex_vat']) ?></td><td class="cor-profit-positive"><?= cor_money($vatDailyTotals['vat_amount']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="7">No VAT records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'daily', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'daily', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3><span style="display:inline-block;width:12px;height:12px;margin-right:6px;border-radius:2px;background:var(--cor-orange-red);vertical-align:middle;"></span>VAT by Payment Method</h3><span><?= number_format(count($vatPaymentRows)) ?> methods</span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Payment Method</th><th>Orders</th><th>Total (Incl. VAT)</th><th>VAT Portion</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatPaymentRows as $row): ?>
                        <tr><td><?= cor_e($row['method']) ?></td><td><?= number_format((int) $row['orders']) ?></td><td><?= cor_money($row['total_incl']) ?></td><td><?= cor_money($row['vat_portion']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($vatPaymentRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((int) $vatPaymentTotals['orders']) ?></td><td><?= cor_money($vatPaymentTotals['total_incl']) ?></td><td class="cor-profit-positive"><?= cor_money($vatPaymentTotals['vat_portion']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="4">No VAT payment records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'payment', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'payment', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>

        <article class="cor-report-card">
            <div class="card-head"><h3><span style="display:inline-block;width:12px;height:12px;margin-right:6px;border-radius:2px;background:var(--cor-amber);vertical-align:middle;"></span>Product VAT Report</h3><span><?= number_format(count($vatProductRows)) ?> products</span></div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th>Product</th><th>Qty Sold</th><th>Total Sales (Incl.)</th><th>VAT Portion</th></tr></thead>
                    <tbody>
                    <?php foreach ($vatProductRows as $row): ?>
                        <tr><td><?= cor_e($row['product']) ?></td><td><?= number_format((float) $row['qty_sold'], 2) ?></td><td><?= cor_money($row['total_incl']) ?></td><td><?= cor_money($row['vat_portion']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($vatProductRows): ?>
                        <tr style="border-top:2px solid var(--cor-border);font-weight:800;"><td>Total</td><td><?= number_format((float) $vatProductTotals['qty'], 2) ?></td><td><?= cor_money($vatProductTotals['total_incl']) ?></td><td class="cor-profit-positive"><?= cor_money($vatProductTotals['vat_portion']) ?></td></tr>
                    <?php else: ?>
                        <tr><td colspan="4">No product VAT records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'products', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'vat', 'section' => 'products', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>
    </section>

    <section id="tab-monthly" class="cor-tab-content <?= $filters['tab'] === 'monthly' ? 'active' : '' ?>">
        <section class="cor-summary-stat-grid" aria-label="Monthly business summary">
            <article class="cor-top-card">
                <div class="tc-label">Net Sales</div>
                <div class="tc-value"><?= cor_money($monthlySummary['net_sales']) ?></div>
                <div class="tc-sub"><?= number_format((int) $monthlySummary['order_count']) ?> orders</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-amber);">
                <div class="tc-label">VAT Payable</div>
                <div class="tc-value" style="color:var(--cor-amber);"><?= cor_money($monthlySummary['vat']) ?></div>
                <div class="tc-sub">on product sales</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-olive);">
                <div class="tc-label">Gross Profit (Est.)</div>
                <div class="tc-value" style="color:var(--cor-olive);"><?= cor_money($monthlySummary['gross_profit']) ?></div>
                <div class="tc-sub"><?= number_format((int) $monthlySummary['margin_pct']) ?>% margin</div>
            </article>
            <article class="cor-top-card" style="border-left:4px solid var(--cor-burgundy);">
                <div class="tc-label">Stock Value</div>
                <div class="tc-value" style="color:var(--cor-burgundy);"><?= cor_money($monthlySummary['stock_cost']) ?></div>
                <div class="tc-sub">at cost price</div>
            </article>
        </section>

        <article class="cor-report-card">
            <div style="padding:18px 20px 10px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span style="font-size:16px;" aria-hidden="true">◷</span>
                    <h3 style="margin:0;color:var(--cor-burgundy);font-size:14px;font-weight:800;">Monthly Business Summary - <?= cor_e($filters['from']) ?> to <?= cor_e($filters['to']) ?></h3>
                </div>
                <p style="margin:0 0 14px;color:var(--cor-text-light);font-size:11px;">Profit figures are estimates based on current inventory cost prices.</p>
            </div>
            <div class="cor-table-wrap">
                <table class="cor-products-table">
                    <thead><tr><th style="width:55%;">Metric</th><th>Amount</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Sales</td></tr>
                        <tr><td>Total Invoice Sales</td><td><?= cor_money($monthlySummary['total_sales']) ?></td><td class="mon-notes"><?= number_format((int) $monthlySummary['order_count']) ?> orders</td></tr>
                        <tr><td>Cash Sales</td><td><?= cor_money($monthlySummary['cash']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Card / Swipe Sales</td><td><?= cor_money($monthlySummary['card']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>EFT Sales</td><td><?= cor_money($monthlySummary['eft']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Other / Wallet Sales</td><td><?= cor_money($monthlySummary['wallet']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Delivery Fees</td><td><?= cor_money($monthlySummary['shipping']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Refunds Issued</td><td style="color:var(--cor-red);"><?= $monthlySummary['refunds'] > 0 ? '-' : '' ?><?= cor_money($monthlySummary['refunds']) ?></td><td class="mon-notes"></td></tr>
                        <tr style="border-top:2px solid var(--cor-border);"><td style="font-weight:800;">Net Sales</td><td style="font-weight:800;"><?= cor_money($monthlySummary['net_sales']) ?></td><td></td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">VAT</td></tr>
                        <tr><td>VATable Sales (excl. shipping)</td><td><?= cor_money($monthlySummary['vatable']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>VAT Amount (15%)</td><td class="cor-vat-amount"><?= cor_money($monthlySummary['vat']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Sales Excl. VAT</td><td><?= cor_money($monthlySummary['ex_vat']) ?></td><td class="mon-notes"></td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Profit (Estimated)</td></tr>
                        <tr><td>Net Sales</td><td><?= cor_money($monthlySummary['net_sales']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Shipping Deducted</td><td style="color:var(--cor-red);">-<?= cor_money($monthlySummary['shipping']) ?></td><td class="mon-notes"></td></tr>
                        <tr><td>Cost of Goods Sold (est.)</td><td style="color:var(--cor-red);"><?= $monthlySummary['cogs'] > 0 ? '-' : '' ?><?= cor_money($monthlySummary['cogs']) ?></td><td class="mon-notes">cost sync pending</td></tr>
                        <tr class="mon-highlight" style="border-top:2px solid var(--cor-border);"><td style="font-weight:800;">Gross Profit (est.)</td><td class="cor-profit-positive" style="font-weight:800;"><?= cor_money($monthlySummary['gross_profit']) ?></td><td class="mon-notes" style="color:var(--cor-olive) !important;"><?= number_format((int) $monthlySummary['margin_pct']) ?>% margin</td></tr>

                        <tr class="mon-group-header"><td colspan="3" class="mon-group-label">Inventory</td></tr>
                        <tr><td>Stock Cost Value</td><td><?= cor_money($monthlySummary['stock_cost']) ?></td><td class="mon-notes">cost sync pending</td></tr>
                        <tr><td>Stock Retail Value</td><td><?= cor_money($monthlySummary['stock_retail']) ?></td><td class="mon-notes">stock sync pending</td></tr>
                        <tr><td>Potential Margin</td><td class="cor-profit-positive" style="font-weight:800;"><?= cor_money($monthlySummary['potential_margin']) ?></td><td class="mon-notes"></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cor-summary-actions" style="padding:12px 16px;margin-bottom:0;">
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'monthly', 'export' => 'csv']))) ?>">CSV</a>
                <a class="cor-btn-export-sm" href="?<?= cor_e(http_build_query(array_merge($queryBase, ['tab' => 'monthly', 'export' => 'excel']))) ?>">Excel</a>
                <button class="cor-btn-export-sm" type="button" onclick="window.print()">PDF</button>
            </div>
        </article>
    </section>

    <section id="tab-inventory" class="cor-tab-content <?= $filters['tab'] === 'inventory' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Inventory Movement</h3><span>Read-only product movement from synced order items</span></div>
            <div class="cor-table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>SKU</th><th>Qty Sold</th><th>Orders</th><th>Stock Status</th><th>Last Sold</th></tr></thead>
                    <tbody>
                    <?php foreach ($inventoryRows as $row): ?>
                        <tr><td><?= cor_e($row['product_name']) ?></td><td><?= cor_e($row['sku']) ?></td><td><?= number_format((float) $row['sold_qty'], 2) ?></td><td><?= number_format((int) $row['order_count']) ?></td><td><span class="inv-instock">Synced</span></td><td><?= $row['last_sold_at'] ? cor_e(date('d M H:i', strtotime((string) $row['last_sold_at']))) : '-' ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$inventoryRows): ?><tr><td colspan="6">No inventory movement found from synced order items.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-orders" class="cor-tab-content <?= $filters['tab'] === 'orders' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Full Orders Table</h3><span>50 rows per page</span></div>
            <div class="cor-orders-wrap">
                <table class="cor-orders-table">
                    <thead><tr><th>Order ID</th><th>Date</th><th>Customer</th><th>Mobile</th><th>Mode</th><th>Payment</th><th>Amount</th><th>Status</th><th>Packed By</th></tr></thead>
                    <tbody>
                    <?php foreach ($orderRows as $row): ?>
                        <tr>
                            <td><?= cor_e($row['order_number']) ?></td>
                            <td><?= cor_e(date('d M H:i', strtotime((string) $row['created_at']))) ?></td>
                            <td><?= cor_e($row['customer_name']) ?></td>
                            <td><?= cor_e($row['customer_contact']) ?></td>
                            <td><span class="cor-pill mode-<?= cor_e(cor_slug($row['order_type'])) ?>"><?= cor_e(ucwords((string) $row['order_type'])) ?></span></td>
                            <td><span class="cor-pill pay-<?= cor_e(cor_slug($row['payment_method'] ?: 'other')) ?>"><?= cor_e($row['payment_method'] ?: 'Other') ?></span></td>
                            <td><?= cor_money($row['total_amount']) ?></td>
                            <td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['status'])) ?>"><?= cor_e(cor_order_status_label((string) $row['status'])) ?></span></td>
                            <td><?= cor_e($row['packer_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orderRows): ?><tr><td colspan="9">No matching orders found for selected filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cor-pagination">
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a class="cor-page-btn <?= $i === $page ? 'active' : '' ?>" href="?<?= cor_e(http_build_query($queryBase + ['tab' => 'orders', 'p' => $i])) ?>"><?= number_format($i) ?></a>
                <?php endfor; ?>
            </div>
        </article>
    </section>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-cor-filter]');
  var rangeInput = document.querySelector('[data-cor-range]');
  var tabInput = document.querySelector('[data-cor-tab-input]');
  var customDates = document.querySelector('[data-custom-dates]');

  document.querySelectorAll('[data-range]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!form || !rangeInput) return;
      rangeInput.value = button.dataset.range || 'month';
      if (customDates) customDates.style.display = rangeInput.value === 'custom' ? '' : 'none';
      if (rangeInput.value !== 'custom') form.submit();
      document.querySelectorAll('[data-range]').forEach(function (btn) { btn.classList.remove('active'); });
      button.classList.add('active');
    });
  });

  document.querySelectorAll('.cor-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.cor-tab').forEach(function (item) { item.classList.remove('active'); });
      document.querySelectorAll('.cor-tab-content').forEach(function (item) { item.classList.remove('active'); });
      tab.classList.add('active');
      var panel = document.getElementById('tab-' + tab.dataset.tab);
      if (panel) panel.classList.add('active');
      if (tabInput) tabInput.value = tab.dataset.tab || 'sales';
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab.dataset.tab || 'sales');
      history.replaceState({}, '', url.toString());
    });
  });

  var daily14El = document.getElementById('daily14Chart');
  if (daily14El && window.Chart) {
    new Chart(daily14El.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_column($daily14Rows, 'd'), JSON_UNESCAPED_SLASHES) ?>,
        datasets: [{
          label: 'Revenue (N$)',
          data: <?= json_encode(array_map(static fn(array $row): float => round((float) $row['rev'], 2), $daily14Rows), JSON_UNESCAPED_SLASHES) ?>,
          borderColor: '#AB3619',
          backgroundColor: 'rgba(171,54,25,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { display: false } },
          y: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { color: '#EDE3D8' } }
        }
      }
    });
  }

  var delPieEl = document.getElementById('delPieChart');
  var delPieLabels = <?= json_encode(array_map(static fn(array $row): string => ucwords((string) $row['label']), $deliveryRows), JSON_UNESCAPED_SLASHES) ?>;
  var delPieData = <?= json_encode(array_map(static fn(array $row): float => round((float) $row['total_cost'], 2), $deliveryRows), JSON_UNESCAPED_SLASHES) ?>;
  var delPieColors = <?= json_encode(array_map(static fn(int $index): string => ['#A8CA19', '#AB3619', '#F07420', '#BB1B21', '#721B1A', '#6B4C3B', '#EDE3D8'][$index % 7], array_keys($deliveryRows)), JSON_UNESCAPED_SLASHES) ?>;
  if (delPieEl && window.Chart && delPieData.length) {
    new Chart(delPieEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: delPieLabels,
        datasets: [{ data: delPieData, backgroundColor: delPieColors, borderWidth: 2, borderColor: '#FDF6EE' }]
      },
      options: {
        responsive: false,
        plugins: { legend: { display: false } },
        cutout: '55%'
      }
    });
  }

  var delDayEl = document.getElementById('delDayChart');
  if (delDayEl && window.Chart) {
    new Chart(delDayEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_column($deliveryDayRows, 'd'), JSON_UNESCAPED_SLASHES) ?>,
        datasets: [{
          label: 'Orders',
          data: <?= json_encode(array_map(static fn(array $row): int => (int) $row['orders'], $deliveryDayRows), JSON_UNESCAPED_SLASHES) ?>,
          borderColor: '#AB3619',
          backgroundColor: 'rgba(171,54,25,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B' }, grid: { display: false } },
          y: { ticks: { font: { family: 'Inter', size: 10 }, color: '#6B4C3B', precision: 0 }, grid: { color: '#EDE3D8' } }
        }
      }
    });
  }

  var vatPieEl = document.getElementById('vatPieChart');
  var vatPieData = <?= json_encode([
      round((float) $vatMetrics['sales_ex_vat'], 2),
      round((float) $vatMetrics['vat_collected'], 2),
      round((float) $vatMetrics['shipping'], 2),
  ], JSON_UNESCAPED_SLASHES) ?>;
  if (vatPieEl && window.Chart && vatPieData.some(function (value) { return value > 0; })) {
    new Chart(vatPieEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['Ex-VAT Revenue', 'VAT Collected', 'Shipping'],
        datasets: [{
          data: vatPieData,
          backgroundColor: ['#AB3619', '#A8CA19', '#F07420'],
          borderWidth: 2,
          borderColor: '#FDF6EE'
        }]
      },
      options: {
        responsive: false,
        plugins: { legend: { display: false } },
        cutout: '55%'
      }
    });
  }
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
