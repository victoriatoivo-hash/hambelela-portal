<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_role('owner_admin');

$phaseThreeTabs = ['attendance'=>'Attendance','orders'=>'Orders','packing-performance'=>'Packing Performance','bookkeeping'=>'Bookkeeping','waybills'=>'Waybills','task-management'=>'Task Management','hr-leave'=>'HR and Leave','website-updates'=>'Website Updates','errors-quality'=>'Errors and Quality','performance-reports'=>'Performance Reports','business-activity'=>'Business Activity Timeline','audit-log'=>'Audit Log'];
$visiblePerformanceTabs = ['performance-reports'=>'Performance Reports','business-activity'=>'Business Activity Timeline','audit-log'=>'Audit Log'];
$tab = (string) ($_GET['tab'] ?? 'business-health');
if (!in_array($tab, array_merge(['business-health','employees','settings'],array_keys($phaseThreeTabs)), true)) $tab = 'business-health';
$currentKpiTitle = $tab === 'settings' ? 'Performance Settings' : ($tab === 'employees' ? 'Employees' : ($phaseThreeTabs[$tab] ?? 'Business Health'));
$pageTitle = $currentKpiTitle . ' | ' . APP_NAME;
$activeApp = 'kpi';
$extraStylesheets = $tab === 'business-health' ? [[
    'path' => 'assets/css/performance-dashboard.css',
    'version' => is_file(BASE_PATH . '/assets/css/performance-dashboard.css') ? (string) filemtime(BASE_PATH . '/assets/css/performance-dashboard.css') : '1',
]] : [];
$ready = ops_database_ready();
$message = '';
$messageType = 'success';
$csrf = (string) ($_SESSION['kpi_settings_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['kpi_settings_csrf'] = $csrf;
}

$settingFields = [
    'trusted_performance_start_date' => ['Trusted performance data start date', 'date', '2026-07-10'],
    'data_start_date' => ['Imported data floor', 'date', '2026-07-01'],
    'adoption_date' => ['System adoption date', 'date', '2026-07-14'],
    'packing_list_adoption_date' => ['Packing List records valid from', 'date', '2026-07-01'],
    'orders_attribution_adoption_date' => ['Orders attribution valid from', 'date', '2026-07-10'],
    'packing_timing_adoption_date' => ['Packing timing valid from', 'date', '2026-07-14'],
    'website_timing_adoption_date' => ['Website timing valid from', 'date', '2026-07-15'],
    'tasks_adoption_date' => ['Task history valid from', 'date', '2026-07-14'],
    'waybills_adoption_date' => ['Waybill timing valid from', 'date', '2026-07-14'],
    'bookkeeping_adoption_date' => ['Bookkeeping module adoption date', 'date', '2026-07-20'],
    'bookkeeping_cash_deadline' => ['Bookkeeping: cash entry deadline', 'time', '17:00'],
    'bookkeeping_deposit_schedule' => ['Bookkeeping: deposit schedule', 'text', 'not_configured'],
    'error_log_adoption_date' => ['Error responsibility valid from', 'date', '2026-07-14'],
    'portal_activity_adoption_date' => ['Portal activity valid from', 'date', '2026-07-14'],
    'attendance_adoption_date' => ['Attendance sessions valid from', 'date', '2026-07-14'],
    'activity_event_adoption_date' => ['Append-only activity events valid from', 'date', '2026-08-03'],
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
    'packer_weight_productivity' => ['Packer: productivity weight', 'number', '30'],
    'packer_weight_accuracy' => ['Packer: accuracy weight', 'number', '25'],
    'packer_weight_speed' => ['Packer: speed weight', 'number', '15'],
    'packer_weight_attendance' => ['Packer: attendance and reliability weight', 'number', '10'],
    'packer_weight_compliance' => ['Packer: process compliance weight', 'number', '10'],
    'packer_weight_team' => ['Packer: team contribution weight', 'number', '10'],
    'frontdesk_weight_orders' => ['Front desk: orders finalised weight', 'number', '20'],
    'frontdesk_weight_payments' => ['Front desk: payment updates weight', 'number', '10'],
    'frontdesk_weight_website' => ['Front desk: website updates and speed weight', 'number', '15'],
    'frontdesk_website_weekday_cutoff' => ['Front desk website update weekday cutoff', 'time', '15:00'],
    'frontdesk_website_weekday_close' => ['Front desk website update weekday deadline', 'time', '17:00'],
    'frontdesk_website_saturday_cutoff' => ['Front desk website update Saturday cutoff', 'time', '11:00'],
    'frontdesk_website_saturday_close' => ['Front desk website update Saturday deadline', 'time', '13:00'],
    'frontdesk_weight_waybills' => ['Front desk: waybill output and timeliness weight', 'number', '15'],
    'frontdesk_weight_bookkeeping' => ['Front desk: bookkeeping compliance weight', 'number', '15'],
    'frontdesk_weight_tasks' => ['Front desk: task compliance weight', 'number', '10'],
    'frontdesk_weight_quality' => ['Front desk: errors and corrections weight', 'number', '5'],
    'frontdesk_weight_attendance' => ['Front desk: attendance and reliability weight', 'number', '10'],
    'front_orders_walkin_weight' => ['Front orders: walk-in compliance share', 'number', '50'],
    'front_orders_nonwalk_weight' => ['Front orders: non-walk-in finalisation share', 'number', '50'],
    'orders_walk_in_identifiers' => ['Walk-in order aliases (comma separated)', 'text', 'walk-in, walk in, walk_in, walk-in customer, walk in customer, walkin customer'],
    'front_orders_walkin_grace_minutes' => ['Walk-in completion grace period (minutes)', 'number', '0'],
    'front_orders_saturday_open' => ['Walk-in Saturday opening time', 'time', '09:00'],
    'front_orders_saturday_close' => ['Walk-in Saturday closing time', 'time', '13:00'],
    'minimum_courier_packing_lead_minutes' => ['Minimum courier packing lead time (minutes)', 'number', '30'],
    'courier_following_applicable_day_rule' => ['Courier performance following day rule (calendar_day, business_day, courier_service_day or not_configured)', 'text', 'not_configured'],
    'courier_morning_inference_enabled' => ['Courier performance morning inference (0 disabled, 1 enabled)', 'number', '0'],
    'courier_late_response_target_minutes' => ['Courier performance response target after late upload (0 means no automatic target)', 'number', '0'],
    'courier_sameday_cutoff' => ['Performance reports: same-day courier cutoff', 'time', '17:00'],
    'courier_nextday_cutoff' => ['Performance reports: next-working-day courier cutoff', 'time', '09:00'],
    'walkin_mode_value' => ['Performance reports: stored walk-in mode value', 'text', 'walk_in'],
    'cash_payment_values' => ['Performance reports: cash payment values', 'text', 'cash'],
    'walkin_completion_target_minutes' => ['Performance reports: walk-in completion target (minutes)', 'number', '60'],
    'task_note_min_chars' => ['Performance reports: substantive task note minimum characters', 'number', '25'],
    'bonus_threshold' => ['Rewards: default qualification threshold (%)', 'number', '75'],
    'bonus_threshold_packer' => ['Rewards: packer threshold override (%) (0 uses default)', 'number', '0'],
    'bonus_threshold_front_desk' => ['Rewards: front-desk threshold override (%) (0 uses default)', 'number', '0'],
    'reward_bronze_min' => ['Rewards: Bronze minimum score (%)', 'number', '75'],
    'reward_silver_min' => ['Rewards: Silver minimum score (%)', 'number', '85'],
    'reward_gold_min' => ['Rewards: Gold minimum score (%)', 'number', '90'],
    'reward_bronze_value' => ['Rewards: Bronze value (blank until confirmed)', 'text', ''],
    'reward_silver_value' => ['Rewards: Silver value (blank until confirmed)', 'text', ''],
    'reward_gold_value' => ['Rewards: Gold value (blank until confirmed)', 'text', ''],
    'reward_bronze_description' => ['Rewards: Bronze description', 'text', 'Recognition + small benefit'],
    'reward_silver_description' => ['Rewards: Silver description', 'text', 'Recognition + moderate voucher or cash reward'],
    'reward_gold_description' => ['Rewards: Gold description', 'text', 'Employee of the Month, voucher, cash reward, or driving-lesson sponsorship up to N$800'],
    'accuracy_scored' => ['Rewards: verified packing variance attribution enabled (0/1)', 'number', '0'],
    'frontdesk_reward_weights_approved' => ['Rewards: front-desk weights approved (0 pending, 1 approved)', 'number', '0'],
    'packing_speed_target_minutes_per_point' => ['Packer: packing-list speed target (minutes per weighted point; 0 = current evidence median)', 'number', '0'],
    'order_speed_target_minutes_per_point' => ['Packer: order-picking speed target (minutes per weighted point; 0 = current evidence median)', 'number', '0'],
    'speed_targets_confirmed' => ['Packer: speed targets owner-confirmed (0 auto-derived/pending, 1 confirmed)', 'number', '0'],
    'provisional_headline_mode' => ['Performance reports: provisional headline mode (1 component bars first, 0 score first)', 'number', '1'],
    'report_weights' => ['Performance reports: role section weights (JSON)', 'text', '{"packer":{"packing":30,"quality":25,"orders":15,"attendance":10,"tasks":10,"waybills":10},"front_desk":{"bookkeeping":30,"orders":25,"tasks":15,"waybills":10,"quality":10,"attendance":10}}'],
];

if ($ready && $tab === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Your session token is invalid.');
        $action = ops_post_string('kpi_action', 40);
        if ($action === 'save_settings') {
            $validatedSettings = [];
            foreach ($settingFields as $key => $definition) {
                $value = substr(trim((string) ($_POST[$key] ?? $definition[2])), 0, 255);
                if ($definition[1] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new RuntimeException('Enter valid reporting dates.');
                if ($definition[1] === 'time' && !preg_match('/^\d{2}:\d{2}$/', $value)) throw new RuntimeException('Enter valid shift times.');
                if ($definition[1] === 'number' && (!is_numeric($value) || (float) $value < 0)) throw new RuntimeException($definition[0] . ' must be zero or more.');
                $validatedSettings[$key] = $value;
            }
            $packerWeight = array_sum(array_map(static fn(string $key): float => (float) $validatedSettings[$key], ['packer_weight_productivity','packer_weight_accuracy','packer_weight_speed','packer_weight_attendance','packer_weight_compliance','packer_weight_team']));
            $frontdeskWeight = array_sum(array_map(static fn(string $key): float => (float) $validatedSettings[$key], ['frontdesk_weight_orders','frontdesk_weight_payments','frontdesk_weight_website','frontdesk_weight_waybills','frontdesk_weight_bookkeeping','frontdesk_weight_tasks','frontdesk_weight_quality','frontdesk_weight_attendance']));
            if (abs($packerWeight - 100) > 0.001 || abs($frontdeskWeight - 100) > 0.001) throw new RuntimeException('Packer and front-desk weights must each total 100. Scores remain disabled until the integrity review is complete.');
            $existingReportWeights = json_decode((string) ($validatedSettings['report_weights'] ?? ''), true);
            if (!is_array($existingReportWeights)) $existingReportWeights = [];
            $existingReportWeights['packer'] = [
                'packing' => (float) $validatedSettings['packer_weight_productivity'],
                'quality' => (float) $validatedSettings['packer_weight_accuracy'],
                'orders' => (float) $validatedSettings['packer_weight_speed'],
                'attendance' => (float) $validatedSettings['packer_weight_attendance'],
                'tasks' => (float) $validatedSettings['packer_weight_compliance'],
                'waybills' => (float) $validatedSettings['packer_weight_team'],
            ];
            $validatedSettings['report_weights'] = json_encode($existingReportWeights, JSON_UNESCAPED_SLASHES);
            if (abs((float) $validatedSettings['front_orders_walkin_weight'] + (float) $validatedSettings['front_orders_nonwalk_weight'] - 100) > 0.001) throw new RuntimeException('The two front-orders component shares must total 100.');
            $database = db();
            $database->beginTransaction();
            try {
                $stmt = $database->prepare('INSERT INTO kpi_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach ($validatedSettings as $key => $value) $stmt->execute([$key, $value]);
                $stmt->execute(['composite_score_enabled', '0']);
                $database->commit();
            } catch (Throwable $settingsError) {
                if ($database->inTransaction()) $database->rollBack();
                throw $settingsError;
            }
            ops_activity_log('kpi_settings_updated', 'kpi_settings', 0, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            $message = 'Performance settings saved.';
        } elseif ($action === 'save_employee_schedule') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $hireDate = ops_post_string('hire_date', 10);
            $workingDays = ops_post_string('working_days', 30);
            $shiftStart = ops_post_string('shift_start', 5);
            $shiftEnd = ops_post_string('shift_end', 5);
            $saturdayStart = ops_post_string('saturday_shift_start', 5);
            $saturdayEnd = ops_post_string('saturday_shift_end', 5);
            $grace = max(0, (int) ($_POST['late_grace_minutes'] ?? 10));
            $effectiveFrom = ops_post_string('effective_from', 10);
            $effectiveTo = ops_post_string('effective_to', 10);
            $timezone = ops_post_string('schedule_timezone', 64) ?: 'Africa/Windhoek';
            $lunchStart = ops_post_string('lunch_start', 5);
            $lunchEnd = ops_post_string('lunch_end', 5);
            $changeReason = ops_post_string('change_reason', 255);
            if ($employeeId <= 0) throw new RuntimeException('Choose an employee.');
            if ($hireDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) throw new RuntimeException('Enter a valid hire date.');
            if ($workingDays !== '' && !preg_match('/^[1-7](,[1-7])*$/', $workingDays)) throw new RuntimeException('Working days must use weekday numbers 1 to 7, separated by commas.');
            if (($shiftStart !== '' && !preg_match('/^\d{2}:\d{2}$/', $shiftStart)) || ($shiftEnd !== '' && !preg_match('/^\d{2}:\d{2}$/', $shiftEnd))) throw new RuntimeException('Enter valid shift times.');
            if (($saturdayStart !== '' && !preg_match('/^\d{2}:\d{2}$/', $saturdayStart)) || ($saturdayEnd !== '' && !preg_match('/^\d{2}:\d{2}$/', $saturdayEnd))) throw new RuntimeException('Enter valid Saturday shift times.');
            if (($shiftStart === '') !== ($shiftEnd === '') || ($saturdayStart === '') !== ($saturdayEnd === '')) throw new RuntimeException('Enter both the start and end for each configured shift.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) || ($effectiveTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo))) throw new RuntimeException('Enter valid schedule effective dates.');
            if ($effectiveTo !== '' && $effectiveTo < $effectiveFrom) throw new RuntimeException('The schedule end date cannot precede its start date.');
            if (!in_array($timezone, timezone_identifiers_list(), true)) throw new RuntimeException('Choose a valid schedule timezone.');
            if (($lunchStart === '') !== ($lunchEnd === '') || ($lunchStart !== '' && (!preg_match('/^\d{2}:\d{2}$/', $lunchStart) || !preg_match('/^\d{2}:\d{2}$/', $lunchEnd)))) throw new RuntimeException('Enter both lunch times in a valid format.');
            if ($changeReason === '') throw new RuntimeException('Enter a reason for this schedule version.');
            $workingDayNumbers = $workingDays === '' ? [] : array_map('intval', explode(',', $workingDays));
            $database = db();
            $database->beginTransaction();
            try {
                $database->prepare('UPDATE ops_employees SET hire_date = ?, working_days = ?, shift_start = ?, shift_end = ?, late_grace_minutes = ? WHERE id = ?')->execute([$hireDate ?: null, $workingDays ?: null, $shiftStart ?: null, $shiftEnd ?: null, $grace, $employeeId]);
                $previous = $database->prepare('SELECT id,effective_from FROM kpi_employee_schedule_versions WHERE employee_id=? AND effective_to IS NULL AND effective_from<? ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE');
                $previous->execute([$employeeId, $effectiveFrom]);
                $previousVersion = $previous->fetch(PDO::FETCH_ASSOC);
                if ($previousVersion) $database->prepare('UPDATE kpi_employee_schedule_versions SET effective_to=DATE_SUB(?,INTERVAL 1 DAY) WHERE id=?')->execute([$effectiveFrom, (int) $previousVersion['id']]);
                $versionInsert = $database->prepare('INSERT INTO kpi_employee_schedule_versions (employee_id,effective_from,effective_to,timezone,lunch_start,lunch_end,grace_minutes,change_reason,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $versionInsert->execute([$employeeId,$effectiveFrom,$effectiveTo ?: null,$timezone,$lunchStart ?: null,$lunchEnd ?: null,$grace,$changeReason,(int) (current_user()['id'] ?? 0) ?: null]);
                $versionId = (int) $database->lastInsertId();
                $dayInsert = $database->prepare('INSERT INTO kpi_employee_schedule_days (schedule_version_id,weekday,is_working,shift_start,shift_end) VALUES (?,?,?,?,?)');
                for ($weekday=1; $weekday<=7; $weekday++) {
                    $working = in_array($weekday, $workingDayNumbers, true);
                    $dayStart = $weekday === 6 ? $saturdayStart : $shiftStart;
                    $dayEnd = $weekday === 6 ? $saturdayEnd : $shiftEnd;
                    if ($working && ($dayStart === '' || $dayEnd === '')) throw new RuntimeException($weekday === 6 ? 'Enter the Saturday shift times.' : 'Enter the weekday shift times.');
                    $dayInsert->execute([$versionId,$weekday,$working ? 1 : 0,$working ? $dayStart : null,$working ? $dayEnd : null]);
                }
                $database->prepare('DELETE FROM kpi_employee_schedules WHERE employee_id = ?')->execute([$employeeId]);
                $scheduleInsert = $database->prepare('INSERT INTO kpi_employee_schedules (employee_id, weekday, is_working, shift_start, shift_end) VALUES (?, ?, 1, ?, ?)');
                foreach ($workingDayNumbers as $weekday) {
                    $dayStart = $weekday === 6 ? $saturdayStart : $shiftStart;
                    $dayEnd = $weekday === 6 ? $saturdayEnd : $shiftEnd;
                    if ($dayStart === '' || $dayEnd === '') throw new RuntimeException($weekday === 6 ? 'Enter the Saturday shift times.' : 'Enter the weekday shift times.');
                    $scheduleInsert->execute([$employeeId, $weekday, $dayStart, $dayEnd]);
                }
                $database->commit();
            } catch (Throwable $scheduleError) {
                if ($database->inTransaction()) $database->rollBack();
                throw $scheduleError;
            }
            ops_activity_log('kpi_employee_schedule_version_created', 'employee', $employeeId, ['effective_from'=>$effectiveFrom,'effective_to'=>$effectiveTo ?: null,'timezone'=>$timezone,'lunch'=>[$lunchStart,$lunchEnd],'working_days' => $workingDays, 'weekday_shift' => [$shiftStart, $shiftEnd], 'saturday_shift' => [$saturdayStart, $saturdayEnd], 'late_grace_minutes' => $grace, 'reason'=>$changeReason,'changed_by' => current_user()['name'] ?? 'Unknown']);
            $message = 'New employee schedule version saved.';
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
            throw new RuntimeException('Unknown performance settings action.');
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $messageType = 'error';
    }
}

$settings = [];
$employees = [];
$employeeSchedules = [];
$employeeScheduleVersions = [];
$holidays = [];
$recentEvents = [];
$recentSessions = [];
if ($ready && $tab === 'settings') {
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    if (!isset($settings['bookkeeping_adoption_date']) || $settings['bookkeeping_adoption_date'] === '2026-07-14') $settings['bookkeeping_adoption_date'] = '2026-07-20';
    $employees = ops_rows("SELECT e.id, e.full_name, e.hire_date, e.working_days, e.shift_start, e.shift_end, e.late_grace_minutes, r.name AS role_name FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE " . kpi_performance_employee_predicate('e', 'r') . " ORDER BY e.full_name");
    foreach (ops_rows('SELECT employee_id, weekday, shift_start, shift_end FROM kpi_employee_schedules WHERE is_working = 1 ORDER BY employee_id, weekday') as $scheduleRow) $employeeSchedules[(int) $scheduleRow['employee_id']][(int) $scheduleRow['weekday']] = $scheduleRow;
    foreach (ops_rows('SELECT employee_id,effective_from,effective_to,timezone,lunch_start,lunch_end,grace_minutes,change_reason,created_at FROM kpi_employee_schedule_versions ORDER BY employee_id,effective_from DESC,id DESC') as $versionRow) $employeeScheduleVersions[(int) $versionRow['employee_id']][] = $versionRow;
    $holidays = ops_rows('SELECT holiday_date, COALESCE(NULLIF(name, \'\'), holiday_name) AS holiday_name FROM kpi_holidays WHERE active = 1 ORDER BY holiday_date');
    $recentEvents = ops_rows('SELECT se.module, se.record_id, se.old_status, se.new_status, se.changed_at, e.full_name FROM kpi_status_events se LEFT JOIN ops_employees e ON e.id = se.changed_by ORDER BY se.id DESC LIMIT 30');
    $recentSessions = ops_rows('SELECT s.login_at, s.last_seen_at, s.logout_at, e.full_name FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id = s.user_id ORDER BY s.id DESC LIMIT 20');
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main id="<?= $tab === 'business-health' ? 'kpi-management' : ($tab === 'performance-reports' ? 'kpi-reports' : ($tab === 'business-activity' ? 'kpi-business-timeline-page' : 'kpi-management')) ?>" class="workspace module kpi-health-page" data-kpi-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
    <section class="module-header">
        <div>
            <p class="eyebrow">Performance</p>
            <h1><?= htmlspecialchars($currentKpiTitle,ENT_QUOTES,'UTF-8') ?></h1>
            <p><?= $tab === 'settings' ? 'Control data windows, fairness thresholds, working calendars and employee schedules.' : ($tab === 'employees' ? 'Role-relative performance evidence, workload and attendance for each employee.' : 'Evidence-based employee performance, accountability and operational insight.') ?></p>
        </div>
    </section>

    <nav class="kpi-health-tabs" aria-label="Employee Performance sections">
        <a href="reports.php?tab=business-health" class="<?= $tab === 'business-health' ? 'active' : '' ?>">Business Health</a>
        <a href="reports.php?tab=employees" class="<?= $tab === 'employees' ? 'active' : '' ?>">Employees</a>
        <?php foreach($visiblePerformanceTabs as $tabKey=>$tabLabel): ?><a href="reports.php?tab=<?= $tabKey ?>" class="<?= $tab===$tabKey?'active':'' ?>"><?= htmlspecialchars($tabLabel,ENT_QUOTES,'UTF-8') ?></a><?php endforeach; ?>
        <a href="reports.php?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Performance Settings</a>
    </nav>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif ($tab === 'business-health'): ?>
        <section class="kpi-period-panel" aria-label="Reporting period">
            <label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label>
            <label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label>
            <label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label>
            <label class="kpi-historical-toggle"><input type="checkbox" data-kpi-include-historical><span>Include Pre-EPI Historical Records</span></label>
            <span class="kpi-period-caption" data-kpi-caption aria-live="polite">Loading reporting period…</span>
        </section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div>
        <div class="ops-alert error" data-kpi-error hidden role="alert"></div>
        <header class="kpi-dashboard-section-head"><div><p>Business overview</p><h2>Business Health</h2></div><span>What is working, what is outstanding and what needs attention</span></header>
        <section class="kpi-management-story" data-kpi-management-story aria-live="polite"></section>
        <section class="kpi-health-grid" data-kpi-cards aria-label="Business health summary"><?php foreach (range(1, 6) as $placeholder): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></section>
        <section class="kpi-dashboard-operational-grid">
            <article class="kpi-health-panel kpi-orders-first"><div class="kpi-panel-heading"><div><p class="eyebrow">Order fulfilment</p><h2>Operational completion</h2></div><small>Authoritative order records and status history</small></div><div data-kpi-orders-overview></div></article>
            <article class="kpi-health-panel kpi-dashboard-risk"><div class="kpi-panel-heading"><div><p class="eyebrow">Risks and exceptions</p><h2>Needs attention</h2></div></div><div class="kpi-attention-list" data-kpi-attention></div></article>
        </section>
        <section class="kpi-chart-grid">
            <article class="kpi-health-panel kpi-orders-sales-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Daily activity</p><h2>Orders and Paid Sales</h2></div><small>Order count and paid sales are shown separately</small></div><div class="kpi-orders-sales-summary" data-kpi-orders-sales-summary></div><div class="kpi-orders-sales-charts"><section><h3>Order Volume</h3><p>Number of orders created each day</p><div class="kpi-chart-frame"><canvas data-kpi-order-volume-chart></canvas></div></section><section><h3>Paid Sales Value</h3><p>Paid order value received each day</p><div class="kpi-chart-frame"><canvas data-kpi-paid-sales-chart></canvas></div></section></div></article>
            <article class="kpi-health-panel kpi-physical-output-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">Measured packing output</p><h2>Physical Packing Output by Employee</h2></div><div class="kpi-chart-toggle"><button type="button" class="active" data-kpi-chart-mode="items">Items</button><button type="button" data-kpi-chart-mode="weight">Weight (kg)</button><button type="button" data-kpi-chart-mode="volume">Volume (L)</button><button type="button" data-kpi-chart-mode="packages">Packages</button><button type="button" data-kpi-chart-mode="units">Units</button></div></div><div class="kpi-chart-context" data-kpi-packing-context>Completed packing items by employee and completion date.</div><div class="kpi-physical-output-summary" data-kpi-physical-output-summary></div><div class="kpi-chart-frame"><canvas data-kpi-packing-chart></canvas></div></article>
        </section>
        <header class="kpi-dashboard-section-head"><div><p>People</p><h2>Operations Team Overview</h2></div><span>Each employee is shown separately using the work that applies to their role</span></header>
        <section class="kpi-team-stack" data-kpi-team aria-label="Employee performance summaries"></section>
        <header class="kpi-dashboard-section-head"><div><p>Live operations</p><h2>Recent Work Across the Portal</h2></div><span>Latest attributable employee activity, grouped by portal area</span></header>
        <section class="kpi-health-panel kpi-live-activity" data-kpi-live-activity aria-live="polite"></section>
        <header class="kpi-dashboard-section-head"><div><p>Operational detail</p><h2>Section Health and Employee Highlights</h2></div><span>Open any detailed section for its underlying evidence</span></header>
        <section class="kpi-management-flow" data-kpi-management-flow aria-label="Operational flow"></section>
        <section class="kpi-recognition" data-kpi-recognition aria-label="Employee awards and recognition"></section>
        <div data-kpi-scores hidden></div><div data-kpi-management-comparison hidden></div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
        <script src="<?= BASE_URL ?>/assets/js/reports-business-health.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/reports-business-health.js') ?>"></script>
    <?php elseif ($tab === 'employees'): ?>
        <section class="kpi-period-panel" aria-label="Reporting period">
            <label><span>Period</span><select data-kpi-period><option value="since_trusted">Since trusted start</option><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label>
            <label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label>
            <span class="kpi-period-caption" data-kpi-caption aria-live="polite">Loading reporting period…</span>
        </section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div>
        <section class="kpi-employee-directory-head" data-kpi-employee-selection-note>
            <div><p class="eyebrow">Team performance</p><h2>Choose an employee</h2><p>Open an individual workspace to review role-specific performance, supporting evidence and activity.</p></div>
            <span data-kpi-employee-count>Loading team…</span>
        </section>
        <nav class="kpi-employee-tabs kpi-employee-directory" data-kpi-employee-tabs aria-label="Employees"></nav>
        <script src="<?= BASE_URL ?>/assets/js/reports-employees.js?v=<?= (int) @filemtime(BASE_PATH . '/assets/js/reports-employees.js') ?>"></script>
    <?php elseif ($tab === 'performance-reports'): ?>
        <section class="performance-report-controls" aria-label="Performance report filters">
            <label><span>Reporting period</span><select data-performance-period><option value="since_adoption">Since adoption (14 Jul 2026)</option><option value="today">Today</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom date range</option></select></label>
            <label data-performance-custom hidden><span>From</span><input type="date" data-performance-from></label><label data-performance-custom hidden><span>To</span><input type="date" data-performance-to></label>
            <label><span>Employee</span><select data-performance-employee><option value="0">All employees</option></select></label>
            <label><span>Role</span><select data-performance-role><option value="all">All roles</option><option value="packer">Packers</option><option value="front_desk">Front desk</option></select></label>
            <label><span>Report view</span><select data-performance-section><option value="workforce">Workforce overview</option><option value="individual">Individual employee</option><option value="team">Collective team</option><option value="same_role">Same-role comparison</option><option value="risks">Current risks</option><option value="evidence">Evidence</option></select></label>
            <div class="performance-report-actions"><button type="button" class="btn-secondary" data-performance-compare>Compare Employees</button><button type="button" class="btn-primary" data-performance-meeting>Start Meeting Mode</button><button type="button" class="btn-secondary" data-performance-print>Print</button><button type="button" class="btn-secondary" data-performance-pdf>Export PDF</button><button type="button" class="btn-secondary" data-performance-csv>Export Excel/CSV</button></div>
        </section>
        <div class="performance-report-meta"><span data-performance-period-caption>Loading period…</span><span data-performance-refreshed></span><span class="performance-quality-badge" data-performance-quality>Checking data quality…</span></div>
        <div class="ops-alert error" data-performance-error hidden role="alert"></div>
        <section class="performance-report-output" data-performance-output data-reward-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" aria-live="polite"><div class="kpi-health-grid"><?php foreach(range(1,4)as$i): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section>
        <div class="performance-meeting" data-performance-meeting-view hidden><header><div><p>Hambelela Organic</p><h1>Performance Meeting</h1><span data-meeting-period></span></div><div><button type="button" data-meeting-previous>Previous</button><span data-meeting-position>1 / 1</span><button type="button" data-meeting-next>Next</button><button type="button" data-meeting-sensitive>Hide sensitive information</button><button type="button" data-meeting-summary>Summary / Evidence</button><button type="button" data-meeting-exit>Exit Meeting Mode</button></div></header><nav data-meeting-nav></nav><main data-meeting-content></main></div>
        <script src="<?= BASE_URL ?>/assets/js/reports-performance.js?v=<?= htmlspecialchars(substr((string)@hash_file('sha256',BASE_PATH.'/assets/js/reports-performance.js'),0,12),ENT_QUOTES,'UTF-8') ?>"></script>
    <?php elseif (isset($phaseThreeTabs[$tab])): ?>
        <section class="kpi-period-panel" aria-label="Reporting period"><label><span>Period</span><select data-kpi-period><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This week</option><option value="last_week">Last week</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="custom">Custom</option></select></label><label data-kpi-custom hidden><span>From</span><input type="date" data-kpi-from></label><label data-kpi-custom hidden><span>To</span><input type="date" data-kpi-to></label><span class="kpi-period-caption" data-kpi-caption>Loading…</span><button class="btn-secondary" type="button" data-kpi-refresh>Refresh</button><?php if($tab==='performance-reports'): ?><button class="btn-secondary" type="button" onclick="window.print()">Print report</button><?php endif; ?></section>
        <div class="kpi-adoption-banner" data-kpi-adoption hidden></div><div class="ops-alert error" data-kpi-error hidden role="alert"></div><section data-kpi-section-content><div class="kpi-health-grid"><?php foreach(range(1,6)as$i): ?><article class="kpi-health-card is-loading"><span></span><strong></strong><small></small></article><?php endforeach; ?></div></section><dialog class="kpi-timeline-dialog" data-kpi-timeline><button type="button" class="kpi-timeline-close" data-kpi-timeline-close aria-label="Close timeline">×</button><div data-kpi-timeline-content></div></dialog>
        <?php if($tab==='business-activity'): ?><section id="kpi-business-timeline" class="kpi-period-panel" aria-label="Business activity filters"><label><span>Actor</span><select data-activity-actor><option value="">All actors</option><option value="employee">Employee</option><option value="owner">Owner</option><option value="system">System</option><option value="unknown">Unknown historical actor</option></select></label><label><span>Module</span><select data-activity-module><option value="">All modules</option><option value="orders">Orders</option><option value="packing_list">Packing</option><option value="tasks">Tasks</option><option value="courier_waybills">Courier</option><option value="error_log">Error Log</option><option value="bookkeeping">Bookkeeping</option><option value="portal_activity">Attendance</option></select></label><label><span>Assignment</span><select data-activity-assignment><option value="">All activity</option><option value="automatically_assigned">Automatically assigned</option><option value="manually_assigned">Manually assigned</option><option value="reassigned">Reassigned</option></select></label><label><span>Result</span><select data-activity-result><option value="">All results</option><option value="successful">Successful</option><option value="failed">Failed</option><option value="skipped">Skipped</option></select></label></section><?php endif; ?>
        <script src="<?= BASE_URL ?>/assets/js/reports-section.js?v=<?= (int)@filemtime(BASE_PATH.'/assets/js/reports-section.js') ?>"></script>
    <?php else: ?>
        <?php if ($message !== ''): ?><div class="ops-alert <?= $messageType === 'error' ? 'error' : 'success' ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Calculation controls</p><h2>Global performance settings</h2><p>Role-specific weights are stored for owner review only. Composite scores, bonus bands and rankings remain disabled until every required data-integrity test passes.</p></div></div>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="kpi_action" value="save_settings">
                <?php foreach ($settingFields as $key => [$label, $type, $default]): ?>
                    <label class="field"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><input type="<?= $type ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, 'UTF-8') ?>" <?= $type === 'number' ? 'min="0" step="any"' : '' ?> required></label>
                <?php endforeach; ?>
                <div class="form-actions"><button class="btn-primary" type="submit">Save performance settings</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="section-row"><div><p class="eyebrow">Fair attendance</p><h2>Employee schedules</h2></div></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Employee</th><th>Role</th><th>Hire date</th><th>Working days</th><th>Mon–Fri shift</th><th>Saturday shift</th><th>Grace</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($employees as $employee): $scheduleFormId = 'employee-schedule-' . (int) $employee['id']; $savedSchedule=$employeeSchedules[(int)$employee['id']]??[]; $weekdaySchedule=$savedSchedule[1]??null; $saturdaySchedule=$savedSchedule[6]??null; $latestVersion=$employeeScheduleVersions[(int)$employee['id']][0]??[]; ?><tr><td><strong><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= count($employeeScheduleVersions[(int)$employee['id']]??[]) ?> version(s)</small></td><td><?= htmlspecialchars((string) $employee['role_name'], ENT_QUOTES, 'UTF-8') ?></td><td><input form="<?= $scheduleFormId ?>" type="date" name="hire_date" value="<?= htmlspecialchars((string) ($employee['hire_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input form="<?= $scheduleFormId ?>" type="date" name="effective_from" value="<?= date('Y-m-d') ?>" required aria-label="Effective from"><input form="<?= $scheduleFormId ?>" type="date" name="effective_to" aria-label="Effective to"></td><td><input form="<?= $scheduleFormId ?>" name="working_days" value="<?= htmlspecialchars((string) ($employee['working_days'] ?? '1,2,3,4,5'), ENT_QUOTES, 'UTF-8') ?>" placeholder="1,2,3,4,5"></td><td><input form="<?= $scheduleFormId ?>" type="time" name="shift_start" value="<?= htmlspecialchars(substr((string) ($weekdaySchedule['shift_start']??$employee['shift_start'] ?? '08:00'), 0, 5), ENT_QUOTES, 'UTF-8') ?>"> <input form="<?= $scheduleFormId ?>" type="time" name="shift_end" value="<?= htmlspecialchars(substr((string) ($weekdaySchedule['shift_end']??$employee['shift_end'] ?? '17:00'), 0, 5), ENT_QUOTES, 'UTF-8') ?>"><input form="<?= $scheduleFormId ?>" type="time" name="lunch_start" value="<?= htmlspecialchars(substr((string)($latestVersion['lunch_start']??'12:00'),0,5),ENT_QUOTES,'UTF-8') ?>" aria-label="Lunch start"><input form="<?= $scheduleFormId ?>" type="time" name="lunch_end" value="<?= htmlspecialchars(substr((string)($latestVersion['lunch_end']??'13:00'),0,5),ENT_QUOTES,'UTF-8') ?>" aria-label="Lunch end"></td><td><input form="<?= $scheduleFormId ?>" type="time" name="saturday_shift_start" value="<?= htmlspecialchars(substr((string)($saturdaySchedule['shift_start']??'09:00'),0,5),ENT_QUOTES,'UTF-8') ?>"> <input form="<?= $scheduleFormId ?>" type="time" name="saturday_shift_end" value="<?= htmlspecialchars(substr((string)($saturdaySchedule['shift_end']??'13:30'),0,5),ENT_QUOTES,'UTF-8') ?>"></td><td><input form="<?= $scheduleFormId ?>" type="number" min="0" name="late_grace_minutes" value="<?= (int) ($employee['late_grace_minutes'] ?? 10) ?>"><input form="<?= $scheduleFormId ?>" name="schedule_timezone" value="<?= htmlspecialchars((string)($latestVersion['timezone']??'Africa/Windhoek'),ENT_QUOTES,'UTF-8') ?>" aria-label="Timezone"><input form="<?= $scheduleFormId ?>" name="change_reason" required placeholder="Reason for schedule version" aria-label="Change reason"></td><td><form id="<?= $scheduleFormId ?>" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="save_employee_schedule"><input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>"><button class="btn-secondary" type="submit">Save new version</button></form></td></tr><?php endforeach; ?>
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
