<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Customer Orders Report | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$hasTotalAmount = $ready && ops_column_exists('ops_orders', 'total_amount');
$hasWooOrder = $ready && ops_column_exists('ops_orders', 'woo_order_id');
$hasCosts = $ready && ops_table_exists('final_product_costs') && ops_column_exists('ops_order_items', 'woo_product_id');
$hasProductTotal = $ready && ops_column_exists('ops_orders', 'product_total');
$hasTaxTotal = $ready && ops_column_exists('ops_orders', 'tax_total');
$hasShippingTotal = $ready && ops_column_exists('ops_orders', 'shipping_total');
$hasShippingTaxTotal = $ready && ops_column_exists('ops_orders', 'shipping_tax_total');
$hasDiscountTotal = $ready && ops_column_exists('ops_orders', 'discount_total');
$hasRefundTotal = $ready && ops_column_exists('ops_orders', 'refund_total');
$hasTimingColumns = $ready
    && ops_column_exists('ops_orders', 'packing_started_at')
    && ops_column_exists('ops_orders', 'completed_at');

if ($ready) {
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ops_report_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
    } catch (Throwable $e) {
        // The report can still run without saved dashboard settings.
    }
}

function order_report_money(float $amount): string
{
    return 'N$ ' . number_format($amount, 2);
}

function order_report_percent(float $value): string
{
    return number_format($value, 1) . '%';
}

function order_report_source_case(bool $hasWooOrder): string
{
    if (!$hasWooOrder) {
        return "'manual'";
    }

    return "CASE WHEN o.woo_order_id IS NULL OR o.woo_order_id = 0 THEN 'manual' ELSE 'website' END";
}

function order_report_setting(string $key, string $default = ''): string
{
    if (!ops_table_exists('ops_report_settings')) {
        return $default;
    }

    $rows = ops_rows('SELECT setting_value FROM ops_report_settings WHERE setting_key = ? LIMIT 1', [$key]);

    return (string) ($rows[0]['setting_value'] ?? $default);
}

