<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Bookkeeping | ' . APP_NAME;
$activeApp = 'operations-bookkeeping';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin');

$transactionTypes = [
    'opening_balance' => 'Opening Balance',
    'cash_received' => 'Cash Received',
    'driver_cash_returned' => 'Driver Cash Returned',
    'cash_taken_out' => 'Cash Taken Out',
    'deposit' => 'Deposit',
    'closing_count' => 'Closing Count',
];
$sources = [
    'walk_in_customer' => 'Walk-in Customer',
    'delivery_driver' => 'Delivery Driver',
    'customer_collection' => 'Customer Collection',
    'manual_cash_entry' => 'Manual Cash Entry',
    'other' => 'Other',
];

function cash_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Older installs may already have some columns. Keep the screen usable.
    }
}

function cash_column_exists(string $column): bool
{
    return ops_table_exists('ops_cash_book_entries') && ops_column_exists('ops_cash_book_entries', $column);
}

function cash_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_cash_book_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            transaction_type VARCHAR(40) NOT NULL,
            description VARCHAR(190) NOT NULL,
            related_order_id INT NULL,
            related_order_number VARCHAR(80) NULL,
            customer_name VARCHAR(190) NULL,
            order_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_in DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
            actual_count DECIMAL(12,2) NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'manual_cash_entry',
            notes TEXT NULL,
            attachment_path VARCHAR(255) NULL,
            recorded_by INT NULL,
            edited_by INT NULL,
            archived_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $columns = [
        'related_order_id' => "ALTER TABLE ops_cash_book_entries ADD COLUMN related_order_id INT NULL AFTER description",
        'related_order_number' => "ALTER TABLE ops_cash_book_entries ADD COLUMN related_order_number VARCHAR(80) NULL AFTER related_order_id",
        'customer_name' => "ALTER TABLE ops_cash_book_entries ADD COLUMN customer_name VARCHAR(190) NULL AFTER related_order_number",
        'order_total' => "ALTER TABLE ops_cash_book_entries ADD COLUMN order_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER customer_name",
        'actual_count' => "ALTER TABLE ops_cash_book_entries ADD COLUMN actual_count DECIMAL(12,2) NULL AFTER cash_out",
        'source' => "ALTER TABLE ops_cash_book_entries ADD COLUMN source VARCHAR(60) NOT NULL DEFAULT 'manual_cash_entry' AFTER actual_count",
        'attachment_path' => "ALTER TABLE ops_cash_book_entries ADD COLUMN attachment_path VARCHAR(255) NULL AFTER notes",
        'recorded_by' => "ALTER TABLE ops_cash_book_entries ADD COLUMN recorded_by INT NULL AFTER attachment_path",
        'edited_by' => "ALTER TABLE ops_cash_book_entries ADD COLUMN edited_by INT NULL AFTER recorded_by",
        'archived_at' => "ALTER TABLE ops_cash_book_entries ADD COLUMN archived_at DATETIME NULL AFTER edited_by",
        'updated_at' => "ALTER TABLE ops_cash_book_entries ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!cash_column_exists($column)) cash_try_sql($sql);
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_cash_book_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            employee_id INT NULL,
            action VARCHAR(80) NOT NULL,
            previous_values TEXT NULL,
            new_values TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function cash_money(float $amount): string
{
    return 'N$' . number_format($amount, 2);
}

function cash_type_class(string $type): string
{
    return 'cash-type-' . preg_replace('/[^a-z0-9_]+/', '', strtolower($type));
}

function cash_upload_attachment(int $entryId): ?string
{
    if (empty($_FILES['attachment']['name']) || !is_uploaded_file($_FILES['attachment']['tmp_name'])) return null;
    $extension = strtolower(pathinfo((string) $_FILES['attachment']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'], true)) return null;
    $uploadDir = BASE_PATH . '/uploads/bookkeeping';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $fileName = 'cash-entry-' . $entryId . '-' . date('YmdHis') . '.' . $extension;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . '/' . $fileName)) return null;
    return 'uploads/bookkeeping/' . $fileName;
}

function cash_order_amount_expr(): string
{
    if (ops_column_exists('ops_orders', 'total_amount')) return 'COALESCE(total_amount, 0)';
    return '0';
}

function cash_is_cash_method(string $method): bool
{
    $method = strtolower($method);
    return str_contains($method, 'cash');
}

if ($ready) {
    cash_bootstrap_schema();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'create_cash_entry') {
            $type = ops_post_string('transaction_type', 40);
            $source = ops_post_string('source', 60);
            if (!array_key_exists($type, $transactionTypes)) throw new RuntimeException('Choose a valid transaction type.');
            if (!array_key_exists($source, $sources)) $source = 'manual_cash_entry';

            $description = ops_post_string('description', 190);
            if ($description === '') throw new RuntimeException('Description is required.');

            $transactionDate = str_replace('T', ' ', ops_post_string('transaction_date', 30));
            if ($transactionDate === '') $transactionDate = date('Y-m-d H:i:s');
            if (strlen($transactionDate) === 16) $transactionDate .= ':00';

            $cashIn = max(0, (float) ($_POST['cash_in'] ?? 0));
            $cashOut = max(0, (float) ($_POST['cash_out'] ?? 0));
            $actualCount = ($_POST['actual_count'] ?? '') === '' ? null : max(0, (float) $_POST['actual_count']);
            if (in_array($type, ['cash_taken_out', 'deposit'], true) && $cashOut <= 0) throw new RuntimeException('Cash out amount is required.');
            if (in_array($type, ['cash_received', 'driver_cash_returned', 'opening_balance'], true) && $cashIn <= 0) throw new RuntimeException('Cash in amount is required.');
            if ($type === 'closing_count' && $actualCount === null) throw new RuntimeException('Actual counted cash is required for closing count.');

            $relatedOrderId = (int) ($_POST['related_order_id'] ?? 0);
            $relatedOrderNumber = ops_post_string('related_order_number', 80);
            $customerName = ops_post_string('customer_name', 190);
            $orderTotal = (float) ($_POST['order_total'] ?? 0);

            if ($relatedOrderId <= 0 && $relatedOrderNumber !== '') {
                $amountExpr = cash_order_amount_expr();
                $matches = ops_rows("SELECT id, order_number, customer_name, {$amountExpr} AS order_total FROM ops_orders WHERE order_number = ? LIMIT 1", [$relatedOrderNumber]);
                if ($matches) {
                    $relatedOrderId = (int) $matches[0]['id'];
                    $customerName = (string) ($matches[0]['customer_name'] ?? $customerName);
                    $orderTotal = (float) ($matches[0]['order_total'] ?? $orderTotal);
                }
            }

            if ($relatedOrderId > 0 && $cashIn > 0) {
                $dupes = ops_rows('SELECT id FROM ops_cash_book_entries WHERE related_order_id = ? AND cash_in > 0 AND archived_at IS NULL LIMIT 1', [$relatedOrderId]);
                if ($dupes) throw new RuntimeException('This order already has a cash entry.');
            }

            $stmt = db()->prepare(
                "INSERT INTO ops_cash_book_entries
                 (transaction_date, transaction_type, description, related_order_id, related_order_number, customer_name, order_total, cash_in, cash_out, actual_count, source, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $transactionDate,
                $type,
                $description,
                $relatedOrderId > 0 ? $relatedOrderId : null,
                $relatedOrderNumber ?: null,
                $customerName ?: null,
                $orderTotal,
                $cashIn,
                $cashOut,
                $actualCount,
                $source,
                ops_post_string('notes', 1500),
                $currentEmployeeId,
            ]);
            $entryId = (int) db()->lastInsertId();
            $path = cash_upload_attachment($entryId);
            if ($path) {
                $stmt = db()->prepare('UPDATE ops_cash_book_entries SET attachment_path = ? WHERE id = ?');
                $stmt->execute([$path, $entryId]);
            }
            if (ops_table_exists('ops_cash_book_audit')) {
                $stmt = db()->prepare('INSERT INTO ops_cash_book_audit (entry_id, employee_id, action, new_values) VALUES (?, ?, ?, ?)');
                $stmt->execute([$entryId, $currentEmployeeId, 'created', json_encode($_POST, JSON_UNESCAPED_SLASHES)]);
            }
            ops_activity_log('cash_entry_created', 'cash_book', $entryId, ['transaction_type' => $type, 'cash_in' => $cashIn, 'cash_out' => $cashOut]);
            $message = 'Cash entry recorded.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$filters = [
    'month' => trim((string) ($_GET['month'] ?? date('Y-m'))),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'transaction_type' => trim((string) ($_GET['transaction_type'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'order' => trim((string) ($_GET['order'] ?? '')),
    'recorded_by' => trim((string) ($_GET['recorded_by'] ?? '')),
    'direction' => trim((string) ($_GET['direction'] ?? '')),
];
$filtersAreActive = $filters['date_from'] !== '' || $filters['date_to'] !== '' || $filters['transaction_type'] !== '' || $filters['source'] !== '' || $filters['order'] !== '' || $filters['recorded_by'] !== '' || $filters['direction'] !== '';

$where = ['c.archived_at IS NULL'];
$params = [];
if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $where[] = 'DATE(c.transaction_date) >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $where[] = 'DATE(c.transaction_date) <= ?';
    $params[] = $filters['date_to'];
}
if (!$filters['date_from'] && !$filters['date_to'] && preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
    $where[] = "DATE_FORMAT(c.transaction_date, '%Y-%m') = ?";
    $params[] = $filters['month'];
}
if (array_key_exists($filters['transaction_type'], $transactionTypes)) {
    $where[] = 'c.transaction_type = ?';
    $params[] = $filters['transaction_type'];
}
if (array_key_exists($filters['source'], $sources)) {
    $where[] = 'c.source = ?';
    $params[] = $filters['source'];
}
if ($filters['order'] !== '') {
    $where[] = '(c.related_order_number LIKE ? OR c.customer_name LIKE ? OR c.description LIKE ?)';
    $params[] = '%' . $filters['order'] . '%';
    $params[] = '%' . $filters['order'] . '%';
    $params[] = '%' . $filters['order'] . '%';
}
if ((int) $filters['recorded_by'] > 0) {
    $where[] = 'c.recorded_by = ?';
    $params[] = (int) $filters['recorded_by'];
}
if ($filters['direction'] === 'cash_in') $where[] = 'c.cash_in > 0';
if ($filters['direction'] === 'cash_out') $where[] = 'c.cash_out > 0';
if ($filters['direction'] === 'variance') $where[] = 'c.actual_count IS NOT NULL';

$whereSql = implode(' AND ', $where);
$entries = $ready ? ops_rows(
    "SELECT c.*, e.full_name AS recorded_by_name
     FROM ops_cash_book_entries c
     LEFT JOIN ops_employees e ON e.id = c.recorded_by
     WHERE {$whereSql}
     ORDER BY c.transaction_date ASC, c.id ASC",
    $params
) : [];

$runningBalance = 0.0;
$entriesByDate = [];
foreach ($entries as &$entry) {
    $type = (string) ($entry['transaction_type'] ?? '');
    if ($type !== 'closing_count') {
        $runningBalance += (float) ($entry['cash_in'] ?? 0) - (float) ($entry['cash_out'] ?? 0);
    }
    $entry['running_balance'] = $runningBalance;
    $entriesByDate[(string) date('Y-m-d', strtotime((string) $entry['transaction_date']))][] = $entry;
}
unset($entry);

$todayRows = $ready ? ops_rows(
    "SELECT *
     FROM ops_cash_book_entries
     WHERE archived_at IS NULL AND DATE(transaction_date) = CURDATE()
     ORDER BY transaction_date ASC, id ASC"
) : [];
$opening = 0.0;
$cashInToday = 0.0;
$cashOutToday = 0.0;
$driverCash = 0.0;
$actualCounted = null;
foreach ($todayRows as $row) {
    $type = (string) ($row['transaction_type'] ?? '');
    if ($type === 'opening_balance') $opening += (float) $row['cash_in'];
    if ($type !== 'opening_balance') $cashInToday += (float) $row['cash_in'];
    $cashOutToday += (float) $row['cash_out'];
    if ((string) ($row['source'] ?? '') === 'delivery_driver' || $type === 'driver_cash_returned') $driverCash += (float) $row['cash_in'];
    if ($type === 'closing_count' && $row['actual_count'] !== null) $actualCounted = (float) $row['actual_count'];
}
$expectedCash = $opening + $cashInToday - $cashOutToday;
$variance = $actualCounted === null ? null : $actualCounted - $expectedCash;

$amountExpr = $ready && ops_table_exists('ops_orders') ? cash_order_amount_expr() : '0';
$cashOrders = $ready && ops_table_exists('ops_orders') ? ops_rows(
    "SELECT id, order_number, customer_name, payment_method, payment_status, order_type, created_at, {$amountExpr} AS order_total
     FROM ops_orders
     WHERE payment_status = 'paid'
       AND LOWER(COALESCE(payment_method, '')) LIKE '%cash%'
       AND status NOT IN ('cancelled', 'canceled', 'refunded', 'failed')
     ORDER BY created_at DESC
     LIMIT 250"
) : [];
$lookupOrders = $ready && ops_table_exists('ops_orders') ? ops_rows(
    "SELECT id, order_number, customer_name, payment_method, payment_status, order_type, created_at, {$amountExpr} AS order_total
     FROM ops_orders
     WHERE status NOT IN ('cancelled', 'canceled', 'refunded', 'failed')
     ORDER BY created_at DESC
     LIMIT 500"
) : [];
$loggedOrderIds = $ready ? ops_rows('SELECT DISTINCT related_order_id FROM ops_cash_book_entries WHERE related_order_id IS NOT NULL AND archived_at IS NULL') : [];
$loggedMap = [];
foreach ($loggedOrderIds as $row) $loggedMap[(int) $row['related_order_id']] = true;
$unloggedCashOrders = array_values(array_filter($cashOrders, static fn (array $row): bool => empty($loggedMap[(int) $row['id']])));

$employees = $ready ? ops_rows(
    "SELECT e.id, e.full_name
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND r.role_key IN ('owner_admin', 'front_desk_admin', 'supervisor_manager')
     ORDER BY e.full_name"
) : [];

$orderLookup = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'order_number' => (string) $row['order_number'],
    'customer_name' => (string) ($row['customer_name'] ?? ''),
    'order_total' => (float) ($row['order_total'] ?? 0),
    'payment_method' => (string) ($row['payment_method'] ?? ''),
    'payment_status' => (string) ($row['payment_status'] ?? ''),
    'order_type' => (string) ($row['order_type'] ?? ''),
    'created_at' => (string) ($row['created_at'] ?? ''),
], $lookupOrders);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module bookkeeping-page ops-board-page" data-board-theme="light">
    <section class="monday-board-top">
        <div class="monday-board-head">
            <div>
                <h1>Bookkeeping <i data-lucide="chevron-down"></i></h1>
                <p class="work-board-subtitle">Physical business cash tracking for walk-ins, drivers, cash orders and daily counts.</p>
            </div>
            <div class="monday-board-head-actions">
                <button class="invite-btn" type="button" data-cash-entry-open><i data-lucide="plus"></i> New Entry</button>
                <button type="button" data-export-cash><i data-lucide="download"></i> Export Excel</button>
                <button type="button" data-theme-toggle><i data-lucide="moon"></i></button>
            </div>
        </div>
    </section>

    <?php ops_nav('bookkeeping'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="work-metric-grid bookkeeping-metric-grid" aria-label="Cash summary">
        <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="wallet-cards"></i></span><div><span class="metric-title">Opening Balance</span><strong><?= cash_money($opening) ?></strong><small>Today</small></div></article>
        <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="circle-plus"></i></span><div><span class="metric-title">Cash In Today</span><strong><?= cash_money($cashInToday) ?></strong><small>Received</small></div></article>
        <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="circle-minus"></i></span><div><span class="metric-title">Cash Out Today</span><strong><?= cash_money($cashOutToday) ?></strong><small>Paid out</small></div></article>
        <article class="work-metric-card metric-purple"><span class="metric-icon"><i data-lucide="calculator"></i></span><div><span class="metric-title">Expected Cash</span><strong><?= cash_money($expectedCash) ?></strong><small>Opening + in - out</small></div></article>
        <article class="work-metric-card metric-slate"><span class="metric-icon"><i data-lucide="scale"></i></span><div><span class="metric-title">Actual Counted</span><strong><?= $actualCounted === null ? 'Not set' : cash_money($actualCounted) ?></strong><small>Closing count</small></div></article>
        <article class="work-metric-card <?= $variance === null || abs($variance) < 0.01 ? 'metric-green' : 'metric-orange' ?>"><span class="metric-icon"><i data-lucide="badge-alert"></i></span><div><span class="metric-title">Difference</span><strong><?= $variance === null ? '-' : cash_money($variance) ?></strong><small><?= $variance === null ? 'No closing count' : (abs($variance) < 0.01 ? 'Balanced' : ($variance > 0 ? 'Over' : 'Short')) ?></small></div></article>
        <article class="work-metric-card metric-teal"><span class="metric-icon"><i data-lucide="truck"></i></span><div><span class="metric-title">Driver Cash</span><strong><?= cash_money($driverCash) ?></strong><small>Returned today</small></div></article>
        <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="receipt-text"></i></span><div><span class="metric-title">Unlogged Cash Orders</span><strong><?= number_format(count($unloggedCashOrders)) ?></strong><small>Needs logging</small></div></article>
    </section>

    <details class="panel task-filter-panel bookkeeping-filter-panel" <?= $filtersAreActive ? 'open' : '' ?>>
        <summary><span><i data-lucide="sliders-horizontal"></i> Filters</span><strong><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form method="get">
            <div class="form-grid compact task-filter-grid">
                <label>Month<input type="month" name="month" value="<?= htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Transaction type<select name="transaction_type"><option value="">All types</option><?php ops_select_options($transactionTypes, $filters['transaction_type']); ?></select></label>
                <label>Source<select name="source"><option value="">All sources</option><?php ops_select_options($sources, $filters['source']); ?></select></label>
                <label>Recorded by<select name="recorded_by"><option value="">All people</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $filters['recorded_by'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label>Direction<select name="direction"><?php ops_select_options(['' => 'All', 'cash_in' => 'Cash in only', 'cash_out' => 'Cash out only', 'variance' => 'Variance / counts'], $filters['direction']); ?></select></label>
                <label class="span-2">Related order / customer<input name="order" value="<?= htmlspecialchars($filters['order'], ENT_QUOTES, 'UTF-8') ?>" placeholder="#33863, customer name or notes"></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="bookkeeping.php">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <?php if ($unloggedCashOrders): ?>
        <section class="panel unlogged-cash-panel">
            <div class="section-row">
                <div><h2>Unlogged cash orders</h2><p>Paid cash orders in Operations that do not yet have a bookkeeping cash entry.</p></div>
                <span class="status warning"><?= number_format(count($unloggedCashOrders)) ?> open</span>
            </div>
            <div class="unlogged-cash-list">
                <?php foreach (array_slice($unloggedCashOrders, 0, 12) as $order): ?>
                    <button type="button" data-fill-cash-order="<?= (int) $order['id'] ?>">
                        <strong>#<?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) $order['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= cash_money((float) $order['order_total']) ?> - <?= htmlspecialchars((string) $order['payment_method'], ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="ops-board-shell bookkeeping-board-shell">
        <div class="ops-board-scroll">
            <table class="ops-board-table bookkeeping-table" data-cash-table>
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>DESCRIPTION</th>
                        <th>TRANSACTION TYPE</th>
                        <th>DRIVER / CUSTOMER</th>
                        <th>RELATED ORDER</th>
                        <th>CASH IN</th>
                        <th>CASH OUT</th>
                        <th>BALANCE</th>
                        <th>NOTES</th>
                        <th>RECORDED BY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entriesByDate as $date => $dateEntries): ?>
                        <?php
                        $dayIn = array_sum(array_map(static fn (array $row): float => (float) $row['cash_in'], $dateEntries));
                        $dayOut = array_sum(array_map(static fn (array $row): float => (float) $row['cash_out'], $dateEntries));
                        ?>
                        <tr class="group-row"><td colspan="10"><i data-lucide="chevron-down"></i> <?= htmlspecialchars((new DateTimeImmutable($date))->format('F j, Y'), ENT_QUOTES, 'UTF-8') ?> <span><?= count($dateEntries) ?> entries</span></td></tr>
                        <?php foreach ($dateEntries as $entry): ?>
                            <?php $type = (string) $entry['transaction_type']; ?>
                            <tr class="cash-transaction-row" data-cash-detail-open="<?= (int) $entry['id'] ?>">
                                <td><?= htmlspecialchars((new DateTimeImmutable((string) $entry['transaction_date']))->format('M j, H:i'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="task-cell"><strong><?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($sources[(string) $entry['source']] ?? (string) $entry['source'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><span class="board-label cash-label <?= cash_type_class($type) ?>"><?= htmlspecialchars($transactionTypes[$type] ?? $type, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) ($entry['customer_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($entry['related_order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="cash-in-amount"><?= (float) $entry['cash_in'] > 0 ? cash_money((float) $entry['cash_in']) : '-' ?></td>
                                <td class="cash-out-amount"><?= (float) $entry['cash_out'] > 0 ? cash_money((float) $entry['cash_out']) : '-' ?></td>
                                <td><strong><?= cash_money((float) $entry['running_balance']) ?></strong></td>
                                <td class="notes-cell"><?= htmlspecialchars((string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($entry['recorded_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="summary-row"><td><?= htmlspecialchars((new DateTimeImmutable($date))->format('M j'), ENT_QUOTES, 'UTF-8') ?></td><td colspan="4"><?= count($dateEntries) ?> transactions</td><td><strong><?= cash_money($dayIn) ?></strong><small>in</small></td><td><strong><?= cash_money($dayOut) ?></strong><small>out</small></td><td colspan="3"></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$entries): ?><tr><td colspan="10" class="board-empty-state">No cash entries found. Use New Entry to record opening balance, cash received, driver cash or closing count.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php foreach ($entries as $entry): ?>
        <?php $type = (string) $entry['transaction_type']; ?>
        <aside class="task-detail-panel cash-detail-panel" data-cash-detail-panel="<?= (int) $entry['id'] ?>" aria-hidden="true">
            <div class="task-detail-head">
                <button class="panel-back-button" type="button" data-cash-detail-close><i data-lucide="arrow-left"></i> Back</button>
                <div><span class="board-label cash-label <?= cash_type_class($type) ?>"><?= htmlspecialchars($transactionTypes[$type] ?? $type, ENT_QUOTES, 'UTF-8') ?></span><h2><?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                <button class="panel-close-button" type="button" data-cash-detail-close aria-label="Close cash details"><i data-lucide="x"></i></button>
            </div>
            <div class="task-detail-grid">
                <div><span>Date</span><strong><?= htmlspecialchars((new DateTimeImmutable((string) $entry['transaction_date']))->format('M j, Y H:i'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Driver / Customer</span><strong><?= htmlspecialchars((string) ($entry['customer_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Related order</span><strong><?= htmlspecialchars((string) ($entry['related_order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Source</span><strong><?= htmlspecialchars($sources[(string) $entry['source']] ?? (string) $entry['source'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Cash in</span><strong><?= cash_money((float) $entry['cash_in']) ?></strong></div>
                <div><span>Cash out</span><strong><?= cash_money((float) $entry['cash_out']) ?></strong></div>
                <div><span>Balance after row</span><strong><?= cash_money((float) $entry['running_balance']) ?></strong></div>
                <div><span>Actual counted</span><strong><?= $entry['actual_count'] === null ? '-' : cash_money((float) $entry['actual_count']) ?></strong></div>
                <div><span>Recorded by</span><strong><?= htmlspecialchars((string) ($entry['recorded_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Order total</span><strong><?= cash_money((float) $entry['order_total']) ?></strong></div>
            </div>
            <section><h3>Notes</h3><p><?= nl2br(htmlspecialchars((string) ($entry['notes'] ?: 'No notes added.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
            <section><h3>Attachment</h3><?php if (!empty($entry['attachment_path'])): ?><a class="button small" href="<?= BASE_URL . '/' . htmlspecialchars((string) $entry['attachment_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open attachment</a><?php else: ?><p>No attachment uploaded.</p><?php endif; ?></section>
        </aside>
    <?php endforeach; ?>

    <aside class="task-create-panel cash-entry-panel" data-cash-entry-panel aria-hidden="true">
        <form class="ops-form checklist-create-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_cash_entry">
            <input type="hidden" name="related_order_id" data-cash-order-id>
            <input type="hidden" name="related_order_number" data-cash-order-number>
            <input type="hidden" name="order_total" data-cash-order-total>
            <div class="task-detail-head">
                <button type="button" data-cash-entry-close aria-label="Close new cash entry"><i data-lucide="x"></i></button>
                <div><span class="status task-kind-manual">Physical cash</span><h2>New cash entry</h2></div>
            </div>
            <label>Search order / customer
                <input type="search" list="cash-order-options" data-cash-order-search placeholder="#33863 or customer name">
            </label>
            <datalist id="cash-order-options">
                <?php foreach ($orderLookup as $order): ?>
                    <option value="#<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="cash-order-preview" data-cash-order-preview>No order selected. You can still create a manual cash entry.</div>
            <div class="form-grid compact">
                <label>Transaction type<select name="transaction_type" required><?php ops_select_options($transactionTypes, 'cash_received'); ?></select></label>
                <label>Source<select name="source"><?php ops_select_options($sources, 'walk_in_customer'); ?></select></label>
                <label>Date/time<input type="datetime-local" name="transaction_date" value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Driver / customer / receiver<input name="customer_name" data-cash-customer placeholder="Driver name, customer name or person receiving cash"></label>
                <label class="span-2">Description<input name="description" required placeholder="Cash received for order #33863"></label>
                <label>Cash in<input type="number" step="0.01" min="0" name="cash_in" value="0"></label>
                <label>Cash out<input type="number" step="0.01" min="0" name="cash_out" value="0"></label>
                <label>Actual cash counted<input type="number" step="0.01" min="0" name="actual_count" placeholder="For closing count"></label>
            </div>
            <label>Notes / reason<textarea name="notes" placeholder="Reason for cash out, driver return details, variance note"></textarea></label>
            <label class="error-upload-zone">
                <input type="file" name="attachment" accept="image/*,.pdf,.doc,.docx">
                <span><i data-lucide="upload-cloud"></i></span>
                <strong>Upload receipt or proof</strong>
                <small>Optional photo, PDF, delivery note or proof file</small>
            </label>
            <div class="ops-form-actions"><button class="button" type="button" data-cash-entry-close>Cancel</button><button class="button primary" type="submit">Save cash entry</button></div>
        </form>
    </aside>
    <div class="panel-backdrop cash-panel-backdrop" data-cash-entry-close hidden></div>
</main>
<script>
window.HambelelaCashOrders = <?= json_encode($orderLookup, JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('click', (event) => {
  const open = event.target.closest('[data-cash-entry-open]');
  const close = event.target.closest('[data-cash-entry-close]');
  const fill = event.target.closest('[data-fill-cash-order]');
  const detailOpen = event.target.closest('[data-cash-detail-open]');
  const detailClose = event.target.closest('[data-cash-detail-close]');
  const panel = document.querySelector('[data-cash-entry-panel]');
  const backdrop = document.querySelector('.cash-panel-backdrop');
  const showPanel = () => {
    if (panel) panel.classList.add('open');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  };
  if (open) showPanel();
  if (fill) {
    showPanel();
    const order = (window.HambelelaCashOrders || []).find((item) => String(item.id) === String(fill.dataset.fillCashOrder));
    if (order) window.fillCashOrder(order);
  }
  if (close) {
    if (panel) panel.classList.remove('open');
    document.querySelectorAll('.cash-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('task-panel-open');
  }
  if (detailOpen) {
    document.querySelectorAll('.cash-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    const detailPanel = document.querySelector(`[data-cash-detail-panel="${detailOpen.dataset.cashDetailOpen}"]`);
    if (detailPanel) detailPanel.classList.add('open');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (detailClose) {
    document.querySelectorAll('.cash-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    if (!panel || !panel.classList.contains('open')) {
      if (backdrop) backdrop.hidden = true;
      document.body.classList.remove('task-panel-open');
    }
  }
});

document.querySelector('[data-export-cash]')?.addEventListener('click', () => {
  const rows = Array.from(document.querySelectorAll('[data-cash-table] tr'))
    .map((row) => Array.from(row.cells || []).map((cell) => `"${cell.innerText.replaceAll('"', '""').trim()}"`).join(','))
    .filter(Boolean);
  const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `hambelela-cash-book-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
});

window.fillCashOrder = (order) => {
  const panel = document.querySelector('[data-cash-entry-panel]');
  if (!panel) return;
  panel.querySelector('[data-cash-order-id]').value = order.id || '';
  panel.querySelector('[data-cash-order-number]').value = order.order_number || '';
  panel.querySelector('[data-cash-customer]').value = order.customer_name || '';
  panel.querySelector('[data-cash-order-total]').value = order.order_total || 0;
  panel.querySelector('[name="description"]').value = `Cash received for order #${order.order_number || ''}`;
  panel.querySelector('[name="cash_in"]').value = Number(order.order_total || 0).toFixed(2);
  panel.querySelector('[name="source"]').value = (String(order.order_type || '').toLowerCase().includes('delivery')) ? 'delivery_driver' : 'walk_in_customer';
  panel.querySelector('[data-cash-order-search]').value = `#${order.order_number || ''} ${order.customer_name || ''}`;
  panel.querySelector('[data-cash-order-preview]').innerHTML = `<strong>Order #${order.order_number}</strong><span>${order.customer_name || 'Customer'} - N$${Number(order.order_total || 0).toFixed(2)} - ${order.payment_method || 'Payment'} - ${order.payment_status || ''}</span>`;
};

document.addEventListener('input', (event) => {
  if (!event.target.matches('[data-cash-order-search]')) return;
  const value = event.target.value.toLowerCase();
  const order = (window.HambelelaCashOrders || []).find((item) => (`#${item.order_number} ${item.customer_name}`).toLowerCase() === value || (`${item.order_number}`).toLowerCase() === value.replace('#', ''));
  if (order) window.fillCashOrder(order);
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  const panel = document.querySelector('[data-cash-entry-panel]');
  const backdrop = document.querySelector('.cash-panel-backdrop');
  if (panel) panel.classList.remove('open');
  document.querySelectorAll('.cash-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('task-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
