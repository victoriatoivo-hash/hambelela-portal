<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

try {
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $zone = new DateTimeZone('Africa/Windhoek');
    $today = new DateTimeImmutable('today', $zone);
    $period = (string) ($_GET['period'] ?? 'today');
    switch ($period) {
        case 'yesterday': $from = $today->modify('-1 day'); $to = $from; break;
        case 'this_week': $from = $today->modify('monday this week'); $to = $today; break;
        case 'last_week': $from = $today->modify('monday last week'); $to = $from->modify('+6 days'); break;
        case 'this_month': $from = $today->modify('first day of this month'); $to = $today; break;
        case 'last_month': $from = $today->modify('first day of last month'); $to = $from->modify('last day of this month'); break;
        case 'custom':
            $from = new DateTimeImmutable((string) ($_GET['date_from'] ?? ''), $zone); $to = new DateTimeImmutable((string) ($_GET['date_to'] ?? ''), $zone); break;
        default: $period = 'today'; $from = $today; $to = $today;
    }
    if ($to < $from) throw new RuntimeException('The end date must be on or after the start date.');
    $settings = [];
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    $dataStart = new DateTimeImmutable($settings['data_start_date'] ?? '2026-07-01', $zone);
    $adoption = new DateTimeImmutable($settings['adoption_date'] ?? '2026-07-14', $zone);
    $effective = $from < $dataStart ? $dataStart : $from;
    $fromSql = $effective->format('Y-m-d 00:00:00'); $toSql = $to->format('Y-m-d 23:59:59');
    $pointS = (float) ($settings['weight_points_s'] ?? 1); $pointM = (float) ($settings['weight_points_m'] ?? 3); $pointL = (float) ($settings['weight_points_l'] ?? 6); $pointXl = (float) ($settings['weight_points_xl'] ?? 10);
    $employees = ops_rows(
        "SELECT e.id, e.full_name, r.name role_name, r.role_key,
                COALESCE(sess.hours,0) hours, COALESCE(pack.items,0) items, COALESCE(pack.points,0) points,
                COALESCE(pack.open_items,0) open_items, COALESCE(ord.orders_done,0) orders_done,
                COALESCE(web.updates_done,0) updates_done,
                EXISTS(SELECT 1 FROM ops_board_presence bp WHERE bp.employee_id=e.id AND bp.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)) online
         FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
         LEFT JOIN (SELECT user_id, SUM(TIMESTAMPDIFF(MINUTE,login_at,COALESCE(logout_at,last_seen_at)))/60 hours FROM kpi_sessions WHERE login_at BETWEEN ? AND ? GROUP BY user_id) sess ON sess.user_id=e.id
         LEFT JOIN (SELECT assigned_employee_id, COUNT(*) items, SUM(CASE weight_class WHEN 'S' THEN ? WHEN 'M' THEN ? WHEN 'L' THEN ? WHEN 'XL' THEN ? ELSE ? END) points, SUM(packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created')) open_items FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id) pack ON pack.assigned_employee_id=e.id
         LEFT JOIN (SELECT changed_by, COUNT(DISTINCT record_id) orders_done FROM kpi_status_events WHERE module='order' AND new_status='completed' AND changed_at BETWEEN ? AND ? GROUP BY changed_by) ord ON ord.changed_by=e.id
         LEFT JOIN (SELECT changed_by, COUNT(*) updates_done FROM kpi_status_events WHERE module='website_update' AND new_status='complete' AND changed_at BETWEEN ? AND ? GROUP BY changed_by) web ON web.changed_by=e.id
         WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY r.role_key,e.full_name",
        [$fromSql,$toSql,$pointS,$pointM,$pointL,$pointXl,$pointM,$fromSql,$toSql,$fromSql,$toSql,$fromSql,$toSql]
    );
    $spark = ops_rows("SELECT assigned_employee_id employee_id, DATE(date_completed) day, SUM(CASE weight_class WHEN 'S' THEN ? WHEN 'M' THEN ? WHEN 'L' THEN ? WHEN 'XL' THEN ? ELSE ? END) points FROM ops_packing_tasks WHERE date_completed>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) AND deleted_at IS NULL GROUP BY assigned_employee_id,DATE(date_completed) ORDER BY day", [$pointS,$pointM,$pointL,$pointXl,$pointM]);
    echo json_encode(['ok'=>true,'period'=>['key'=>$period,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'adoption_date'=>$adoption->format('Y-m-d'),'show_adoption_banner'=>$from<$adoption],'employees'=>$employees,'spark'=>$spark], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM).' employee index: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Employee performance is temporarily unavailable.']);
}
