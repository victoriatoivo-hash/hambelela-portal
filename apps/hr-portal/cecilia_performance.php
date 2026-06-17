<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/operations/operations.php';

require_login();

$pageTitle = 'Cecilia - Performance Dashboard | ' . APP_NAME;

function hp_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hp_money($value): string
{
    return 'N$' . number_format((float) $value, 2);
}

function hp_dt($value): string
{
    if (!$value) return '-';
    $time = strtotime((string) $value);
    return $time ? date('d M H:i', $time) : '-';
}

function hp_day($value): string
{
    if (!$value) return '-';
    $time = strtotime((string) $value);
    return $time ? date('d M', $time) : '-';
}

function hp_duration(?float $minutes): string
{
    if ($minutes === null || $minutes < 0) return '-';
    if ($minutes < 1) return '0 min';
    if ($minutes < 60) return round($minutes) . ' min';
    $hours = floor($minutes / 60);
    $mins = round($minutes - ($hours * 60));
    if ($hours < 24) return $hours . 'h' . ($mins > 0 ? ' ' . $mins . 'm' : '');
    $days = floor($hours / 24);
    $rem = $hours % 24;
    return $days . 'd' . ($rem > 0 ? ' ' . $rem . 'h' : '');
}

function hp_pct(int $part, int $total): int
{
    return $total > 0 ? (int) round(($part / $total) * 100) : 0;
}

function hp_tag(string $label, string $type = ''): string
{
    $class = $type === 'good' ? 'tg' : ($type === 'warn' ? 'tw' : ($type === 'danger' ? 'tr' : ''));
    return '<span class="tag ' . $class . '">' . hp_e($label) . '</span>';
}

function hp_empty(int $cols, string $message = 'No records found'): string
{
    return '<tr><td colspan="' . $cols . '" class="empty-cell">' . hp_e($message) . '</td></tr>';
}

function hp_avg(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($v) => $v !== null && is_numeric($v)));
    return $values ? array_sum($values) / count($values) : null;
}

function hp_minutes_between($from, $to): ?float
{
    if (!$from || !$to) return null;
    $a = strtotime((string) $from);
    $b = strtotime((string) $to);
    return $a && $b && $b >= $a ? ($b - $a) / 60 : null;
}

function hp_safe_col(string $table, string $column): bool
{
    return ops_table_exists($table) && ops_column_exists($table, $column);
}

function hp_bootstrap_schema(): void
{
    if (!ops_table_exists('ops_packing_tasks')) return;
    try {
        if (!ops_column_exists('ops_packing_tasks', 'website_uploaded')) {
            db()->exec("ALTER TABLE ops_packing_tasks ADD COLUMN website_uploaded TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!ops_column_exists('ops_packing_tasks', 'website_uploaded_at')) {
            db()->exec("ALTER TABLE ops_packing_tasks ADD COLUMN website_uploaded_at DATETIME NULL");
        }
    } catch (Throwable $e) {
        // The dashboard remains readable even if an older database user cannot alter tables.
    }
}

function hp_cecilia_employee(): array
{
    if (!ops_table_exists('ops_employees')) return ['id' => 0, 'full_name' => 'Cecilia', 'email' => '', 'role_name' => 'Front Desk/Admin'];
    $rows = ops_rows(
        "SELECT e.*, r.name AS role_name, r.role_key
         FROM ops_employees e
         LEFT JOIN ops_roles r ON r.id = e.role_id
         WHERE LOWER(e.full_name) LIKE '%cecilia%'
            OR LOWER(e.full_name) LIKE '%secilia%'
            OR LOWER(e.email) LIKE '%frontdesk%'
            OR r.role_key = 'front_desk_admin'
         ORDER BY
            CASE
                WHEN LOWER(e.full_name) LIKE '%cecilia%' OR LOWER(e.full_name) LIKE '%secilia%' THEN 0
                WHEN LOWER(e.email) LIKE '%frontdesk%' THEN 1
                ELSE 2
            END,
            e.id ASC
         LIMIT 1"
    );
    return $rows[0] ?? ['id' => 0, 'full_name' => 'Cecilia', 'email' => '', 'role_name' => 'Front Desk/Admin'];
}

function hp_month_link(DateTimeImmutable $month, string $delta): string
{
    return '?month=' . $month->modify($delta)->format('Y-m');
}

function hp_sla_due(string $uploadedAt): ?int
{
    $uploaded = strtotime($uploadedAt);
    if (!$uploaded) return null;
    $sameDayFive = strtotime(date('Y-m-d 17:00:00', $uploaded));
    if ($uploaded <= $sameDayFive) return $sameDayFive;
    $next = strtotime('+1 day', strtotime(date('Y-m-d 09:00:00', $uploaded)));
    while ((int) date('N', $next) >= 7) {
        $next = strtotime('+1 day', $next);
    }
    return $next;
}

function hp_workdays_in_month(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $days = [];
    for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 6 && $d <= new DateTimeImmutable('today 23:59:59')) {
            $days[] = $d->format('Y-m-d');
        }
    }
    return $days;
}

$ready = ops_database_ready();
if ($ready) hp_bootstrap_schema();

$monthParam = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) $monthParam = date('Y-m');
$monthStart = new DateTimeImmutable($monthParam . '-01 00:00:00');
$monthEnd = $monthStart->modify('first day of next month');
$periodStart = $monthStart->format('Y-m-d H:i:s');
$periodEnd = $monthEnd->format('Y-m-d H:i:s');
$monthLabel = $monthStart->format('F Y');

$cecilia = $ready ? hp_cecilia_employee() : ['id' => 0, 'full_name' => 'Cecilia', 'email' => '', 'role_name' => 'Front Desk/Admin'];
$ceciliaId = (int) ($cecilia['id'] ?? 0);
$ceciliaName = (string) ($cecilia['full_name'] ?? 'Cecilia');
$initial = strtoupper(substr($ceciliaName !== '' ? $ceciliaName : 'Cecilia', 0, 1));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_website_update') {
    $rowId = (int) ($_POST['packing_task_id'] ?? 0);
    $done = isset($_POST['website_uploaded']) ? 1 : 0;
    if ($rowId > 0 && ops_table_exists('ops_packing_tasks') && hp_safe_col('ops_packing_tasks', 'website_uploaded')) {
        $sql = hp_safe_col('ops_packing_tasks', 'website_uploaded_at')
            ? "UPDATE ops_packing_tasks SET website_uploaded = ?, website_uploaded_at = CASE WHEN ? = 1 THEN COALESCE(website_uploaded_at, NOW()) ELSE NULL END WHERE id = ?"
            : "UPDATE ops_packing_tasks SET website_uploaded = ? WHERE id = ?";
        $params = hp_safe_col('ops_packing_tasks', 'website_uploaded_at') ? [$done, $done, $rowId] : [$done, $rowId];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $stmt->closeCursor();
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#') . '#picking');
    exit;
}

$paidAtExpr = hp_safe_col('ops_orders', 'paid_at') ? 'o.paid_at' : "CASE WHEN o.payment_status = 'paid' THEN o.updated_at ELSE NULL END";

