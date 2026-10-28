<?php
declare(strict_types=1);
// Included inside the isolated closure transaction. No production database access.
require_once __DIR__.'/../shared/epi/CourierPackerDeadlineBridge.php';
use Hambelela\EPI\{CourierPackerDeadlineBridge,OrdersSlaPolicy,OrdersStageBridge,V2OperationalBridge,DeadlineEngine,
    PerformanceRefreshRuntime,EmployeePerformanceService};
$uploadPolicy=OrdersSlaPolicy::approved();
check('UPLOAD01 weekday cutoff retained','2030-10-01 13:30:00',CourierPackerDeadlineBridge::due('2030-10-01 09:00:00','2030-10-01 13:30:00',$uploadPolicy));
check('UPLOAD02 upstream lateness does not become expired upload SLA','2030-10-02 13:30:00',CourierPackerDeadlineBridge::due('2030-10-01 14:00:00','2030-10-01 13:30:00',$uploadPolicy));
check('UPLOAD03 no Saturday or Sunday collection','2030-10-07 13:30:00',CourierPackerDeadlineBridge::due('2030-10-05 10:00:00','2030-10-04 13:30:00',$uploadPolicy));
$enabled=CourierPackerDeadlineBridge::enable($db,999,'Owner-authorised prospective document-upload obligation','2030-10-01 08:00:00');
check('UPLOAD04 explicit prospective activation','2030-10-01 08:00:00',$enabled['effective_from']);
check('UPLOAD05 repeated activation cannot reset clocks',true,CourierPackerDeadlineBridge::enable($db,999,'Same authority retry','2030-10-02 08:00:00')['already_active']);
$p=['version'=>'courier-upload-provisional-fixture','role'=>'packer','status'=>'provisional','financial_use_allowed'=>false,
 'provisional_capture'=>['enabled'=>true,'authorised_by'=>999,'authorised_at'=>'2030-10-01 07:00:00','from'=>'2030-10-01 08:00:00'],
 'categories'=>['courier'=>['weight_hundredths'=>10000,'metrics'=>['upload'=>['weight_hundredths'=>10000,'minimum_volume'=>1,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success']]]]];
$j=json_encode($p);sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$p['version'],'packer',$j,hash('sha256',$j)]);
sql("INSERT INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,assigned_by) VALUES(102,'2030-10-01',?,999)",[$p['version']]);
sql('INSERT INTO epi_v2_orders_policy(version,effective_from,approved_by,policy_json) VALUES(?,?,?,?)',['courier-upload-2030','2030-10-01 00:00:00',999,json_encode($uploadPolicy)]);
$order=['id'=>9911,'order_number'=>'VISIBLE9911','created_at'=>'2030-10-01 08:00:00','order_type'=>'courier','fulfilment_mode'=>'courier','portal_paid_confirmed'=>1,'status'=>'new_order','assigned_packer_id'=>102];
OrdersStageBridge::capture($db,'order_created',$order,['employee_id'=>999,'occurred_at'=>'2030-10-01 08:00:00']);
$order['status']='in_progress';$meta=['employee_id'=>102,'occurred_at'=>'2030-10-01 09:00:00'];
OrdersStageBridge::capture($db,'status_changed',$order,$meta);
CourierPackerDeadlineBridge::capture($db,'order','status_changed',$order,$meta);
CourierPackerDeadlineBridge::capture($db,'order','status_changed',$order,$meta);
check('UPLOAD06 readiness event creates exactly one document obligation',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
check('UPLOAD07 upload allowance begins only when packing is ready','2030-10-01 09:00:00',scalar("SELECT starts_at FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
check('UPLOAD08 approved dispatch cutoff is authoritative','2030-10-01 13:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
(new DeadlineEngine($db))->processDue('2030-10-01 13:31:00');
check('UPLOAD09 no upload click still produces responsible breach',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='ORDER-9911' AND event_key='courier_upload_sla_breached'"));
sql("INSERT INTO hambelela_waybills(id,batch_id,uploaded_at,uploaded_by,file_path,status) VALUES(9911,'synthetic-upload','2030-10-01 13:50:00',103,'synthetic-document.pdf','pending')");
V2OperationalBridge::record($db,'courier_waybill','courier_waybill_uploaded',9911,['employee_id'=>103,'occurred_at'=>'2030-10-01 13:50:00']);
check('UPLOAD10 anonymous/unlinked file cannot fulfil an order','breached',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
sql("INSERT INTO hambelela_waybill_orders(waybill_id,order_id,linked_by_employee_id,linked_at) VALUES(9911,9911,103,'2030-10-01 14:00:00')");
V2OperationalBridge::record($db,'courier_waybill','courier_waybill_orders_assigned',9911,['employee_id'=>103,'occurred_at'=>'2030-10-01 14:00:00']);
check('UPLOAD11 structured link resolves current document risk','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
check('UPLOAD12 late linking keeps A historical responsibility',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='ORDER-9911' AND event_key='courier_upload_sla_breached'"));
check('UPLOAD13 B recorded only as document fulfiller',103,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
check('UPLOAD14 availability is when file and order link both exist','2030-10-01 14:00:00',scalar("SELECT fulfilled_at FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
PerformanceRefreshRuntime::run($db);$r=(new EmployeePerformanceService($db))->read(102,'2030-10-01');
check('UPLOAD15 neglected upload belongs in denominator',1,$r['categories']['courier']['metrics']['upload']['eligible_volume']);
check('UPLOAD16 genuine calculated observed zero is preserved',0,$r['categories']['courier']['metrics']['upload']['rate_hundredths']);
check('UPLOAD17 helper receives no inherited upload breach',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE event_key='courier_upload_sla_breached' AND responsible_employee_at_breach=103"));
check('UPLOAD18 still no official score from provisional evidence',null,$r['official_score_hundredths']);
check('UPLOAD19 upload and Front sending have separate deadlines','Packing',scalar("SELECT module FROM epi_v2_operational_deadlines WHERE object_reference='ORDER-9911' AND obligation_key='upload_waybill'"));
