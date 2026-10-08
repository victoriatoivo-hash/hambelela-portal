<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/PackingProcessEvidence.php';
use Hambelela\EPI\PackingProcessEvidence;
$quantity=static function(string $planned,string $packed):array{
    return PackingProcessEvidence::evaluate(['quantity_planned'=>$planned,'quantity_packed'=>$packed]);
};
check('PACK01 exact package plan measured',true,$quantity('100g(5) 1kg(2)','1kg(2), 100g(5)')['measured']);
check('PACK02 equivalent base units match',true,$quantity('1kg(2)','1000g(2)')['measured']);
check('PACK03 equal mass wrong packages needs review',false,$quantity('1kg(2)','500g(4)')['measured']);
check('PACK04 liquid is not mass',false,$quantity('1l(2)','1kg(2)')['measured']);
check('PACK05 unparsed text cannot falsely match',false,$quantity('100g(5) unknown quantity','100g(5)')['measured']);
check('PACK06 negative quantity cannot match',false,$quantity('-100g(5)','100g(5)')['measured']);
check('PACK07 missing packed evidence cannot be perfect',false,$quantity('100g(5)','')['measured']);
check('PACK08 supported multiplier syntax',true,$quantity('500ml x 3','0.5l(3)')['measured']);
check('PACK09 package count variance pending not employee fault',true,$quantity('100g(5)','100g(4)')['requires_review']);
check('PACK10 no invented failure outcome',false,array_key_exists('outcome',$quantity('100g(5)','100g(4)')));

// Real completion capture -> quantity evidence -> reviewed incident -> published rate.
resetObjects();
$packingPolicy=['version'=>'packing-quantity-fixture','role'=>'packer','status'=>'approved','categories'=>[
    'quality'=>['weight_hundredths'=>10000,'metrics'=>['first_time_right'=>[
        'weight_hundredths'=>10000,'minimum_volume'=>1,'direction'=>'success',
        'source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']]]]]];
