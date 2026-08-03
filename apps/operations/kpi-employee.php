<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_role('owner_admin');

$employeeId = max(0, (int) ($_GET['id'] ?? 0));
$employee = ops_rows('SELECT e.id,e.full_name,e.status,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1', [$employeeId])[0] ?? null;
if (!$employee || (string) $employee['role_key'] === 'owner_admin') { http_response_code(404); exit('Employee not found.'); }
$initialAnchor = (string) ($_GET['section'] ?? $_GET['tab'] ?? 'overview');
$anchors = ['overview','attendance','orders','packing','website','tasks','waybills','bookkeeping','quality','score-breakdown'];
if (!in_array($initialAnchor, $anchors, true)) $initialAnchor = 'overview';
$pageTitle = $employee['full_name'] . ' KPI | ' . APP_NAME;
$activeApp = 'kpi';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-health-page kpi-employee-page" data-employee-id="<?= (int) $employee['id'] ?>" data-initial-anchor="<?= htmlspecialchars($initialAnchor, ENT_QUOTES, 'UTF-8') ?>">
  <section class="module-header kpi-employee-header">
    <div class="kpi-employee-identity"><span class="kpi-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string) $employee['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><div><p class="eyebrow">Employee performance</p><h1><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string) $employee['status']), ENT_QUOTES, 'UTF-8') ?> <span data-kpi-online-state>· Checking presence…</span></p></div></div>
    <div class="kpi-employee-header-actions"><button type="button" class="btn-secondary" data-kpi-refresh>Refresh</button><button type="button" class="btn-secondary" data-kpi-print>Print / Export</button><a class="btn-secondary" href="reports.php?tab=employees">Back to employees</a></div>
  </section>
  <section class="kpi-period-panel" aria-label="Reporting period"><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span><span class="kpi-period-caption" data-kpi-refreshed>Last refreshed: —</span></section>
  <nav class="employee-kpi-jump-nav" aria-label="Employee KPI sections"><?php foreach (['overview'=>'Overview','attendance'=>'Attendance','orders'=>'Orders','packing'=>'Packing','website'=>'Website','tasks'=>'Tasks','waybills'=>'Waybills','bookkeeping'=>'Bookkeeping','quality'=>'Quality','score-breakdown'=>'Score breakdown'] as $key => $label): ?><a href="#<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></nav>
  <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
  <section class="employee-kpi-page" data-kpi-employee-content><div class="kpi-health-grid"><?php foreach (range(1, 8) as $unused): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
  <dialog class="kpi-timeline-dialog" data-kpi-timeline><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close timeline">×</button><div data-kpi-timeline-content></div></dialog>
  <script src="<?= BASE_URL ?>/assets/js/kpi-employee.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/kpi-employee.js') ?>"></script>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
