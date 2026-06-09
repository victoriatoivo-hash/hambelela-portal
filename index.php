<?php

declare(strict_types=1);

require_once __DIR__ . '/apps/operations/operations.php';

require_login();

$pageTitle = APP_NAME;
$activeApp = 'dashboard';
$roleKey = current_role_key();
$ready = ops_database_ready();

function portal_money(float $amount): string
{
    return 'N$' . number_format($amount, 0);
}

function portal_percent(float $value): string
{
    return number_format($value, 1) . '%';
}

function portal_sum(string $sql, array $params = []): float
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $value = (float) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $value;
    } catch (Throwable $e) {
        return 0.0;
    }
}

function portal_scalar(string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function portal_setting(string $key, string $default = ''): string
{
    if (!ops_table_exists('ops_report_settings')) return $default;
    $rows = ops_rows('SELECT setting_value FROM ops_report_settings WHERE setting_key = ? LIMIT 1', [$key]);

    return (string) ($rows[0]['setting_value'] ?? $default);
}

function portal_order_amount_expr(): string
{
    return ops_column_exists('ops_orders', 'total_amount') ? 'COALESCE(total_amount, 0)' : '0';
}

function portal_valid_order_where(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    return "{$prefix}status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged')
        AND COALESCE({$prefix}payment_status, '') NOT IN ('refunded', 'cancelled', 'canceled', 'failed')";
}

function portal_chart_bar(float $value, float $max): string
{
    $width = $max > 0 ? max(4, min(100, ($value / $max) * 100)) : 4;

    return number_format($width, 2, '.', '');
}

function portal_app_list(string $roleKey): array
{
    if ($roleKey === 'owner_admin') {
        return [
            ['name' => 'Cost Workbook', 'desc' => 'invoice to landed cost, margins, VAT and profit', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php', 'active' => true, 'tone' => 'pink'],
            ['name' => 'Operations', 'desc' => 'orders, packing, checklists, errors and KPIs', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php', 'active' => true, 'tone' => 'green'],
            ['name' => 'Employees & Roles', 'desc' => 'create, delete and reset employee login codes', 'icon' => 'users', 'href' => BASE_URL . '/apps/operations/employees.php', 'active' => true, 'tone' => 'peach'],
            ['name' => 'HR Portal', 'desc' => 'employee records, payroll and leave management', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php', 'active' => true, 'tone' => 'green'],
            ['name' => 'KPI Reports', 'desc' => 'packing speed, checklist compliance, accuracy and bonus support', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php', 'active' => true, 'tone' => 'violet'],
            ['name' => 'Packing List', 'desc' => 'consignment breakdowns, fair packer assignments and actual quantities', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'blue'],
        ];
    }

    if (in_array($roleKey, ['front_desk_admin', 'supervisor_manager'], true)) {
        return [
            ['name' => 'Live Orders Board', 'desc' => 'website orders, payments, picker assignment and daily status', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php', 'active' => true, 'tone' => 'blue'],
            ['name' => 'Error Log', 'desc' => 'record order, stock, customer and cash handling issues', 'icon' => 'triangle-alert', 'href' => BASE_URL . '/apps/operations/errors.php', 'active' => true, 'tone' => 'rose'],
            ['name' => 'Digital Checklist', 'desc' => 'opening, closing, cleaning and stock refill tasks', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php', 'active' => true, 'tone' => 'green'],
        ];
    }

    if ($roleKey === 'packer') {
        return [
            ['name' => 'Live Orders Board', 'desc' => 'see assigned orders and what the team is packing', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php', 'active' => true, 'tone' => 'blue'],
            ['name' => 'Packing List', 'desc' => 'assigned consignment packing quantities and completion status', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'pink'],
            ['name' => 'Digital Checklist', 'desc' => 'daily assigned tasks and completion tracking', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php', 'active' => true, 'tone' => 'green'],
            ['name' => 'Barcode Verification', 'desc' => 'scan products before marking orders packed', 'icon' => 'scan-barcode', 'href' => BASE_URL . '/apps/operations/barcode.php', 'active' => false, 'tone' => 'violet'],
        ];
    }

    return [];
}

$apps = portal_app_list($roleKey);
if ($roleKey !== 'owner_admin') {
    $apps[] = ['name' => 'HR Portal', 'desc' => 'leave, payslips, documents and employee self-service', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php', 'active' => true, 'tone' => 'green'];
}

$dashboard = [];
if ($roleKey === 'owner_admin') {
    $hasOrders = $ready && ops_table_exists('ops_orders');
    $hasPacking = $ready && ops_table_exists('ops_packing_tasks');
    $hasTasks = $ready && ops_table_exists('ops_checklist_tasks');
    $hasErrors = $ready && ops_table_exists('ops_error_logs');
    $hasCash = $ready && ops_table_exists('ops_cash_book_entries');
    $amountExpr = $hasOrders ? portal_order_amount_expr() : '0';
    $validOrders = $hasOrders ? portal_valid_order_where() : '1=0';
    $validOrdersAlias = $hasOrders ? portal_valid_order_where('o') : '1=0';
    $today = date('Y-m-d');
    $month = date('Y-m');
    $monthStart = date('Y-m-01');
    $nextMonth = date('Y-m-d', strtotime($monthStart . ' +1 month'));
    $monthStartSql = addslashes($monthStart);
    $nextMonthSql = addslashes($nextMonth);
    $daysLeft = max(0, (int) date('t') - (int) date('j'));
    $monthlyGoal = max(0, (float) portal_setting('monthly_sales_goal', '250000'));

    $todayRevenue = $hasOrders ? portal_sum("SELECT COALESCE(SUM({$amountExpr}), 0) FROM ops_orders WHERE {$validOrders} AND payment_status IN ('paid', 'partial') AND DATE(created_at) = CURDATE()") : 0;
    $monthRevenue = $hasOrders ? portal_sum("SELECT COALESCE(SUM({$amountExpr}), 0) FROM ops_orders WHERE {$validOrders} AND payment_status IN ('paid', 'partial') AND created_at >= ? AND created_at < ?", [$monthStart, $nextMonth]) : 0;
    $goalProgress = $monthlyGoal > 0 ? min(100, ($monthRevenue / $monthlyGoal) * 100) : 0;
    $remainingGoal = max(0, $monthlyGoal - $monthRevenue);
    $dailyNeeded = $daysLeft > 0 ? $remainingGoal / $daysLeft : $remainingGoal;

    $orderCounts = [
        'today' => $hasOrders ? ops_count('ops_orders', 'DATE(created_at) = CURDATE()') : 0,
        'new' => $hasOrders ? ops_count('ops_orders', "status IN ('new_order', 'assigned') AND DATE(created_at) = CURDATE()") : 0,
        'in_progress' => $hasOrders ? ops_count('ops_orders', "status = 'in_progress'") : 0,
        'completed' => $hasOrders ? ops_count('ops_orders', "status = 'completed' AND DATE(COALESCE(completed_at, updated_at, created_at)) = CURDATE()") : 0,
        'pending' => $hasOrders ? ops_count('ops_orders', "status NOT IN ('completed', 'cancelled', 'canceled', 'refunded', 'failed')") : 0,
        'unpaid' => $hasOrders ? ops_count('ops_orders', "payment_status IN ('unpaid', 'partial') AND {$validOrders}") : 0,
        'unassigned' => $hasOrders ? ops_count('ops_orders', "assigned_packer_id IS NULL AND status NOT IN ('completed', 'cancelled', 'canceled', 'refunded', 'failed')") : 0,
    ];
    $orderModes = $hasOrders ? ops_rows("SELECT order_type AS label, COUNT(*) AS total FROM ops_orders WHERE {$validOrders} AND DATE(created_at) = CURDATE() GROUP BY order_type") : [];
    $paymentRows = $hasOrders ? ops_rows("SELECT COALESCE(NULLIF(payment_method, ''), 'Unknown') AS label, COUNT(*) AS total FROM ops_orders WHERE {$validOrders} AND created_at >= ? AND created_at < ? GROUP BY label ORDER BY total DESC LIMIT 6", [$monthStart, $nextMonth]) : [];
    $ordersByDay = $hasOrders ? ops_rows("SELECT DATE(created_at) AS label, COUNT(*) AS orders, COALESCE(SUM(CASE WHEN payment_status IN ('paid', 'partial') THEN {$amountExpr} ELSE 0 END), 0) AS revenue FROM ops_orders WHERE {$validOrders} AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY DATE(created_at)") : [];

    $packingCounts = [
        'total' => $hasPacking ? ops_count('ops_packing_tasks', '1=1') : 0,
        'pending' => $hasPacking ? ops_count('ops_packing_tasks', "packing_status NOT IN ('done', 'website')") : 0,
        'not_started' => $hasPacking ? ops_count('ops_packing_tasks', "packing_status = 'not_started'") : 0,
        'packing' => $hasPacking ? ops_count('ops_packing_tasks', "packing_status = 'packing'") : 0,
        'done' => $hasPacking ? ops_count('ops_packing_tasks', "packing_status IN ('done', 'website')") : 0,
        'label_needed' => $hasPacking ? ops_count('ops_packing_tasks', "packing_status IN ('done_needs_label', 'packed_label_needed')") : 0,
        'website_pending' => $hasPacking ? ops_count('ops_packing_tasks', "COALESCE(website_uploaded, 0) = 0") : 0,
    ];
    $packingSplit = $hasPacking ? ops_rows(
        "SELECT COALESCE(e.full_name, 'Unassigned') AS label, COUNT(pt.id) AS total
         FROM ops_packing_tasks pt
         LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
         GROUP BY label
         ORDER BY total DESC
         LIMIT 6"
    ) : [];
    $packingStatuses = $hasPacking ? ops_rows("SELECT packing_status AS label, COUNT(*) AS total FROM ops_packing_tasks GROUP BY packing_status ORDER BY total DESC") : [];

    $taskDoneValue = ops_column_exists('ops_checklist_tasks', 'status') ? "'completed', 'done', 'approved'" : "''";
    $taskCounts = [
        'overdue' => $hasTasks ? ops_count('ops_checklist_tasks', "status = 'overdue' OR (status NOT IN ({$taskDoneValue}) AND deadline IS NOT NULL AND deadline < NOW())") : 0,
        'pending' => $hasTasks ? ops_count('ops_checklist_tasks', "status IN ('pending', 'not_started')") : 0,
        'in_progress' => $hasTasks ? ops_count('ops_checklist_tasks', "status = 'in_progress'") : 0,
        'completed_today' => $hasTasks ? ops_count('ops_checklist_tasks', "DATE(completed_at) = CURDATE()") : 0,
        'missed_recurring' => $hasTasks && ops_column_exists('ops_checklist_tasks', 'recurrence_key') ? ops_count('ops_checklist_tasks', "recurrence_key IS NOT NULL AND status NOT IN ({$taskDoneValue}) AND deadline < NOW()") : 0,
        'cleaning_due' => $hasTasks ? ops_count('ops_checklist_tasks', "checklist_type IN ('cleaning', 'saturday') AND status NOT IN ({$taskDoneValue})") : 0,
    ];
    $taskTrend = $hasTasks ? ops_rows("SELECT DATE(COALESCE(completed_at, created_at)) AS label, COUNT(*) AS total FROM ops_checklist_tasks WHERE completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(COALESCE(completed_at, created_at)) ORDER BY label") : [];

    $opening = $hasCash ? portal_sum("SELECT COALESCE(SUM(cash_in), 0) FROM ops_cash_book_entries WHERE archived_at IS NULL AND transaction_type = 'opening_balance' AND DATE(transaction_date) = CURDATE()") : 0;
    $cashInToday = $hasCash ? portal_sum("SELECT COALESCE(SUM(cash_in), 0) FROM ops_cash_book_entries WHERE archived_at IS NULL AND transaction_type <> 'opening_balance' AND DATE(transaction_date) = CURDATE()") : 0;
    $cashOutToday = $hasCash ? portal_sum("SELECT COALESCE(SUM(cash_out), 0) FROM ops_cash_book_entries WHERE archived_at IS NULL AND DATE(transaction_date) = CURDATE()") : 0;
    $actualCash = $hasCash ? portal_scalar("SELECT actual_count FROM ops_cash_book_entries WHERE archived_at IS NULL AND transaction_type = 'closing_count' AND DATE(transaction_date) = CURDATE() ORDER BY transaction_date DESC, id DESC LIMIT 1", [], null) : null;
    $expectedCash = $opening + $cashInToday - $cashOutToday;
    $variance = $actualCash === null ? null : (float) $actualCash - $expectedCash;
    $unloggedCash = 0;
    if ($hasOrders && $hasCash) {
        $unloggedCash = (int) portal_scalar(
            "SELECT COUNT(*)
             FROM ops_orders o
             LEFT JOIN ops_cash_book_entries c ON c.related_order_id = o.id AND c.archived_at IS NULL AND c.cash_in > 0
             WHERE {$validOrdersAlias}
               AND o.payment_status = 'paid'
               AND LOWER(COALESCE(o.payment_method, '')) LIKE '%cash%'
               AND c.id IS NULL",
            [],
            0
        );
    }

    $errorCounts = [
        'month' => $hasErrors ? ops_count('ops_error_logs', "logged_at >= '{$monthStartSql}' AND logged_at < '{$nextMonthSql}'") : 0,
        'critical' => $hasErrors ? ops_count('ops_error_logs', "severity = 'critical' AND logged_at >= '{$monthStartSql}' AND logged_at < '{$nextMonthSql}'") : 0,
        'high' => $hasErrors ? ops_count('ops_error_logs', "severity = 'high' AND logged_at >= '{$monthStartSql}' AND logged_at < '{$nextMonthSql}'") : 0,
        'repeat' => $hasErrors ? ops_count('ops_error_logs', "repeat_issue = 1 AND logged_at >= '{$monthStartSql}' AND logged_at < '{$nextMonthSql}'") : 0,
        'unresolved' => $hasErrors ? ops_count('ops_error_logs', "(resolution IS NULL OR resolution = '') AND logged_at >= '{$monthStartSql}' AND logged_at < '{$nextMonthSql}'") : 0,
    ];
    $topErrorEmployee = $hasErrors ? ops_rows(
        "SELECT COALESCE(e.full_name, 'Unassigned') AS name, COUNT(el.id) AS total
         FROM ops_error_logs el
         LEFT JOIN ops_employees e ON e.id = el.employee_id
         WHERE el.logged_at >= ? AND el.logged_at < ?
         GROUP BY name
         ORDER BY total DESC
         LIMIT 1",
        [$monthStart, $nextMonth]
    ) : [];

    $bestPacker = $hasOrders ? ops_rows(
        "SELECT COALESCE(e.full_name, 'Unassigned') AS name, COUNT(o.id) AS total
         FROM ops_orders o
         LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
         WHERE o.status = 'completed' AND o.created_at >= ? AND o.created_at < ?
         GROUP BY name
         ORDER BY total DESC
         LIMIT 1",
        [$monthStart, $nextMonth]
    ) : [];
    $monthOrders = $hasOrders ? ops_count('ops_orders', "created_at >= '{$monthStartSql}' AND created_at < '{$nextMonthSql}'") : 0;
    $monthCompleted = $hasOrders ? ops_count('ops_orders', "status = 'completed' AND created_at >= '{$monthStartSql}' AND created_at < '{$nextMonthSql}'") : 0;
    $orderCompletionRate = $monthOrders > 0 ? ($monthCompleted / $monthOrders) * 100 : 0;
    $packingCompletionRate = $packingCounts['total'] > 0 ? ($packingCounts['done'] / $packingCounts['total']) * 100 : 0;
    $totalTasksThisMonth = $hasTasks ? ops_count('ops_checklist_tasks', "created_at >= '{$monthStartSql}' AND created_at < '{$nextMonthSql}'") : 0;
    $doneTasksThisMonth = $hasTasks ? ops_count('ops_checklist_tasks', "status IN ({$taskDoneValue}) AND created_at >= '{$monthStartSql}' AND created_at < '{$nextMonthSql}'") : 0;
    $checklistCompliance = $totalTasksThisMonth > 0 ? ($doneTasksThisMonth / $totalTasksThisMonth) * 100 : 0;
    $avgMinutes = 0.0;
    if ($hasOrders && ops_column_exists('ops_orders', 'completed_at')) {
        $avgMinutes = portal_sum("SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)), 0) FROM ops_orders WHERE completed_at IS NOT NULL AND created_at >= ? AND created_at < ?", [$monthStart, $nextMonth]);
    }
    $avgProcessing = $avgMinutes <= 0 ? 'Not enough data' : (floor($avgMinutes / 60) > 0 ? floor($avgMinutes / 60) . 'h ' . ((int) $avgMinutes % 60) . 'm' : (int) $avgMinutes . 'm');

    $dashboard = compact(
        'todayRevenue',
        'monthRevenue',
        'monthlyGoal',
        'goalProgress',
        'remainingGoal',
        'daysLeft',
        'dailyNeeded',
        'orderCounts',
        'orderModes',
        'paymentRows',
        'ordersByDay',
        'packingCounts',
        'packingSplit',
        'packingStatuses',
        'taskCounts',
        'taskTrend',
        'opening',
        'cashInToday',
        'cashOutToday',
        'expectedCash',
        'actualCash',
        'variance',
        'unloggedCash',
        'errorCounts',
        'topErrorEmployee',
        'bestPacker',
        'orderCompletionRate',
        'packingCompletionRate',
        'checklistCompliance',
        'avgProcessing'
    );
}

include __DIR__ . '/shared/header.php';
include __DIR__ . '/shared/sidebar.php';
?>
<?php if ($roleKey === 'owner_admin'): ?>
<main class="workspace business-dashboard ops-board-page" data-board-theme="light">
    <section class="business-dashboard-hero">
        <div>
            <p class="eyebrow">Hambelela Business Portal</p>
            <h1>Business Dashboard</h1>
            <p>One central view of sales, orders, cash, packing, tasks, errors and operational performance.</p>
        </div>
        <div class="business-dashboard-actions">
            <a class="button primary" href="<?= BASE_URL ?>/apps/operations/orders-board.php"><i data-lucide="table-2"></i> Live orders</a>
            <a class="button" href="<?= BASE_URL ?>/apps/operations/reports.php"><i data-lucide="chart-no-axes-combined"></i> KPI reports</a>
            <button type="button" data-theme-toggle><i data-lucide="moon"></i></button>
        </div>
    </section>

    <?php if (!$ready): ?>
        <section class="ops-alert"><strong>Operations data is not ready.</strong> Import the operations migration files to activate the dashboard data.</section>
    <?php endif; ?>

    <section class="business-card-grid" aria-label="Business overview">
        <?php
        $overviewCards = [
            ['Today\'s Revenue', portal_money((float) $dashboard['todayRevenue']), 'Paid/partial orders today', 'banknote', 'metric-green'],
            ['Monthly Revenue', portal_money((float) $dashboard['monthRevenue']), 'Current month', 'chart-line', 'metric-purple'],
            ['Monthly Goal', portal_percent((float) $dashboard['goalProgress']), portal_money((float) $dashboard['remainingGoal']) . ' remaining', 'target', 'metric-blue'],
            ['Orders Today', number_format((int) $dashboard['orderCounts']['today']), 'All order sources', 'shopping-bag', 'metric-orange'],
            ['Pending Orders', number_format((int) $dashboard['orderCounts']['pending']), 'Awaiting completion', 'hourglass', 'metric-red'],
            ['Completed Orders', number_format((int) $dashboard['orderCounts']['completed']), 'Completed today', 'check-circle-2', 'metric-green'],
            ['Cash on Hand', portal_money((float) $dashboard['expectedCash']), 'Expected today', 'wallet-cards', 'metric-teal'],
            ['Packing Pending', number_format((int) $dashboard['packingCounts']['pending']), 'Rows still open', 'package-open', 'metric-pink'],
            ['Overdue Tasks', number_format((int) $dashboard['taskCounts']['overdue']), 'Needs attention', 'calendar-alert', 'metric-red'],
            ['Errors This Month', number_format((int) $dashboard['errorCounts']['month']), 'Logged incidents', 'triangle-alert', 'metric-orange'],
        ];
        ?>
        <?php foreach ($overviewCards as [$title, $value, $subtitle, $icon, $tone]): ?>
            <article class="work-metric-card dashboard-overview-card <?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="dashboard-layout">
        <article class="dashboard-panel goal-dashboard-panel">
            <div class="dashboard-panel-head">
                <div><h2>Monthly Goal Tracker</h2><p><?= htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8') ?> sales progress</p></div>
                <strong><?= portal_percent((float) $dashboard['goalProgress']) ?></strong>
            </div>
            <div class="goal-progress dashboard-goal-progress"><span style="width: <?= number_format((float) $dashboard['goalProgress'], 2, '.', '') ?>%"></span></div>
            <div class="goal-dashboard-stats">
                <span>Current <strong><?= portal_money((float) $dashboard['monthRevenue']) ?></strong></span>
                <span>Target <strong><?= portal_money((float) $dashboard['monthlyGoal']) ?></strong></span>
                <span>Remaining <strong><?= portal_money((float) $dashboard['remainingGoal']) ?></strong></span>
                <span>Days left <strong><?= number_format((int) $dashboard['daysLeft']) ?></strong></span>
                <span>Daily needed <strong><?= portal_money((float) $dashboard['dailyNeeded']) ?></strong></span>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>KPI Snapshot</h2><p>High-level operating signals</p></div></div>
            <div class="kpi-snapshot-grid">
                <div><span>Best packer</span><strong><?= htmlspecialchars((string) ($dashboard['bestPacker'][0]['name'] ?? 'No data'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= number_format((int) ($dashboard['bestPacker'][0]['total'] ?? 0)) ?> completed this month</small></div>
                <div><span>Order completion</span><strong><?= portal_percent((float) $dashboard['orderCompletionRate']) ?></strong><small>Completed / monthly orders</small></div>
                <div><span>Packing completion</span><strong><?= portal_percent((float) $dashboard['packingCompletionRate']) ?></strong><small>Done rows / all rows</small></div>
                <div><span>Checklist compliance</span><strong><?= portal_percent((float) $dashboard['checklistCompliance']) ?></strong><small>Completed tasks this month</small></div>
                <div><span>Avg processing</span><strong><?= htmlspecialchars((string) $dashboard['avgProcessing'], ENT_QUOTES, 'UTF-8') ?></strong><small>Created to completed</small></div>
            </div>
        </article>
    </section>

    <section class="dashboard-section-grid">
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Orders Summary</h2><p>Daily order flow and fulfilment</p></div><a href="<?= BASE_URL ?>/apps/operations/orders-board.php">Open board</a></div>
            <div class="dashboard-mini-grid">
                <span>New <strong><?= number_format((int) $dashboard['orderCounts']['new']) ?></strong></span>
                <span>In Progress <strong><?= number_format((int) $dashboard['orderCounts']['in_progress']) ?></strong></span>
                <span>Completed <strong><?= number_format((int) $dashboard['orderCounts']['completed']) ?></strong></span>
                <span>Unpaid <strong><?= number_format((int) $dashboard['orderCounts']['unpaid']) ?></strong></span>
                <span>Unassigned <strong><?= number_format((int) $dashboard['orderCounts']['unassigned']) ?></strong></span>
            </div>
            <div class="dashboard-bars">
                <?php $maxMode = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['orderModes'] ?: [['total' => 1]])); ?>
                <?php foreach ($dashboard['orderModes'] as $row): ?>
                    <div><span><?= htmlspecialchars(ucwords((string) $row['label']), ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= portal_chart_bar((float) $row['total'], $maxMode) ?>%"></i></b><strong><?= number_format((int) $row['total']) ?></strong></div>
                <?php endforeach; ?>
                <?php if (!$dashboard['orderModes']): ?><p class="dashboard-empty">No order mode data yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Packing Summary</h2><p>Bulk stock and website readiness</p></div><a href="<?= BASE_URL ?>/apps/operations/consignments.php">Open list</a></div>
            <div class="dashboard-mini-grid">
                <span>Total <strong><?= number_format((int) $dashboard['packingCounts']['total']) ?></strong></span>
                <span>Not Started <strong><?= number_format((int) $dashboard['packingCounts']['not_started']) ?></strong></span>
                <span>Packing <strong><?= number_format((int) $dashboard['packingCounts']['packing']) ?></strong></span>
                <span>Done <strong><?= number_format((int) $dashboard['packingCounts']['done']) ?></strong></span>
                <span>Label Needed <strong><?= number_format((int) $dashboard['packingCounts']['label_needed']) ?></strong></span>
                <span>Website Pending <strong><?= number_format((int) $dashboard['packingCounts']['website_pending']) ?></strong></span>
            </div>
            <div class="dashboard-bars compact-bars">
                <?php $maxSplit = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['packingSplit'] ?: [['total' => 1]])); ?>
                <?php foreach ($dashboard['packingSplit'] as $row): ?>
                    <div><span><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= portal_chart_bar((float) $row['total'], $maxSplit) ?>%"></i></b><strong><?= number_format((int) $row['total']) ?></strong></div>
                <?php endforeach; ?>
                <?php if (!$dashboard['packingSplit']): ?><p class="dashboard-empty">No packing rows yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Task Summary</h2><p>Checklist and recurring responsibility</p></div><a href="<?= BASE_URL ?>/apps/operations/checklists.php">Open tasks</a></div>
            <div class="dashboard-mini-grid">
                <span>Overdue <strong><?= number_format((int) $dashboard['taskCounts']['overdue']) ?></strong></span>
                <span>Pending <strong><?= number_format((int) $dashboard['taskCounts']['pending']) ?></strong></span>
                <span>In Progress <strong><?= number_format((int) $dashboard['taskCounts']['in_progress']) ?></strong></span>
                <span>Completed Today <strong><?= number_format((int) $dashboard['taskCounts']['completed_today']) ?></strong></span>
                <span>Missed Recurring <strong><?= number_format((int) $dashboard['taskCounts']['missed_recurring']) ?></strong></span>
                <span>Cleaning Due <strong><?= number_format((int) $dashboard['taskCounts']['cleaning_due']) ?></strong></span>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Bookkeeping Summary</h2><p>Physical cash for today</p></div><a href="<?= BASE_URL ?>/apps/operations/bookkeeping.php">Open cash</a></div>
            <div class="dashboard-mini-grid">
                <span>Opening <strong><?= portal_money((float) $dashboard['opening']) ?></strong></span>
                <span>Cash In <strong><?= portal_money((float) $dashboard['cashInToday']) ?></strong></span>
                <span>Cash Out <strong><?= portal_money((float) $dashboard['cashOutToday']) ?></strong></span>
                <span>Expected <strong><?= portal_money((float) $dashboard['expectedCash']) ?></strong></span>
                <span>Actual <strong><?= $dashboard['actualCash'] === null ? 'Not set' : portal_money((float) $dashboard['actualCash']) ?></strong></span>
                <span>Variance <strong><?= $dashboard['variance'] === null ? '-' : portal_money((float) $dashboard['variance']) ?></strong></span>
                <span>Unlogged Cash Orders <strong><?= number_format((int) $dashboard['unloggedCash']) ?></strong></span>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Error Summary</h2><p>This month's operational issues</p></div><a href="<?= BASE_URL ?>/apps/operations/errors.php">Open errors</a></div>
            <div class="dashboard-mini-grid">
                <span>Total <strong><?= number_format((int) $dashboard['errorCounts']['month']) ?></strong></span>
                <span>Critical <strong><?= number_format((int) $dashboard['errorCounts']['critical']) ?></strong></span>
                <span>High <strong><?= number_format((int) $dashboard['errorCounts']['high']) ?></strong></span>
                <span>Repeat <strong><?= number_format((int) $dashboard['errorCounts']['repeat']) ?></strong></span>
                <span>Unresolved <strong><?= number_format((int) $dashboard['errorCounts']['unresolved']) ?></strong></span>
                <span>Most Logged <strong><?= htmlspecialchars((string) ($dashboard['topErrorEmployee'][0]['name'] ?? 'No data'), ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
        </article>
    </section>

    <section class="dashboard-chart-grid">
        <article class="dashboard-panel chart-panel">
            <h2>Revenue By Day</h2>
            <?php $maxRevenue = max(1, ...array_map(static fn ($row) => (float) $row['revenue'], $dashboard['ordersByDay'] ?: [['revenue' => 1]])); ?>
            <div class="dashboard-column-chart">
                <?php foreach ($dashboard['ordersByDay'] as $row): ?>
                    <div><b style="height: <?= portal_chart_bar((float) $row['revenue'], $maxRevenue) ?>%"></b><span><?= htmlspecialchars(date('D', strtotime((string) $row['label'])), ENT_QUOTES, 'UTF-8') ?></span><small><?= portal_money((float) $row['revenue']) ?></small></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="dashboard-panel chart-panel">
            <h2>Orders By Day</h2>
            <?php $maxOrders = max(1, ...array_map(static fn ($row) => (float) $row['orders'], $dashboard['ordersByDay'] ?: [['orders' => 1]])); ?>
            <div class="dashboard-column-chart orange-chart">
                <?php foreach ($dashboard['ordersByDay'] as $row): ?>
                    <div><b style="height: <?= portal_chart_bar((float) $row['orders'], $maxOrders) ?>%"></b><span><?= htmlspecialchars(date('D', strtotime((string) $row['label'])), ENT_QUOTES, 'UTF-8') ?></span><small><?= number_format((int) $row['orders']) ?></small></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="dashboard-panel chart-panel">
            <h2>Payment Methods</h2>
            <div class="dashboard-bars">
                <?php $maxPayment = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['paymentRows'] ?: [['total' => 1]])); ?>
                <?php foreach ($dashboard['paymentRows'] as $row): ?>
                    <div><span><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= portal_chart_bar((float) $row['total'], $maxPayment) ?>%"></i></b><strong><?= number_format((int) $row['total']) ?></strong></div>
                <?php endforeach; ?>
                <?php if (!$dashboard['paymentRows']): ?><p class="dashboard-empty">No payment method data yet.</p><?php endif; ?>
            </div>
        </article>
        <article class="dashboard-panel chart-panel">
            <h2>Delivery / Collection</h2>
            <div class="dashboard-bars status-bars">
                <?php $maxModeChart = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['orderModes'] ?: [['total' => 1]])); ?>
                <?php foreach ($dashboard['orderModes'] as $row): ?>
                    <div><span><?= htmlspecialchars(ucwords((string) $row['label']), ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= portal_chart_bar((float) $row['total'], $maxModeChart) ?>%"></i></b><strong><?= number_format((int) $row['total']) ?></strong></div>
                <?php endforeach; ?>
                <?php if (!$dashboard['orderModes']): ?><p class="dashboard-empty">No fulfilment mode data yet.</p><?php endif; ?>
            </div>
        </article>
        <article class="dashboard-panel chart-panel">
            <h2>Task Completion Trend</h2>
            <?php $maxTaskTrend = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['taskTrend'] ?: [['total' => 1]])); ?>
            <div class="dashboard-column-chart teal-chart">
                <?php foreach ($dashboard['taskTrend'] as $row): ?>
                    <div><b style="height: <?= portal_chart_bar((float) $row['total'], $maxTaskTrend) ?>%"></b><span><?= htmlspecialchars(date('D', strtotime((string) $row['label'])), ENT_QUOTES, 'UTF-8') ?></span><small><?= number_format((int) $row['total']) ?></small></div>
                <?php endforeach; ?>
                <?php if (!$dashboard['taskTrend']): ?><p class="dashboard-empty">No completed task trend yet.</p><?php endif; ?>
            </div>
        </article>
        <article class="dashboard-panel chart-panel">
            <h2>Packing Progress</h2>
            <div class="dashboard-bars status-bars">
                <?php $maxPackingStatus = max(1, ...array_map(static fn ($row) => (float) $row['total'], $dashboard['packingStatuses'] ?: [['total' => 1]])); ?>
                <?php foreach ($dashboard['packingStatuses'] as $row): ?>
                    <div><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $row['label'])), ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= portal_chart_bar((float) $row['total'], $maxPackingStatus) ?>%"></i></b><strong><?= number_format((int) $row['total']) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="app-grid dashboard-app-grid" aria-label="Business apps">
        <?php foreach ($apps as $app): ?>
            <a class="app-card is-active" href="<?= htmlspecialchars($app['href'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="app-icon <?= htmlspecialchars($app['tone'], ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="<?= htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <strong><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($app['desc'], ENT_QUOTES, 'UTF-8') ?></small>
            </a>
        <?php endforeach; ?>
    </section>
</main>
<script>
(() => {
  const page = document.querySelector('.business-dashboard');
  if (!page) return;
  const storedTheme = localStorage.getItem('hambelelaDashboardTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-theme-toggle]');
    if (!toggle) return;
    const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
    page.dataset.boardTheme = next;
    localStorage.setItem('hambelelaDashboardTheme', next);
  });
})();
</script>
<?php else: ?>
<main class="workspace launcher">
    <section class="launcher-hero" aria-labelledby="launcher-title">
        <h1 id="launcher-title">essentials <span class="mascot" aria-hidden="true">&#9822;</span></h1>
        <p>your business command center</p>
    </section>

    <section class="app-grid role-app-grid" aria-label="Business apps">
        <?php foreach ($apps as $app): ?>
            <?php $tag = $app['active'] ? 'a' : 'div'; ?>
            <<?= $tag ?> class="app-card <?= $app['active'] ? 'is-active' : 'is-muted' ?>" <?= $app['active'] ? 'href="' . htmlspecialchars($app['href'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <?php if (!$app['active']): ?><span class="soon">Soon</span><?php endif; ?>
                <span class="app-icon <?= htmlspecialchars($app['tone'], ENT_QUOTES, 'UTF-8') ?>">
                    <i data-lucide="<?= htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </span>
                <strong><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($app['desc'], ENT_QUOTES, 'UTF-8') ?></small>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
</main>
<?php endif; ?>
<?php include __DIR__ . '/shared/footer.php'; ?>
