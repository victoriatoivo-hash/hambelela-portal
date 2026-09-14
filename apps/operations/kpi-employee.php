<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
$currentEmployeeId=ops_current_employee_id();$canReview=current_role_key()==='owner_admin';

$employeeId = max(0, (int) ($_GET['id'] ?? $currentEmployeeId));
if (!$canReview && $employeeId !== $currentEmployeeId) { http_response_code(403); exit('You may view only your own performance report.'); }
$employee = ops_rows('SELECT e.id,e.full_name,e.status,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1', [$employeeId])[0] ?? null;
if (!$employee || (string) $employee['role_key'] === 'owner_admin') { http_response_code(404); exit('Employee not found.'); }
$initialAnchor = (string) ($_GET['section'] ?? $_GET['tab'] ?? 'orders');
if ($initialAnchor === 'order-packing') $initialAnchor = 'orders';
$isPacker = strpos((string)$employee['role_key'], 'packer') !== false;
$showWebsiteUpdates = !$isPacker && $employeeId !== 2;
$anchors = $isPacker ? ['orders','packing','tasks','waybills','hr-attendance','quality','activity-log'] : ['orders','packing','tasks','bookkeeping','waybills','hr-attendance','quality','activity-log'];
if ($showWebsiteUpdates) array_splice($anchors, 6, 0, ['website']);
if (!in_array($initialAnchor, $anchors, true)) $initialAnchor = 'orders';
$pageTitle = $employee['full_name'] . ' Performance Profile | ' . APP_NAME;
if (empty($_SESSION['kpi_presence_csrf_token'])) $_SESSION['kpi_presence_csrf_token']=bin2hex(random_bytes(32));
$activeApp = 'kpi';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main id="kpi-employee-profile" class="workspace module kpi-health-page kpi-employee-page" data-employee-id="<?= (int) $employee['id'] ?>" data-role-key="<?= htmlspecialchars((string)$employee['role_key'], ENT_QUOTES, 'UTF-8') ?>" data-initial-anchor="<?= htmlspecialchars($initialAnchor, ENT_QUOTES, 'UTF-8') ?>" data-show-website-updates="<?= $showWebsiteUpdates?'1':'0' ?>" data-can-review="<?= $canReview?'1':'0' ?>" data-presence-csrf="<?= htmlspecialchars((string)$_SESSION['kpi_presence_csrf_token'],ENT_QUOTES,'UTF-8') ?>">
  <nav class="kpi-employee-breadcrumb" aria-label="Breadcrumb"><a href="reports.php?tab=employees">Employees</a><span aria-hidden="true">›</span><strong><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></nav>
  <section class="module-header kpi-employee-header">
    <div class="kpi-employee-identity"><span class="kpi-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string) $employee['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><div><p class="eyebrow">Employee Performance</p><h1><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?>’s Performance Profile</h1><p><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string) $employee['status']), ENT_QUOTES, 'UTF-8') ?> <span data-kpi-online-state>· Checking presence…</span></p></div></div>
  </section>
  <section class="kpi-period-panel" aria-label="Reporting period"><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Previous month</option><option value="last_3_months">Last 3 months</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span><span class="kpi-period-caption" data-kpi-refreshed>Last refreshed: —</span></section>
  <section class="kpi-score-placeholder" aria-labelledby="employee-score-heading">
    <div class="kpi-score-placeholder__mark" aria-hidden="true">—</div>
    <div class="kpi-score-placeholder__copy">
      <p class="eyebrow">Overall performance</p>
      <h2 id="employee-score-heading">Calculating verified score</h2>
      <p>The portal is checking every role-specific component and its source evidence.</p>
    </div>
    <div class="kpi-score-placeholder__status">
      <span>Current status</span>
      <strong>Loading evidence</strong>
      <small>Missing evidence is not counted as zero.</small>
    </div>
  </section>
  <?php $profileSections=['orders'=>'Orders Performance','packing'=>'Packing','tasks'=>'Task Management','waybills'=>$isPacker?'Waybill Uploads':'Waybill Status Management','hr-attendance'=>'HR, Leave & Attendance','quality'=>'Errors and Quality','activity-log'=>'Activity Log'];if(!$isPacker)$profileSections=array_slice($profileSections,0,3,true)+['bookkeeping'=>'Bookkeeping']+array_slice($profileSections,3,null,true);if($showWebsiteUpdates)$profileSections=array_slice($profileSections,0,6,true)+['website'=>'Website Updates']+array_slice($profileSections,6,null,true); ?>
  <nav class="employee-kpi-jump-nav" aria-label="Employee Performance sections"><?php foreach ($profileSections as $key => $label): ?><a href="?id=<?= $employeeId ?>&amp;section=<?= $key ?>" data-employee-section="<?= $key ?>" class="<?= $initialAnchor===$key?'active':'' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></nav>
  <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
  <section class="employee-kpi-page" data-kpi-employee-content><div class="kpi-health-grid"><?php foreach (range(1, 8) as $unused): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
  <dialog class="kpi-timeline-dialog kpi-evidence-drawer" data-kpi-timeline><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close evidence">×</button><div data-kpi-timeline-content></div></dialog>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/kpi-employee.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/kpi-employee.js') ?>"></script>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
