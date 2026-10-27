<?php
// Owner-only presentation, included by reports.php. Never calculates employee scores.
if (!defined('BASE_PATH') || !isset($tab) || $tab !== 'dashboard') { http_response_code(404); exit; }
$pwEscape = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$pwToday = new DateTimeImmutable('today', new DateTimeZone('Africa/Windhoek'));
$pwPeople = ops_rows("SELECT e.id,e.full_name,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE ".kpi_performance_employee_predicate('e','r')." ORDER BY CASE WHEN r.role_key LIKE '%packer%' THEN 0 WHEN r.role_key LIKE '%marketing%' THEN 1 ELSE 2 END,e.full_name");
$pwPeople = array_values(array_filter($pwPeople, static function (array $person): bool { return (bool)preg_match('/packer|marketing|front.?desk/i', $person['role_key'].' '.$person['role_name']); }));
$pwLeave = []; $pwLeaveAvailable = false;
try {
    $pwHr = ops_hr_db();
    if ($pwHr instanceof PDO) {
        $pwLinks = ops_rows('SELECT portal_user_id,hr_employee_id FROM employee_user_links WHERE active=1');
        $pwNames = []; $pwRoster = array_column($pwPeople, 'full_name', 'id');
        foreach ($pwLinks as $link) if (isset($pwRoster[(int)$link['portal_user_id']])) $pwNames[(int)$link['hr_employee_id']] = $pwRoster[(int)$link['portal_user_id']];
        if ($pwNames) {
            $pwStatement = $pwHr->prepare("SELECT employee_id,start_date,end_date FROM leave_requests WHERE status='approved' AND end_date>=? AND start_date<=? AND employee_id IN (".implode(',',array_fill(0,count($pwNames),'?')).") ORDER BY start_date,end_date LIMIT 8");
            $pwStatement->execute(array_merge([$pwToday->format('Y-m-d'),$pwToday->modify('+30 days')->format('Y-m-d')],array_keys($pwNames)));
            $pwLeave = $pwStatement->fetchAll(PDO::FETCH_ASSOC);
            $pwLeaveAvailable = true;
        }
    }
} catch (Throwable $error) { error_log('Performance workspace: upcoming leave unavailable'); }
?>
<section class="pw-hero" aria-label="Performance workspace">
    <div><p class="pw-eyebrow">People &amp; business health</p><h2>Support your team.<br><span>See the bigger picture.</span></h2><p>Employee workspaces, operational progress and the month’s business health in one place.</p></div>
    <div class="pw-hero-note"><i data-lucide="chart-no-axes-combined" aria-hidden="true"></i><strong>One team. Clear priorities.</strong><span>Real evidence. Better conversations.</span></div>