$orders = $ready && ops_table_exists('ops_orders') ? ops_rows(
    "SELECT o.*, {$paidAtExpr} AS paid_at_value
     FROM ops_orders o
     WHERE o.created_at >= ? AND o.created_at < ?
     ORDER BY o.created_at DESC, o.id DESC",
    [$periodStart, $periodEnd]
) : [];

$walkInOrders = array_values(array_filter($orders, static function ($row): bool {
    $contact = strtolower((string) ($row['customer_contact'] ?? ''));
    return strpos($contact, 'walk-in') !== false || strpos($contact, 'walk in') !== false || strpos($contact, 'walkin') !== false;
}));
$completedStatuses = ['completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'];
$completedOrders = array_values(array_filter($orders, static fn($row) => in_array((string) ($row['status'] ?? ''), $completedStatuses, true)));
$activeOrders = array_values(array_filter($orders, static fn($row) => !in_array((string) ($row['status'] ?? ''), array_merge($completedStatuses, ['cancelled', 'canceled', 'refunded', 'failed']), true)));
$unpaidOrders = array_values(array_filter($orders, static fn($row) => (string) ($row['payment_status'] ?? '') !== 'paid'));
$walkDurations = [];
foreach ($walkInOrders as $row) {
    $doneAt = $row['completed_at'] ?: ($row['status'] === 'completed' ? $row['updated_at'] : null);
    $walkDurations[] = hp_minutes_between($row['created_at'] ?? null, $doneAt);
}

$cashBookRows = $ready && ops_table_exists('ops_cash_book_entries') ? ops_rows(
    "SELECT c.*, e.full_name AS recorded_by_name
     FROM ops_cash_book_entries c
     LEFT JOIN ops_employees e ON e.id = c.recorded_by
     WHERE c.transaction_date >= ? AND c.transaction_date < ? AND c.archived_at IS NULL
     ORDER BY c.transaction_date DESC, c.id DESC",
    [$periodStart, $periodEnd]
) : [];
$bookkeepingJoined = $ready && ops_table_exists('ops_cash_book_entries') && ops_table_exists('ops_orders') ? ops_rows(
    "SELECT o.order_number, o.customer_name, o.order_type, o.payment_method, o.total_amount, o.created_at, c.transaction_date, c.transaction_type,
            TIMESTAMPDIFF(MINUTE, o.created_at, c.transaction_date) AS delay_minutes
     FROM ops_orders o
     LEFT JOIN ops_cash_book_entries c ON c.archived_at IS NULL AND (c.related_order_id = o.id OR c.related_order_number = o.order_number)
     WHERE o.created_at >= ? AND o.created_at < ? AND o.order_type IN ('delivery', 'courier')
     ORDER BY o.created_at DESC",
    [$periodStart, $periodEnd]
) : [];
$missingCashOrders = $ready && ops_table_exists('ops_orders') && ops_table_exists('ops_cash_book_entries') ? ops_rows(
    "SELECT o.*
     FROM ops_orders o
     LEFT JOIN ops_cash_book_entries c ON c.archived_at IS NULL AND (c.related_order_id = o.id OR c.related_order_number = o.order_number)
     WHERE o.created_at >= ? AND o.created_at < ?
       AND LOWER(COALESCE(o.payment_method, '')) LIKE '%cash%'
       AND o.payment_status = 'paid'
       AND c.id IS NULL
     ORDER BY o.created_at DESC",
    [$periodStart, $periodEnd]
) : [];

$waybills = $ready && ops_table_exists('ops_courier_waybills') ? ops_rows(
    "SELECT w.*, up.full_name AS uploaded_by_name, sp.full_name AS sent_by_name
     FROM ops_courier_waybills w
     LEFT JOIN ops_employees up ON up.id = w.uploaded_by
     LEFT JOIN ops_employees sp ON sp.id = w.sent_by
     WHERE w.uploaded_at >= ? AND w.uploaded_at < ?
     ORDER BY w.uploaded_at DESC, w.id DESC",
    [$periodStart, $periodEnd]
) : [];
$waybillSent = 0;
$waybillBreaches = 0;
$waybillPending = 0;
$waybillDurations = [];
foreach ($waybills as $row) {
    $due = hp_sla_due((string) ($row['uploaded_at'] ?? ''));
    $sentAt = $row['sent_at'] ?? null;
    if ($sentAt) {
        $waybillSent++;
        $waybillDurations[] = hp_minutes_between($row['uploaded_at'] ?? null, $sentAt);
        if ($due && strtotime((string) $sentAt) > $due) $waybillBreaches++;
    } else {
        $waybillPending++;
        if ($due && time() > $due) $waybillBreaches++;
    }
}

$taskAssignedExpr = hp_safe_col('ops_checklist_tasks', 'date_assigned') ? 't.date_assigned' : 't.created_at';
$taskCompletedExpr = hp_safe_col('ops_checklist_tasks', 'date_completed') ? 't.date_completed' : 't.completed_at';
$taskCompletedBySelect = hp_safe_col('ops_checklist_tasks', 'completed_by') ? ', c.full_name AS completed_by_name' : ", NULL AS completed_by_name";
$taskCompletedByJoin = hp_safe_col('ops_checklist_tasks', 'completed_by') ? 'LEFT JOIN ops_employees c ON c.id = t.completed_by' : '';
$tasks = $ready && ops_table_exists('ops_checklist_tasks') ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name {$taskCompletedBySelect}
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     {$taskCompletedByJoin}
     WHERE t.assigned_employee_id = ?
       AND COALESCE({$taskAssignedExpr}, t.created_at, t.deadline) >= ?
       AND COALESCE({$taskAssignedExpr}, t.created_at, t.deadline) < ?
     ORDER BY COALESCE(t.deadline, t.created_at) DESC, t.id DESC",
    [$ceciliaId, $periodStart, $periodEnd]
) : [];
$taskCounts = ['done' => 0, 'in_progress' => 0, 'not_started' => 0, 'needs_review' => 0, 'overdue' => 0];
$taskDurations = [];
foreach ($tasks as $task) {
    $status = strtolower(str_replace(' ', '_', (string) ($task['status'] ?? 'not_started')));
    $done = in_array($status, ['done', 'completed', 'complete', 'approved'], true);
    if ($done) {
        $taskCounts['done']++;
        $taskDurations[] = hp_minutes_between($task['date_assigned'] ?? $task['created_at'] ?? null, $task['date_completed'] ?? $task['completed_at'] ?? null) / 1440;
    } elseif (!empty($task['deadline']) && strtotime((string) $task['deadline']) < time()) {
        $taskCounts['overdue']++;
    } elseif ($status === 'in_progress') {
        $taskCounts['in_progress']++;
    } elseif ($status === 'needs_review') {
        $taskCounts['needs_review']++;
    } else {
        $taskCounts['not_started']++;
    }
}

