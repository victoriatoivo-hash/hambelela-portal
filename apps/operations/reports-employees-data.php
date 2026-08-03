<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

try {
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $zone = new DateTimeZone('Africa/Windhoek');
    $resolvedPeriod = kpi_resolve_reporting_period($_GET);
    $period = $resolvedPeriod['key'];
    $from = $resolvedPeriod['from'];
    $to = $resolvedPeriod['to'];
    $settings = [];
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    $dataStart = new DateTimeImmutable($settings['data_start_date'] ?? '2026-07-01', $zone);
    $adoption = new DateTimeImmutable($settings['adoption_date'] ?? '2026-07-14', $zone);
    $effective = $from < $dataStart ? $dataStart : $from;
    $fromSql = $effective->format('Y-m-d 00:00:00'); $toSql = $to->format('Y-m-d 23:59:59');
    $employees = ops_rows(
        "SELECT e.id, e.full_name, r.name role_name, r.role_key,
                COALESCE(sess.hours,0) hours, COALESCE(pack.items,0) items, COALESCE(pack.points,0) points,
                COALESCE(pack.open_items,0) open_items, COALESCE(ord.orders_done,0) orders_done,
                COALESCE(web.updates_done,0) updates_done,
                EXISTS(SELECT 1 FROM ops_board_presence bp WHERE bp.employee_id=e.id AND bp.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)) online
         FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
         LEFT JOIN (SELECT user_id, SUM(TIMESTAMPDIFF(MINUTE,login_at,COALESCE(logout_at,last_seen_at)))/60 hours FROM kpi_sessions WHERE login_at BETWEEN ? AND ? GROUP BY user_id) sess ON sess.user_id=e.id
         LEFT JOIN (SELECT assigned_employee_id, COUNT(*) items, COALESCE(SUM(workload_points),0) points, SUM(packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created')) open_items FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id) pack ON pack.assigned_employee_id=e.id
         LEFT JOIN (SELECT assigned_packer_id, COUNT(DISTINCT id) orders_done FROM ops_orders WHERE assigned_packer_id IS NOT NULL AND status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ? GROUP BY assigned_packer_id) ord ON ord.assigned_packer_id=e.id
         LEFT JOIN (SELECT frontdesk_website_updated_by, COUNT(DISTINCT id) updates_done FROM ops_packing_tasks WHERE frontdesk_website_updated=1 AND frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY frontdesk_website_updated_by) web ON web.frontdesk_website_updated_by=e.id
         WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY r.role_key,CASE WHEN r.role_key='packer' THEN COALESCE(pack.points,0) END DESC,e.full_name",
        [$fromSql,$toSql,$fromSql,$toSql,$fromSql,$toSql,$fromSql,$toSql]
    );
    $spark = ops_rows("SELECT assigned_employee_id employee_id, DATE(date_completed) day, COALESCE(SUM(workload_points),0) points FROM ops_packing_tasks WHERE date_completed>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) AND deleted_at IS NULL GROUP BY assigned_employee_id,DATE(date_completed) ORDER BY day");
    kpi_send_json(['ok'=>true,'period'=>kpi_period_response($resolvedPeriod,$adoption,$effective),'employees'=>$employees,'spark'=>$spark,'scores_disabled'=>true,'last_refreshed_at'=>(new DateTimeImmutable('now',$zone))->format(DATE_ATOM)]);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM).' employee index: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_send_json(['ok'=>false,'success'=>false,'data'=>null,'message'=>'Employee performance is temporarily unavailable.','error_code'=>'KPI_EMPLOYEE_INDEX_FAILED'],500);
}
