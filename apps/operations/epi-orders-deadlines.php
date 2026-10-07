<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login();require_role('owner_admin');
header('Cache-Control: no-store');
$rows=[];$message='Orders SLA tracking is not activated.';
try {
    if(\Hambelela\EPI\OrdersStageBridge::enabled(db())) {
        $q=db()->query("SELECT t.order_id,t.classification,t.dispatch_at,t.rush_candidate,t.removed_at,d.module,d.obligation_key,d.starts_at,d.due_at,d.fulfilled_at,d.state,d.breach_incident_uuid,i.responsible_employee_at_breach,i.exclusion_reason,e.full_name FROM epi_v2_orders_tracking t LEFT JOIN epi_v2_operational_deadlines d ON d.object_reference=t.object_reference AND d.module IN('Orders','Packing') LEFT JOIN epi_v2_performance_incidents i ON i.incident_uuid=d.breach_incident_uuid LEFT JOIN ops_employees e ON e.id=i.responsible_employee_at_breach ORDER BY t.order_id DESC,d.id LIMIT 300");
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $message='Shadow review only — official scores unchanged. Latest 300 stage records. Missing ownership requires review, not an assumed deduction.';
    }
} catch(Throwable $error) { $message='Orders deadline review is unavailable. No data has been changed.'; }
function ods_escape($value):string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Orders deadline review</title>
<style>@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{background:#f7f7f2;color:#263521;font:400 13px/1.5 Jost,sans-serif;margin:24px}h1{font-size:24px;font-weight:500}.table-wrap{overflow:auto;background:white;border:1px solid #dce2d4;border-radius:12px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:10px;border-bottom:1px solid #e4e8de;vertical-align:top}th{background:#eef2e5;font-size:11px;font-weight:500}a{color:#28639b}</style></head><body>
<a href="epi-v2-shadow.php">Back to EPI shadow review</a><h1>Orders deadline review</h1><p><?=ods_escape($message)?></p>
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Responsibility / stage</th><th>Clock started</th><th>Deadline</th><th>Finished</th><th>State</th><th>Responsible at breach</th><th>Dispatch target</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=ods_escape($r['order_id'])?><br><?=ods_escape($r['classification'])?><?=$r['removed_at']?' · Removed':''?></td><td><?=ods_escape($r['module'])?><br><?=ods_escape($r['obligation_key'])?></td><td><?=ods_escape($r['starts_at'])?></td><td><?=ods_escape($r['due_at'])?></td><td><?=ods_escape($r['fulfilled_at']??'—')?></td><td><?=ods_escape($r['state']??'Needs classification')?><?=$r['breach_incident_uuid']?' · Historical breach retained':''?></td><td><?=ods_escape($r['full_name']??'Not established')?><br><?=ods_escape($r['exclusion_reason'])?></td><td><?=ods_escape($r['dispatch_at']??'—')?><?=$r['rush_candidate']?' · Late arrival / optional rush':''?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8">No active prospective stage records to show.</td></tr><?php endif;?>
</tbody></table></div></body></html>
