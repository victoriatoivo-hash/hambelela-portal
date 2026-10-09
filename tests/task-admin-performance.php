<?php
// Dedicated loopback MariaDB fixture only. No live database configuration is loaded.
namespace Hambelela\EPI {
class PerformanceRefreshRuntime {static function enabled(\PDO $db):bool{return true;}}
class Performance {
 public static $activities=[];public static $evidence=[];
 static function configure(\PDO $pdo):void{} static function enabled():bool{return true;}
 static function businessMinutes($from,$to):float{return DeadlineEngine::minutes($from->format('Y-m-d H:i:s'),$to->format('Y-m-d H:i:s'),[]);}
 static function recordActivity(array $input){self::$activities[]=$input;}
 static function recordEvidence(array $input){self::$evidence[]=$input;return Support::uuidFromHash(Support::json($input));}
}
}
namespace {
require_once dirname(__DIR__).'/shared/epi/Support.php';
require_once dirname(__DIR__).'/shared/epi/V2Store.php';
require_once dirname(__DIR__).'/shared/epi/OwnershipPeriodEngine.php';
require_once dirname(__DIR__).'/shared/epi/DeadlineEngine.php';
require_once dirname(__DIR__).'/shared/epi/TaskActivityBridge.php';
$source=file_get_contents(dirname(__DIR__).'/shared/epi/CompletedWorkCapture.php');$source=str_replace("require_once __DIR__.'/PerformanceRefreshRuntime.php';",'', $source);eval(substr($source,5));
$port=(int)(getenv('TASK_TEST_MYSQL_PORT')?:33319);$db=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root',getenv('TASK_TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='task_correction_test_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
$db->exec("CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100),role_id INT);
INSERT INTO ops_employees VALUES(1,'Original Employee',1),(2,'Actual Completer',1),(3,'Owner',1);
CREATE TABLE ops_roles(id INT PRIMARY KEY,name VARCHAR(100));INSERT INTO ops_roles VALUES(1,'Operations');
CREATE TABLE epi_employee_performance_settings(setting_key VARCHAR(100),setting_value TEXT);INSERT INTO epi_employee_performance_settings VALUES('task_module_enabled','1');
CREATE TABLE epi_employee_evidence(id INT PRIMARY KEY,module VARCHAR(100),reference_number VARCHAR(100),action VARCHAR(100),metadata_json LONGTEXT);
INSERT INTO epi_employee_evidence VALUES(1,'Tasks','TASK-10','task_completed','{}');
CREATE TABLE epi_v2_ownership_audits(id INT AUTO_INCREMENT PRIMARY KEY,scope_key VARCHAR(190),actor_id INT,reason TEXT,before_json LONGTEXT,after_json LONGTEXT);
CREATE TABLE epi_v2_operational_deadlines(id INT PRIMARY KEY,deadline_uuid VARCHAR(100),module VARCHAR(100),object_reference VARCHAR(100),obligation_key VARCHAR(100),starts_at DATETIME,due_at DATETIME,breach_eligible_at DATETIME,state VARCHAR(50),fulfilled_at DATETIME,fulfilled_by INT,late_business_minutes DECIMAL(10,2),policy_snapshot_json LONGTEXT,breach_incident_uuid VARCHAR(100));
INSERT INTO epi_v2_operational_deadlines VALUES(1,'fixture-completion','Tasks','TASK-10','complete_task','2026-10-08 08:00:00','2026-10-08 17:00:00','2026-10-08 17:00:00','fulfilled','2026-10-09 10:00:00',1,120,'{}','fixture-breach'),(2,'fixture-start','Tasks','TASK-10','start_task','2026-10-08 08:00:00','2026-10-08 08:30:00','2026-10-08 08:30:00','open',NULL,NULL,0,'{}',NULL);
CREATE TABLE epi_v2_performance_incidents(id INT PRIMARY KEY,deadline_uuid VARCHAR(100),responsible_employee_at_breach INT,current_risk_state VARCHAR(30),resolved_at DATETIME,eligibility_state VARCHAR(30),exclusion_reason VARCHAR(100),metadata_json LONGTEXT);
INSERT INTO epi_v2_performance_incidents VALUES(1,'fixture-completion',1,'open',NULL,'pending_rule',NULL,'{}');
CREATE TABLE epi_v2_completed_work_units(opportunity_key VARCHAR(100) PRIMARY KEY,employee_id INT,fulfiller_id INT,completed_at DATETIME,source_snapshot_json LONGTEXT);
INSERT INTO epi_v2_completed_work_units VALUES('checklist_task:10:0',1,1,'2026-10-09 10:00:00','{\"original\":\"preserved\"}');
CREATE TABLE ops_checklist_tasks(id INT PRIMARY KEY,task_name TEXT,assigned_employee_id INT,date_assigned DATETIME,started_at DATETIME,date_completed DATETIME,completed_at DATETIME,status VARCHAR(30),deadline DATETIME,checklist_items TEXT,checked_items TEXT,completion_note TEXT);
INSERT INTO ops_checklist_tasks VALUES(10,'Fixture',2,'2026-10-08 08:00:00',NULL,'2026-10-08 16:00:00','2026-10-08 16:00:00','complete','2026-10-08 17:00:00','[]','[]','Completed work');
CREATE TABLE ops_checklist_attachments(id INT PRIMARY KEY,task_id INT,removed_at DATETIME);");
function checkTask(bool $test,string $name):void{if(!$test)throw new RuntimeException('FAIL: '.$name);echo 'PASS: '.$name.PHP_EOL;}
$engine=new \Hambelela\EPI\DeadlineEngine($db);$engine->correctTaskCompletion('TASK-10',2,'2026-10-08 16:00:00',3,'Verified original completion',99);
$d=$db->query('SELECT * FROM epi_v2_operational_deadlines WHERE id=1')->fetch();$i=$db->query('SELECT * FROM epi_v2_performance_incidents')->fetch();
checkTask($d['fulfilled_at']==='2026-10-08 16:00:00'&&(int)$d['fulfilled_by']===2,'Performance uses actual completion time and worker');
checkTask((int)$i['responsible_employee_at_breach']===1,'Earlier overdue responsibility never moves to replacement employee');
checkTask($i['eligibility_state']==='needs_review'&&$i['current_risk_state']==='resolved','Corrected incident is review evidence, not automatic points');
checkTask($db->query('SELECT fulfilled_at FROM epi_v2_operational_deadlines WHERE id=2')->fetchColumn()===null,'Management completion does not fulfil a fictional start');
checkTask((float)$d['late_business_minutes']===0.0,'Late duration recalculated against actual completion');
$meta=['management_correction'=>true,'completion_changed'=>true,'audit_id'=>99,'editor_id'=>3,'employee_id'=>2,'actual_completed_at'=>'2026-10-08 16:00:00','recorded_at'=>'2026-10-09 10:00:00','occurred_at'=>'2026-10-09 10:00:00','reason'=>'Verified yesterday'];$task=$db->query('SELECT * FROM ops_checklist_tasks')->fetch();
\Hambelela\EPI\CompletedWorkCapture::capture($db,'checklist_task','task_management_corrected',$task,$meta);
$unit=$db->query('SELECT * FROM epi_v2_completed_work_units')->fetch();$source=json_decode($unit['source_snapshot_json'],true);
checkTask((int)$unit['employee_id']===1&&(int)$unit['fulfiller_id']===2&&$unit['completed_at']===$meta['actual_completed_at'],'Denominator retains responsibility and records actual fulfiller');
checkTask($source['original']==='preserved'&&count($source['management_corrections'])===1,'Original performance snapshot and correction provenance preserved');
checkTask((int)$db->query('SELECT COUNT(*) FROM epi_v2_completed_work_units')->fetchColumn()===1,'No duplicate completed work denominator');
\Hambelela\EPI\TaskActivityBridge::record($db,'task_management_corrected',10,$meta);
$evidence=\Hambelela\EPI\Performance::$evidence;
checkTask(count($evidence)===1&&$evidence[0]['action']==='task_completed'&&$evidence[0]['employee_id']===2&&$evidence[0]['timestamp']->format('Y-m-d H:i:s')===$meta['actual_completed_at'],'Legacy completion analytics records actual completer and actual time');
checkTask($evidence[0]['metadata']['editor_id']===3&&$evidence[0]['metadata']['review_status']==='pending_review','Owner correction provenance and approved review mode retained');
checkTask(json_decode($db->query('SELECT metadata_json FROM epi_employee_evidence')->fetchColumn(),true)['management_superseded']===true,'Superseded completion excluded without deleting original evidence');
checkTask(count($evidence)===1,'Correction produces no transferred penalty or automatic bonus candidates');
echo 'Synthetic database: '.$name.PHP_EOL;
}