$errorsLogged = $ready && ops_table_exists('ops_error_logs') ? ops_rows(
    "SELECT l.*, r.full_name AS responsible_name, by_emp.full_name AS logged_by_name
     FROM ops_error_logs l
     LEFT JOIN ops_employees r ON r.id = l.employee_id
     LEFT JOIN ops_employees by_emp ON by_emp.id = l.logged_by
     WHERE l.logged_at >= ? AND l.logged_at < ? AND l.logged_by = ?
     ORDER BY l.logged_at DESC, l.id DESC",
    [$periodStart, $periodEnd, $ceciliaId]
) : [];
$errorsAgainst = $ready && ops_table_exists('ops_error_logs') ? ops_rows(
    "SELECT l.*, by_emp.full_name AS logged_by_name
     FROM ops_error_logs l
     LEFT JOIN ops_employees by_emp ON by_emp.id = l.logged_by
     WHERE l.logged_at >= ? AND l.logged_at < ? AND l.employee_id = ?
     ORDER BY l.logged_at DESC, l.id DESC",
    [$periodStart, $periodEnd, $ceciliaId]
) : [];
$completeErrorLogs = 0;
foreach ($errorsLogged as $err) {
    if (trim((string) ($err['description'] ?? '')) !== '' && (int) ($err['employee_id'] ?? 0) > 0) $completeErrorLogs++;
}

$packingRows = $ready && ops_table_exists('ops_packing_tasks') ? ops_rows(
    "SELECT p.*, e.full_name AS assigned_name
     FROM ops_packing_tasks p
     LEFT JOIN ops_employees e ON e.id = p.assigned_employee_id
     WHERE COALESCE(p.date_loaded, p.created_at) >= ? AND COALESCE(p.date_loaded, p.created_at) < ?
     ORDER BY COALESCE(p.date_loaded, p.created_at) DESC, p.id DESC",
    [$periodStart, $periodEnd]
) : [];
$websiteOnTime = 0;
$websiteLate = 0;
$websitePending = 0;
$websiteDurations = [];
foreach ($packingRows as $row) {
    $loaded = $row['date_loaded'] ?? $row['created_at'] ?? null;
    $uploadedAt = $row['website_uploaded_at'] ?? ($row['website_uploaded'] ? $row['updated_at'] ?? null : null);
    if ((int) ($row['website_uploaded'] ?? 0) === 1) {
        $minutes = hp_minutes_between($loaded, $uploadedAt);
        $websiteDurations[] = $minutes;
        if ($minutes !== null && $minutes <= 1440) $websiteOnTime++; else $websiteLate++;
    } else {
        $websitePending++;
    }
}

