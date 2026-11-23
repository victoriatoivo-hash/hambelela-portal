<?php
declare(strict_types=1);
// Private CLI integration probe. All database writes are rolled back, never published.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');
if(!$root || strpos(__DIR__,$root)===0)throw new RuntimeException('Private folder and portal root required');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/CompletedWorkCapture.php';require_once $root.'/shared/epi/IncidentCorrelation.php';
require_once $root.'/shared/epi/OrderCommunicationCapture.php';
use Hambelela\EPI\{V2Store,Support,OwnershipPeriodEngine,DeadlineEngine,HrConnection,PerformanceRefreshRuntime,
 EmployeePerformanceService,CompletedWorkCapture,V2QualityBridge,IncidentCorrelation,OperationalPerformanceProjector,V2PerformanceQuery};
$db=db();$db->exec("SET time_zone='+02:00'");$owner=(int)(V2Store::activation($db)['approved_by']??0);
$lock='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Watchdog busy; retry later');
$checks=[];$before=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
function probeCheck(string $name,$expected,$actual):void {global $checks;$checks[$name]=['pass'=>$expected===$actual,'expected'=>$expected,'actual'=>$actual];if($expected!==$actual)throw new RuntimeException('Probe failed: '.$name);}
try {
 $db->beginTransaction();
 $prefix='ROLLBACK-PROBE-'.bin2hex(random_bytes(5));$A=2;$B=15;
 $date=Support::timestamp()->modify('tomorrow')->setTime(8,0);while((int)$date->format('N')>5)$date=$date->modify('+1 day');
 $start=$date->format('Y-m-d H:i:s');$due=$date->modify('+1 hour')->format('Y-m-d H:i:s');
 $done=$date->modify('+20 minutes')->format('Y-m-d H:i:s');$late=$date->modify('+90 minutes')->format('Y-m-d H:i:s');$period=$date->format('Y-m-01');
 if(V2Store::one($db,'SELECT employee_id FROM epi_v2_employee_result_heads WHERE employee_id IN(?,?) AND period_start=? AND locked_at IS NOT NULL',[$A,$B,$period]))throw new RuntimeException('Refusing probe of a locked month');
 foreach(['epi_v2_results_enabled','epi_v2_provisional_capture_enabled'] as $key)$db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,'1']);
 $metric=['weight_hundredths'=>10000,'minimum_volume'=>5,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success'];
 $policy=['version'=>$prefix,'role'=>'front_desk','status'=>'provisional','financial_use_allowed'=>false,
  'provisional_capture'=>['enabled'=>true,'authorised_by'=>$owner,'authorised_at'=>$start,'from'=>$start],
  'categories'=>['orders'=>['weight_hundredths'=>5000,'metrics'=>['progression'=>$metric]],'communication'=>['weight_hundredths'=>5000,'metrics'=>['accuracy'=>$metric]]]];
 $json=Support::json($policy);$db->prepare('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)')->execute([$prefix,'front_desk',$json,hash('sha256',$json)]);
 foreach([$A,$B] as $employee)$db->prepare('INSERT INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,assigned_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE scorecard_version=VALUES(scorecard_version),assigned_by=VALUES(assigned_by),official_from=NULL,validation_approved_at=NULL,validation_approved_by=NULL')->execute([$employee,$period,$prefix,$owner]);
 $owners=new OwnershipPeriodEngine($db);$engine=new DeadlineEngine($db,HrConnection::connect());$service=new EmployeePerformanceService($db);
 $assign=function(string $ref)use($owners,$A,$owner,$start){$owners->assign(['module'=>'Orders','object_reference'=>$ref,'employee_id'=>$A,'assigned_by'=>$owner,'accepted_by'=>$A,'effective_from'=>$start,'accepted_at'=>$start,'source'=>'rollback_integration_probe']);};
 for($n=0;$n<5;$n++){
  $ref=$prefix.'-ORDER-'.$n;$assign($ref);
  $uuid=$engine->schedule(['module'=>'Orders','object_reference'=>$ref,'obligation_key'=>'move_order_out_of_new','breach_event_key'=>'order_new_sla_breached','starts_at'=>$start,'due_at'=>$due,'cycle_id'=>$prefix,'source_event'=>'rollback_probe']);
  if($n===0)$breach=$uuid;else $engine->fulfil($uuid,$A,$done);
 }
 $engine->processDue($late,500);PerformanceRefreshRuntime::run($db);$r=$service->read($A,$period);
 probeCheck('untouched_order_in_denominator',5,$r['categories']['orders']['metrics']['progression']['eligible_volume']);
 probeCheck('A_orders_rate_after_breach',8000,$r['categories']['orders']['metrics']['progression']['rate_hundredths']);
 $engine->fulfil($breach,$B,$late);PerformanceRefreshRuntime::run($db);$r=$service->read($A,$period);
 probeCheck('A_retains_failure_after_B_completes',8000,$r['categories']['orders']['metrics']['progression']['rate_hundredths']);
 $incident=V2Store::one($db,'SELECT responsible_employee_at_breach,current_risk_state FROM epi_v2_performance_incidents WHERE deadline_uuid=?',[$breach]);
 probeCheck('A_owns_historical_breach',$A,(int)$incident['responsible_employee_at_breach']);probeCheck('risk_resolved','resolved',$incident['current_risk_state']);
 probeCheck('B_only_fulfiller',$B,(int)V2Store::one($db,'SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$breach])['fulfilled_by']);
 $base=2100000000;
 for($n=0;$n<5;$n++){
  $id=$base+$n;$ref='CONVERSATION-'.$id;
  if(V2Store::one($db,'SELECT id FROM ops_whatsapp_conversations WHERE id=?',[$id]))throw new RuntimeException('Synthetic reference collision');
  $assign($ref);CompletedWorkCapture::capture($db,'ops_whatsapp_conversation','save_whatsapp_conversation',['id'=>$id,'created_at'=>$start,'status'=>'resolved'],['employee_id'=>$B,'occurred_at'=>$done]);
  Hambelela\EPI\OrderCommunicationCapture::reviewUnit($db,'ops_whatsapp_conversation:'.$id.':0',$owner,'accurate','Rollback-only synthetic contact proof','Synthetic quality evidence verified inside rollback probe',$done);
 }
 foreach([0=>0,1=>1,2=>0,3=>2] as $index=>$conversation){
  $id=$base+100+$index;
  if(V2Store::one($db,'SELECT id FROM ops_error_logs WHERE id=?',[$id]))throw new RuntimeException('Synthetic error collision');
  $record=['id'=>$id,'attribution_type'=>$index===3?'system':'employee','attributed_employee_id'=>$A,'responsible_employee_id'=>$A,'logged_by'=>$B,'affects_kpi_accuracy'=>$index===3?0:1,'accuracy_verified_by'=>$owner,'attribution_verified_by'=>$owner,'accuracy_verified_at'=>$late,'attribution_verified_at'=>$late,'occurred_at'=>$done,'status'=>'resolved','updated_at'=>$late,'category'=>'incorrect_payment_information','severity'=>'medium'];
  V2QualityBridge::capture($db,'error_owner_reviewed',$id,['actor_employee_id'=>$owner],$record);
  IncidentCorrelation::link($db,['source_key'=>'error:'.$id,'root_incident_id'=>$prefix.'-COMM-'.$conversation,'opportunity_key'=>'ops_whatsapp_conversation:'.($base+$conversation).':0','category_key'=>'communication','metric_key'=>'accuracy','employee_id'=>$A],$owner,'Rollback-only controlled integration probe',$late);
 }
 PerformanceRefreshRuntime::run($db);$r=$service->read($A,$period);$b=$service->read($B,$period);
 probeCheck('communication_denominator',5,$r['categories']['communication']['metrics']['accuracy']['eligible_volume']);
 probeCheck('communication_excludes_duplicate_and_system',6000,$r['categories']['communication']['metrics']['accuracy']['rate_hundredths']);
 probeCheck('combined_fixture_provisional_result',7000,$r['provisional_score_hundredths']);
 probeCheck('no_official_score',null,$r['official_score_hundredths']);probeCheck('no_financial_use',false,$r['financial_use_allowed']);
 probeCheck('B_not_penalised',0,count($b['evidence']));
 probeCheck('CSV_same_contract',count(EmployeePerformanceService::exportHeaders()),count(EmployeePerformanceService::exportRows($r)[0]));
 $result=['status'=>'passed','simulation_only'=>true,'all_writes_rolled_back'=>true,'checks'=>$checks];
}catch(Throwable $e){$result=['status'=>'failed','simulation_only'=>true,'all_writes_rolled_back'=>true,'error'=>$e->getMessage(),'checks'=>$checks];}
finally {if($db->inTransaction())$db->rollBack();$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
$result['history_unchanged']=$before===(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
file_put_contents(__DIR__.'/live-probe-result.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result),PHP_EOL;exit($result['status']==='passed'&&$result['history_unchanged']?0:1);
