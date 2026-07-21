<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin');

$phaseThreeTabs = ['attendance'=>'Attendance','orders'=>'Orders','packing-performance'=>'Packing Performance','bookkeeping'=>'Bookkeeping','waybills'=>'Waybills','task-management'=>'Task Management','hr-leave'=>'HR and Leave','website-updates'=>'Website Updates','errors-quality'=>'Errors and Quality','performance-reports'=>'Performance Reports','audit-log'=>'Audit Log'];
$tab = (string) ($_GET['tab'] ?? 'business-health');
if (!in_array($tab, array_merge(['business-health','employees','settings'],array_keys($phaseThreeTabs)), true)) $tab = 'business-health';
$currentKpiTitle = $tab === 'settings' ? 'KPI Settings' : ($tab === 'employees' ? 'Employees' : ($phaseThreeTabs[$tab] ?? 'Business Health'));
$pageTitle = $currentKpiTitle . ' | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();
$message = '';
$messageType = 'success';
$csrf = (string) ($_SESSION['kpi_settings_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['kpi_settings_csrf'] = $csrf;
}

$settingFields = [
    'data_start_date' => ['Data start date', 'date', '2026-07-01'],
    'adoption_date' => ['System adoption date', 'date', '2026-07-14'],
    'target_fulfilment_hours' => ['Fulfilment target (hours)', 'number', '6'],
    'on_time_dispatch_hours' => ['On-time dispatch (hours)', 'number', '6'],
    'waybill_overdue_hours' => ['Waybill overdue threshold (hours)', 'number', '24'],
    'website_update_lag_target_minutes' => ['Website update target (minutes)', 'number', '60'],
    'stale_work_days' => ['Stale work threshold (days)', 'number', '2'],
    'weight_points_s' => ['Small weight points', 'number', '1'],
    'weight_points_m' => ['Medium weight points', 'number', '3'],
    'weight_points_l' => ['Large weight points', 'number', '6'],
    'weight_points_xl' => ['Extra-large weight points', 'number', '10'],
    'working_days_per_week' => ['Default working days per week', 'number', '5'],
    'default_shift_start' => ['Default shift start', 'time', '08:00'],
    'default_shift_end' => ['Default shift end', 'time', '17:00'],
    'late_grace_minutes' => ['Late grace period (minutes)', 'number', '10'],
    'composite_score_enabled' => ['Composite score enabled (0 or 1)', 'number', '0'],
    'composite_weight_attendance' => ['Composite attendance weight', 'number', '25'],
    'composite_weight_output' => ['Composite output weight', 'number', '35'],
    'composite_weight_accuracy' => ['Composite accuracy weight', 'number', '25'],
    'composite_weight_tasks' => ['Composite tasks weight', 'number', '15'],
];

if ($ready && $tab === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Your session token is invalid.');
        $action = ops_post_string('kpi_action', 40);
        if ($action === 'save_settings') {
            $stmt = db()->prepare('INSERT INTO kpi_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settingFields as $key => $definition) {
                $value = substr(trim((string) ($_POST[$key] ?? $definition[2])), 0, 255);
                if ($definition[1] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new RuntimeException('Enter valid KPI dates.');
                if ($definition[1] === 'time' && !preg_match('/^\d{2}:\d{2}$/', $value)) throw new RuntimeException('Enter valid shift times.');
                if ($definition[1] === 'number' && (!is_numeric($value) || (float) $value < 0)) throw new RuntimeException($definition[0] . ' must be zero or more.');
                $stmt->execute([$key, $value]);
            }
            ops_activity_log('kpi_settings_updated', 'kpi_settings', 0, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            $message = 'KPI settings saved.';
        } elseif ($action === 'save_employee_schedule') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $hireDate = ops_post_string('hire_date', 10);
            $workingDays = ops_post_string('working_days', 30);
            $shiftStart = ops_post_string('shift_start', 5);
            $shiftEnd = ops_post_string('shift_end', 5);
            $grace = max(0, (int) ($_POST['late_grace_minutes'] ?? 10));
            if ($employeeId <= 0) throw new RuntimeException('Choose an employee.');
            if ($hireDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) throw new RuntimeException('Enter a valid hire date.');
            if ($workingDays !== '' && !preg_match('/^[1-7](,[1-7])*$/', $workingDays)) throw new RuntimeException('Working days must use weekday numbers 1 to 7, separated by commas.');
            if (($shiftStart !== '' && !preg_match('/^\d{2}:\d{2}$/', $shiftStart)) || ($shiftEnd !== '' && !preg_match('/^\d{2}:\d{2}$/', $shiftEnd))) throw new RuntimeException('Enter valid shift times.');
            db()->prepare('UPDATE ops_employees SET hire_date = ?, working_days = ?, shift_start = ?, shift_end = ?, late_grace_minutes = ? WHERE id = ?')->execute([$hireDate ?: null, $workingDays ?: null, $shiftStart ?: null, $shiftEnd ?: null, $grace, $employeeId]);
            ops_activity_log('kpi_employee_schedule_updated', 'employee', $employeeId, ['working_days' => $workingDays, 'shift_start' => $shiftStart, 'shift_end' => $shiftEnd, 'late_grace_minutes' => $grace, 'changed_by' => current_user()['name'] ?? 'Unknown']);
            $message = 'Employee schedule saved.';
        } elseif ($action === 'save_holiday') {
            $holidayDate = ops_post_string('holiday_date', 10);
            $holidayName = ops_post_string('holiday_name', 100);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate) || $holidayName === '') throw new RuntimeException('Enter a holiday date and name.');
            db()->prepare('INSERT INTO kpi_holidays (holiday_date, name, holiday_name, active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), holiday_name = VALUES(holiday_name), active = 1')->execute([$holidayDate, $holidayName, $holidayName]);
            $message = 'Holiday saved.';
        } elseif ($action === 'remove_holiday') {
            $holidayDate = ops_post_string('holiday_date', 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) throw new RuntimeException('Choose a valid holiday.');
            db()->prepare('UPDATE kpi_holidays SET active = 0 WHERE holiday_date = ?')->execute([$holidayDate]);
            $message = 'Holiday removed.';
        } else {
            throw new RuntimeException('Unknown KPI settings action.');
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $messageType = 'error';
    }
}

