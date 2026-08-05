<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
$currentEmployeeId=ops_current_employee_id();$canReview=current_role_key()==='owner_admin';

$employeeId = max(0, (int) ($_GET['id'] ?? $currentEmployeeId));
if (!$canReview && $employeeId !== $currentEmployeeId) { http_response_code(403); exit('You may view only your own performance report.'); }
$employee = ops_rows('SELECT e.id,e.full_name,e.status,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1', [$employeeId])[0] ?? null;
if (!$employee || (string) $employee['role_key'] === 'owner_admin') { http_response_code(404); exit('Employee not found.'); }
$initialAnchor = (string) ($_GET['section'] ?? $_GET['tab'] ?? 'overview');
$anchors = ['overview','attendance','orders','packing','website','tasks','waybills','bookkeeping','quality','score-breakdown','activity-timeline'];
if (!in_array($initialAnchor, $anchors, true)) $initialAnchor = 'overview';
$pageTitle = $employee['full_name'] . ' KPI | ' . APP_NAME;
if (empty($_SESSION['kpi_presence_csrf_token'])) $_SESSION['kpi_presence_csrf_token']=bin2hex(random_bytes(32));
$activeApp = 'kpi';
$employeeOptions = $canReview ? ops_rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY e.full_name") : [];
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main id="kpi-employee-profile" class="workspace module kpi-health-page kpi-employee-page" data-employee-id="<?= (int) $employee['id'] ?>" data-initial-anchor="<?= htmlspecialchars($initialAnchor, ENT_QUOTES, 'UTF-8') ?>" data-can-review="<?= $canReview?'1':'0' ?>" data-presence-csrf="<?= htmlspecialchars((string)$_SESSION['kpi_presence_csrf_token'],ENT_QUOTES,'UTF-8') ?>">
  <section class="module-header kpi-employee-header">
    <div class="kpi-employee-identity"><span class="kpi-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string) $employee['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><div><p class="eyebrow">Employee performance</p><h1><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string) $employee['status']), ENT_QUOTES, 'UTF-8') ?> <span data-kpi-online-state>· Checking presence…</span></p></div></div>
    <div class="kpi-employee-header-actions"><button type="button" class="btn-secondary" data-kpi-presentation>Presentation Mode</button><button type="button" class="btn-secondary" data-kpi-print>Export Report</button><button type="button" class="btn-secondary" data-kpi-print>Print</button><button type="button" class="btn-secondary" data-kpi-refresh>Refresh</button><?php if($canReview): ?><a class="btn-secondary" href="reports.php?tab=employees">Back to employees</a><?php endif; ?></div>
  </section>
  <section class="kpi-period-panel" aria-label="Reporting period"><?php if($canReview): ?><label><span>Employee</span><select data-kpi-employee-select><?php foreach($employeeOptions as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)$option['id']===$employeeId?'selected':'' ?>><?= htmlspecialchars((string)$option['full_name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></label><?php endif; ?><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Previous month</option><option value="last_3_months">Last 3 months</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span><span class="kpi-period-caption" data-kpi-refreshed>Last refreshed: —</span></section>
  <nav class="employee-kpi-jump-nav" aria-label="Employee KPI sections"><?php foreach (['overview'=>'Overall','attendance'=>'Attendance / Portal Activity','orders'=>'Orders','packing'=>'Packing List','website'=>'Website Updates','tasks'=>'Tasks','waybills'=>'Courier','bookkeeping'=>'Bookkeeping','quality'=>'Error / Quality','score-breakdown'=>'Score Breakdown','activity-timeline'=>'Complete Activity Timeline'] as $key => $label): ?><a href="#<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></nav>
  <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
  <section class="employee-kpi-page" data-kpi-employee-content><div class="kpi-health-grid"><?php foreach (range(1, 8) as $unused): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
  <dialog class="kpi-timeline-dialog kpi-evidence-drawer" data-kpi-timeline><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close evidence">×</button><div data-kpi-timeline-content></div></dialog>
  <div class="kpi-presentation-controls" data-kpi-presentation-controls hidden><button type="button" data-kpi-slide="previous">Previous</button><span data-kpi-slide-status></span><button type="button" data-kpi-slide="next">Next</button><button type="button" data-kpi-presentation-exit>Exit presentation</button></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/kpi-employee.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/kpi-employee.js') ?>"></script>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
