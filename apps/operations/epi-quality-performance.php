<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_role('owner_admin');
if (empty($_SESSION['epi_quality_csrf_token'])) $_SESSION['epi_quality_csrf_token'] = bin2hex(random_bytes(32));
$filters = [];
foreach (['period','date_from','date_to','responsible_employee_id','category','severity','status','responsibility_type'] as $key) $filters[$key] = trim((string) ($_GET[$key] ?? ''));
if ($filters['period'] === '') $filters['period'] = 'previous_month';
$service = new \Hambelela\EPI\QualityPerformance(db());
$summary = $service->getSummary($filters); $evidence = $service->getEvidence($filters, 500); $timeline = $service->getTimeline($filters, 300); $employees = $service->employeeOptions();
$pageTitle = 'EPI Quality Verification | ' . APP_NAME; $activeApp = 'kpi';
include BASE_PATH . '/shared/header.php'; include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module epi-page epi-quality-page">
 <section class="module-header"><div><p class="eyebrow">Employee Performance Intelligence · Recovery Step 2</p><h1>Quality verification</h1><p>Owner-only evidence review. No score or deduction is calculated here.</p></div></section>
 <?php if (\Hambelela\EPI\Performance::mode() === 'disabled'): ?><section class="ops-alert">EPI is Disabled. Historical evidence remains readable; new recording is off.</section><?php endif; ?>
 <form class="section-card epi-quality-filters" method="get">
  <label>Period<select name="period"><?php foreach (['today'=>'Today','this_month'=>'This Month','previous_month'=>'Previous Month','custom'=>'Custom'] as $v=>$l): ?><option value="<?= $v ?>" <?= $filters['period']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label>Employee<select name="responsible_employee_id"><option value="">All</option><?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $filters['responsible_employee_id']===(string)$e['id']?'selected':'' ?>><?= htmlspecialchars((string)$e['full_name']) ?></option><?php endforeach; ?></select></label>
  <label>Severity<select name="severity"><option value="">All</option><?php foreach (['low','medium','high','critical'] as $v): ?><option value="<?= $v ?>" <?= $filters['severity']===$v?'selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></label>
  <label>Status<input name="status" value="<?= htmlspecialchars($filters['status']) ?>" placeholder="All statuses"></label>
  <label>From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>"></label><label>To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>"></label><button class="btn-primary">Apply</button>
 </form>
 <section class="epi-quality-metrics"><?php foreach (['errors_reported'=>'Errors reported','confirmed_employee_errors'=>'Confirmed employee errors','pending_review'=>'Pending review','open_errors'=>'Open errors','resolved_errors'=>'Resolved errors','customer_complaints'=>'Customer complaints','net_financial_impact'=>'Net financial impact','unresolved_financial_impact'=>'Unresolved financial impact'] as $k=>$l): ?><article class="section-card"><span><?= $l ?></span><strong><?= htmlspecialchars((string)$summary[$k]) ?></strong><a href="#quality-evidence">View Evidence</a></article><?php endforeach; ?></section>
 <section class="section-card epi-quality-section"><h2>Current unresolved quality risks</h2><div class="epi-quality-risk"><?php foreach ($summary['current_risk'] as $k=>$v): ?><p><span><?= htmlspecialchars(ucwords(str_replace('_',' ',$k))) ?></span><strong><?= htmlspecialchars((string)($v ?? 'Insufficient Historical Data')) ?></strong></p><?php endforeach; ?></div></section>
 <section id="quality-evidence" class="section-card epi-quality-section"><h2>Quality evidence</h2><div class="epi-quality-table-wrap"><table class="ops-table"><thead><tr><th>Error</th><th>Date</th><th>Title</th><th>Category</th><th>Severity</th><th>Logged by</th><th>Responsible</th><th>Responsibility</th><th>Status</th><th>Financial impact</th><th>Review</th></tr></thead><tbody><?php if (!$evidence): ?><tr><td colspan="11">No quality evidence exists for this filter.</td></tr><?php else: foreach ($evidence as $r): ?><tr><td><a href="errors.php?error_id=<?= (int)$r['id'] ?>">ERROR-<?= (int)$r['id'] ?></a></td><td><?= htmlspecialchars((string)$r['logged_at']) ?></td><td><?= htmlspecialchars((string)$r['error_title']) ?></td><td><?= htmlspecialchars((string)$r['category']) ?></td><td><?= htmlspecialchars((string)$r['severity']) ?></td><td><?= htmlspecialchars((string)($r['logged_by_name'] ?? '—')) ?></td><td><?= htmlspecialchars((string)($r['responsible_name'] ?? 'Unconfirmed')) ?></td><td><?= htmlspecialchars((string)($r['responsibility_type'] ?? 'Unconfirmed')) ?></td><td><?= htmlspecialchars((string)$r['status']) ?></td><td>N$<?= number_format((float)($r['net_financial_impact'] ?? 0),2) ?></td><td><?= htmlspecialchars((string)($r['review_decision'] ?? 'Pending Review')) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
 <section class="section-card epi-quality-section"><h2>Activity timeline</h2><?php if (!$timeline): ?><p>No quality activity for this period.</p><?php else: foreach ($timeline as $r): ?><article class="epi-quality-timeline"><strong><?= htmlspecialchars((string)$r['activity_type']) ?></strong><span><?= htmlspecialchars((string)$r['reference_number']) ?> · <?= htmlspecialchars((string)$r['occurred_at']) ?></span></article><?php endforeach; endif; ?></section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