$json=json_encode($packingPolicy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$packingPolicy['version'],'packer',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from='2026-10-01',validation_approved_by=999,validation_approved_at='2026-09-30 08:00:00' WHERE employee_id=102",[$packingPolicy['version']]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
$db->exec('ALTER TABLE ops_packing_tasks ADD quantity_planned VARCHAR(100), ADD quantity_packed VARCHAR(100)');
foreach([901=>'100g(5)',902=>'100g(4)'] as $id=>$packed){
    sql("INSERT INTO ops_packing_tasks(id,created_at,date_loaded,assigned_employee_id,packing_status,quantity_planned,quantity_packed) VALUES(?,'2026-10-01 08:00:00','2026-10-01 08:00:00',102,'not_started','100g(5)',?)",[$id,$packed]);
    \Hambelela\EPI\V2OperationalBridge::record($db,'packing_task','packing_item_created',$id,
        ['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    sql("UPDATE ops_packing_tasks SET packing_status='done' WHERE id=?",[$id]);
    \Hambelela\EPI\V2OperationalBridge::record($db,'packing_task','packing_packing_status_updated',$id,
        ['employee_id'=>102,'occurred_at'=>'2026-10-01 09:00:00','field'=>'packing_status']);
}
\Hambelela\EPI\PerformanceRefreshRuntime::run($db);
$packingService=new \Hambelela\EPI\EmployeePerformanceService($db);
$packingResult=$packingService->read(102,'2026-10-01');
check('PACK11 unresolved quantity variance blocks false perfect score',null,$packingResult['official_score_hundredths']);
check('PACK12 only confirmed exact packing in measured numerator',1,$packingResult['categories']['quality']['metrics']['first_time_right']['numerator']);
check('PACK13 unrelated completed tasks excluded from packer workload',1,$packingResult['categories']['quality']['metrics']['first_time_right']['eligible_volume']);
sql("INSERT INTO ops_error_logs VALUES(31,'employee',102,102,103,1,999,999,'2026-10-01 10:00:00',
    '2026-10-01 10:00:00','2026-10-01 09:00:00','open','2026-10-01 10:00:00','packing_quantity_error','medium')");
\Hambelela\EPI\V2OperationalBridge::record($db,'error_log','error_owner_reviewed',31,
    ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 10:00:00']);
\Hambelela\EPI\IncidentCorrelation::link($db,['source_key'=>'error:31','root_incident_id'=>'packing-failure:902',
    'opportunity_key'=>'packing_task:902:0','category_key'=>'quality','metric_key'=>'first_time_right','employee_id'=>102],
    999,'Confirmed under-pack with original quantity plan unchanged','2026-10-01 10:01:00');
\Hambelela\EPI\PerformanceRefreshRuntime::run($db);
$packingResult=$packingService->read(102,'2026-10-01');
check('PACK14 reviewed attributable mismatch enters real denominator',2,$packingResult['categories']['quality']['metrics']['first_time_right']['eligible_volume']);
check('PACK15 one confirmed failure in two packing units',5000,$packingResult['official_score_hundredths']);
check('PACK16 quantity review never attributes reporter',102,(int)array_values(array_filter($packingResult['evidence'],static function(array $r):bool{return $r['outcome']==='failure';}))[0]['employee_id']);
$db->exec(file_get_contents(dirname(__DIR__).'/operations-epi-orders-sla-migration.sql'));
$db->exec("CREATE TABLE IF NOT EXISTS ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80),name VARCHAR(100));
    INSERT IGNORE INTO ops_roles VALUES(1,'owner_admin','Owner'),(2,'front_desk_admin','Front Desk'),(3,'packer','Packer');
    ALTER TABLE ops_employees ADD COLUMN IF NOT EXISTS role_id INT DEFAULT 2, ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active';
    UPDATE ops_employees SET role_id=3 WHERE id=102;
    UPDATE ops_employees SET role_id=1 WHERE id=999");
sql('INSERT INTO epi_v2_orders_policy(version,effective_from,approved_by,policy_json) VALUES(?,?,?,?)',
    ['packing-stage-workload-fixture','2026-10-01 00:00:00',999,json_encode(\Hambelela\EPI\OrdersSlaPolicy::approved())]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_orders_sla_enabled'");
duty(103,'2026-10-01 08:00:00','2026-10-01 17:00:00');
$stageCapture=static function(string $action,array $record,string $at,int $actor)use($db):void{
    \Hambelela\EPI\V2Store::transaction($db,static function()use($db,$action,$record,$at,$actor):void{
        $meta=['employee_id'=>$actor,'occurred_at'=>$at];
        \Hambelela\EPI\OrdersStageBridge::capture($db,$action,$record,$meta);
        \Hambelela\EPI\CompletedWorkCapture::capture($db,'order',$action,$record,$meta);
    });
};
$stageOrder=['id'=>991,'order_number'=>'VISIBLE991','order_type'=>'collection','fulfilment_mode'=>'collection',
    'created_at'=>'2026-10-01 08:00:00','status'=>'new_order','portal_paid_confirmed'=>0,'assigned_packer_id'=>102];
$stageCapture('order_created',$stageOrder,'2026-10-01 08:00:00',999);
$stageOrder['status']='in_progress';$stageCapture('status_changed',$stageOrder,'2026-10-01 09:00:00',102);
check('PACK17 ready order captures packer workload before Front completion',102,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='order_packing:991:0'"));
$stageCapture('status_changed',$stageOrder,'2026-10-01 09:00:00',102);
check('PACK18 repeated packing capture counts once',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_completed_work_units WHERE opportunity_key='order_packing:991:0'"));
$stageOrder['portal_paid_confirmed']=1;$stageCapture('payment_status_updated',$stageOrder,'2026-10-01 09:01:00',103);
$stageOrder['status']='completed';$stageCapture('order_completed',$stageOrder,'2026-10-01 10:00:00',103);
check('PACK19 Front completion denominator remains separate',103,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='order:991:0'"));
foreach([992=>'courier',993=>'walk_in'] as $id=>$mode){
    $other=array_replace($stageOrder,['id'=>$id,'order_number'=>'VISIBLE'.$id,'order_type'=>$mode,'fulfilment_mode'=>$mode,
        'status'=>'new_order','portal_paid_confirmed'=>0]);
    $stageCapture('order_created',$other,'2026-10-01 08:00:00',999);
    $other['status']='in_progress';$stageCapture('status_changed',$other,'2026-10-01 09:00:00',102);
    check('PACK20 no ineligible packing workload for '.$mode,0,(int)scalar('SELECT COUNT(*) FROM epi_v2_completed_work_units WHERE opportunity_key=?',['order_packing:'.$id.':0']));
}
$direct=array_replace($stageOrder,['id'=>994,'status'=>'new_order']);
$stageCapture('order_created',$direct,'2026-10-01 08:00:00',999);
$direct['status']='completed';$stageCapture('order_completed',$direct,'2026-10-01 10:00:00',103);
check('PACK21 direct completion retains packing owner',102,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='order_packing:994:0'"));
check('PACK22 direct completion retains actual helper',103,(int)scalar("SELECT fulfiller_id FROM epi_v2_completed_work_units WHERE opportunity_key='order_packing:994:0'"));
check('PACK23 direct completion independently records Front owner',103,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='order:994:0'"));
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_orders_sla_enabled')");