$loginRows = $ready && ops_table_exists('ops_login_events') ? ops_rows(
    "SELECT *
     FROM ops_login_events
     WHERE employee_id = ? AND login_at >= ? AND login_at < ?
     ORDER BY login_at DESC",
    [$ceciliaId, $periodStart, $periodEnd]
) : [];
$firstLoginByDay = [];
foreach ($loginRows as $row) {
    $day = substr((string) ($row['login_at'] ?? ''), 0, 10);
    if ($day !== '' && (!isset($firstLoginByDay[$day]) || strtotime((string) $row['login_at']) < strtotime((string) $firstLoginByDay[$day]['login_at']))) {
        $firstLoginByDay[$day] = $row;
    }
}
$workdays = hp_workdays_in_month($monthStart, $monthEnd);
$lateLogins = 0;
$earlyLogins = 0;
$loginMinutes = [];
foreach ($firstLoginByDay as $day => $row) {
    $time = substr((string) ($row['login_at'] ?? ''), 11, 5);
    if ($time !== '') {
        [$h, $m] = array_map('intval', explode(':', $time));
        $loginMinutes[] = $h * 60 + $m;
        if ($time <= '08:00') $earlyLogins++;
        if ($time > '08:15') $lateLogins++;
    }
}
$avgLoginMins = hp_avg($loginMinutes);
$avgLoginTime = $avgLoginMins === null ? '-' : sprintf('%02d:%02d', floor($avgLoginMins / 60), $avgLoginMins % 60);
$daysPresent = count($firstLoginByDay);
$absentDays = max(0, count($workdays) - $daysPresent);
$monthNav = '<div class="month-nav"><a class="month-btn" href="' . hp_e(hp_month_link($monthStart, '-1 month')) . '">&#8249;</a><div class="month-label">' . hp_e($monthLabel) . '</div><a class="month-btn" href="' . hp_e(hp_month_link($monthStart, '+1 month')) . '">&#8250;</a></div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= hp_e($pageTitle) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap');
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --bg: #F7F5F2; --surface: #FFFFFF; --border: #E4E0D9; --text-primary: #1A1A18; --text-secondary: #6B6860; --text-muted: #A09D98; --accent: #2D5016; --accent-light: #EBF2E4; --accent-mid: #4A7C28; --warn: #C4621A; --warn-light: #FDF0E6; --danger: #B83232; --danger-light: #FDEAEA; --good: #2D5016; --good-light: #EBF2E4; --tag-bg: #EDEBE7; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-primary); font-size: 14px; line-height: 1.5; overflow-x: hidden; }
  .topbar { background: var(--accent); color: white; padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
  .topbar-brand { font-size: 13px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
  .topbar-right { font-size: 13px; opacity: 0.85; }
  .layout { display: flex; min-height: calc(100vh - 52px); }
  .sidebar { width: 220px; background: var(--surface); border-right: 1px solid var(--border); padding: 24px 0; flex-shrink: 0; position: sticky; top: 52px; height: calc(100vh - 52px); overflow-y: auto; }
  .sidebar-section { padding: 0 16px; margin-bottom: 8px; }
  .sidebar-label { font-size: 10px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); padding: 0 12px; margin-bottom: 4px; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; color: var(--text-secondary); font-weight: 400; transition: all 0.15s; margin-bottom: 1px; }
  .nav-item:hover { background: var(--bg); color: var(--text-primary); }
  .nav-item.active { background: var(--accent-light); color: var(--accent); font-weight: 500; }
  .nav-icon { font-size: 14px; width: 18px; text-align: center; }
  .nav-divider { height: 1px; background: var(--border); margin: 10px 16px; }
  .main { flex: 1; min-width: 0; padding: 28px 32px; overflow-y: auto; }
  .section-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 24px; }
  .sec-tab { padding: 7px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: all 0.15s; }
  .sec-tab:hover { border-color: var(--accent); color: var(--accent); }
  .sec-tab.active { background: var(--accent); color: white; border-color: var(--accent); }
  .profile-strip { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px 22px; display: flex; align-items: center; gap: 18px; margin-bottom: 22px; }
  .avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: white; display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 600; flex-shrink: 0; }
  .profile-info { flex: 1; }
  .profile-name { font-size: 15px; font-weight: 600; }
  .profile-role { font-size: 12.5px; color: var(--text-secondary); margin-top: 1px; }
  .profile-meta { display: flex; gap: 20px; margin-top: 8px; flex-wrap: wrap; }
  .meta-item { font-size: 12px; color: var(--text-secondary); }
  .meta-item strong { color: var(--text-primary); font-weight: 500; }
  .profile-actions { display: flex; gap: 8px; }
  .btn { padding: 8px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 500; cursor: pointer; border: none; font-family: inherit; transition: opacity 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .btn:hover { opacity: 0.85; }
  .btn-primary { background: var(--accent); color: white; }
  .btn-danger { background: var(--danger); color: white; }
  .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-secondary); }
  .btn-sm { padding: 5px 12px; font-size: 12px; }
  .section { display: none; }
  .section.active { display: block; }
  .section-heading { font-size: 17px; font-weight: 600; margin-bottom: 4px; }
  .section-sub { font-size: 13px; color: var(--text-secondary); margin-bottom: 20px; }
  .month-nav { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
  .month-label { font-size: 13.5px; font-weight: 600; }
  .month-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--border); background: var(--surface); cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none; }
  .stats-row { display: grid; gap: 12px; margin-bottom: 20px; }
  .cols-3 { grid-template-columns: repeat(3, 1fr); } .cols-4 { grid-template-columns: repeat(4, 1fr); } .cols-5 { grid-template-columns: repeat(5, 1fr); } .cols-6 { grid-template-columns: repeat(6, 1fr); }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 9px; padding: 16px 18px; }
  .stat-label { font-size: 10.5px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 7px; }
  .stat-value { font-family: 'DM Mono', monospace; font-size: 24px; font-weight: 500; color: var(--text-primary); line-height: 1; }
  .stat-sub { font-size: 11.5px; color: var(--text-secondary); margin-top: 5px; }
  .badge { display: inline-block; font-size: 11px; font-weight: 500; padding: 2px 8px; border-radius: 20px; margin-top: 6px; }
  .bg-good { background: var(--good-light); color: var(--good); } .bg-warn { background: var(--warn-light); color: var(--warn); } .bg-danger { background: var(--danger-light); color: var(--danger); }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: 9px; overflow: hidden; margin-bottom: 16px; }
  .card-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border); }
  .card-title { font-size: 13px; font-weight: 600; }
  .card-action { font-size: 12px; color: var(--accent-mid); cursor: pointer; font-weight: 500; }
  .card-body { padding: 18px; }
  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
  .tag { font-size: 11px; padding: 2px 8px; border-radius: 20px; background: var(--tag-bg); color: var(--text-secondary); font-weight: 500; display: inline-block; }
  .tg { background: var(--good-light); color: var(--good); } .tw { background: var(--warn-light); color: var(--warn); } .tr { background: var(--danger-light); color: var(--danger); }
  .table-wrap { width: 100%; overflow-x: auto; }
  .data-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 640px; }
  .data-table th { text-align: left; font-size: 10.5px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); padding: 0 12px 9px; border-bottom: 1px solid var(--border); }
  .data-table td { padding: 11px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  .data-table tr:last-child td { border-bottom: none; }
  .data-table tr:hover td { background: var(--bg); }
  .tname { font-weight: 500; }
  .tmono { font-family: 'DM Mono', monospace; font-size: 11.5px; color: var(--text-secondary); }
  .empty-cell { color: var(--text-secondary); padding: 18px !important; }
  .prog-bg { height: 5px; border-radius: 3px; background: var(--border); overflow: hidden; margin-top: 5px; }
  .prog-fill { height: 100%; border-radius: 3px; }
  .pf-green { background: var(--accent); } .pf-warn { background: var(--warn); } .pf-danger { background: var(--danger); }
  .alert-card { border-color: var(--danger) !important; }
  .alert-header { background: var(--danger-light); }
  .alert-title { color: var(--danger); font-size: 13px; font-weight: 600; }
  .task-pills { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
  .task-pill { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); }
  .pill-pending { background: var(--tag-bg); color: var(--text-secondary); } .pill-progress { background: var(--warn-light); color: var(--warn); } .pill-done { background: var(--good-light); color: var(--good); } .pill-overdue { background: var(--danger-light); color: var(--danger); }
  .kpi-list { display: flex; flex-direction: column; gap: 14px; }
  .kpi-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px; }
  .kpi-name { font-size: 13px; font-weight: 500; }
  .kpi-val { font-family: 'DM Mono', monospace; font-size: 13px; color: var(--text-secondary); }
  .kpi-bg { height: 5px; border-radius: 3px; background: var(--border); overflow: hidden; }
  .kpi-fill { height: 100%; border-radius: 3px; } .kf-green { background: var(--accent); } .kf-warn { background: var(--warn); } .kf-danger { background: var(--danger); }
  .kpi-note { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
  .attend-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 10px; }
  .day-lbl { text-align: center; font-size: 10px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; padding-bottom: 3px; }
  .ad { aspect-ratio: 1; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 500; font-family: 'DM Mono', monospace; }
  .ad-p { background: var(--accent-light); color: var(--accent); } .ad-l { background: var(--warn-light); color: var(--warn); } .ad-a { background: var(--danger-light); color: var(--danger); } .ad-o { background: var(--tag-bg); color: var(--text-muted); }
  .legend { display: flex; gap: 14px; font-size: 11px; color: var(--text-secondary); margin-top: 10px; flex-wrap: wrap; }
  .leg-item { display: flex; align-items: center; gap: 4px; }
  .leg-dot { width: 8px; height: 8px; border-radius: 2px; }
  .update-form { display: inline-flex; align-items: center; gap: 7px; }
  .update-form input { width: 16px; height: 16px; accent-color: var(--accent); }
  .offline-note { background: var(--warn-light); border: 1px solid #F2D6BD; color: var(--warn); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
  @media (max-width: 1000px) { .cols-5, .cols-6 { grid-template-columns: repeat(3, 1fr); } .cols-4 { grid-template-columns: repeat(2, 1fr); } .cols-3 { grid-template-columns: repeat(2, 1fr); } .two-col { grid-template-columns: 1fr; } .sidebar { display: none; } .main { padding: 20px 16px; } .profile-strip { align-items: flex-start; flex-wrap: wrap; } }
  @media (max-width: 640px) { .cols-3, .cols-4, .cols-5, .cols-6 { grid-template-columns: 1fr; } .topbar { padding: 0 14px; } .profile-actions { width: 100%; flex-wrap: wrap; } }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-brand">Hambelela Organic - HR Portal</div>
  <div class="topbar-right">Admin View &nbsp;-&nbsp; <?= hp_e($monthLabel) ?></div>
</div>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-section">
      <div class="sidebar-label">Staff</div>
      <div class="nav-item active"><span class="nav-icon">👤</span> <?= hp_e($ceciliaName) ?></div>
      <div class="nav-item"><span class="nav-icon">📦</span> Klaudia</div>
      <div class="nav-item"><span class="nav-icon">📦</span> Ndinelao</div>
    </div>
    <div class="nav-divider"></div>
    <div class="sidebar-section">
      <div class="sidebar-label">Sections</div>
      <div class="nav-item" onclick="showSection('orders')"><span class="nav-icon">🛒</span> Order Board</div>
      <div class="nav-item" onclick="showSection('bookkeeping')"><span class="nav-icon">📒</span> Bookkeeping</div>
      <div class="nav-item" onclick="showSection('courier')"><span class="nav-icon">🚚</span> Courier Waybills</div>
      <div class="nav-item" onclick="showSection('tasks')"><span class="nav-icon">✅</span> Task Management</div>
      <div class="nav-item" onclick="showSection('errors')"><span class="nav-icon">⚠</span> Error Log</div>
      <div class="nav-item" onclick="showSection('picking')"><span class="nav-icon">📋</span> Picking List</div>
      <div class="nav-item" onclick="showSection('attendance')"><span class="nav-icon">🕐</span> Attendance</div>
    </div>
  </aside>
  <main class="main">
    <?php if (!$ready): ?><div class="offline-note">Operations tables are not available yet, so this dashboard cannot load live data.</div><?php endif; ?>
    <div class="profile-strip">
      <div class="avatar"><?= hp_e($initial) ?></div>
      <div class="profile-info">
        <div class="profile-name"><?= hp_e($ceciliaName) ?></div>
        <div class="profile-role"><?= hp_e((string) ($cecilia['role_name'] ?? 'Customer Service & Operations Supervisor')) ?></div>
        <div class="profile-meta">
          <div class="meta-item"><strong>Portal logins this month</strong> <?= number_format($daysPresent) ?>/<?= number_format(count($workdays)) ?> days</div>
          <div class="meta-item"><strong>Avg login time</strong> <?= hp_e($avgLoginTime) ?></div>
          <div class="meta-item"><strong>Orders visible</strong> <?= number_format(count($orders)) ?></div>
          <div class="meta-item"><strong>Employee ID</strong> <?= $ceciliaId > 0 ? number_format($ceciliaId) : 'Not linked' ?></div>
        </div>
      </div>
      <div class="profile-actions">
        <a class="btn btn-outline btn-sm" href="<?= hp_e(BASE_URL . '/apps/operations/reports.php') ?>">KPI Reports</a>
        <a class="btn btn-primary btn-sm" href="<?= hp_e(BASE_URL . '/apps/operations/employees.php') ?>">Employee File</a>
      </div>
    </div>
    <div class="section-tabs">
      <div class="sec-tab active" onclick="showSection('orders')">🛒 Orders</div>
      <div class="sec-tab" onclick="showSection('bookkeeping')">📒 Bookkeeping</div>
      <div class="sec-tab" onclick="showSection('courier')">🚚 Courier</div>
      <div class="sec-tab" onclick="showSection('tasks')">✅ Tasks</div>
      <div class="sec-tab" onclick="showSection('errors')">⚠ Errors</div>
      <div class="sec-tab" onclick="showSection('picking')">📋 Picking List</div>
      <div class="sec-tab" onclick="showSection('attendance')">🕐 Attendance</div>
    </div>

    <div class="section active" id="sec-orders">
      <div class="section-heading">Order Board</div>
      <div class="section-sub">Tracking order completion time, walk-in fulfilment, payment status, and processing speed.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-5">
        <div class="stat-card"><div class="stat-label">Total Orders</div><div class="stat-value"><?= number_format(count($orders)) ?></div><div class="stat-sub">this month</div></div>
        <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-value"><?= number_format(count($completedOrders)) ?></div><div class="badge bg-good"><?= hp_pct(count($completedOrders), count($orders)) ?>% completion</div></div>
        <div class="stat-card"><div class="stat-label">Still In Progress</div><div class="stat-value"><?= number_format(count($activeOrders)) ?></div><div class="badge bg-warn">Not yet marked done</div></div>
        <div class="stat-card"><div class="stat-label">Avg Order to Complete</div><div class="stat-value"><?= hp_e(hp_duration(hp_avg($walkDurations))) ?></div><div class="stat-sub">walk-in orders</div></div>
        <div class="stat-card"><div class="stat-label">Unpaid Orders</div><div class="stat-value"><?= number_format(count($unpaidOrders)) ?></div><div class="badge bg-warn">Payment not ticked</div></div>
      </div>
      <div class="two-col">
        <div class="card"><div class="card-header"><div class="card-title">Walk-in Orders - Completion Time</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Order</th><th>Loaded</th><th>Completed</th><th>Duration</th><th>Status</th></tr></thead><tbody>
          <?php if (!$walkInOrders): ?><?= hp_empty(5) ?><?php endif; ?>
          <?php foreach (array_slice($walkInOrders, 0, 8) as $row): $doneAt = $row['completed_at'] ?: ((string) $row['status'] === 'completed' ? $row['updated_at'] : null); $mins = hp_minutes_between($row['created_at'] ?? null, $doneAt); ?>
          <tr><td><div class="tname"><?= hp_e($row['order_number'] ?: ('#' . $row['id'])) ?> <?= hp_e($row['customer_name'] ?? '') ?></div></td><td class="tmono"><?= hp_dt($row['created_at'] ?? null) ?></td><td class="tmono"><?= hp_dt($doneAt) ?></td><td class="tmono"><?= hp_e($doneAt ? hp_duration($mins) : 'Ongoing') ?></td><td><?= hp_tag(ucwords(str_replace('_', ' ', (string) $row['status'])), in_array((string) $row['status'], $completedStatuses, true) ? 'good' : 'warn') ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card"><div class="card-header"><div class="card-title">Payment Status Tracking</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Order</th><th>Loaded</th><th>Paid Ticked</th><th>Delay</th><th>Status</th></tr></thead><tbody>
          <?php if (!$orders): ?><?= hp_empty(5) ?><?php endif; ?>
          <?php foreach (array_slice($orders, 0, 8) as $row): $paidAt = $row['paid_at_value'] ?? null; $paid = (string) ($row['payment_status'] ?? '') === 'paid'; ?>
          <tr><td><div class="tname"><?= hp_e($row['order_number'] ?: ('#' . $row['id'])) ?></div></td><td class="tmono"><?= hp_dt($row['created_at'] ?? null) ?></td><td class="tmono"><?= $paid ? hp_dt($paidAt) : '-' ?></td><td class="tmono"><?= $paid ? hp_e(hp_duration(hp_minutes_between($row['created_at'] ?? null, $paidAt))) : hp_e(hp_duration(hp_minutes_between($row['created_at'] ?? null, date('Y-m-d H:i:s'))) . '+') ?></td><td><?= hp_tag($paid ? 'Paid' : 'Unpaid', $paid ? 'good' : 'danger') ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Orders Still In Progress</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Order ID</th><th>Type</th><th>Date Loaded</th><th>Time Open</th><th>Paid</th><th>Amount</th></tr></thead><tbody>
        <?php if (!$activeOrders): ?><?= hp_empty(6, 'No active orders found') ?><?php endif; ?>
        <?php foreach (array_slice($activeOrders, 0, 12) as $row): ?>
        <tr><td><div class="tname"><?= hp_e($row['order_number'] ?: ('#' . $row['id'])) ?></div></td><td><?= hp_tag(ucwords((string) $row['order_type'])) ?></td><td class="tmono"><?= hp_dt($row['created_at'] ?? null) ?></td><td class="tmono"><?= hp_duration(hp_minutes_between($row['created_at'] ?? null, date('Y-m-d H:i:s'))) ?></td><td><?= hp_tag((string) ($row['payment_status'] ?? '') === 'paid' ? 'Yes' : 'No', (string) ($row['payment_status'] ?? '') === 'paid' ? 'good' : 'danger') ?></td><td class="tmono"><?= hp_money($row['total_amount'] ?? 0) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div></div>
    </div>

    <div class="section" id="sec-bookkeeping">
      <div class="section-heading">Bookkeeping</div>
      <div class="section-sub">Tracking how quickly delivery orders and cash transactions are logged onto the bookkeeping sheet.</div>
      <?= $monthNav ?>
      <?php $loggedDelivery = array_values(array_filter($bookkeepingJoined, static fn($r) => !empty($r['transaction_date']))); $lateDelivery = array_values(array_filter($loggedDelivery, static fn($r) => (int) ($r['delay_minutes'] ?? 0) > 1440)); ?>
      <div class="stats-row cols-5">
        <div class="stat-card"><div class="stat-label">Delivery Orders</div><div class="stat-value"><?= number_format(count($bookkeepingJoined)) ?></div><div class="stat-sub">to be logged</div></div>
        <div class="stat-card"><div class="stat-label">Logged on Time</div><div class="stat-value"><?= number_format(max(0, count($loggedDelivery) - count($lateDelivery))) ?></div><div class="badge bg-good">Within same day</div></div>
        <div class="stat-card"><div class="stat-label">Late Entries</div><div class="stat-value"><?= number_format(count($lateDelivery)) ?></div><div class="badge bg-warn">Logged next day+</div></div>
        <div class="stat-card"><div class="stat-label">Cash Orders Missing</div><div class="stat-value"><?= number_format(count($missingCashOrders)) ?></div><div class="badge bg-danger">Never uploaded</div></div>
        <div class="stat-card"><div class="stat-label">Avg Log Delay</div><div class="stat-value"><?= hp_e(hp_duration(hp_avg(array_column($loggedDelivery, 'delay_minutes')))) ?></div><div class="stat-sub">order placed to logged</div></div>
      </div>
      <div class="two-col">
        <div class="card"><div class="card-header"><div class="card-title">Delivery Orders to Bookkeeping Log Time</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Order</th><th>Order Date</th><th>Logged</th><th>Delay</th><th>Status</th></tr></thead><tbody>
          <?php if (!$bookkeepingJoined): ?><?= hp_empty(5) ?><?php endif; ?>
          <?php foreach (array_slice($bookkeepingJoined, 0, 10) as $row): $logged = !empty($row['transaction_date']); $delay = $logged ? (float) $row['delay_minutes'] : null; ?>
          <tr><td><div class="tname"><?= hp_e($row['order_number']) ?></div></td><td class="tmono"><?= hp_dt($row['created_at']) ?></td><td class="tmono"><?= hp_dt($row['transaction_date'] ?? null) ?></td><td class="tmono"><?= $logged ? hp_duration($delay) : '-' ?></td><td><?= hp_tag(!$logged ? 'Missing' : ($delay > 1440 ? 'Late' : 'On time'), !$logged ? 'danger' : ($delay > 1440 ? 'warn' : 'good')) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card alert-card"><div class="card-header alert-header"><div class="alert-title">Cash Orders Not Uploaded</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Order</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>
          <?php if (!$missingCashOrders): ?><?= hp_empty(4, 'No missing cash orders found') ?><?php endif; ?>
          <?php foreach (array_slice($missingCashOrders, 0, 10) as $row): ?><tr><td><div class="tname"><?= hp_e($row['order_number']) ?></div></td><td class="tmono"><?= hp_day($row['created_at']) ?></td><td class="tmono"><?= hp_money($row['total_amount']) ?></td><td><?= hp_tag('Never logged', 'danger') ?></td></tr><?php endforeach; ?>
        </tbody></table><div style="padding:14px 18px;font-size:12.5px;color:var(--text-secondary)">Total unaccounted cash: <strong style="color:var(--danger)"><?= hp_money(array_sum(array_map('floatval', array_column($missingCashOrders, 'total_amount')))) ?></strong></div></div></div>
      </div>
    </div>

    <div class="section" id="sec-courier">
      <div class="section-heading">Courier Waybills</div>
      <div class="section-sub">Tracking time from waybill upload to customer notification. SLA: same day before 5pm, or next working day before 9am.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-5">
        <div class="stat-card"><div class="stat-label">Waybills Uploaded</div><div class="stat-value"><?= number_format(count($waybills)) ?></div><div class="stat-sub">by packers</div></div>
        <div class="stat-card"><div class="stat-label">Sent on Time</div><div class="stat-value"><?= number_format(max(0, $waybillSent - $waybillBreaches)) ?></div><div class="badge bg-good">Before SLA</div></div>
        <div class="stat-card"><div class="stat-label">SLA Breaches</div><div class="stat-value"><?= number_format($waybillBreaches) ?></div><div class="badge bg-danger">After 5pm / 9am</div></div>
        <div class="stat-card"><div class="stat-label">Not Yet Sent</div><div class="stat-value"><?= number_format($waybillPending) ?></div><div class="badge bg-danger">Pending</div></div>
        <div class="stat-card"><div class="stat-label">Avg Send Time</div><div class="stat-value"><?= hp_e(hp_duration(hp_avg($waybillDurations))) ?></div><div class="stat-sub">upload to sent</div></div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Waybill Log - Upload to Customer Sent</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Waybill</th><th>Uploaded</th><th>Sent to Customer</th><th>Duration</th><th>SLA</th><th>Status</th></tr></thead><tbody>
        <?php if (!$waybills): ?><?= hp_empty(6) ?><?php endif; ?>
        <?php foreach ($waybills as $row): $due = hp_sla_due((string) $row['uploaded_at']); $sent = $row['sent_at'] ?? null; $breach = $due && (($sent && strtotime((string) $sent) > $due) || (!$sent && time() > $due)); ?>
        <tr><td><div class="tname"><?= hp_e($row['waybill_reference'] ?: $row['original_filename'] ?: ('#' . $row['id'])) ?></div></td><td class="tmono"><?= hp_dt($row['uploaded_at']) ?></td><td class="tmono"><?= hp_dt($sent) ?></td><td class="tmono"><?= $sent ? hp_duration(hp_minutes_between($row['uploaded_at'], $sent)) : 'Pending' ?></td><td><?= hp_tag($breach ? 'SLA breach' : ($sent ? 'On time' : 'Open'), $breach ? 'danger' : ($sent ? 'good' : 'warn')) ?></td><td><?= hp_tag(ucwords((string) ($row['status'] ?? 'uploaded')), $sent ? 'good' : 'warn') ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div></div>
    </div>

    <div class="section" id="sec-tasks">
      <div class="section-heading">Task Management</div>
      <div class="section-sub">Tracking task completion rate, speed, and overdue items assigned to Cecilia.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-5">
        <div class="stat-card"><div class="stat-label">Total Tasks</div><div class="stat-value"><?= number_format(count($tasks)) ?></div><div class="stat-sub">this month</div></div>
        <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-value"><?= number_format($taskCounts['done']) ?></div><div class="badge bg-good"><?= hp_pct($taskCounts['done'], count($tasks)) ?>% done</div></div>
        <div class="stat-card"><div class="stat-label">In Progress</div><div class="stat-value"><?= number_format($taskCounts['in_progress']) ?></div><div class="badge bg-warn">Ongoing</div></div>
        <div class="stat-card"><div class="stat-label">Not Started</div><div class="stat-value"><?= number_format($taskCounts['not_started']) ?></div><div class="badge bg-warn">Pending</div></div>
        <div class="stat-card"><div class="stat-label">Overdue</div><div class="stat-value"><?= number_format($taskCounts['overdue']) ?></div><div class="badge bg-danger">Past due date</div></div>
      </div>
      <div class="task-pills"><span class="task-pill pill-done">Completed: <?= number_format($taskCounts['done']) ?></span><span class="task-pill pill-progress">In Progress: <?= number_format($taskCounts['in_progress']) ?></span><span class="task-pill pill-pending">Not Started: <?= number_format($taskCounts['not_started']) ?></span><span class="task-pill pill-overdue">Overdue: <?= number_format($taskCounts['overdue']) ?></span></div>
      <div class="card"><div class="card-header"><div class="card-title">Task Log</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Task</th><th>Assigned</th><th>Due Date</th><th>Completed</th><th>Duration</th><th>Status</th></tr></thead><tbody>
        <?php if (!$tasks): ?><?= hp_empty(6) ?><?php endif; ?>
        <?php foreach ($tasks as $task): $doneAt = $task['date_completed'] ?? $task['completed_at'] ?? null; $assignedAt = $task['date_assigned'] ?? $task['created_at'] ?? null; $status = (string) ($task['status'] ?? 'not_started'); $overdue = !$doneAt && !empty($task['deadline']) && strtotime((string) $task['deadline']) < time(); ?>
        <tr><td><div class="tname"><?= hp_e($task['task_name'] ?? 'Task') ?></div></td><td class="tmono"><?= hp_day($assignedAt) ?></td><td class="tmono"><?= hp_dt($task['deadline'] ?? null) ?></td><td class="tmono"><?= hp_dt($doneAt) ?></td><td class="tmono"><?= $doneAt ? hp_duration(hp_minutes_between($assignedAt, $doneAt)) : 'Ongoing' ?></td><td><?= hp_tag($overdue ? 'Overdue' : ucwords(str_replace('_', ' ', $status)), $overdue ? 'danger' : (in_array(strtolower($status), ['done', 'completed', 'complete'], true) ? 'good' : 'warn')) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div></div>
    </div>

    <div class="section" id="sec-errors">
      <div class="section-heading">Error Log</div>
      <div class="section-sub">Errors Cecilia has logged (with notes and tagging) vs errors logged against her.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-4">
        <div class="stat-card"><div class="stat-label">Errors She Logged</div><div class="stat-value"><?= number_format(count($errorsLogged)) ?></div><div class="badge bg-good">This month</div></div>
        <div class="stat-card"><div class="stat-label">Properly Completed</div><div class="stat-value"><?= number_format($completeErrorLogs) ?></div><div class="stat-sub">Notes + employee tagged</div><div class="badge bg-good"><?= hp_pct($completeErrorLogs, count($errorsLogged)) ?>% quality</div></div>
        <div class="stat-card"><div class="stat-label">Incomplete Logs</div><div class="stat-value"><?= number_format(max(0, count($errorsLogged) - $completeErrorLogs)) ?></div><div class="badge bg-warn">Missing notes or tag</div></div>
        <div class="stat-card"><div class="stat-label">Errors Against Her</div><div class="stat-value"><?= number_format(count($errorsAgainst)) ?></div><div class="badge bg-danger">Logged by others</div></div>
      </div>
      <div class="two-col">
        <div class="card"><div class="card-header"><div class="card-title">Errors Logged by Cecilia</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Date</th><th>Description</th><th>Employee Tagged</th><th>Notes</th><th>Quality</th></tr></thead><tbody>
          <?php if (!$errorsLogged): ?><?= hp_empty(5) ?><?php endif; ?>
          <?php foreach ($errorsLogged as $err): $hasNote = trim((string) ($err['description'] ?? '')) !== ''; $tagged = (int) ($err['employee_id'] ?? 0) > 0; ?>
          <tr><td class="tmono"><?= hp_day($err['logged_at'] ?? null) ?></td><td><div class="tname"><?= hp_e($err['category'] ?: $err['description']) ?></div></td><td><?= hp_tag($tagged ? (string) ($err['responsible_name'] ?? 'Tagged') : 'Not tagged', $tagged ? '' : 'warn') ?></td><td><?= hp_tag($hasNote ? 'Yes' : 'No', $hasNote ? 'good' : 'danger') ?></td><td><?= hp_tag($hasNote && $tagged ? 'Complete' : 'Incomplete', $hasNote && $tagged ? 'good' : 'warn') ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card"><div class="card-header"><div class="card-title">Errors Logged Against Cecilia</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Date</th><th>Error</th><th>Logged By</th><th>Status</th></tr></thead><tbody>
          <?php if (!$errorsAgainst): ?><?= hp_empty(4) ?><?php endif; ?>
          <?php foreach ($errorsAgainst as $err): ?><tr><td class="tmono"><?= hp_day($err['logged_at'] ?? null) ?></td><td><div class="tname"><?= hp_e($err['category'] ?: $err['description']) ?></div></td><td><?= hp_tag($err['logged_by_name'] ?? 'System') ?></td><td><?= hp_tag((string) ($err['resolution'] ?? '') !== '' ? 'Resolved' : 'Unresolved', (string) ($err['resolution'] ?? '') !== '' ? 'good' : 'danger') ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
      </div>
    </div>

    <div class="section" id="sec-picking">
      <div class="section-heading">Picking List</div>
      <div class="section-sub">Products loaded onto the system. Cecilia must update stock quantities on the website within 24 hours of loading.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-5">
        <div class="stat-card"><div class="stat-label">Products Loaded</div><div class="stat-value"><?= number_format(count($packingRows)) ?></div><div class="stat-sub">this month</div></div>
        <div class="stat-card"><div class="stat-label">Website Updated</div><div class="stat-value"><?= number_format($websiteOnTime) ?></div><div class="badge bg-good">Within 24h window</div></div>
        <div class="stat-card"><div class="stat-label">Updated Late</div><div class="stat-value"><?= number_format($websiteLate) ?></div><div class="badge bg-warn">After 24h window</div></div>
        <div class="stat-card"><div class="stat-label">Not Updated</div><div class="stat-value"><?= number_format($websitePending) ?></div><div class="badge bg-danger">Still outstanding</div></div>
        <div class="stat-card"><div class="stat-label">Avg Update Time</div><div class="stat-value"><?= hp_e(hp_duration(hp_avg($websiteDurations))) ?></div><div class="stat-sub">loaded to website updated</div></div>
      </div>
      <div class="card"><div class="card-header"><div class="card-title">Picking List - Website Inventory Update Tracker</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Product</th><th>Qty Loaded</th><th>Date Loaded</th><th>Website Updated</th><th>Time Taken</th><th>Within 24h</th><th>Inventory Update</th></tr></thead><tbody>
        <?php if (!$packingRows): ?><?= hp_empty(7) ?><?php endif; ?>
        <?php foreach ($packingRows as $row): $loaded = $row['date_loaded'] ?? $row['created_at'] ?? null; $uploadedAt = $row['website_uploaded_at'] ?? ((int) ($row['website_uploaded'] ?? 0) === 1 ? $row['updated_at'] ?? null : null); $mins = hp_minutes_between($loaded, $uploadedAt ?: date('Y-m-d H:i:s')); $done = (int) ($row['website_uploaded'] ?? 0) === 1; $late = $mins !== null && $mins > 1440; ?>
        <tr><td><div class="tname"><?= hp_e($row['item_name'] ?? 'Packing item') ?></div></td><td class="tmono"><?= hp_e($row['quantity_planned'] ?? '-') ?></td><td class="tmono"><?= hp_dt($loaded) ?></td><td class="tmono"><?= hp_dt($uploadedAt) ?></td><td class="tmono"><?= hp_duration($mins) ?><?= !$done ? ' (ongoing)' : '' ?></td><td><?= hp_tag($done && !$late ? 'Yes' : ($done ? 'No - Late' : ($late ? 'No - Overdue' : 'Open')), $done && !$late ? 'good' : ($late ? 'danger' : 'warn')) ?></td><td><form class="update-form" method="post"><input type="hidden" name="action" value="toggle_website_update"><input type="hidden" name="packing_task_id" value="<?= (int) $row['id'] ?>"><input type="checkbox" name="website_uploaded" value="1" <?= $done ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= $done ? 'Complete' : 'Not Done' ?></span></form></td></tr>
        <?php endforeach; ?>
      </tbody></table></div></div>
    </div>

    <div class="section" id="sec-attendance">
      <div class="section-heading">Attendance &amp; Punctuality</div>
      <div class="section-sub">Portal login times, physical attendance, punctuality patterns, and overtime averages.</div>
      <?= $monthNav ?>
      <div class="stats-row cols-6">
        <div class="stat-card"><div class="stat-label">Days Present</div><div class="stat-value"><?= number_format($daysPresent) ?><span style="font-size:14px;color:var(--text-muted)">/<?= number_format(count($workdays)) ?></span></div><div class="badge bg-warn"><?= hp_pct($daysPresent, count($workdays)) ?>%</div></div>
        <div class="stat-card"><div class="stat-label">Late Arrivals</div><div class="stat-value"><?= number_format($lateLogins) ?></div><div class="badge bg-warn">This month</div></div>
        <div class="stat-card"><div class="stat-label">Portal Logins</div><div class="stat-value"><?= number_format(count($loginRows)) ?></div><div class="stat-sub">login events</div></div>
        <div class="stat-card"><div class="stat-label">Avg Login Time</div><div class="stat-value" style="font-size:20px"><?= hp_e($avgLoginTime) ?></div><div class="badge bg-good">Target before 08:15</div></div>
        <div class="stat-card"><div class="stat-label">Early Logins</div><div class="stat-value"><?= number_format($earlyLogins) ?></div><div class="stat-sub">before 08:00</div><div class="badge bg-good">Good habit</div></div>
        <div class="stat-card"><div class="stat-label">Unplanned Absences</div><div class="stat-value"><?= number_format($absentDays) ?></div><div class="stat-sub">based on login record</div></div>
      </div>
      <div class="two-col">
        <div class="card"><div class="card-header"><div class="card-title">Attendance Calendar - <?= hp_e($monthLabel) ?></div></div><div class="card-body"><div class="attend-grid"><div class="day-lbl">M</div><div class="day-lbl">T</div><div class="day-lbl">W</div><div class="day-lbl">T</div><div class="day-lbl">F</div><div class="day-lbl">S</div><div class="day-lbl">S</div>
          <?php $firstDow = (int) $monthStart->format('N'); for ($i = 1; $i < $firstDow; $i++): ?><div class="ad ad-o">-</div><?php endfor; ?>
          <?php for ($d = $monthStart; $d < $monthEnd; $d = $d->modify('+1 day')): $key = $d->format('Y-m-d'); $off = (int) $d->format('N') >= 7; $present = isset($firstLoginByDay[$key]); $late = $present && substr((string) $firstLoginByDay[$key]['login_at'], 11, 5) > '08:15'; $future = $d > new DateTimeImmutable('today 23:59:59'); $cls = $off || $future ? 'ad-o' : ($present ? ($late ? 'ad-l' : 'ad-p') : 'ad-a'); ?>
          <div class="ad <?= $cls ?>"><?= $off || $future ? '-' : ($present ? ($late ? 'L' : '✓') : '×') ?></div>
          <?php endfor; ?>
        </div><div class="legend"><div class="leg-item"><div class="leg-dot" style="background:var(--accent)"></div>Present</div><div class="leg-item"><div class="leg-dot" style="background:var(--warn)"></div>Late</div><div class="leg-item"><div class="leg-dot" style="background:var(--danger)"></div>Absent</div><div class="leg-item"><div class="leg-dot" style="background:var(--tag-bg)"></div>Off/Future</div></div></div></div>
        <div class="card"><div class="card-header"><div class="card-title">Portal Login Time Log</div></div><div class="card-body table-wrap" style="padding:0"><table class="data-table"><thead><tr><th>Date</th><th>Login Time</th><th>On Time</th><th>Note</th></tr></thead><tbody>
          <?php if (!$firstLoginByDay): ?><?= hp_empty(4) ?><?php endif; ?>
          <?php foreach (array_slice($firstLoginByDay, 0, 12, true) as $day => $row): $time = substr((string) $row['login_at'], 11, 5); ?>
          <tr><td class="tmono"><?= hp_e(date('d M (D)', strtotime($day))) ?></td><td class="tmono"><?= hp_e($time) ?></td><td><?= hp_tag($time <= '08:00' ? 'Early' : ($time <= '08:15' ? 'On time' : 'Late'), $time <= '08:15' ? 'good' : 'warn') ?></td><td><?= $time > '08:15' ? 'No explanation logged' : '-' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
      </div>
    </div>
  </main>
</div>
<script>
  function showSection(name) {
    document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.sec-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('sec-' + name).classList.add('active');
    var tabs = document.querySelectorAll('.sec-tab');
    var map = { orders: 0, bookkeeping: 1, courier: 2, tasks: 3, errors: 4, picking: 5, attendance: 6 };
    tabs[map[name]].classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  if (window.location.hash === '#picking') showSection('picking');
</script>
</body>
</html>