$settings = [];
$employees = [];
$holidays = [];
$recentEvents = [];
$recentSessions = [];
if ($ready && $tab === 'settings') {
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    $employees = ops_rows("SELECT e.id, e.full_name, e.hire_date, e.working_days, e.shift_start, e.shift_end, e.late_grace_minutes, r.name AS role_name FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE e.status = 'active' ORDER BY e.full_name");
    $holidays = ops_rows('SELECT holiday_date, COALESCE(NULLIF(name, \'\'), holiday_name) AS holiday_name FROM kpi_holidays WHERE active = 1 ORDER BY holiday_date');
    $recentEvents = ops_rows('SELECT se.module, se.record_id, se.old_status, se.new_status, se.changed_at, e.full_name FROM kpi_status_events se LEFT JOIN ops_employees e ON e.id = se.changed_by ORDER BY se.id DESC LIMIT 30');
    $recentSessions = ops_rows('SELECT s.login_at, s.last_seen_at, s.logout_at, e.full_name FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id = s.user_id ORDER BY s.id DESC LIMIT 20');
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-health-page" data-kpi-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
    <section class="module-header">
        <div>
            <p class="eyebrow">KPI &amp; Performance Management</p>
            <h1><?= htmlspecialchars($currentKpiTitle,ENT_QUOTES,'UTF-8') ?></h1>
            <p><?= $tab === 'settings' ? 'Control data windows, fairness thresholds, working calendars and employee schedules.' : ($tab === 'employees' ? 'Role-relative performance evidence, workload and attendance for each employee.' : 'A fair, evidence-led view of operational health and the work needing attention.') ?></p>
        </div>
        <?php if ($tab === 'business-health'): ?><button class="btn-secondary" type="button" data-kpi-refresh>Refresh</button><?php endif; ?>
    </section>

    <nav class="kpi-health-tabs" aria-label="KPI sections">
        <a href="reports.php?tab=business-health" class="<?= $tab === 'business-health' ? 'active' : '' ?>">Business Health</a>
        <a href="reports.php?tab=employees" class="<?= $tab === 'employees' ? 'active' : '' ?>">Employees</a>
        <?php foreach($phaseThreeTabs as $tabKey=>$tabLabel): ?><a href="reports.php?tab=<?= $tabKey ?>" class="<?= $tab===$tabKey?'active':'' ?>"><?= htmlspecialchars($tabLabel,ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?>
        <a href="reports.php?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">KPI Settings</a>
    </nav>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif ($tab === 'business-health'): ?>
        <section class="kpi-period-panel" aria-label="Reporting period">
            <label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label>
            <label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label>
            <label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label>
            <span class="kpi-period-caption" data-kpi-caption aria-live="polite">Loading reporting period…</span>
        </section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div>
        <div class="ops-alert error" data-kpi-error hidden role="alert"></div>
        <section class="kpi-health-grid" data-kpi-cards aria-label="Business health summary"><?php foreach (range(1, 6) as $placeholder): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></section>
        <section class="kpi-health-columns">
            <article class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Seven operating areas</p><h2>Operational scores</h2></div><small>Dash means unmeasured · fewer than 5 records is low data</small></div><div class="kpi-score-list" data-kpi-scores></div></article>
            <article class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Prioritised exceptions</p><h2>Needs attention</h2></div></div><div class="kpi-attention-list" data-kpi-attention></div></article>
        </section>
        <section class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">People and workload</p><h2>Team today</h2></div></div><div class="kpi-team-grid" data-kpi-team></div></section>
        <section class="kpi-chart-grid">
            <article class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Volume and value</p><h2>Orders and revenue</h2></div></div><div class="kpi-chart-frame"><canvas data-kpi-orders-chart></canvas></div></article>
            <article class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Fair workload view</p><h2>Packing output</h2></div><div class="kpi-chart-toggle"><button type="button" class="active" data-kpi-chart-mode="raw">Items</button><button type="button" data-kpi-chart-mode="weighted">Weighted</button></div></div><div class="kpi-chart-frame"><canvas data-kpi-packing-chart></canvas></div></article>
        </section>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
        <script src="<?= BASE_URL ?>/assets/js/reports-business-health.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/reports-business-health.js') ?>"></script>
    <?php elseif ($tab === 'employees'): ?>
        <section class="kpi-period-panel" aria-label="Reporting period">
            <label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label>
            <label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label>
            <span class="kpi-period-caption" data-kpi-caption aria-live="polite">Loading reporting period…</span>
        </section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
        <section class="kpi-employee-index" data-kpi-employees><?php foreach (range(1, 3) as $placeholder): ?><article class="kpi-team-card is-loading"><header><span></span><div><strong></strong><small></small></div></header></article><?php endforeach; ?></section>
        <script src="<?= BASE_URL ?>/assets/js/reports-employees.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/reports-employees.js') ?>"></script>
    <?php elseif (isset($phaseThreeTabs[$tab])): ?>
        <section class="kpi-period-panel" aria-label="Reporting period"><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span><button class="btn-secondary" type="button" data-kpi-refresh>Refresh</button><?php if($tab==='performance-reports'): ?><button class="btn-secondary" type="button" onclick="window.print()">Print report</button><?php endif; ?></section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div><section data-kpi-section-content><div class="kpi-health-grid"><?php foreach(range(1,6)as$i): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section><dialog class="kpi-timeline-dialog" data-kpi-timeline><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close timeline">×</button><div data-kpi-timeline-content></div></dialog>
        <script src="<?= BASE_URL ?>/assets/js/reports-section.js?v=<?= (int)@filemtime(BASE_PATH.'/assets/js/reports-section.js') ?>"></script>
    <?php else: ?>
        <?php if ($message !== ''): ?><div class="ops-alert <?= $messageType === 'error' ? 'error' : 'success' ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Calculation controls</p><h2>Global KPI settings</h2></div></div>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="kpi_action" value="save_settings">
                <?php foreach ($settingFields as $key => [$label, $type, $default]): ?>
                    <label class="field"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><input type="<?= $type ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, 'UTF-8') ?>" <?= $type === 'number' ? 'min="0" step="any"' : '' ?> required></label>
                <?php endforeach; ?>
                <div class="form-actions"><button class="btn-primary" type="submit">Save KPI settings</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Fair attendance</p><h2>Employee schedules</h2></div></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Employee</th><th>Role</th><th>Hire date</th><th>Working days</th><th>Shift</th><th>Grace</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($employees as $employee): $scheduleFormId = 'employee-schedule-' . (int) $employee['id']; ?><tr><td><strong><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></td><td><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?></td><td><input form="<?= $scheduleFormId ?>" type="date" name="hire_date" value="<?= htmlspecialchars((string) ($employee['hire_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td><td><input form="<?= $scheduleFormId ?>" name="working_days" value="<?= htmlspecialchars((string) ($employee['working_days'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="1,2,3,4,5"></td><td><input form="<?= $scheduleFormId ?>" type="time" name="shift_start" value="<?= htmlspecialchars(substr((string) ($employee['shift_start'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>"> <input form="<?= $scheduleFormId ?>" type="time" name="shift_end" value="<?= htmlspecialchars(substr((string) ($employee['shift_end'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>"></td><td><input form="<?= $scheduleFormId ?>" type="number" min="0" name="late_grace_minutes" value="<?= (int) ($employee['late_grace_minutes'] ?? 10) ?>"></td><td><form id="<?= $scheduleFormId ?>" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="save_employee_schedule"><input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>"><button class="btn-secondary" type="submit">Save</button></form></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Working calendar</p><h2>Public holidays</h2></div></div>
            <form method="post" class="inline-fields"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="save_holiday"><label class="field"><span>Date</span><input type="date" name="holiday_date" required></label><label class="field"><span>Name</span><input name="holiday_name" maxlength="100" required></label><button class="btn-primary" type="submit">Add holiday</button></form>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Date</th><th>Holiday</th><th>Action</th></tr></thead><tbody><?php foreach ($holidays as $holiday): ?><tr><td><?= htmlspecialchars((string) $holiday['holiday_date'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $holiday['holiday_name'], ENT_QUOTES, 'UTF-8') ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="remove_holiday"><input type="hidden" name="holiday_date" value="<?= htmlspecialchars((string) $holiday['holiday_date'], ENT_QUOTES, 'UTF-8') ?>"><button class="btn-secondary" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        </section>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Phase 1 verification</p><h2>Contained tracking evidence</h2></div></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>When</th><th>Module</th><th>Record</th><th>Status change</th><th>Changed by</th></tr></thead><tbody><?php foreach ($recentEvents as $event): ?><tr><td><?= htmlspecialchars((string) $event['changed_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $event['module'], ENT_QUOTES, 'UTF-8') ?></td><td>#<?= (int) $event['record_id'] ?></td><td><?= htmlspecialchars((string) ($event['old_status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars((string) $event['new_status'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($event['full_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?><?php if (!$recentEvents): ?><tr><td colspan="5">No status events recorded yet.</td></tr><?php endif; ?></tbody></table></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Employee</th><th>Login</th><th>Last activity</th><th>Logout</th></tr></thead><tbody><?php foreach ($recentSessions as $session): ?><tr><td><?= htmlspecialchars((string) ($session['full_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $session['login_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $session['last_seen_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($session['logout_at'] ?? 'Open'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?><?php if (!$recentSessions): ?><tr><td colspan="4">No tracked login sessions yet.</td></tr><?php endif; ?></tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
