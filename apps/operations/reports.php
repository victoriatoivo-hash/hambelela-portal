<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'supervisor_manager');

$pageTitle = 'KPI Reports | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();

$completed = $ready ? ops_count('ops_orders', "status = 'completed'") : 0;
$errors = $ready ? ops_count('ops_error_logs') : 0;
$tasks = $ready ? ops_count('ops_checklist_tasks') : 0;
$completedTasks = $ready ? ops_count('ops_checklist_tasks', "status IN ('completed', 'approved')") : 0;
$taskRate = $tasks > 0 ? round(($completedTasks / $tasks) * 100) : 0;
$accuracy = max(0, 100 - min(100, $errors * 5));
$packingTasks = $ready && ops_table_exists('ops_packing_tasks') ? ops_count('ops_packing_tasks') : 0;
$packingDone = $ready && ops_table_exists('ops_packing_tasks') ? ops_count('ops_packing_tasks', "packing_status IN ('done', 'done_needs_label')") : 0;

$employeeRows = $ready ? ops_rows(
    "SELECT e.id,
            e.full_name,
            r.name AS role_name,
            COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN o.id END) AS completed_orders,
            ROUND(AVG(CASE WHEN o.packing_started_at IS NOT NULL AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, o.packing_started_at, o.completed_at) END), 1) AS avg_pick_minutes,
            ROUND(AVG(CASE WHEN o.assigned_at IS NOT NULL AND o.packing_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, o.assigned_at, o.packing_started_at) END), 1) AS avg_wait_minutes,
            COUNT(DISTINCT ct.id) AS checklist_total,
            COUNT(DISTINCT CASE WHEN ct.status IN ('completed', 'approved') THEN ct.id END) AS checklist_done,
            COUNT(DISTINCT el.id) AS error_count
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     LEFT JOIN ops_orders o ON o.assigned_packer_id = e.id
     LEFT JOIN ops_checklist_tasks ct ON ct.assigned_employee_id = e.id
     LEFT JOIN ops_error_logs el ON el.employee_id = e.id
     WHERE e.status = 'active'
     GROUP BY e.id, e.full_name, r.name
     ORDER BY completed_orders DESC, checklist_done DESC, e.full_name"
) : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">KPI Reports</p>
            <h1>Performance Dashboard</h1>
            <p>Checklist compliance, picking speed, packing output, errors and bonus support data in one admin app.</p>
        </div>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>

    <section class="ops-dashboard-grid">
        <article class="metric"><span>Completed orders</span><strong><?= number_format($completed) ?></strong></article>
        <article class="metric"><span>Total errors</span><strong><?= number_format($errors) ?></strong></article>
        <article class="metric"><span>Checklist compliance</span><strong><?= $taskRate ?>%</strong></article>
        <article class="metric"><span>Packing tasks done</span><strong><?= number_format($packingDone) ?>/<?= number_format($packingTasks) ?></strong></article>
    </section>

    <section class="ops-card-grid">
        <article class="system-card">
            <div class="system-card-top"><span class="system-icon"><i data-lucide="timer"></i></span><span class="status">Orders</span></div>
            <h2>Picking Speed</h2>
            <p>Uses assigned time, in-progress time and completed time to measure how long orders sit and how long picking takes.</p>
            <div class="kpi-bar"><span><i style="width: <?= min(100, $completed * 10) ?>%"></i></span></div>
        </article>
        <article class="system-card">
            <div class="system-card-top"><span class="system-icon"><i data-lucide="list-checks"></i></span><span class="status">Tasks</span></div>
            <h2>Checklist Compliance</h2>
            <p>Every completed checklist records loaded time, deadline and completion timestamp for KPI tracking.</p>
            <div class="kpi-bar"><span><i style="width: <?= $taskRate ?>%"></i></span></div>
        </article>
        <article class="system-card">
            <div class="system-card-top"><span class="system-icon"><i data-lucide="shield-check"></i></span><span class="status">Quality</span></div>
            <h2>Accuracy Signal</h2>
            <p>Errors, repeat issues, missed checklist items and barcode mismatches support objective bonus decisions.</p>
            <div class="kpi-bar"><span><i style="width: <?= $accuracy ?>%"></i></span></div>
        </article>
    </section>

    <section class="panel">
        <div class="section-row"><h2>Employee KPI summary</h2></div>
        <div class="table-scroll">
            <table class="data-table ops-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Role</th>
                        <th>Completed orders</th>
                        <th>Avg pick time</th>
                        <th>Avg wait before start</th>
                        <th>Checklist done</th>
                        <th>Checklist rate</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employeeRows as $row): ?>
                    <?php
                    $checkTotal = (int) $row['checklist_total'];
                    $checkDone = (int) $row['checklist_done'];
                    $rate = $checkTotal > 0 ? round(($checkDone / $checkTotal) * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $row['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars((string) $row['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $row['completed_orders']) ?></td>
                        <td><?= htmlspecialchars($row['avg_pick_minutes'] !== null ? (string) $row['avg_pick_minutes'] . ' min' : '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['avg_wait_minutes'] !== null ? (string) $row['avg_wait_minutes'] . ' min' : '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format($checkDone) ?>/<?= number_format($checkTotal) ?></td>
                        <td><?= $rate ?>%</td>
                        <td><?= number_format((int) $row['error_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$employeeRows): ?><tr><td colspan="8">No KPI data recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
