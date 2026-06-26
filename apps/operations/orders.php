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

function cor_slug($value): string
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'other';

    return trim($value, '-');
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

$range = trim((string) ($_GET['range'] ?? 'month'));
if (!in_array($range, ['today', 'week', 'month', 'custom'], true)) {
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
if (!in_array($filters['tab'], ['sales', 'payment', 'mode', 'staff', 'products', 'trend', 'orders'], true)) {
    $filters['tab'] = 'sales';
}

if ($ready && isset($_GET['export']) && in_array((string) $_GET['export'], ['csv', 'excel'], true)) {
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
];
$statusRows = [];
$paymentRows = [];
$modeRows = [];
$staffRows = [];
$productRows = [];
$dailyRows = [];
$orderRows = [];
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
                SUM(CASE WHEN status IN ('new_order','assigned') THEN 1 ELSE 0 END) AS pending
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
         ORDER BY qty DESC
         LIMIT 50",
        $params
    );
    $dailyRows = cor_query_rows(
        "SELECT DATE(o.created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS rev
         FROM ops_orders o
         WHERE {$where}
         GROUP BY DATE(o.created_at)
         ORDER BY d ASC",
        $params
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
        .cor-filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; padding: 12px 16px; border: 1px solid var(--cor-border); border-radius: 10px; background: var(--cor-surface); }
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
        .cor-stat-card.pending-stat { border-left: 4px solid var(--cor-amber); }
        .cor-stat-card .s-title { margin-bottom: 6px; color: var(--cor-text-mid); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .cor-stat-card .s-num { color: var(--cor-text); font-size: 24px; font-weight: 800; line-height: 1; }
        .cor-stat-card .s-sub { margin-top: 4px; color: var(--cor-text-light); font-size: 11px; }
        .cor-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 16px; border-bottom: 2px solid var(--cor-border); }
        .cor-tab { height: 36px; margin-bottom: -2px; padding: 0 16px; border: 0; border-bottom: 3px solid transparent; border-radius: 8px 8px 0 0; background: transparent; color: var(--cor-text-mid); font-size: 12px; font-weight: 600; cursor: pointer; }
        .cor-tab:hover { color: var(--cor-orange-red); }
        .cor-tab.active { background: var(--cor-cream); border-bottom-color: var(--cor-orange-red); color: var(--cor-burgundy); }
        .cor-tab-content { display: none; }
        .cor-tab-content.active { display: block; }
        .cor-report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
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
        .cor-rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: var(--cor-border); color: var(--cor-text-mid); font-size: 11px; font-weight: 800; }
        .cor-rank-1 { background: var(--cor-amber); color: #fff; }
        .cor-rank-2 { background: var(--cor-orange-red); color: #fff; }
        .cor-rank-3 { background: var(--cor-red); color: #fff; }
        .cor-chart-body { height: 260px; padding: 16px; }
        .cor-orders-wrap { max-height: 520px; overflow: auto; }
        .cor-orders-table { width: 100%; border-collapse: collapse; }
        .cor-orders-table th { position: sticky; top: 0; z-index: 2; }
        .cor-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 4px; padding: 12px 16px; border-top: 1px solid var(--cor-border); }
        .cor-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 8px; border: 1px solid var(--cor-border); border-radius: 5px; background: var(--cor-surface); color: var(--cor-text-mid); font-size: 12px; text-decoration: none; }
        .cor-page-btn.active { border-color: var(--cor-orange-red); background: var(--cor-orange-red); color: #fff; }
        .cor-empty { padding: 16px 18px; color: var(--cor-text-mid); font-size: 13px; }
        @media (max-width: 1100px) { .cor-stat-grid { grid-template-columns: repeat(3, 1fr); } .cor-report-grid { grid-template-columns: 1fr; } }
        @media (max-width: 767px) { .cor-stat-grid { grid-template-columns: repeat(2, 1fr); } .cor-tabs { flex-wrap: nowrap; overflow-x: auto; } .cor-tab { flex: 0 0 auto; white-space: nowrap; } .cor-filter-bar { align-items: stretch; flex-direction: column; } .cor-filter-selects, .cor-date-inputs, .cor-quick-ranges { align-items: stretch; } .cor-filter-bar select, .cor-filter-bar input[type="date"], .cor-btn-apply, .cor-btn-export { width: 100%; } }
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

    <form class="cor-filter-bar" method="get" data-cor-filter>
        <input type="hidden" name="range" value="<?= cor_e($filters['range']) ?>" data-cor-range>
        <input type="hidden" name="tab" value="<?= cor_e($filters['tab']) ?>" data-cor-tab-input>
        <div class="cor-quick-ranges">
            <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $key => $label): ?>
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
        <a class="cor-btn-export" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'csv'])) ?>">CSV</a>
        <a class="cor-btn-export" href="?<?= cor_e(http_build_query($queryBase + ['export' => 'excel'])) ?>">Excel</a>
    </form>

    <section class="cor-stat-grid" aria-label="Order report summary">
        <article class="cor-stat-card total-orders"><div class="s-title">Total Orders</div><div class="s-num"><?= number_format($summary['total_orders']) ?></div><div class="s-sub"><?= cor_e($filters['from']) ?> to <?= cor_e($filters['to']) ?></div></article>
        <article class="cor-stat-card total-revenue"><div class="s-title">Total Revenue</div><div class="s-num"><?= cor_money($summary['total_revenue']) ?></div><div class="s-sub">Refunded orders excluded</div></article>
        <article class="cor-stat-card aov"><div class="s-title">Avg Order Value</div><div class="s-num"><?= cor_money($summary['aov']) ?></div><div class="s-sub">Revenue divided by orders</div></article>
        <article class="cor-stat-card completed"><div class="s-title">Completed</div><div class="s-num"><?= number_format($summary['completed']) ?></div><div class="s-sub">Completed orders</div></article>
        <article class="cor-stat-card processing"><div class="s-title">Processing</div><div class="s-num"><?= number_format($summary['processing']) ?></div><div class="s-sub">In progress or packed</div></article>
        <article class="cor-stat-card pending-stat"><div class="s-title">Pending</div><div class="s-num"><?= number_format($summary['pending']) ?></div><div class="s-sub">New or assigned</div></article>
    </section>

    <nav class="cor-tabs" aria-label="Report tabs">
        <?php
        $tabs = [
            'sales' => 'Sales Summary',
            'payment' => 'By Payment',
            'mode' => 'By Mode',
            'staff' => 'By Staff',
            'products' => 'Products',
            'trend' => 'Trend',
            'orders' => 'All Orders',
        ];
        foreach ($tabs as $key => $label):
        ?>
            <button class="cor-tab <?= $filters['tab'] === $key ? 'active' : '' ?>" type="button" data-tab="<?= cor_e($key) ?>"><?= cor_e($label) ?></button>
        <?php endforeach; ?>
    </nav>

    <section id="tab-sales" class="cor-tab-content <?= $filters['tab'] === 'sales' ? 'active' : '' ?>">
        <div class="cor-report-grid">
            <article class="cor-report-card">
                <div class="card-head"><h3>Sales Summary</h3><span><?= cor_money($summary['total_revenue']) ?></span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                        <tbody>
                        <tr><td>Total orders</td><td><?= number_format($summary['total_orders']) ?></td></tr>
                        <tr><td>Total revenue</td><td><?= cor_money($summary['total_revenue']) ?></td></tr>
                        <tr><td>Average order value</td><td><?= cor_money($summary['aov']) ?></td></tr>
                        <tr><td>Completed</td><td><?= number_format($summary['completed']) ?></td></tr>
                        <tr><td>Processing</td><td><?= number_format($summary['processing']) ?></td></tr>
                        <tr><td>Pending</td><td><?= number_format($summary['pending']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="cor-report-card">
                <div class="card-head"><h3>Status Breakdown</h3><span><?= number_format(count($statusRows)) ?> statuses</span></div>
                <div class="cor-table-wrap">
                    <table>
                        <thead><tr><th>Status</th><th>Orders</th><th>Revenue</th></tr></thead>
                        <tbody>
                        <?php foreach ($statusRows as $row): ?>
                            <tr><td><span class="cor-pill cor-status-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e(cor_order_status_label((string) $row['label'])) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$statusRows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </section>

    <section id="tab-payment" class="cor-tab-content <?= $filters['tab'] === 'payment' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Orders by Payment Method</h3><span><?= number_format(count($paymentRows)) ?> methods</span></div>
            <div class="cor-table-wrap">
                <table>
                    <thead><tr><th>Method</th><th>Orders</th><th>Revenue</th><th>%</th><th>Bar</th></tr></thead>
                    <tbody>
                    <?php foreach ($paymentRows as $row): $pct = $summary['total_revenue'] > 0 ? ((float) $row['rev'] / $summary['total_revenue']) * 100 : 0; ?>
                        <tr><td><span class="cor-pill pay-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e($row['label']) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td><td><?= cor_pct($pct) ?></td><td><div class="cor-bar-wrap"><span class="cor-bar-track"><span class="cor-bar-fill" style="width:<?= cor_e(((float) $row['rev'] / $maxPayment) * 100) ?>%"></span></span><span class="cor-pct"><?= cor_pct($pct) ?></span></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$paymentRows): ?><tr><td colspan="5">No records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-mode" class="cor-tab-content <?= $filters['tab'] === 'mode' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Orders by Mode</h3><span>Delivery, collection and courier</span></div>
            <div class="cor-table-wrap">
                <table>
                    <thead><tr><th>Mode</th><th>Orders</th><th>Revenue</th><th>% of Total</th><th>Bar</th></tr></thead>
                    <tbody>
                    <?php foreach ($modeRows as $row): $pct = $summary['total_orders'] > 0 ? ((int) $row['cnt'] / $summary['total_orders']) * 100 : 0; ?>
                        <tr><td><span class="cor-pill mode-<?= cor_e(cor_slug($row['label'])) ?>"><?= cor_e(ucwords((string) $row['label'])) ?></span></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td><td><?= cor_pct($pct) ?></td><td><div class="cor-bar-wrap"><span class="cor-bar-track"><span class="cor-bar-fill" style="width:<?= cor_e(((float) $row['rev'] / $maxMode) * 100) ?>%"></span></span><span class="cor-pct"><?= cor_pct($pct) ?></span></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$modeRows): ?><tr><td colspan="5">No records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-staff" class="cor-tab-content <?= $filters['tab'] === 'staff' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Orders by Staff</h3><span>Packed by / assigned packer</span></div>
            <div class="cor-table-wrap">
                <table>
                    <thead><tr><th>Staff Name</th><th>Orders</th><th>Revenue</th><th>Avg Order Value</th><th>% of Orders</th></tr></thead>
                    <tbody>
                    <?php foreach ($staffRows as $row): $pct = $summary['total_orders'] > 0 ? ((int) $row['cnt'] / $summary['total_orders']) * 100 : 0; ?>
                        <tr><td><?= cor_e($row['label']) ?></td><td><?= number_format((int) $row['cnt']) ?></td><td><?= cor_money($row['rev']) ?></td><td><?= cor_money(((float) $row['rev']) / max(1, (int) $row['cnt'])) ?></td><td><div class="cor-bar-wrap"><span class="cor-bar-track"><span class="cor-bar-fill" style="width:<?= cor_e(((float) $row['rev'] / $maxStaff) * 100) ?>%"></span></span><span class="cor-pct"><?= cor_pct($pct) ?></span></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$staffRows): ?><tr><td colspan="5">No records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-products" class="cor-tab-content <?= $filters['tab'] === 'products' ? 'active' : '' ?>">
        <article class="cor-report-card">
            <div class="card-head"><h3>Product Sales</h3><span>Top 50 products</span></div>
            <div class="cor-table-wrap">
                <table>
                    <thead><tr><th>Rank</th><th>Product</th><th>Qty Sold</th><th>Revenue</th><th>% of Revenue</th><th>Bar</th></tr></thead>
                    <tbody>
                    <?php foreach ($productRows as $index => $row): $rank = $index + 1; $pct = $summary['total_revenue'] > 0 ? ((float) $row['rev'] / $summary['total_revenue']) * 100 : 0; ?>
                        <tr><td><span class="cor-rank cor-rank-<?= $rank <= 3 ? $rank : 'n' ?>"><?= number_format($rank) ?></span></td><td><?= cor_e($row['product_name']) ?></td><td><?= number_format((float) $row['qty'], 2) ?></td><td><?= cor_money($row['rev']) ?></td><td><?= cor_pct($pct) ?></td><td><div class="cor-bar-wrap"><span class="cor-bar-track"><span class="cor-bar-fill" style="width:<?= cor_e(((float) $row['rev'] / $maxProduct) * 100) ?>%"></span></span><span class="cor-pct"><?= cor_pct($pct) ?></span></div></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$productRows): ?><tr><td colspan="6">No records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section id="tab-trend" class="cor-tab-content <?= $filters['tab'] === 'trend' ? 'active' : '' ?>">
        <article class="cor-chart-card">
            <div class="card-head"><h3>Daily / Weekly Trend</h3><span>Revenue bars and order count line</span></div>
            <div class="cor-chart-body"><canvas id="corTrendChart"></canvas></div>
            <?php if (!$dailyRows): ?><div class="cor-empty">No records found.</div><?php endif; ?>
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

  var chartEl = document.getElementById('corTrendChart');
  if (chartEl && window.Chart) {
    new Chart(chartEl.getContext('2d'), {
      data: {
        labels: <?= json_encode(array_column($dailyRows, 'd'), JSON_UNESCAPED_SLASHES) ?>,
        datasets: [
          { type: 'bar', label: 'Revenue (N$)', data: <?= json_encode(array_map(static fn(array $row): float => round((float) $row['rev'], 2), $dailyRows), JSON_UNESCAPED_SLASHES) ?>, backgroundColor: '#AB3619', borderRadius: 4, yAxisID: 'y' },
          { type: 'line', label: 'Orders', data: <?= json_encode(array_map(static fn(array $row): int => (int) $row['orders'], $dailyRows), JSON_UNESCAPED_SLASHES) ?>, borderColor: '#A8CA19', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, yAxisID: 'y1' }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { font: { family: 'Inter', size: 11 }, color: '#6B4C3B' } } },
        scales: {
          x: { ticks: { font: { family: 'Inter', size: 11 }, color: '#6B4C3B' }, grid: { display: false } },
          y: { ticks: { font: { family: 'Inter', size: 11 }, color: '#6B4C3B' }, title: { display: true, text: 'Revenue (N$)', font: { size: 11 }, color: '#6B4C3B' } },
          y1: { position: 'right', ticks: { font: { family: 'Inter', size: 11 }, color: '#A8CA19' }, title: { display: true, text: 'Orders', font: { size: 11 }, color: '#A8CA19' }, grid: { drawOnChartArea: false } }
        }
      }
    });
  }
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
