<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Operations | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();

$stats = [
    'open_orders' => $ready ? ops_count('ops_orders', "status NOT IN ('completed')") : 0,
    'today_completed' => $ready ? ops_count('ops_orders', "DATE(completed_at) = CURDATE()") : 0,
    'missed_tasks' => $ready ? ops_count('ops_checklist_tasks', "status = 'overdue' OR (status NOT IN ('done', 'needs_review', 'completed', 'approved') AND deadline IS NOT NULL AND deadline < NOW())") : 0,
    'critical_errors' => $ready ? ops_count('ops_error_logs', "severity IN ('high', 'critical') AND logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)") : 0,
];

$sections = [
    ['Employees & Roles', 'Create staff accounts and permission levels for owner, front desk, packers and supervisors.', 'users', 'employees.php', 'Core'],
    ['Live Orders Board', 'Monday-style shared order table with packer assignment and lunch availability.', 'table-2', 'orders-board.php', 'Core'],
    ['Customer Orders Report', 'Sales operations reporting, payment insights, order timing and management intelligence.', 'shopping-bag', 'orders.php', 'Core'],
    ['Task Management', 'Assigned work, automatic cleaning tasks, daily shelf stocking and completion notes.', 'list-checks', 'checklists.php', 'Core'],
    ['Error Logging', 'Record errors by category, severity, impact, resolution and repeat issue.', 'triangle-alert', 'errors.php', 'Core'],
    ['Barcode Verification', 'Keyboard-input scanner screen with match/mismatch logging.', 'scan-barcode', 'barcode.php', 'Phase 3'],
    ['Consignment Packing', 'Bulk stock breakdown, fair workload allocation and actual quantity reporting.', 'package-open', 'consignments.php', 'Phase 2'],
];
if (user_has_role('owner_admin', 'front_desk_admin')) {
    array_splice($sections, 3, 0, [[
        'Bookkeeping',
        'Physical cash tracking for walk-ins, drivers, cash orders, cash-outs and daily closing counts.',
        'wallet-cards',
        'bookkeeping.php',
        'Core',
    ]]);
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Hambelela Organic</p>
            <h1>Operations Management System</h1>
            <p>A centralized command center for order fulfilment, employee accountability, stock packing, barcode control, errors and checklists.</p>
        </div>
        <div class="actions">
            <a class="button" href="../../index.php"><i data-lucide="arrow-left"></i> Portal</a>
            <a class="button primary" href="orders-board.php"><i data-lucide="table-2"></i> Orders board</a>
        </div>
    </section>

    <?php ops_nav('index'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>

    <section class="ops-dashboard-grid" aria-label="Operations summary">
        <article class="metric"><span>Open orders</span><strong><?= number_format($stats['open_orders']) ?></strong></article>
        <article class="metric"><span>Completed today</span><strong><?= number_format($stats['today_completed']) ?></strong></article>
        <article class="metric"><span>Overdue tasks</span><strong><?= number_format($stats['missed_tasks']) ?></strong></article>
        <article class="metric"><span>High/Critical errors</span><strong><?= number_format($stats['critical_errors']) ?></strong></article>
    </section>

    <section class="system-flow">
        <div><span>1</span><strong>Order Intake</strong><small>Capture order, items and priority</small></div>
        <div><span>2</span><strong>Fair Assignment</strong><small>Score workload before assigning</small></div>
        <div><span>3</span><strong>Packing</strong><small>Employee task ownership</small></div>
        <div><span>4</span><strong>Barcode Check</strong><small>Block wrong product scans</small></div>
        <div><span>5</span><strong>Error Log</strong><small>Record root cause and impact</small></div>
        <div><span>6</span><strong>KPI App</strong><small>Tracked from dashboard</small></div>
    </section>

    <section class="ops-card-grid" aria-label="Operations modules">
        <?php foreach ($sections as [$title, $desc, $icon, $href, $status]): ?>
            <a class="system-card" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                <div class="system-card-top">
                    <span class="system-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                    <span class="status"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>
                <small>Structured operational record</small>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if (user_has_role('owner_admin')): ?>
        <section class="panel admin-utility-panel">
            <div class="section-row">
                <div>
                    <h2>Admin utilities</h2>
                    <p>Technical monitoring tools for background services. Daily order work should stay on the Orders Board and Customer Orders Report.</p>
                </div>
                <a class="button" href="sync-orders.php"><i data-lucide="refresh-cw"></i> Sync health</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