function order_report_save_setting(string $key, string $value): void
{
    if (!ops_table_exists('ops_report_settings')) {
        throw new RuntimeException('Report settings table is not available yet.');
    }

    $stmt = db()->prepare(
        "INSERT INTO ops_report_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$key, $value]);
}

function order_report_business_start(DateTimeImmutable $time): DateTimeImmutable
{
    return $time->setTime(8, 0, 0);
}

function order_report_business_end(DateTimeImmutable $time): DateTimeImmutable
{
    return $time->setTime(17, 0, 0);
}

function order_report_normalize_business_start(DateTimeImmutable $time): DateTimeImmutable
{
    $start = order_report_business_start($time);
    $end = order_report_business_end($time);

    if ($time < $start) {
        return $start;
    }

    if ($time >= $end) {
        return $start->modify('+1 day');
    }

    return $time;
}

function order_report_business_minutes(?string $startValue, ?string $endValue): ?float
{
    if (!$startValue || !$endValue) {
        return null;
    }

    try {
        $start = order_report_normalize_business_start(new DateTimeImmutable($startValue));
        $end = new DateTimeImmutable($endValue);
    } catch (Throwable $e) {
        return null;
    }

    if ($end <= $start) {
        return 0.0;
    }

    $minutes = 0.0;
    $cursor = $start;

    while ($cursor < $end) {
        $dayStart = order_report_business_start($cursor);
        $dayEnd = order_report_business_end($cursor);
        $segmentStart = $cursor > $dayStart ? $cursor : $dayStart;
        $segmentEnd = $end < $dayEnd ? $end : $dayEnd;

        if ($segmentEnd > $segmentStart) {
            $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
        }

        $cursor = $dayStart->modify('+1 day');
    }

    return $minutes;
}

function order_report_duration(?float $minutes): string
{
    if ($minutes === null) {
        return 'Not enough data';
    }

    $minutes = max(0, (int) round($minutes));
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    if ($hours <= 0) {
        return $remaining . ' min';
    }

    return $hours . 'h ' . $remaining . 'm';
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40) ?: 'manual_order';

        if ($action === 'save_monthly_goal') {
            $goal = max(0, (float) ($_POST['monthly_goal'] ?? 0));
            order_report_save_setting('monthly_sales_goal', number_format($goal, 2, '.', ''));
            $message = 'Monthly sales goal saved.';
        } else {
        $itemCount = max(1, (int) ($_POST['item_count'] ?? 1));
        $orderType = ops_post_string('order_type', 30) ?: 'collection';
        $priority = ops_post_string('priority', 30) ?: 'normal';
        $complexity = max(1, min(5, (int) ($_POST['complexity'] ?? 1)));
        $workload = ops_workload_score($itemCount, $orderType, $complexity, $priority);
        $packerId = ops_best_packer_id($workload);
        $status = $packerId ? 'assigned' : 'new_order';
        $totalColumn = $hasTotalAmount ? ', total_amount' : '';
        $totalPlaceholder = $hasTotalAmount ? ', ?' : '';

        $stmt = db()->prepare(
            "INSERT INTO ops_orders (order_number, customer_name, customer_contact, payment_method, payment_status, order_type, priority, complexity, assigned_packer_id, status, notes, workload_score{$totalColumn})
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$totalPlaceholder})"
        );
        $values = [
            ops_post_string('order_number', 80),
            ops_post_string('customer_name', 190),
            ops_post_string('customer_contact', 80),
            ops_post_string('payment_method', 80),
            ops_post_string('payment_status', 30) ?: 'unpaid',
            $orderType,
            $priority,
            $complexity,
            $packerId,
            $status,
            ops_post_string('notes', 1000),
            $workload,
        ];
        if ($hasTotalAmount) {
            $values[] = (float) ($_POST['total_amount'] ?? 0);
        }
        $stmt->execute($values);

        $orderId = (int) db()->lastInsertId();
        $itemName = ops_post_string('item_name', 190);
        if ($itemName !== '') {
            $itemStmt = db()->prepare("INSERT INTO ops_order_items (order_id, product_name, barcode, quantity) VALUES (?, ?, ?, ?)");
            $itemStmt->execute([$orderId, $itemName, ops_post_string('barcode', 120), (float) ($_POST['quantity'] ?? 1)]);
        }

        $message = 'Emergency manual order saved with a workload score of ' . number_format($workload, 2) . '.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$filters = [
    'period' => trim((string) ($_GET['period'] ?? 'this_month')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'payment_method' => trim((string) ($_GET['payment_method'] ?? '')),
    'payment_status' => trim((string) ($_GET['payment_status'] ?? '')),
    'delivery_mode' => trim((string) ($_GET['delivery_mode'] ?? '')),
    'customer' => trim((string) ($_GET['customer'] ?? '')),
    'packer_id' => trim((string) ($_GET['packer_id'] ?? '')),
    'product' => trim((string) ($_GET['product'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
];

$validPeriods = ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'];
if (!in_array($filters['period'], $validPeriods, true)) {
    $filters['period'] = 'this_month';
}

$today = new DateTimeImmutable('today');
if ($filters['period'] === 'today') {
    $filters['date_from'] = $today->format('Y-m-d');
    $filters['date_to'] = $today->format('Y-m-d');
} elseif ($filters['period'] === 'yesterday') {
    $filters['date_from'] = $today->modify('-1 day')->format('Y-m-d');
    $filters['date_to'] = $today->modify('-1 day')->format('Y-m-d');
} elseif ($filters['period'] === 'this_week') {
    $filters['date_from'] = $today->modify('monday this week')->format('Y-m-d');
    $filters['date_to'] = $today->format('Y-m-d');
} elseif ($filters['period'] === 'this_month') {
    $filters['date_from'] = $today->modify('first day of this month')->format('Y-m-d');
    $filters['date_to'] = $today->format('Y-m-d');
} elseif ($filters['period'] === 'last_month') {
    $lastMonth = $today->modify('first day of last month');
    $filters['date_from'] = $lastMonth->format('Y-m-d');
    $filters['date_to'] = $lastMonth->modify('last day of this month')->format('Y-m-d');
} elseif ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
    $filters['period'] = 'custom';
}

$where = ['1=1'];
$params = [];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $where[] = 'DATE(o.created_at) >= ?';
    $params[] = $filters['date_from'];
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $where[] = 'DATE(o.created_at) <= ?';
    $params[] = $filters['date_to'];
}

if (array_key_exists($filters['status'], OPS_ORDER_STATUSES)) {
    $where[] = 'o.status = ?';
    $params[] = $filters['status'];
}

if (in_array($filters['payment_status'], ['paid', 'unpaid', 'partial', 'refunded'], true)) {
    $where[] = 'o.payment_status = ?';
    $params[] = $filters['payment_status'];
}

if ($filters['payment_method'] !== '') {
    $where[] = 'o.payment_method LIKE ?';
    $params[] = '%' . $filters['payment_method'] . '%';
}

if ($filters['delivery_mode'] !== '') {
    $where[] = 'o.order_type = ?';
    $params[] = $filters['delivery_mode'];
}

if ($filters['customer'] !== '') {
    $where[] = 'o.customer_name LIKE ?';
    $params[] = '%' . $filters['customer'] . '%';
}

if ((int) $filters['packer_id'] > 0) {
    $where[] = 'o.assigned_packer_id = ?';
    $params[] = (int) $filters['packer_id'];
}

if ($filters['product'] !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM ops_order_items oi_filter WHERE oi_filter.order_id = o.id AND oi_filter.product_name LIKE ?)';
    $params[] = '%' . $filters['product'] . '%';
}

if ($filters['source'] === 'website' && $hasWooOrder) {
    $where[] = 'o.woo_order_id IS NOT NULL AND o.woo_order_id <> 0';
} elseif ($filters['source'] === 'manual' && $hasWooOrder) {
    $where[] = '(o.woo_order_id IS NULL OR o.woo_order_id = 0)';
}

$whereSql = implode(' AND ', $where);
$totalExpr = $hasTotalAmount ? 'o.total_amount' : 'CAST(0 AS DECIMAL(12,2))';
$productExpr = $hasProductTotal ? 'o.product_total' : $totalExpr;
$taxExpr = $hasTaxTotal ? 'o.tax_total' : "({$totalExpr} * 15 / 115)";
$shippingExpr = $hasShippingTotal ? 'o.shipping_total' : "CASE WHEN o.order_type IN ('delivery', 'courier') THEN 0 ELSE 0 END";
$shippingTaxExpr = $hasShippingTaxTotal ? 'o.shipping_tax_total' : '0';
$discountExpr = $hasDiscountTotal ? 'o.discount_total' : '0';
$refundExpr = $hasRefundTotal ? 'o.refund_total' : "CASE WHEN o.payment_status = 'refunded' OR o.status IN ('cancelled', 'canceled', 'refunded', 'failed') THEN {$totalExpr} ELSE 0 END";
$sourceExpr = order_report_source_case($hasWooOrder);
$revenueWhere = $whereSql . " AND o.payment_status = 'paid' AND o.status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged')";

