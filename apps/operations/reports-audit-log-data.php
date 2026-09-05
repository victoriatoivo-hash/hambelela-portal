<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once __DIR__.'/kpi-reporting.php';
require_role('owner_admin');
// Audit history does not depend on operational scoring services or their caches.
try {
    $period = kpi_resolve_reporting_period($_GET);
    $from = $period['from'];
    $to = $period['to'];
    $rows = ops_rows('SELECT l.created_at,l.action,l.entity_type,l.entity_id,l.metadata,l.ip_address,e.full_name employee FROM ops_activity_logs l LEFT JOIN ops_employees e ON e.id=l.employee_id WHERE l.created_at BETWEEN ? AND ? ORDER BY l.created_at DESC,l.id DESC LIMIT 500', [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')]);
    kpi_send_json(['ok'=>true,'section'=>'audit-log','period'=>['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'show_adoption_banner'=>false], 'cards'=>[['label'=>'Recent actions shown (up to 500)','value'=>count($rows)]], 'rows'=>$rows,'breakdown'=>[],'funnel'=>[],'overdue'=>[]]);
} catch (Throwable $error) {
    error_log('Performance audit log: '.$error->getMessage());
    kpi_send_json(['ok'=>false,'message'=>'Audit history could not be loaded. Please refresh or contact the administrator.','error_code'=>'AUDIT_LOG_FAILED'],500);
}
