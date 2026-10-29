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
$extraStylesheets[] = ['path'=>'assets/css/employee-performance.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/employee-performance.css')];
$profileMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)($_GET['month']??'')) ? (string)$_GET['month'] : (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m');
$profileInitials = implode('', array_map(static function($part){return strtoupper(substr($part,0,1));}, array_slice(preg_split('/\s+/',trim((string)$employee['full_name'])),0,2)));
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main id="kpi-employee-profile" class="workspace module kpi-employee-page epi-profile-v5" data-employee-id="<?= (int) $employee['id'] ?>" data-role-key="<?= htmlspecialchars((string)$employee['role_key'], ENT_QUOTES, 'UTF-8') ?>" data-initial-anchor="<?= htmlspecialchars($initialAnchor, ENT_QUOTES, 'UTF-8') ?>" data-show-website-updates="<?= $showWebsiteUpdates?'1':'0' ?>" data-can-review="<?= $canReview?'1':'0' ?>" data-presence-csrf="<?= htmlspecialchars((string)$_SESSION['kpi_presence_csrf_token'],ENT_QUOTES,'UTF-8') ?>">
  <nav class="kpi-employee-breadcrumb" aria-label="Breadcrumb"><a href="reports.php?tab=employees">Employees</a><span aria-hidden="true">›</span><strong><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></nav>
  <section class="module-header kpi-employee-header">
    <div class="kpi-employee-identity"><span class="kpi-avatar" aria-hidden="true"><?= htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8') ?></span><div><p class="eyebrow">Employee Performance</p><h1><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?>’s Performance Profile</h1><p class="ep-identity-meta"><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?> <span class="ep-status"><?= htmlspecialchars(ucfirst((string) $employee['status']), ENT_QUOTES, 'UTF-8') ?></span></p></div></div>
  </section>
  <section class="kpi-period-panel ep-toolbar" aria-label="Reporting period">
    <label>Reporting month<input type="month" data-ep-month value="<?= htmlspecialchars($profileMonth,ENT_QUOTES,'UTF-8') ?>"></label>
    <label data-ep-employee-label <?= $canReview?'':'hidden' ?>>Employee<select data-ep-employee aria-label="Employee" disabled><option value="<?= $employeeId ?>"><?= htmlspecialchars($employee['full_name'].' · '.$employee['role_name'],ENT_QUOTES,'UTF-8') ?></option></select></label>
    <button type="button" class="btn-secondary" data-ep-refresh><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M20 7a9 9 0 1 0 1 9M21 2v6h-6"/></svg>Refresh view</button>
    <a class="btn-secondary" data-ep-export href="kpi-employee-data.php?action=performance_export&amp;id=<?= $employeeId ?>&amp;month=<?= htmlspecialchars($profileMonth,ENT_QUOTES,'UTF-8') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg>Export CSV</a><span class="ep-muted">Monthly results · Africa/Windhoek</span>
  </section>
  <nav class="employee-kpi-jump-nav" aria-label="Employee Performance sections" aria-busy="true"><button type="button" aria-current="page" disabled>Overview</button><span class="ep-muted">Loading role-specific sections…</span></nav>
  <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
  <section class="ep-content" data-kpi-employee-content aria-busy="true"><div class="ep-loading" role="status">Loading verified performance…</div><div class="ep-summary" aria-hidden="true"><?php foreach (range(1, 5) as $unused): ?><article class="ep-card ep-skeleton"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
  <noscript><p class="ep-loading">Enable JavaScript to view performance and evidence. No employee data has been changed.</p></noscript>
  <dialog class="kpi-timeline-dialog kpi-evidence-drawer" data-kpi-timeline aria-label="Evidence details"><header class="ep-drawer-header"><span>Evidence details</span><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close evidence">×</button></header><div data-kpi-timeline-content></div><footer class="ep-drawer-footer"><button class="btn-primary" type="button" data-kpi-timeline-close>Close</button></footer></dialog>
  <script src="<?= BASE_URL ?>/assets/js/employee-performance.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/employee-performance.js') ?>"></script>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