if ($ready && ($_GET['export'] ?? '') === 'csv') {
    $exportRows = ops_rows(
        "SELECT o.order_number, o.customer_name, o.created_at, {$totalExpr} AS total_amount, o.payment_method, o.payment_status,
                o.order_type, o.status, COALESCE(e.full_name, 'Unassigned') AS packer_name, o.workload_score, {$sourceExpr} AS source
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE {$whereSql}
         ORDER BY o.created_at DESC
         LIMIT 1000",
        $params
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer-orders-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order', 'Customer', 'Date', 'Amount', 'Payment Method', 'Payment Status', 'Mode', 'Status', 'Packed By', 'Workload', 'Source']);
    foreach ($exportRows as $row) {
        fputcsv($out, [
            $row['order_number'],
            $row['customer_name'],
            $row['created_at'],
            number_format((float) $row['total_amount'], 2, '.', ''),
            $row['payment_method'],
            $row['payment_status'],
            $row['order_type'],
            OPS_ORDER_STATUSES[$row['status']] ?? $row['status'],
            $row['packer_name'],
            number_format((float) $row['workload_score'], 2, '.', ''),
            $row['source'],
        ]);
    }
    fclose($out);
    exit;
}

$packers = $ready ? ops_rows(
    "SELECT e.id, e.full_name
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager', 'owner_admin')
     ORDER BY e.full_name"
) : [];

$modeOptions = ['collection' => 'Collection', 'delivery' => 'Delivery', 'courier' => 'Courier'];
if ($ready) {
    foreach (ops_rows("SELECT DISTINCT order_type FROM ops_orders WHERE order_type IS NOT NULL AND order_type <> '' ORDER BY order_type") as $row) {
        $modeKey = (string) $row['order_type'];
        $modeOptions[$modeKey] = ucwords(str_replace(['_', '-'], ' ', $modeKey));
    }
}

$summary = $ready ? (ops_rows(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.status IN ('completed', 'verified', 'packed') THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN o.status IN ('cancelled', 'canceled', 'refunded', 'failed') OR o.payment_status = 'refunded' THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN o.status IN ('new_order', 'assigned', 'in_progress', 'correction_required') THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN o.payment_status IN ('unpaid', 'partial') THEN 1 ELSE 0 END) AS unpaid_orders,
        SUM(CASE WHEN o.order_type = 'collection' THEN 1 ELSE 0 END) AS collection_orders,
        SUM(CASE WHEN o.order_type = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders,
        SUM(CASE WHEN o.order_type = 'courier' THEN 1 ELSE 0 END) AS courier_orders,
        COALESCE(SUM({$totalExpr}), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$totalExpr} ELSE 0 END), 0) AS paid_sales,
        COALESCE(SUM(CASE WHEN o.payment_status IN ('unpaid', 'partial') THEN {$totalExpr} ELSE 0 END), 0) AS unpaid_value,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$productExpr} ELSE 0 END), 0) AS product_revenue,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$taxExpr} ELSE 0 END), 0) AS vat_collected,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$shippingExpr} ELSE 0 END), 0) AS shipping_total,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$shippingTaxExpr} ELSE 0 END), 0) AS shipping_tax_total,
        COALESCE(SUM({$discountExpr}), 0) AS discount_total,
        COALESCE(SUM({$refundExpr}), 0) AS refund_total,
        COALESCE(AVG(NULLIF({$totalExpr}, 0)), 0) AS avg_order_value,
        COALESCE(AVG(o.workload_score), 0) AS avg_workload
     FROM ops_orders o
     WHERE {$whereSql}",
    $params
)[0] ?? []) : [];

$revenue = $ready ? (ops_rows(
    "SELECT COALESCE(SUM({$totalExpr}), 0) AS revenue
     FROM ops_orders o
     WHERE {$revenueWhere}",
    $params
)[0]['revenue'] ?? 0) : 0;

$productRevenue = (float) ($summary['product_revenue'] ?? 0);
$vatCollected = (float) ($summary['vat_collected'] ?? 0);
$shippingTotal = (float) ($summary['shipping_total'] ?? 0);
$shippingTaxTotal = (float) ($summary['shipping_tax_total'] ?? 0);
$discountTotal = (float) ($summary['discount_total'] ?? 0);
$refundTotal = (float) ($summary['refund_total'] ?? 0);
$netSales = max(0, (float) $revenue - $shippingTotal - $refundTotal);
$vatExclusiveProductSales = max(0, $productRevenue - $vatCollected);

$monthlyGoal = max(0, (float) order_report_setting('monthly_sales_goal', '250000'));
$monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$monthEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
$monthRevenueParams = [$monthStart, $monthEnd];
$monthRevenue = $ready ? (float) (ops_rows(
    "SELECT COALESCE(SUM({$totalExpr}), 0) AS revenue
     FROM ops_orders o
     WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
       AND o.payment_status = 'paid'
       AND o.status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged')",
    $monthRevenueParams
)[0]['revenue'] ?? 0) : 0.0;
$goalProgress = $monthlyGoal > 0 ? min(100, ($monthRevenue / $monthlyGoal) * 100) : 0.0;
$remainingGoal = max(0, $monthlyGoal - $monthRevenue);
$now = new DateTimeImmutable('now');
$daysElapsed = max(1, (int) $now->format('j'));
$daysLeft = max(0, (int) $now->diff(new DateTimeImmutable('last day of this month'))->format('%a'));
$averageDailySales = $monthRevenue / $daysElapsed;
$dailySalesNeeded = $daysLeft > 0 ? $remainingGoal / $daysLeft : $remainingGoal;

