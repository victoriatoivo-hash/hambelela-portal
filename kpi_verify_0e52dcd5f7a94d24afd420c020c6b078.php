<?php

declare(strict_types=1);

require_once __DIR__ . '/apps/operations/operations.php';
require_once __DIR__ . '/apps/operations/kpi-reporting.php';

header('Content-Type: application/json; charset=utf-8');
const KPI_VERIFY_TOKEN = '0e52dcd5f7a94d24afd420c020c6b078';
if (!isset($_GET['token']) || !hash_equals(KPI_VERIFY_TOKEN, (string) $_GET['token'])) {
    http_response_code(404);
    exit;
}

$from = '2026-07-01 00:00:00';
$to = '2026-07-31 23:59:59';
$paid = kpi_paid_revenue_condition('o');

try {
    $orders = ops_rows("SELECT COUNT(DISTINCT o.id) total_orders, COUNT(DISTINCT CASE WHEN o.status IN ('completed','packed','verified') THEN o.id END) completed_orders, COUNT(DISTINCT CASE WHEN {$paid} THEN o.id END) paid_orders, COALESCE(SUM(CASE WHEN {$paid} THEN o.total_amount ELSE 0 END),0) paid_revenue FROM ops_orders o WHERE o.created_at BETWEEN ? AND ?", [$from, $to])[0] ?? [];
    $packerOrders = ops_rows("SELECT e.id employee_id,e.full_name,COUNT(DISTINCT o.id) packed_orders FROM ops_employees e LEFT JOIN ops_orders o ON o.assigned_packer_id=e.id AND o.status IN ('completed','packed','verified') AND o.completed_at BETWEEN ? AND ? WHERE LOWER(e.full_name) LIKE '%secilia%' OR LOWER(e.full_name) LIKE '%klaudia%' OR LOWER(e.full_name) LIKE '%ndinelao%' GROUP BY e.id,e.full_name ORDER BY e.full_name", [$from, $to]);
    $duplicateEvents = ops_rows("SELECT module,record_id,old_status,new_status,changed_by,COUNT(*) duplicates FROM kpi_status_events WHERE changed_at BETWEEN ? AND ? GROUP BY module,record_id,old_status,new_status,changed_by,DATE_FORMAT(changed_at,'%Y-%m-%d %H:%i:%s') HAVING COUNT(*)>1 ORDER BY duplicates DESC LIMIT 20", [$from, $to]);
    $packing = ops_rows("SELECT COUNT(*) completed_rows,SUM(date_loaded<=date_started AND date_started<=date_completed) valid_timing_rows,SUM(date_started IS NOT NULL AND date_completed IS NOT NULL AND NOT(date_loaded<=date_started AND date_started<=date_completed)) invalid_timing_rows,COUNT(DISTINCT COALESCE(workload_points_override,workload_points)) distinct_point_values,SUM(workload_parse_status='pending_review') pending_review_rows,MIN(COALESCE(workload_points_override,workload_points)) min_points,MAX(COALESCE(workload_points_override,workload_points)) max_points FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL", [$from, $to])[0] ?? [];
    $packingDurations = array_map(static fn(array $row): int => (int) $row['minutes'], ops_rows("SELECT TIMESTAMPDIFF(MINUTE,date_started,date_completed) minutes FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND date_loaded<=date_started AND date_started<=date_completed AND deleted_at IS NULL ORDER BY minutes", [$from, $to]));
    $medianPacking = null;
    if ($packingDurations) {
        $middle = intdiv(count($packingDurations), 2);
        $medianPacking = count($packingDurations) % 2 ? $packingDurations[$middle] : ($packingDurations[$middle - 1] + $packingDurations[$middle]) / 2;
    }
    $website = ops_rows("SELECT SUM(frontdesk_website_updated=1) completed_updates,SUM(frontdesk_website_updated=0) pending_updates,SUM(frontdesk_website_updated_at>=date_loaded) valid_lag_rows,SUM(frontdesk_website_updated_at<date_loaded) invalid_negative_rows,MIN(CASE WHEN frontdesk_website_updated=0 THEN date_loaded END) oldest_pending_loaded_at FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL", [$from, $to])[0] ?? [];
    $errors = ops_rows("SELECT COUNT(*) errors_logged,SUM(responsible_employee_id IS NOT NULL) attributed,SUM(responsible_employee_id IS NULL) unattributed,SUM(affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL) verified_accuracy_errors FROM ops_error_logs WHERE logged_at BETWEEN ? AND ? AND deleted_at IS NULL", [$from, $to])[0] ?? [];
    $schedules = ops_rows("SELECT e.full_name,e.working_days,e.shift_start,e.shift_end,e.late_grace_minutes FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY e.full_name");
    $sessions = ops_rows("SELECT e.full_name,COUNT(*) session_rows,COUNT(DISTINCT DATE(DATE_ADD(s.login_at,INTERVAL 2 HOUR))) presence_days,ROUND(SUM(TIMESTAMPDIFF(SECOND,s.login_at,COALESCE(s.logout_at,s.last_seen_at)))/3600,2) portal_active_hours FROM kpi_sessions s JOIN ops_employees e ON e.id=s.user_id WHERE s.login_at BETWEEN ? AND ? GROUP BY e.id,e.full_name ORDER BY e.full_name", [$from, $to]);
    $bookkeeping = ops_rows("SELECT (SELECT COUNT(*) FROM ops_cash_book_entries WHERE entry_at BETWEEN ? AND ? AND deleted_at IS NULL) live_entries,(SELECT COUNT(*) FROM hambelela_cashbook_recon WHERE recon_date BETWEEN '2026-07-01' AND '2026-07-31') reconciliation_days", [$from, $to])[0] ?? [];
    $tasks = ops_rows("SELECT SUM(status='complete' AND completed_at<=deadline) completed_on_time,SUM(status='complete' AND completed_at>deadline) completed_late,SUM(status<>'complete' AND deadline<NOW()) currently_overdue FROM ops_checklist_tasks WHERE (created_at BETWEEN ? AND ? OR completed_at BETWEEN ? AND ?) AND deleted_at IS NULL", [$from, $to, $from, $to])[0] ?? [];
    $waybills = ops_rows("SELECT SUM(status='sent' AND sent_at<=due_by) sent_on_time,SUM(status='sent' AND sent_at>due_by) sent_late,SUM(status IN ('pending','overdue') AND due_by<NOW()) currently_overdue FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL", [$from, $to, $from, $to])[0] ?? [];
    $hrLeave = [];
    if (function_exists('ops_hr_rows')) {
        $hrLeave = ops_hr_rows("SELECT e.full_name,COUNT(*) approved_requests,COALESCE(SUM(l.days),0) approved_days FROM leave_requests l JOIN employees e ON e.id=l.employee_id WHERE l.status='approved' AND l.start_date<='2026-07-31' AND l.end_date>='2026-07-01' GROUP BY e.id,e.full_name ORDER BY e.full_name");
    }

    echo json_encode([
        'ok' => true,
        'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
        'orders' => $orders,
        'packer_order_credit' => $packerOrders,
        'duplicate_exact_status_events' => $duplicateEvents,
        'packing' => $packing + ['median_elapsed_minutes' => $medianPacking],
        'website_updates' => $website,
        'errors' => $errors,
        'schedules' => $schedules,
        'portal_sessions' => $sessions,
        'bookkeeping' => $bookkeeping,
        'tasks' => $tasks,
        'waybills' => $waybills,
        'hr_leave' => $hrLeave,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
