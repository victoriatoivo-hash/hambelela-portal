<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
if (current_role_key() === 'guest') { header('Location: ' . BASE_URL . '/login.php'); exit; }
if (!user_has_role('owner_admin')) { http_response_code(403); exit('Owner access required.'); }
\Hambelela\EPI\Performance::configure(db());
$user = current_user();
$owner = user_has_role('owner_admin');
$now = new DateTimeImmutable('first day of this month');
$default = $owner ? $now->modify('-1 month') : $now;
$pageTitle = 'Employee Performance Intelligence | ' . APP_NAME;
$activeApp = 'kpi';
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/epi.css?v=10.4.0">
<main class="workspace module epi-dashboard" data-epi-dashboard data-owner="<?= $owner ? '1' : '0' ?>" data-viewer="<?= (int) ($user['id'] ?? 0) ?>" data-default-year="<?= $default->format('Y') ?>" data-default-month="<?= $default->format('n') ?>">
  <header class="epi-dashboard__header portal-header-with-status">
    <div><p class="eyebrow">Performance</p><h1>Employee Performance Intelligence</h1><p>Evidence-based employee performance, accountability and operational insight.</p></div>
    <?php if ($owner): ?><div class="module-header-actions"><a class="btn-secondary" href="reports.php">Legacy KPI fallback</a><a class="btn-secondary" href="epi-scoring-performance.php">Scoring verification</a></div><?php endif; ?>
  </header>
  <?php if (!\Hambelela\EPI\Performance::enabled()): ?><div class="ops-alert">Owner preview only. The Employee Performance feature flag is disabled, so the existing performance page remains primary.</div><?php endif; ?>
  <section class="epi-filter-bar" aria-label="Performance filters">
    <label><span>Employee</span><select data-epi-employee></select></label>
    <label><span>Month</span><select data-epi-month><?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>"><?= htmlspecialchars(date('F', mktime(0,0,0,$m,1)), ENT_QUOTES, 'UTF-8') ?></option><?php endfor; ?></select></label>
    <label><span>Year</span><input data-epi-year type="number" min="2020" max="2100"></label>
    <button type="button" class="btn-secondary" data-epi-refresh>Refresh</button>
    <?php if ($owner): ?><button type="button" class="btn-secondary" data-epi-export>Export CSV</button><button type="button" class="btn-secondary" data-epi-print>Print</button><button type="button" class="btn-primary" data-epi-present>Presentation</button><?php endif; ?>
  </section>
  <div class="ops-alert error" data-epi-error hidden role="alert"></div>
  <section data-epi-content aria-live="polite"><div class="epi-skeleton-grid" aria-label="Loading performance"><i></i><i></i><i></i><i></i></div></section>
  <dialog class="epi-evidence-dialog" data-epi-evidence><header><div><p class="eyebrow">Authoritative evidence</p><h2 data-epi-evidence-title>Evidence</h2></div><button type="button" data-epi-close aria-label="Close evidence">&times;</button></header><div data-epi-evidence-body></div><footer data-epi-evidence-pages></footer></dialog>
  <div class="epi-presentation-controls" data-epi-presentation-controls hidden><button type="button" data-epi-slide-nav="previous">Previous</button><span data-epi-slide-status></span><button type="button" data-epi-slide-nav="next">Next</button><button type="button" data-epi-presentation-exit>Exit</button></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/js/epi-dashboard.js?v=10.4.0" defer></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
