<?php
declare(strict_types=1);
use Hambelela\EPI\{V2OperationalBridge,DeadlineEngine,PerformanceRefreshRuntime,EmployeePerformanceService};
resetObjects();
$db->exec("ALTER TABLE ops_checklist_tasks ADD checklist_items TEXT,ADD checked_items TEXT,
 ADD completion_note_required INT,ADD completion_evidence_required INT,ADD completion_note TEXT");
$metrics=[];
foreach(['completion'=>4000,'timeliness'=>3000,'compliance'=>3000] as $key=>$weight)$metrics[$key]=[
 'weight_hundredths'=>$weight,'minimum_volume'=>1,'direction'=>'success',
 'source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']];
$policy=['version'=>'task-process-fixture','status'=>'approved','categories'=>[
 'tasks'=>['weight_hundredths'=>10000,'metrics'=>$metrics]]];$json=json_encode($policy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
 [$policy['version'],'front_desk',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from='2026-10-01' WHERE employee_id=102",[$policy['version']]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
foreach([801,802,803] as $id){
 sql("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,date_assigned,deadline,
 checklist_items,checked_items,completion_note_required,completion_evidence_required,completion_note)
 VALUES(?,102,999,'2026-10-01 08:00:00','2026-10-01 09:00:00','[\"Inspect\"]',?,0,0,'')",
 [$id,$id===802?'[]':'["Inspect"]']);
 V2OperationalBridge::record($db,'checklist_task','task_created',$id,['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
 if($id!==803)V2OperationalBridge::record($db,'checklist_task','task_completed',$id,
 ['employee_id'=>102,'occurred_at'=>'2026-10-01 08:20:00']);
}
(new DeadlineEngine($db))->processDue('2026-10-01 09:01:00');
PerformanceRefreshRuntime::run($db);$service=new EmployeePerformanceService($db);$r=$service->read(102,'2026-10-01');
check('TASKP01 untouched overdue assignment in completion denominator',3,$r['categories']['tasks']['metrics']['completion']['eligible_volume']);
check('TASKP02 completed tasks numerator',2,$r['categories']['tasks']['metrics']['completion']['numerator']);
check('TASKP03 unfinished obligation not penalised a second time in timeliness',2,$r['categories']['tasks']['metrics']['timeliness']['eligible_volume']);
check('TASKP04 completed checklist compliance rate',5000,$r['categories']['tasks']['metrics']['compliance']['rate_hundredths']);
sql("UPDATE ops_checklist_tasks SET checked_items='[\"Inspect\"]' WHERE id=802");
PerformanceRefreshRuntime::run($db);
check('TASKP05 editing checklist later does not rewrite completion snapshot',5000,$service->read(102,'2026-10-01')['categories']['tasks']['metrics']['compliance']['rate_hundredths']);
V2OperationalBridge::record($db,'checklist_task','task_completed',803,
 ['employee_id'=>103,'occurred_at'=>'2026-10-01 10:00:00']);
PerformanceRefreshRuntime::run($db);$r=$service->read(102,'2026-10-01');
check('TASKP06 eventual completion updates completion numerator',3,$r['categories']['tasks']['metrics']['completion']['numerator']);
check('TASKP07 late completion remains a timeliness failure',6667,$r['categories']['tasks']['metrics']['timeliness']['rate_hundredths']);
check('TASKP08 original employee keeps late task evidence',1,count(array_filter($r['evidence'],static function(array $e):bool{
 return $e['source_reference']==='TASK-803' && $e['metric']==='timeliness' && $e['outcome']==='failure' && $e['fulfilled_by']===103;
})));
$db->exec('START TRANSACTION READ ONLY');
$view=$service->profile(102,'2026-10-01','2026-10-01 10:30:00');
check('VIEW01 existing-consumer contract works in read-only transaction',$r['official_score_hundredths'],$view['official_score_hundredths']);
check('VIEW02 export uses identical published score',$r['official_score_hundredths']/100,EmployeePerformanceService::exportRows($view)[0][4]);
check('VIEW03 late helper has human-readable distinct name','Synthetic C',array_values(array_filter($view['evidence'],static function(array $e):bool{
 return $e['source_reference']==='TASK-803'&&$e['metric']==='timeliness';
}))[0]['fulfilled_by_name']);
check('VIEW04 unmeasured prior month is not fabricated',null,$view['previous_period']['official_score_hundredths']);
$db->exec('ROLLBACK');
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