$timeRows = ($ready && $hasTimingColumns) ? ops_rows(
    "SELECT o.created_at, o.packing_started_at, o.completed_at, o.status
     FROM ops_orders o
     WHERE {$whereSql}
       AND o.completed_at IS NOT NULL
     ORDER BY o.completed_at DESC
     LIMIT 1000",
    $params
) : [];

$timeSummary = [
    'completed_count' => 0,
    'avg_received_to_complete' => null,
    'avg_received_to_started' => null,
    'avg_started_to_complete' => null,
    'fastest' => null,
    'slowest' => null,
    'overdue' => 0,
    'within_business_hours' => 0,
    'carried_over' => 0,
];
$receivedToComplete = [];
$receivedToStarted = [];
$startedToComplete = [];

foreach ($timeRows as $row) {
    $completeMinutes = order_report_business_minutes((string) $row['created_at'], (string) $row['completed_at']);
    if ($completeMinutes === null) {
        continue;
    }

    $timeSummary['completed_count']++;
    $receivedToComplete[] = $completeMinutes;
    $timeSummary['fastest'] = $timeSummary['fastest'] === null ? $completeMinutes : min($timeSummary['fastest'], $completeMinutes);
    $timeSummary['slowest'] = $timeSummary['slowest'] === null ? $completeMinutes : max($timeSummary['slowest'], $completeMinutes);

    if ($completeMinutes > 540) {
        $timeSummary['overdue']++;
    }

    try {
        $createdAt = new DateTimeImmutable((string) $row['created_at']);
        $completedAt = new DateTimeImmutable((string) $row['completed_at']);
        if ($createdAt->format('Y-m-d') !== $completedAt->format('Y-m-d')) {
            $timeSummary['carried_over']++;
        }
        if ($completedAt >= order_report_business_start($completedAt) && $completedAt <= order_report_business_end($completedAt)) {
            $timeSummary['within_business_hours']++;
        }
    } catch (Throwable $e) {
        // Skip secondary timing labels if a date cannot be parsed.
    }

    $startMinutes = order_report_business_minutes((string) $row['created_at'], (string) ($row['packing_started_at'] ?? ''));
    if ($startMinutes !== null) {
        $receivedToStarted[] = $startMinutes;
    }

    $packMinutes = order_report_business_minutes((string) ($row['packing_started_at'] ?? ''), (string) $row['completed_at']);
    if ($packMinutes !== null) {
        $startedToComplete[] = $packMinutes;
    }
}

$timeSummary['avg_received_to_complete'] = $receivedToComplete ? array_sum($receivedToComplete) / count($receivedToComplete) : null;
$timeSummary['avg_received_to_started'] = $receivedToStarted ? array_sum($receivedToStarted) / count($receivedToStarted) : null;
$timeSummary['avg_started_to_complete'] = $startedToComplete ? array_sum($startedToComplete) / count($startedToComplete) : null;

$profitTotals = ['known_cogs' => 0.0, 'estimated_profit' => 0.0, 'costed_orders' => 0];
if ($hasCosts) {
    $profitRows = ops_rows(
        "SELECT o.id, {$totalExpr} AS total_amount,
                COALESCE(SUM(oi.quantity * c.total_cogs), 0) AS cogs
         FROM ops_orders o
         JOIN ops_order_items oi ON oi.order_id = o.id
         LEFT JOIN (
            SELECT woo_product_id, MIN(total_cogs) AS total_cogs
            FROM final_product_costs
            WHERE woo_product_id IS NOT NULL AND woo_product_id > 0
            GROUP BY woo_product_id
         ) c ON c.woo_product_id = oi.woo_product_id
         WHERE {$revenueWhere}
         GROUP BY o.id, {$totalExpr}",
        $params
    );

    foreach ($profitRows as $row) {
        $cogs = (float) $row['cogs'];
        if ($cogs <= 0) {
            continue;
        }
        $profitTotals['known_cogs'] += $cogs;
        $profitTotals['estimated_profit'] += (float) $row['total_amount'] - $cogs;
        $profitTotals['costed_orders']++;
    }
}

$paymentBreakdown = $ready ? ops_rows(
    "SELECT COALESCE(NULLIF(o.payment_method, ''), 'Not recorded') AS label, COUNT(*) AS order_count, COALESCE(SUM({$totalExpr}), 0) AS amount
     FROM ops_orders o
     WHERE {$whereSql}
     GROUP BY COALESCE(NULLIF(o.payment_method, ''), 'Not recorded')
     ORDER BY amount DESC, order_count DESC
     LIMIT 8",
    $params
) : [];

$modeBreakdown = $ready ? ops_rows(
    "SELECT o.order_type AS label, COUNT(*) AS order_count, COALESCE(SUM({$totalExpr}), 0) AS amount
     FROM ops_orders o
     WHERE {$whereSql}
     GROUP BY o.order_type
     ORDER BY order_count DESC",
    $params
) : [];

$dailyTrend = $ready ? ops_rows(
    "SELECT DATE(o.created_at) AS report_day, COUNT(*) AS order_count, COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$totalExpr} ELSE 0 END), 0) AS revenue
     FROM ops_orders o
     WHERE {$whereSql}
     GROUP BY DATE(o.created_at)
     ORDER BY report_day ASC
     LIMIT 45",
    $params
) : [];
$maxDailyRevenue = max(array_map(static fn (array $row): float => (float) $row['revenue'], $dailyTrend ?: [['revenue' => 0]]));
$maxDailyOrders = max(array_map(static fn (array $row): float => (float) $row['order_count'], $dailyTrend ?: [['order_count' => 0]]));