</section>
<div class="pw-toolbar"><label for="pw-month">Reporting month <input type="month" id="pw-month" value="<?= $pwToday->format('Y-m') ?>" max="<?= $pwToday->format('Y-m') ?>"></label><span data-pw-period>Monthly business overview · Namibia time</span><button class="pw-button" type="button" data-pw-refresh><i data-lucide="refresh-cw" aria-hidden="true"></i>Refresh overview</button></div>
<div data-pw-error class="pw-error" role="alert" hidden></div>
<div class="pw-layout">
    <div class="pw-main">
        <section class="pw-panel" aria-labelledby="pw-apps-title">
            <header class="pw-section-head"><div><h2 id="pw-apps-title"><i data-lucide="layout-grid" aria-hidden="true"></i>Performance Apps</h2><p>Open an employee’s role-specific performance and supporting evidence.</p></div><a href="reports.php?tab=employees" data-pw-employees>View all employees <span aria-hidden="true">→</span></a></header>
            <div class="pw-app-grid">
            <?php foreach ($pwPeople as $person): $pwIcon = stripos($person['role_key'],'packer')!==false ? 'package-check' : (stripos($person['role_key'],'marketing')!==false ? 'megaphone' : 'clipboard-check'); ?>
                <a class="pw-app" href="kpi-employee.php?id=<?= (int)$person['id'] ?>&amp;month=<?= $pwToday->format('Y-m') ?>" data-pw-employee="<?= (int)$person['id'] ?>"><span class="pw-app-icon"><i data-lucide="<?= $pwIcon ?>" aria-hidden="true"></i></span><span><strong><?= $pwEscape($person['full_name']) ?></strong><small><?= $pwEscape($person['role_name']) ?></small><em>Open performance <span aria-hidden="true">→</span></em></span></a>
            <?php endforeach; ?>
                <a class="pw-app" href="reports.php?tab=business-health" data-pw-health><span class="pw-app-icon"><i data-lucide="activity" aria-hidden="true"></i></span><span><strong>Business Health</strong><small>Operational results and underlying evidence</small><em>Explore the month <span aria-hidden="true">→</span></em></span></a>
                <a class="pw-app" href="reports.php?tab=settings"><span class="pw-app-icon"><i data-lucide="sliders-horizontal" aria-hidden="true"></i></span><span><strong>Performance Settings</strong><small>Schedules, measurement windows and existing controls</small><em>Manage settings <span aria-hidden="true">→</span></em></span></a>
            </div>
        </section>
        <section class="pw-panel" aria-labelledby="pw-health-title" data-pw-health-section aria-busy="true">
            <header class="pw-section-head"><div><h2 id="pw-health-title"><i data-lucide="chart-column" aria-hidden="true"></i>Monthly Business Health</h2><p data-pw-health-period>Loading this month’s operational evidence…</p></div><a href="reports.php?tab=business-health" data-pw-health>View full report →</a></header>
            <div class="pw-score-panel"><div class="pw-score" data-pw-score>—</div><div><h3>Overall operational score</h3><p data-pw-score-note>Loading measured business results…</p><small>Business indicator only. Not an average employee score, ranking or payroll deduction.</small></div></div>
            <div class="pw-metrics" data-pw-metrics></div>
            <details class="pw-evidence"><summary>How this score is supported</summary><p>The existing Business Health calculation is unchanged. Unmeasured sections are excluded, not counted as zero or perfect. Some indicators use the current operational snapshot; open the full report for definitions.</p><div data-pw-components></div></details>
        </section>
    </div>
    <aside class="pw-side" aria-label="Operational priorities">
        <section class="pw-panel"><header class="pw-section-head"><div><h2><i data-lucide="package" aria-hidden="true"></i>Packing progress</h2><p data-pw-packing-period>Orders in the selected month</p></div></header><div data-pw-packing>Loading packing progress…</div><a class="pw-text-link" href="orders-board.php">Open Orders List →</a></section>
        <section class="pw-panel"><header class="pw-section-head"><div><h2><i data-lucide="calendar-days" aria-hidden="true"></i>Upcoming leave</h2><p>Approved · today and the next 30 days</p></div></header>
            <?php if (!$pwLeaveAvailable): ?><p class="pw-empty">Leave information unavailable. <a href="../hr-portal/leave.php">Check HR leave</a>.</p>
            <?php elseif (!$pwLeave): ?><p class="pw-empty">No approved leave in the next 30 days for this team.</p>
            <?php else: ?><ul class="pw-leave"><?php foreach($pwLeave as $leave): ?><li><strong><?= $pwEscape($pwNames[(int)$leave['employee_id']]) ?></strong><span><?= $pwEscape((new DateTimeImmutable($leave['start_date']))->format('d M')) ?> – <?= $pwEscape((new DateTimeImmutable($leave['end_date']))->format('d M Y')) ?></span><small><?= $leave['start_date'] <= $pwToday->format('Y-m-d') ? 'On leave' : 'Upcoming' ?></small></li><?php endforeach; ?></ul><?php endif; ?>
            <a class="pw-text-link" href="../hr-portal/leave.php">View leave calendar →</a>
        </section>
        <section class="pw-panel"><header class="pw-section-head"><div><h2><i data-lucide="list-checks" aria-hidden="true"></i>Needs attention</h2><p>Current open work and operational exceptions</p></div></header><div data-pw-attention>Loading attention list…</div></section>
    </aside>
</div>
<footer class="pw-footnote"><i data-lucide="sprout" aria-hidden="true"></i>Support the people. Improve the process. Grow together.</footer>
<script src="<?= BASE_URL ?>/assets/js/performance-workspace.js?v=<?= (int)@filemtime(BASE_PATH.'/assets/js/performance-workspace.js') ?>"></script>
