<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/OperationalPerformanceProjector.php';
require_once __DIR__.'/../shared/epi/PerformanceRefreshRuntime.php';
use Hambelela\EPI\OperationalPerformanceProjector;
use Hambelela\EPI\PerformanceRefreshRuntime;
use Hambelela\EPI\EmployeePerformanceService;
use Hambelela\EPI\DeadlineEngine;
use Hambelela\EPI\V2PerformanceQuery;

resetObjects();
owner('PROJECT-1',101);
$d=deadline(['object_reference'=>'PROJECT-1']);
$p=['version'=>'projector-test-1','status'=>'draft','categories'=>['orders'=>[
    'weight_hundredths'=>10000,'metrics'=>['progression'=>['weight_hundredths'=>10000,
    'minimum_volume'=>1,'direction'=>'success','source_coverage'=>['verified'=>true,'verified_by'=>999,
    'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']]]]]];
$json=json_encode($p);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$p['version'],'front_desk',$json,hash('sha256',$json)]);
sql('UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from=NULL WHERE employee_id IN(101,102,103)',[$p['version']]);
$projector=new OperationalPerformanceProjector($db);
(new DeadlineEngine($db))->processDue('2026-10-01 10:00:00');
$input=$projector->employee(101,'2026-10-01');
check('PROJ01 no-action deadline appears',1,count($input['opportunities']));
check('PROJ02 breach belongs to owner at deadline',101,$input['opportunities'][0]['employee_id']);
check('PROJ03 untouched obligation is failure','failure',$input['opportunities'][0]['outcome']);
check('PROJ04 original scoring exclusion retained',true,$input['opportunities'][0]['metadata']['excluded_from_scoring']);
(new DeadlineEngine($db))->fulfil($d,102,'2026-10-01 10:17:00');
$input=$projector->employee(101,'2026-10-01');
check('PROJ05 completion retains A historical failure','failure',$input['opportunities'][0]['outcome']);
check('PROJ06 B recorded separately as fulfiller',102,$input['opportunities'][0]['fulfilled_by']);
check('PROJ07 resolved current state','fulfilled',$input['opportunities'][0]['current_state']);
check('PROJ08 B has no inherited opportunity',0,count($projector->employee(102,'2026-10-01')['opportunities']));
check('PROJ09 current risk cleared',0,count((new V2PerformanceQuery($db))->personalRisk(101,'2026-10-01 11:00:00')));
$db->exec('START TRANSACTION READ ONLY');
check('PROJ10 source projection read only',true,attempt(function()use($projector){$projector->employee(101,'2026-10-01');}));$db->exec('ROLLBACK');
sql("INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES('epi_v2_results_enabled','1')");
PerformanceRefreshRuntime::invalidate($db,'synthetic operational change');
$run=PerformanceRefreshRuntime::run($db);
check('PROJ11 scheduled projection completes',0,$run['failed']);
check('PROJ12 all assigned periods refreshed',3,$run['processed']);
check('PROJ13 shadow does not silently become official',null,(new EmployeePerformanceService($db))->read(102,'2026-10-01')['official_score_hundredths']);
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_results_enabled'");
check('PROJ14 disabled runtime leaves production opt-in intact','disabled',PerformanceRefreshRuntime::run($db)['status']);

// Approved prospective capture is explicit and synthetic; no historic shadow promotion.
$p['version']='projector-approved-test-1';$p['status']='approved';$json=json_encode($p);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$p['version'],'front_desk',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from='2026-10-01' WHERE employee_id IN(101,102)",[$p['version']]);
sql("INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES('epi_v2_official_capture_enabled','1')");
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_results_enabled'");
resetObjects();
foreach(['PROSPECTIVE-LATE','PROSPECTIVE-OK1','PROSPECTIVE-OK2'] as $ref){
    owner($ref,102);$uuid=deadline(['object_reference'=>$ref,'responsible_employee_id'=>102]);
    if($ref==='PROSPECTIVE-LATE')$lateUuid=$uuid;
    else (new DeadlineEngine($db))->fulfil($uuid,102,'2026-10-01 08:20:00');
}
(new DeadlineEngine($db))->processDue('2026-10-01 09:00:00');
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_results_enabled'");
PerformanceRefreshRuntime::run($db);
$before=(new EmployeePerformanceService($db))->read(102,'2026-10-01');
check('PROJ15 actual deadlines update rate without manual calculation',6667,$before['official_score_hundredths']);
check('PROJ16 original responsible workload denominator',3,$before['categories']['orders']['metrics']['progression']['eligible_volume']);
(new DeadlineEngine($db))->fulfil($lateUuid,101,'2026-10-01 09:17:00');
PerformanceRefreshRuntime::run($db);
$after=(new EmployeePerformanceService($db))->read(102,'2026-10-01');
check('PROJ17 late completion cannot restore perfect score',6667,$after['official_score_hundredths']);
check('PROJ18 helper not charged',0,count($projector->employee(101,'2026-10-01')['opportunities']));
check('PROJ19 resolved breach retained in score evidence',1,count(array_filter($after['evidence'],static function(array $r):bool{
    return $r['outcome']==='failure' && $r['current_state']==='fulfilled' && $r['fulfilled_by']===101;
})));
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");

resetObjects();
$db->exec("CREATE TABLE ops_whatsapp_conversations(id INT PRIMARY KEY,assigned_employee_id INT,
    created_at DATETIME,follow_up_at DATETIME,last_customer_message_at DATETIME NULL,status VARCHAR(30))");
sql("INSERT INTO ops_whatsapp_conversations VALUES(71,102,'2026-10-01 08:00:00','2026-10-01 09:00:00',NULL,'follow_up')");
\Hambelela\EPI\V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',71,
    ['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
check('COMM01 saved native conversation creates follow-up deadline',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE obligation_key='customer_followup'"));
\Hambelela\EPI\V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',71,
    ['employee_id'=>999,'occurred_at'=>'2026-10-01 08:05:00']);
check('COMM02 repeated save does not duplicate obligation',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines'));
(new DeadlineEngine($db))->processDue('2026-10-01 09:01:00');
check('COMM03 missed follow-up breach without employee action',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
\Hambelela\EPI\V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_saved',71,
    ['employee_id'=>101,'occurred_at'=>'2026-10-01 09:10:00','direction'=>'outbound']);
check('COMM04 unrelated outbound message cannot erase follow-up risk','breached',scalar('SELECT state FROM epi_v2_operational_deadlines'));
sql("UPDATE ops_whatsapp_conversations SET status='resolved' WHERE id=71");
\Hambelela\EPI\V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',71,
    ['employee_id'=>101,'occurred_at'=>'2026-10-01 09:20:00']);
check('COMM05 resolution records actual helper',101,(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines'));
check('COMM06 historical failure remains original owner',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
check('COMM07 resolved follow-up leaves historical root',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
check('COMM08 native events consumed without failed capture',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'"));
