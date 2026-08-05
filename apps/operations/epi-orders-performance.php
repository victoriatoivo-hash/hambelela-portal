<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
if (current_role_key() === 'guest') { header('Location: ' . BASE_URL . '/login.php'); exit; }

\Hambelela\EPI\Performance::configure(db());
$service = new \Hambelela\EPI\OrdersPerformance(db());
$canReviewAll = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$employeeId = $canReviewAll ? max(0, (int) ($_GET['employee_id'] ?? 0)) : ops_current_employee_id();
$filters = [
    'period' => (string) ($_GET['period'] ?? 'previous_month'),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
    'employee_id' => $employeeId ?: '',
];
$summary = $service->getSummary($filters);
$evidence = $service->getEvidence($filters, 200);
$employees = $canReviewAll ? $service->employeeOptions() : [];
$pageTitle = 'EPI Orders Verification | ' . APP_NAME;
$activeApp = 'kpi';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
$metricLabels = [
    'orders_completed'=>'Orders completed','orders_outstanding'=>'Outstanding','orders_pending'=>'Pending',
    'orders_overdue'=>'Overdue','orders_reopened'=>'Reopened','late_orders'=>'Late completion evidence',
    'average_completion_minutes'=>'Average completion (business min)','evidence_count'=>'Evidence records','activity_count'=>'Activity records',
];
?>
<main class="workspace module epi-page">
  <section class="module-header">
    <div><p class="eyebrow">Employee Performance Intelligence · Phase 2 verification</p><h1>Front Desk &amp; Orders Performance</h1><p>Plain verification view. No score is calculated and no existing Employee Performance page is changed.</p></div>
  </section>
  <?php if (!\Hambelela\EPI\Performance::enabled()): ?><section class="ops-alert">EPI background recording is currently disabled by its master feature flag. Existing evidence remains readable.</section><?php endif; ?>
  <form method="get" class="section-card" style="padding:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
    <?php if ($canReviewAll): ?><label><span>Employee</span><select name="employee_id"><option value="">All employees</option><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= (int)$employee['id']===$employeeId?'selected':'' ?>><?= htmlspecialchars((string)$employee['full_name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label><span>Period</span><select name="period"><?php foreach(['today'=>'Today','yesterday'=>'Yesterday','this_week'=>'This Week','last_week'=>'Last Week','this_month'=>'This Month','previous_month'=>'Previous Month','quarter'=>'Quarter','year'=>'Year','custom'=>'Custom'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['period']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label><span>From</span><input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'],ENT_QUOTES,'UTF-8') ?>"></label>
    <label><span>To</span><input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'],ENT_QUOTES,'UTF-8') ?>"></label>
    <button class="btn-primary" type="submit">Apply</button>
  </form>
  <section class="work-metrics-grid" aria-label="Front Desk metrics">
    <?php foreach($metricLabels as $key=>$label): ?><article class="work-metric-card"><div><span class="metric-title"><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></span><strong><?= $summary[$key]===null?'Insufficient historical data':htmlspecialchars((string)$summary[$key],ENT_QUOTES,'UTF-8') ?></strong><small><a href="#evidence">View Evidence</a></small></div></article><?php endforeach; ?>
  </section>
  <section class="section-card" style="padding:16px"><h2>Order types</h2><p>Walk-in: <?= (int)$summary['order_types']['walk_in'] ?> · Collection: <?= (int)$summary['order_types']['collection'] ?> · Delivery: <?= (int)$summary['order_types']['delivery'] ?> · Courier: <?= (int)$summary['order_types']['courier'] ?></p></section>
  <section id="evidence" class="section-card" style="padding:16px;overflow:auto"><h2>Evidence used</h2><table class="ops-table"><thead><tr><th>Evidence ID</th><th>Reference</th><th>Employee</th><th>Action</th><th>Timestamp</th><th>Duration</th><th>Source</th></tr></thead><tbody><?php if(!$evidence): ?><tr><td colspan="7">No EPI Orders evidence exists for this filter.</td></tr><?php else: foreach($evidence as $row): ?><tr><td><?= htmlspecialchars((string)$row['evidence_uuid'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['reference_number'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['employee_name']?:'System'),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['action'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['occurred_at'],ENT_QUOTES,'UTF-8') ?></td><td><?= $row['working_minutes']!==null?htmlspecialchars((string)$row['working_minutes'],ENT_QUOTES,'UTF-8').' business min':'—' ?></td><td><?= htmlspecialchars((string)$row['activity_source'],ENT_QUOTES,'UTF-8') ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
