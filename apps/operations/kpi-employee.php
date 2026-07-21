<?php

declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_role('owner_admin');

$employeeId = max(0, (int) ($_GET['id'] ?? 0));
$employee = ops_rows('SELECT e.id,e.full_name,e.status,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1', [$employeeId])[0] ?? null;
if (!$employee || (string) $employee['role_key'] === 'owner_admin') { http_response_code(404); exit('Employee not found.'); }
$section = (string) ($_GET['section'] ?? 'overview');
$sections = ['overview'=>'Overview','attendance'=>'Attendance','orders'=>'Orders','packing'=>'Packing','tasks'=>'Tasks','waybills'=>'Waybills','quality'=>'Quality & Errors','trends'=>'Trends'];
if ((string) $employee['role_key'] === 'front_desk_admin') $sections = array_slice($sections,0,6,true) + ['bookkeeping'=>'Bookkeeping'] + array_slice($sections,6,null,true);
if (!isset($sections[$section])) $section = 'overview';
$pageTitle = $employee['full_name'] . ' KPI | ' . APP_NAME; $activeApp='kpi';
include BASE_PATH . '/shared/header.php'; include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-health-page kpi-employee-page" data-employee-id="<?= (int) $employee['id'] ?>" data-kpi-section="<?= htmlspecialchars($section,ENT_QUOTES,'UTF-8') ?>">
  <section class="module-header"><div><p class="eyebrow">Employee performance</p><h1><?= htmlspecialchars((string)$employee['full_name'],ENT_QUOTES,'UTF-8') ?></h1><p><?= htmlspecialchars((string)$employee['role_name'],ENT_QUOTES,'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string)$employee['status']),ENT_QUOTES,'UTF-8') ?></p></div><a class="btn-secondary" href="reports.php?tab=employees">Back to employees</a></section>
  <section class="kpi-period-panel" aria-label="Reporting period"><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span></section>
  <nav class="kpi-employee-tabs" aria-label="Employee KPI sections"><?php foreach($sections as $key=>$label): ?><a class="<?= $section===$key?'active':'' ?>" href="?id=<?= $employeeId ?>&amp;section=<?= $key ?>"><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?></nav>
  <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
  <section data-kpi-employee-content><div class="kpi-health-grid"><?php foreach(range(1,6) as $i): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script><script src="<?= BASE_URL ?>/assets/js/kpi-employee.js?v=<?= (int)@filemtime(BASE_PATH.'/assets/js/kpi-employee.js') ?>"></script>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