$topProducts = $ready ? ops_rows(
    "SELECT oi.product_name, COUNT(DISTINCT o.id) AS orders_count, COALESCE(SUM(oi.quantity), 0) AS qty,
            COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN {$totalExpr} ELSE 0 END), 0) AS order_value
     FROM ops_order_items oi
     JOIN ops_orders o ON o.id = oi.order_id
     WHERE {$whereSql}
     GROUP BY oi.product_name
     ORDER BY qty DESC, orders_count DESC
     LIMIT 10",
    $params
) : [];

$topCustomers = $ready ? ops_rows(
    "SELECT o.customer_name, COUNT(*) AS orders_count, COALESCE(SUM({$totalExpr}), 0) AS amount,
            SUM(CASE WHEN o.payment_status IN ('unpaid', 'partial') THEN 1 ELSE 0 END) AS unpaid_count
     FROM ops_orders o
     WHERE {$whereSql}
     GROUP BY o.customer_name
     ORDER BY amount DESC, orders_count DESC
     LIMIT 10",
    $params
) : [];

$packerRows = $ready ? ops_rows(
    "SELECT COALESCE(e.full_name, 'Unassigned') AS packer_name, COUNT(*) AS orders_count,
            COALESCE(SUM(o.workload_score), 0) AS workload,
            SUM(CASE WHEN o.status IN ('completed', 'verified', 'packed') THEN 1 ELSE 0 END) AS done_count
     FROM ops_orders o
     LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
     WHERE {$whereSql}
     GROUP BY COALESCE(e.full_name, 'Unassigned')
     ORDER BY workload DESC, orders_count DESC
     LIMIT 10",
    $params
) : [];

$recentOrders = $ready ? ops_rows(
    "SELECT o.*, {$totalExpr} AS total_amount, {$sourceExpr} AS source, e.full_name AS packer_name
     FROM ops_orders o
     LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
     WHERE {$whereSql}
     ORDER BY o.created_at DESC
     LIMIT 80",
    $params
) : [];

$recentProfitByOrder = [];
if ($hasCosts && $recentOrders) {
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $recentOrders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = ops_rows(
        "SELECT oi.order_id, COALESCE(SUM(oi.quantity * c.total_cogs), 0) AS cogs
         FROM ops_order_items oi
         LEFT JOIN (
            SELECT woo_product_id, MIN(total_cogs) AS total_cogs
            FROM final_product_costs
            WHERE woo_product_id IS NOT NULL AND woo_product_id > 0
            GROUP BY woo_product_id
         ) c ON c.woo_product_id = oi.woo_product_id
         WHERE oi.order_id IN ({$placeholders})
         GROUP BY oi.order_id",
        $ids
    );
    foreach ($rows as $row) {
        $recentProfitByOrder[(int) $row['order_id']] = (float) $row['cogs'];
    }
}

$maxPayment = max(array_map(static fn (array $row): float => (float) $row['amount'], $paymentBreakdown ?: [['amount' => 0]]));
$maxMode = max(array_map(static fn (array $row): float => (float) $row['order_count'], $modeBreakdown ?: [['order_count' => 0]]));

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Sales operations</p>
            <h1>Customer Orders Report</h1>
            <p>Analyse synced website and POS orders, payment flow, workload, product demand and operational issues.</p>
        </div>
        <div class="actions">
            <a class="button" href="orders-board.php"><i data-lucide="table-2"></i> Orders board</a>
            <?php if (user_has_role('owner_admin')): ?>
                <a class="button" href="sync-orders.php"><i data-lucide="refresh-cw"></i> Sync health</a>
            <?php endif; ?>
        </div>
    </section>
    <?php ops_nav('orders'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <form class="panel report-filter-panel" method="get" data-report-filter-form>
        <div class="section-row">
            <h2>Report filters</h2>
            <div class="actions">
                <a class="button" href="orders.php" data-report-clear>Clear</a>
                <button class="button" type="submit" name="export" value="csv"><i data-lucide="download"></i> Export CSV</button>
                <button class="button" type="button" onclick="window.print()"><i data-lucide="printer"></i> Print/PDF</button>
                <button class="button primary" type="submit" data-report-apply>Apply filters</button>
            </div>
        </div>
        <div class="report-loading" data-report-loading hidden><i data-lucide="loader-2"></i> Loading report...</div>
        <div class="form-grid">
            <label>View
                <select name="period" data-report-period>
                    <?php ops_select_options(['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom date range'], $filters['period']); ?>
                </select>
            </label>
            <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>" data-report-date></label>
            <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>" data-report-date></label>
            <label>Status
                <select name="status">
                    <option value="">All statuses</option>
                    <?php ops_select_options(OPS_ORDER_STATUSES, $filters['status']); ?>
                </select>
            </label>
            <label>Payment status
                <select name="payment_status">
                    <option value="">All payment statuses</option>
                    <?php ops_select_options(['paid' => 'Paid', 'unpaid' => 'Unpaid', 'partial' => 'Partial', 'refunded' => 'Refunded'], $filters['payment_status']); ?>
                </select>
            </label>
            <label>Payment method<input name="payment_method" value="<?= htmlspecialchars($filters['payment_method'], ENT_QUOTES, 'UTF-8') ?>" placeholder="EFT, cash, card"></label>
            <label>Mode
                <select name="delivery_mode">
                    <option value="">All modes</option>
                    <?php ops_select_options($modeOptions, $filters['delivery_mode']); ?>
                </select>
            </label>
            <label>Customer<input name="customer" value="<?= htmlspecialchars($filters['customer'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Customer name"></label>
            <label>Packer
                <select name="packer_id">
                    <option value="">All packers</option>
                    <?php foreach ($packers as $packer): ?>
                        <option value="<?= (int) $packer['id'] ?>" <?= (string) $packer['id'] === $filters['packer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($packer['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Product<input name="product" value="<?= htmlspecialchars($filters['product'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Product name"></label>
            <label>Source
                <select name="source">
                    <option value="">All sources</option>
                    <?php ops_select_options(['website' => 'Website', 'manual' => 'Manual/POS fallback'], $filters['source']); ?>
                </select>
            </label>
        </div>
        <p class="report-filter-summary">Showing orders from <strong><?= htmlspecialchars($filters['date_from'] ?: 'any start', ENT_QUOTES, 'UTF-8') ?></strong> to <strong><?= htmlspecialchars($filters['date_to'] ?: 'any end', ENT_QUOTES, 'UTF-8') ?></strong>.</p>
    </form>

    <?php if ($ready && (int) ($summary['total_orders'] ?? 0) === 0): ?>
        <section class="ops-alert">No matching orders found for selected filters.</section>
    <?php endif; ?>

    <section class="metric-grid order-kpi-grid">
        <article class="metric"><span>Total orders</span><strong><?= number_format((int) ($summary['total_orders'] ?? 0)) ?></strong><small><?= number_format((int) ($summary['pending_orders'] ?? 0)) ?> pending</small></article>
        <article class="metric"><span>Total revenue</span><strong><?= order_report_money((float) $revenue) ?></strong><small><?= order_report_money((float) ($summary['unpaid_value'] ?? 0)) ?> unpaid/partial</small></article>
        <article class="metric"><span>Product revenue</span><strong><?= order_report_money($productRevenue) ?></strong><small><?= order_report_money($vatExclusiveProductSales) ?> excl. VAT estimate</small></article>
        <article class="metric"><span>VAT collected</span><strong><?= order_report_money($vatCollected) ?></strong><small>15% VAT-inclusive estimate unless Woo tax is synced</small></article>
        <article class="metric"><span>Delivery/transport</span><strong><?= order_report_money($shippingTotal) ?></strong><small><?= number_format((int) ($summary['delivery_orders'] ?? 0) + (int) ($summary['courier_orders'] ?? 0)) ?> delivery/courier orders</small></article>
        <article class="metric"><span>Discounts</span><strong><?= order_report_money($discountTotal) ?></strong><small>Synced when Woo breakdown fields exist</small></article>
        <article class="metric"><span>Net sales</span><strong><?= order_report_money($netSales) ?></strong><small>Revenue less delivery and refunds</small></article>
        <article class="metric"><span>Average order value</span><strong><?= order_report_money((float) ($summary['avg_order_value'] ?? 0)) ?></strong><small><?= order_report_percent((float) (($summary['total_orders'] ?? 0) ? (($summary['completed_orders'] ?? 0) / max(1, (int) $summary['total_orders'])) * 100 : 0)) ?> completed/packed</small></article>
        <article class="metric"><span>Average completion time</span><strong><?= order_report_duration($timeSummary['avg_received_to_complete']) ?></strong><small>Business hours only, 08:00 to 17:00</small></article>
        <article class="metric"><span>Orders completed</span><strong><?= number_format((int) ($summary['completed_orders'] ?? 0)) ?></strong><small><?= number_format((int) $timeSummary['within_business_hours']) ?> within business hours</small></article>
        <article class="metric"><span>Estimated profit</span><strong><?= $profitTotals['costed_orders'] > 0 ? order_report_money($profitTotals['estimated_profit']) : 'Needs cost link' ?></strong><small><?= $profitTotals['costed_orders'] > 0 ? ((int) $profitTotals['costed_orders'] . ' costed orders') : 'Link Woo products to costs' ?></small></article>
    </section>

    <section class="panel goal-panel">
        <div class="section-row">
            <h2>Monthly sales goal</h2>
            <form method="post" class="inline-fields goal-form">
                <input type="hidden" name="action" value="save_monthly_goal">
                <label>Monthly target<input type="number" step="0.01" min="0" name="monthly_goal" value="<?= htmlspecialchars(number_format($monthlyGoal, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <button class="button primary" type="submit">Save goal</button>
            </form>
        </div>
        <div class="goal-layout">
            <div>
                <div class="goal-progress"><span style="width: <?= number_format($goalProgress, 2, '.', '') ?>%"></span></div>
                <p><?= order_report_money($monthRevenue) ?> of <?= order_report_money($monthlyGoal) ?> reached: <strong><?= order_report_percent($goalProgress) ?></strong></p>
            </div>
            <div class="goal-stats">
                <span>Remaining <strong><?= order_report_money($remainingGoal) ?></strong></span>
                <span>Days left <strong><?= number_format($daysLeft) ?></strong></span>
                <span>Daily needed <strong><?= order_report_money($dailySalesNeeded) ?></strong></span>
                <span>Current daily avg <strong><?= order_report_money($averageDailySales) ?></strong></span>
            </div>
        </div>
    </section>

    <section class="report-grid order-report-grid">
        <section class="panel">
            <div class="section-row"><h2>Revenue breakdown</h2><span class="status">Gross, VAT, delivery, discounts and net</span></div>
            <div class="breakdown-list">
                <div><span>Gross paid order total</span><strong><?= order_report_money((float) $revenue) ?></strong></div>
                <div><span>Product revenue</span><strong><?= order_report_money($productRevenue) ?></strong></div>
                <div><span>VAT collected</span><strong><?= order_report_money($vatCollected) ?></strong></div>
                <div><span>VAT-exclusive product sales</span><strong><?= order_report_money($vatExclusiveProductSales) ?></strong></div>
                <div><span>Delivery/transport collected</span><strong><?= order_report_money($shippingTotal) ?></strong></div>
                <div><span>Shipping VAT</span><strong><?= order_report_money($shippingTaxTotal) ?></strong></div>
                <div><span>Discounts</span><strong><?= order_report_money($discountTotal) ?></strong></div>
                <div><span>Refunds/cancellations</span><strong><?= order_report_money($refundTotal) ?></strong></div>
                <div><span>Net sales after delivery/refunds</span><strong><?= order_report_money($netSales) ?></strong></div>
            </div>
            <?php $mixMax = max(1, $productRevenue, $vatCollected, $shippingTotal); ?>
            <div class="report-bars compact revenue-mix">
                <?php foreach ([['Product', $productRevenue], ['VAT', $vatCollected], ['Delivery', $shippingTotal]] as [$label, $amount]): ?>
                    <div class="report-bar-row">
                        <div><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><span><?= order_report_money((float) $amount) ?></span></div>
                        <div class="report-bar"><span style="width: <?= number_format(((float) $amount / $mixMax) * 100, 2, '.', '') ?>%"></span></div>
                        <strong><?= order_report_percent(((float) $amount / max(1, (float) $revenue)) * 100) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="section-row"><h2>Order time summary</h2><span class="status">08:00 to 17:00 business time</span></div>
            <div class="breakdown-list compact">
                <div><span>Avg received to completed</span><strong><?= order_report_duration($timeSummary['avg_received_to_complete']) ?></strong></div>
                <div><span>Avg received to packing started</span><strong><?= order_report_duration($timeSummary['avg_received_to_started']) ?></strong></div>
                <div><span>Avg packing started to completed</span><strong><?= order_report_duration($timeSummary['avg_started_to_complete']) ?></strong></div>
                <div><span>Fastest completed order</span><strong><?= order_report_duration($timeSummary['fastest']) ?></strong></div>
                <div><span>Slowest completed order</span><strong><?= order_report_duration($timeSummary['slowest']) ?></strong></div>
                <div><span>Overdue orders</span><strong><?= number_format((int) $timeSummary['overdue']) ?></strong></div>
                <div><span>Completed within business hours</span><strong><?= number_format((int) $timeSummary['within_business_hours']) ?></strong></div>
                <div><span>Carried over to next day</span><strong><?= number_format((int) $timeSummary['carried_over']) ?></strong></div>
            </div>
        </section>
    </section>

    <section class="report-grid order-report-grid">
        <section class="panel">
            <div class="section-row"><h2>Revenue by day</h2><span class="status">Paid orders</span></div>
            <div class="daily-chart">
                <?php foreach ($dailyTrend as $row): ?>
                    <?php $height = $maxDailyRevenue > 0 ? max(8, ((float) $row['revenue'] / $maxDailyRevenue) * 120) : 8; ?>
                    <div class="daily-chart-col">
                        <span style="height: <?= number_format($height, 2, '.', '') ?>px"></span>
                        <small><?= htmlspecialchars((string) $row['report_day'], ENT_QUOTES, 'UTF-8') ?></small>
                        <strong><?= order_report_money((float) $row['revenue']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!$dailyTrend): ?><p>No daily revenue data for this filter.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="section-row"><h2>Order volume by day</h2><span class="status">Order count</span></div>
            <div class="daily-chart">
                <?php foreach ($dailyTrend as $row): ?>
                    <?php $height = $maxDailyOrders > 0 ? max(8, ((float) $row['order_count'] / $maxDailyOrders) * 120) : 8; ?>
                    <div class="daily-chart-col order-volume">
                        <span style="height: <?= number_format($height, 2, '.', '') ?>px"></span>
                        <small><?= htmlspecialchars((string) $row['report_day'], ENT_QUOTES, 'UTF-8') ?></small>
                        <strong><?= number_format((int) $row['order_count']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!$dailyTrend): ?><p>No daily order data for this filter.</p><?php endif; ?>
            </div>
        </section>
    </section>

    <section class="report-grid order-report-grid">
        <section class="panel">
            <div class="section-row"><h2>Payment breakdown</h2><span class="status"><?= order_report_money((float) ($summary['total_sales'] ?? 0)) ?> total value</span></div>
            <div class="report-bars">
                <?php foreach ($paymentBreakdown as $row): ?>
                    <?php $width = $maxPayment > 0 ? ((float) $row['amount'] / $maxPayment) * 100 : 0; ?>
                    <div class="report-bar-row">
                        <div><strong><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= number_format((int) $row['order_count']) ?> orders</span></div>
                        <div class="report-bar"><span style="width: <?= number_format($width, 2, '.', '') ?>%"></span></div>
                        <strong><?= order_report_money((float) $row['amount']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!$paymentBreakdown): ?><p>No payment data for this filter.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="section-row"><h2>Mode breakdown</h2><span class="status">Collection / delivery / courier</span></div>
            <div class="report-bars compact">
                <?php foreach ($modeBreakdown as $row): ?>
                    <?php $width = $maxMode > 0 ? ((float) $row['order_count'] / $maxMode) * 100 : 0; ?>
                    <div class="report-bar-row">
                        <div><strong><?= htmlspecialchars(ucfirst((string) $row['label']), ENT_QUOTES, 'UTF-8') ?></strong><span><?= order_report_money((float) $row['amount']) ?></span></div>
                        <div class="report-bar"><span style="width: <?= number_format($width, 2, '.', '') ?>%"></span></div>
                        <strong><?= number_format((int) $row['order_count']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!$modeBreakdown): ?><p>No mode data for this filter.</p><?php endif; ?>
            </div>
        </section>
    </section>

    <section class="report-grid order-report-grid">
        <section class="panel">
            <div class="section-row"><h2>Best performing products</h2><span class="status">By quantity ordered</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Product</th><th>Orders</th><th>Qty</th><th>Order value</th></tr></thead>
                    <tbody>
                    <?php foreach ($topProducts as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int) $row['orders_count']) ?></td>
                            <td><?= number_format((float) $row['qty'], 2) ?></td>
                            <td><?= order_report_money((float) $row['order_value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topProducts): ?><tr><td colspan="4">No product lines found for this filter.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-row"><h2>Top customers</h2><span class="status">Repeat and high value</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Customer</th><th>Orders</th><th>Value</th><th>Unpaid</th></tr></thead>
                    <tbody>
                    <?php foreach ($topCustomers as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int) $row['orders_count']) ?></td>
                            <td><?= order_report_money((float) $row['amount']) ?></td>
                            <td><?= number_format((int) $row['unpaid_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topCustomers): ?><tr><td colspan="4">No customer data found for this filter.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>

    <section class="panel">
        <div class="section-row"><h2>Operational performance</h2><span class="status">Packing workload by employee</span></div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>Packer</th><th>Orders</th><th>Completed/Packed</th><th>Total workload</th><th>Average workload</th></tr></thead>
                <tbody>
                <?php foreach ($packerRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $row['packer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $row['orders_count']) ?></td>
                        <td><?= number_format((int) $row['done_count']) ?></td>
                        <td><?= number_format((float) $row['workload'], 2) ?></td>
                        <td><?= number_format(((float) $row['workload']) / max(1, (int) $row['orders_count']), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$packerRows): ?><tr><td colspan="5">No packer workload found for this filter.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-row">
            <h2>Recent orders</h2>
            <span class="status">Improved report table</span>
        </div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Amount</th><th>Payment</th><th>Status</th><th>Mode</th><th>Packed by</th><th>Workload</th><th>Profit</th></tr></thead>
                <tbody>
                <?php foreach ($recentOrders as $order): ?>
                    <?php
                    $cogs = $recentProfitByOrder[(int) $order['id']] ?? 0.0;
                    $profitLabel = $cogs > 0 ? order_report_money((float) $order['total_amount'] - $cogs) : 'Needs cost link';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $order['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $order['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= order_report_money((float) $order['total_amount']) ?></td>
                        <td><?= htmlspecialchars(trim((string) $order['payment_method']) !== '' ? (string) $order['payment_method'] : (string) $order['payment_status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status"><?= htmlspecialchars(OPS_ORDER_STATUSES[$order['status']] ?? $order['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars(ucfirst((string) $order['order_type']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($order['packer_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((float) $order['workload_score'], 2) ?></td>
                        <td><?= htmlspecialchars($profitLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentOrders): ?><tr><td colspan="10">No orders match this report filter.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <details class="panel emergency-order-panel">
        <summary><span><i data-lucide="plus-circle"></i> Emergency manual order fallback</span><small>Use only if WooCommerce/POS cannot create the order.</small></summary>
        <form class="ops-form" method="post">
            <input type="hidden" name="action" value="manual_order">
            <div class="form-grid">
                <label>Order number<input name="order_number" required></label>
                <label>Customer name<input name="customer_name" required></label>
                <label>Contact number<input name="customer_contact"></label>
                <label>Payment method<input name="payment_method"></label>
                <?php if ($hasTotalAmount): ?><label>Order value<input type="number" step="0.01" min="0" name="total_amount" value="0"></label><?php endif; ?>
                <label>Payment status<select name="payment_status"><?php ops_select_options(['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid', 'refunded' => 'Refunded']); ?></select></label>
                <label>Order type<select name="order_type"><?php ops_select_options(['collection' => 'Collection', 'courier' => 'Courier', 'delivery' => 'Delivery']); ?></select></label>
                <label>Priority<select name="priority"><?php ops_select_options(['normal' => 'Normal', 'urgent' => 'Urgent', 'same_day' => 'Same day']); ?></select></label>
                <label>Complexity<input type="number" name="complexity" min="1" max="5" value="1"></label>
                <label>Number of line items<input type="number" name="item_count" min="1" value="1"></label>
                <label>First item quantity<input type="number" step="0.001" name="quantity" min="0" value="1"></label>
                <label class="span-2">First item name<input name="item_name"></label>
                <label class="span-2">Expected barcode<input name="barcode"></label>
                <label class="span-2">Notes<textarea name="notes"></textarea></label>
            </div>
            <div class="ops-form-actions"><button class="button primary" type="submit">Save emergency order</button></div>
        </form>
    </details>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-report-filter-form]');
    if (!form) return;

    const period = form.querySelector('[data-report-period]');
    const dateInputs = form.querySelectorAll('[data-report-date]');
    const loading = form.querySelector('[data-report-loading]');
    const apply = form.querySelector('[data-report-apply]');

    const updateDateState = () => {
        const isCustom = period?.value === 'custom';
        dateInputs.forEach((input) => {
            input.readOnly = !isCustom;
            input.classList.toggle('is-readonly', !isCustom);
        });
    };

    period?.addEventListener('change', updateDateState);
    dateInputs.forEach((input) => {
        input.addEventListener('change', () => {
            if (period) period.value = 'custom';
            updateDateState();
        });
    });

    form.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (submitter && submitter.name === 'export') return;
        loading.hidden = false;
        apply?.classList.add('is-loading');
        if (apply) apply.disabled = true;
    });

    updateDateState();
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
