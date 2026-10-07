<?php
declare(strict_types=1);

/** Read-only review of application code; writes ONLY synthetic, disposable data.
 * Deliberately exits 1 when the reviewed P0 contract is violated. No config.php,
 * production credentials, schema reuse, schema drops, or application edits.
 * Run with PDO MySQL against a dedicated loopback MariaDB on port 33317.
 */
require_once dirname(__DIR__) . '/shared/epi/bootstrap.php';

use Hambelela\EPI\{Support,OwnershipPeriodEngine,DeadlineEngine,V2OperationalBridge,V2PerformanceQuery,
    QualityActivityBridge,EligibilityPolicy,PerformanceScore,BusinessTimeEngine,SourceCompletenessEngine,V2Store};

function connectAudit(?string $database = null): PDO {
    if ($database !== null && !preg_match('/^epi_p0_audit_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Unsafe audit database');
    $pdo = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4' . ($database ? ';dbname='.$database : ''), 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone='+02:00'");
    return $pdo;
}
if (($argv[1] ?? '') === '--worker') {
    $db=connectAudit($argv[2]);
    $db->exec('SET innodb_lock_wait_timeout=1');
    echo json_encode((new DeadlineEngine($db))->processDue('2026-10-01 18:00:00'));
    exit;
}
$database='epi_p0_audit_'.bin2hex(random_bytes(6));
$db=connectAudit();
$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$db->exec("USE `$database`");
$results=[];
function check(string $id, $expected, $actual): void {
    global $results;
    $results[]=['test'=>$id,'status'=>$expected===$actual?'PASS':'FAIL','expected'=>$expected,'actual'=>$actual];
}
function scalar(string $sql, array $params=[]){global $db;$s=$db->prepare($sql);$s->execute($params);return $s->fetchColumn();}
function row(string $sql,array $params=[]):array{global $db;$s=$db->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
function sql(string $sql,array $params=[]):void{global $db;$db->prepare($sql)->execute($params);}
function attempt(callable $fn):bool{try{$fn();return true;}catch(Throwable $e){return false;}}
function resetObjects():void{global $db;foreach(['epi_v2_outbox','epi_v2_object_duties','epi_v2_quality_revisions','epi_v2_exceptions','epi_v2_performance_incidents','epi_v2_operational_deadlines','epi_v2_ownership_periods','epi_v2_duty_periods','epi_v2_handovers','epi_v2_order_lifecycle_events','ops_orders','ops_checklist_tasks','ops_error_logs','epi_performance_logs']as$table)$db->exec('DELETE FROM '.$table);}
function owner(string $ref='O-1',int $employee=101,string $from='2026-10-01 08:00:00',string $module='Orders',?string $accepted=null):string{
    global $db;
    return(new OwnershipPeriodEngine($db))->assign(['module'=>$module,'object_reference'=>$ref,'employee_id'=>$employee,'assigned_by'=>999,'accepted_by'=>$employee,'effective_from'=>$from,'accepted_at'=>$accepted??$from]);
}
function duty(int $employee=101,string $from='2026-10-01 08:00:00',string $to='2026-10-01 17:00:00',string $level='primary'):string{
    global $db;return(new OwnershipPeriodEngine($db))->assignDuty(['duty_key'=>'front_desk','employee_id'=>$employee,'assigned_by'=>999,'accepted_by'=>$employee,'effective_from'=>$from,'effective_to'=>$to,'responsibility_level'=>$level,'reason'=>'approved test coverage','source'=>'fixture approval']);
}
function deadline(array $extra=[]):string{
    global $db;return(new DeadlineEngine($db))->schedule(array_replace(['module'=>'Orders','object_reference'=>'O-1','obligation_key'=>'move_order_out_of_new','breach_event_key'=>'order_new_sla_breached','responsible_employee_id'=>101,'responsible_team'=>'front_desk','source_event'=>'order_created','starts_at'=>'2026-10-01 08:00:00','due_at'=>'2026-10-01 08:30:00'],$extra));
}
function orderEvent(int $id=1,string $mode='courier',string $at='2026-10-01 08:00:00'):void{
    global $db;sql('INSERT INTO ops_orders(id,order_number,fulfilment_mode,order_type,status) VALUES(?,?,?,?,?)',[$id,'O-'.$id,$mode,$mode,'new_order']);
    V2OperationalBridge::record($db,'order','order_created',$id,['occurred_at'=>$at,'employee_id'=>101]);
}
function taskEvent(int $id=1,string $action='task_created'):void{
    global $db;sql("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,date_assigned,deadline) VALUES(?,101,999,'2026-10-01 08:00:00','2026-10-01 09:00:00')",[$id]);
    V2OperationalBridge::record($db,'checklist_task',$action,$id,['occurred_at'=>'2026-10-01 08:00:00','employee_id'=>999]);
}

// Minimal predecessor schema: not a substitute for a production schema snapshot.
$db->exec("CREATE TABLE epi_employee_performance_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(30),description TEXT);
CREATE TABLE epi_performance_score_events(id INT PRIMARY KEY,employee_id INT DEFAULT 101,period_start DATE DEFAULT '2026-09-01',period_end DATE DEFAULT '2026-09-30',confirmation_status VARCHAR(40),automatic_status VARCHAR(40),confidence_level VARCHAR(40),reversed INT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,confirmed_by INT,confirmed_at DATETIME,reviewer_note TEXT,applied_at DATETIME,evidence_uuid VARCHAR(100));
CREATE TABLE epi_employee_evidence(id INT PRIMARY KEY,evidence_uuid VARCHAR(100),employee_id INT,module VARCHAR(50),metadata_json LONGTEXT);
CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100));
INSERT INTO ops_employees VALUES(101,'Synthetic A'),(102,'Synthetic B'),(103,'Synthetic C'),(999,'Synthetic Owner');
CREATE TABLE epi_scoring_monthly_scores(id INT PRIMARY KEY,employee_id INT,score_year INT,score_month INT,locked INT,final_hundredths INT);
INSERT INTO epi_scoring_monthly_scores VALUES(1,101,2026,9,1,9000);
INSERT INTO epi_performance_score_events(id,confirmation_status,automatic_status,confidence_level,reversed) VALUES(1,'confirmed','needs_review','insufficient',0);
CREATE TABLE epi_employee_business_calendar(business_date DATE PRIMARY KEY,is_working_day INT,opens_at TIME,closes_at TIME);
CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number VARCHAR(190),fulfilment_mode VARCHAR(50),order_type VARCHAR(50),status VARCHAR(50),customer_name VARCHAR(100),customer_contact VARCHAR(100));
CREATE TABLE ops_checklist_tasks(id INT PRIMARY KEY,assigned_employee_id INT,created_by INT,date_assigned DATETIME,deadline DATETIME,released_at DATETIME,scheduled_at DATETIME);
CREATE TABLE ops_error_logs(id INT PRIMARY KEY,attribution_type VARCHAR(40),attributed_employee_id INT,responsible_employee_id INT,logged_by INT,affects_kpi_accuracy INT,accuracy_verified_by INT,attribution_verified_by INT,accuracy_verified_at DATETIME,attribution_verified_at DATETIME,occurred_at DATETIME,status VARCHAR(40),updated_at DATETIME,category VARCHAR(100),severity VARCHAR(40));
CREATE TABLE epi_performance_logs(id INT AUTO_INCREMENT PRIMARY KEY,level VARCHAR(30),component VARCHAR(100),message TEXT,context_json LONGTEXT);");
sql("INSERT INTO epi_performance_score_events(id,employee_id,confirmation_status,automatic_status,confidence_level,reversed) VALUES(2,103,'confirmed','automatically_applied','high',0),(3,103,'confirmed','reversed','high',1)");
$preservedBefore=['events'=>$db->query('SELECT * FROM epi_performance_score_events ORDER BY id')->fetchAll(),'monthly'=>$db->query('SELECT * FROM epi_scoring_monthly_scores ORDER BY id')->fetchAll()];
$migration=file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql');
$statements=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$migration))));
$cut=(int)floor(count($statements)/2);
foreach(array_slice($statements,0,$cut)as$s)$db->exec($s);
check('M01 partial migration leaves capture disabled','0',(string)scalar("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_capture_enabled'"));
foreach($statements as$s)$db->exec($s);
check('M02 legacy score event unchanged','needs_review',scalar('SELECT automatic_status FROM epi_performance_score_events WHERE id=1'));
check('M03 locked score row unchanged',9000,(int)scalar('SELECT final_hundredths FROM epi_scoring_monthly_scores WHERE id=1'));
$counts=(int)scalar('SELECT COUNT(*) FROM epi_v2_event_registry');
foreach($statements as$s)$db->exec($s);
check('M04 replay does not duplicate registry',$counts,(int)scalar('SELECT COUNT(*) FROM epi_v2_event_registry'));
sql("UPDATE epi_v2_event_registry SET active=0,description='owner custom definition' WHERE event_key='order_new_sla_breached'");
foreach($statements as$s)$db->exec($s);
check('M05 replay preserves configured registry',0,(int)scalar("SELECT active FROM epi_v2_event_registry WHERE event_key='order_new_sla_breached'"));
check('M06 immutable activation boundary exists',true,(bool)scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='epi_v2_activation'"));
// Explicit fixture policy, not a production default. Fresh migrations remain inactive.
sql("UPDATE epi_v2_event_registry SET active=1 WHERE event_key='order_new_sla_breached'");
V2Store::activate($db,'2026-10-01 00:00:00',999,['version'=>'fixture-1','calendar_version'=>'fixture-1','minimum_opportunity_minutes'=>30,'task_assignment_policy'=>'owner_directed','task_start_minutes'=>30,'orders'=>['courier'=>['new_minutes'=>30,'completion_minutes'=>60]]]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_capture_enabled'");
check('N01 migration field-equivalent old records',$preservedBefore,['events'=>$db->query('SELECT * FROM epi_performance_score_events ORDER BY id')->fetchAll(),'monthly'=>$db->query('SELECT * FROM epi_scoring_monthly_scores ORDER BY id')->fetchAll()]);
$engine=new DeadlineEngine($db);$owners=new OwnershipPeriodEngine($db);$query=new V2PerformanceQuery($db);

resetObjects();owner();$id=deadline();for($i=0;$i<10;$i++)$engine->processDue('2026-10-01 09:00:00');
check('D01 ten watchdog runs one persisted incident',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
check('D02 breach belongs to owner A',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
$engine->fulfil($id,102,'2026-10-01 09:05:00');
check('D03 later B completion resolves risk','resolved',scalar('SELECT current_risk_state FROM epi_v2_performance_incidents'));
check('D04 late completion preserves A breach',[101,'breach'],[(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'),scalar('SELECT historical_state FROM epi_v2_performance_incidents')]);
check('D05 completer B recorded',102,(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines'));
check('D06 repeat fulfilment is a no-op',false,$engine->fulfil($id,102,'2026-10-01 09:06:00'));
resetObjects();owner();$id=deadline();$engine->fulfil($id,102,'2026-10-01 08:31:00');$engine->processDue('2026-10-01 09:00:00');
check('D07 late completion BEFORE scan retains breach',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
foreach(['08:29:59','08:30:00']as$time){resetObjects();owner();$id=deadline();$engine->fulfil($id,101,'2026-10-01 '.$time);$engine->processDue('2026-10-01 09:00:00');check('D08 compliant completion '.$time,0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));}
resetObjects();owner();deadline();$engine->processDue('2026-10-01 08:30:00');check('D09 watchdog at exact deadline compliant',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
resetObjects();owner();deadline(['exception_id'=>123456]);$engine->processDue('2026-10-01 09:00:00');check('D10 arbitrary exception ID cannot excuse','breached',scalar('SELECT state FROM epi_v2_operational_deadlines'));
resetObjects();owner();deadline();owner('O-1',102,'2026-10-01 08:20:00');$engine->processDue('2026-10-01 09:00:00');check('D11 transfer before due gives B breach',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
resetObjects();owner('OLD',101,'2020-01-01 08:00:00');deadline(['object_reference'=>'OLD','starts_at'=>'2020-01-01 08:00:00','due_at'=>'2020-01-01 08:30:00']);$engine->processDue('2026-10-01 09:00:00');check('D12 historical data cannot become pending_rule','historical_recovered',scalar('SELECT eligibility_state FROM epi_v2_performance_incidents'));
resetObjects();deadline();$engine->processDue('2026-10-01 09:00:00');check('D13 no ownership is held for review','needs_review',scalar('SELECT eligibility_state FROM epi_v2_performance_incidents'));
resetObjects();owner();$id=deadline();$engine->processDue('2026-10-01 09:00:00');$incident=row('SELECT * FROM epi_v2_performance_incidents');$meta=json_decode((string)$incident['metadata_json'],true);$required=['deadline_uuid','obligation_key','responsible_employee_at_breach','responsible_team','ownership_uuid','sla_version','starts_at','due_at','actual_state','fulfilment_state','calendar_version','exception_state','object_reference'];check('D14 full immutable snapshot',[],array_values(array_diff($required,array_keys($meta+$incident))));

resetObjects();duty();check('O01 normal duty at 10:35',101,(int)($owners->dutyAt('front_desk','2026-10-01 10:35:00')['employee_id']??0));duty(102,'2026-10-01 12:00:00','2026-10-01 13:00:00','coverage');check('O02 lunch coverage overrides primary',102,(int)($owners->dutyAt('front_desk','2026-10-01 12:25:00')['employee_id']??0));
resetObjects();duty(101,'2026-10-01 08:00:00','2026-10-01 12:00:00');duty(102,'2026-10-01 12:00:00','2026-10-01 13:00:00','coverage');duty(101,'2026-10-01 13:00:00','2026-10-01 17:00:00');check('O03 explicit nonoverlapping coverage works',102,(int)($owners->dutyAt('front_desk','2026-10-01 12:25:00')['employee_id']??0));
resetObjects();owner('O-1',101,'2026-10-01 08:00:00','Orders','2026-10-01 10:00:00');check('O04 future acceptance cannot own earlier due',null,$owners->ownerAt('Orders','O-1','2026-10-01 09:00:00')['employee_id']??null);
resetObjects();owner();attempt(function(){owner('O-1',102,'2026-10-01 07:00:00');});check('O05 overlapping retroactive assignment rejected',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_periods WHERE effective_from<='2026-10-01 08:30:00' AND(effective_to IS NULL OR effective_to>'2026-10-01 08:30:00')"));
check('O06 invalid interval blocked by database',false,attempt(function(){sql("UPDATE epi_v2_ownership_periods SET effective_to='2026-09-01 00:00:00'");}));
resetObjects();attempt(function(){owner('O-1',123456);});check('O07 orphan employee rejected',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_ownership_periods'));
resetObjects();duty();orderEvent();check('O08 object ownership ends with duty','2026-10-01 17:00:00',scalar('SELECT effective_to FROM epi_v2_ownership_periods'));
resetObjects();owner();duty();$handover=$owners->initiateHandover(['duty_key'=>'front_desk','outgoing_employee_id'=>101,'incoming_employee_id'=>102,'initiated_by'=>101,'initiated_at'=>'2026-10-01 13:55:00','transfer_reason'=>'test coverage','open_orders'=>['O-1']]);$owners->acceptHandover($handover,102,'2026-10-01 14:10:00');check('O09 observed A owns until actual acceptance',101,(int)($owners->ownerAt('Orders','O-1','2026-10-01 14:00:00')['employee_id']??0));check('O10 B owns after acceptance',102,(int)($owners->ownerAt('Orders','O-1','2026-10-01 14:15:00')['employee_id']??0));check('O11 repeated acceptance no extra period',false,attempt(function()use($owners,$handover){$owners->acceptHandover($handover,102,'2026-10-01 14:10:00');}));check('O12 handover duty resolves B',102,(int)($owners->dutyAt('front_desk','2026-10-01 14:15:00')['employee_id']??0));

resetObjects();duty();orderEvent();$engine->processDue('2026-10-01 09:00:00');check('B01 untouched new order single root incident',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
check('B02 completion obligation is created',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE obligation_key='complete_order'"));
check('B03 team risk includes owned overdue work',2,count($query->teamRisk('front_desk')));check('B04 unrelated employee has no personal risk',0,count($query->personalRisk(102,'2026-10-01 10:00:00')));
owner('O-1',102,'2026-10-01 09:01:00');check('B05 current risk follows accepted new owner',2,count($query->personalRisk(102,'2026-10-01 10:00:00')));
resetObjects();orderEvent(1,'other');check('B06 unknown mode has no arbitrary SLA',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines'));
resetObjects();duty();$db->beginTransaction();orderEvent();$db->commit();check('B07 order capture inside caller transaction persists',2,(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines'));check('B08 transaction capture has no swallowed failure',0,(int)scalar('SELECT COUNT(*) FROM epi_performance_logs'));
resetObjects();duty();orderEvent();V2OperationalBridge::record($db,'order','status_changed',1,['occurred_at'=>'2026-10-01 08:15:00','old_value'=>'new_order','new_value'=>'cancelled','employee_id'=>101]);check('B09 cancellation creates cancelled not fulfilled state','cancelled',scalar('SELECT state FROM epi_v2_operational_deadlines LIMIT 1'));
resetObjects();duty();orderEvent();$engine->processDue('2026-10-01 10:00:00');V2OperationalBridge::record($db,'order','status_changed',1,['occurred_at'=>'2026-10-01 09:15:00','old_value'=>'new_order','new_value'=>'cancelled','employee_id'=>101]);check('B10 post-breach cancellation retains history',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE historical_state='breach'"));
resetObjects();taskEvent();$engine->processDue('2026-10-01 10:00:00');check('T01 untouched assigned task owns A breach',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents LIMIT 1'));
resetObjects();taskEvent();V2OperationalBridge::record($db,'checklist_task','task_completed',1,['occurred_at'=>'2026-10-01 08:20:00','employee_id'=>101]);$engine->processDue('2026-10-01 10:00:00');check('T02 early completed task no open start breach',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
resetObjects();taskEvent();V2OperationalBridge::record($db,'checklist_task','task_acknowledged',1,['occurred_at'=>'2026-10-01 08:10:00','employee_id'=>101]);sql('UPDATE ops_checklist_tasks SET assigned_employee_id=102 WHERE id=1');V2OperationalBridge::record($db,'checklist_task','task_reassigned',1,['occurred_at'=>'2026-10-01 08:20:00','employee_id'=>999]);$engine->processDue('2026-10-01 10:00:00');check('T03 task reassignment moves completion responsibility',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE event_key='task_completion_sla_breached'"));

// Test actual quality-capture entry point; legacy master mode defaults disabled.
resetObjects();sql("INSERT INTO ops_error_logs VALUES(1,'employee',101,101,102,1,999,999,'2026-10-01 09:00:00','2026-10-01 09:00:00','2026-10-01 08:00:00','open','2026-10-01 09:00:00','poor_communication','medium')");
QualityActivityBridge::record($db,'error_owner_reviewed',1,['actor_employee_id'=>999]);check('Q01 reporter B owner reviewer do not replace responsible A',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));$meta=json_decode((string)scalar('SELECT metadata_json FROM epi_v2_performance_incidents'),true);check('Q02 reporter and editor preserved',[102,999],[$meta['reporter_employee_id'],$meta['actor_employee_id']]);
sql("UPDATE ops_error_logs SET status='resolved' WHERE id=1");QualityActivityBridge::record($db,'error_status_updated',1,['actor_employee_id'=>999]);check('Q03 resolving quality incident clears current risk','resolved',scalar('SELECT current_risk_state FROM epi_v2_performance_incidents'));
sql("UPDATE ops_error_logs SET attributed_employee_id=103,status='open',attribution_verified_at='2026-10-01 10:00:00' WHERE id=1");QualityActivityBridge::record($db,'error_attribution_corrected',1,['actor_employee_id'=>999]);check('Q04 correction leaves only one eligible root',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE eligibility_state='pending_rule'"));
foreach(['system','business','supplier','courier','customer','shared','unknown']as$type){sql('UPDATE ops_error_logs SET attribution_type=? WHERE id=1',[$type]);QualityActivityBridge::record($db,'error_attribution_corrected',1,['actor_employee_id'=>999]);check('Q05 '.$type.' no employee scoring',null,scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents ORDER BY id DESC LIMIT 1'));}
// Every flag is fed through both central policy consumers, not regex-only testing.
$base=['module'=>'Orders','employee_id'=>101,'evidence_uuid'=>'test-evidence','occurred_at'=>'2026-10-01 08:00:00','business_date'=>'2026-10-01','reference_number'=>'O-1','action'=>'order_completed','recording_mode'=>'automatic'];
$automaticMethod=new ReflectionMethod(PerformanceScore::class,'automaticDecision');$automaticMethod->setAccessible(true);
$eligibilityMethod=new ReflectionMethod(SourceCompletenessEngine::class,'eligibility');$eligibilityMethod->setAccessible(true);
foreach(['excluded_from_scoring','test_data','duplicate','superseded','system_error','business_error','external_dependency','supplier_delay','courier_delay','customer_delay','approved_leave','approved_exception','system_outage','internet_outage','device_failure','insufficient_attribution']as$flag){
    $record=$base+['metadata_json'=>json_encode([$flag=>true])];
    $decision=$automaticMethod->invoke(new PerformanceScore($db),$record,[$flag=>true]);$eligibility=$eligibilityMethod->invoke(new SourceCompletenessEngine($db),$record);
    check('E01 '.$flag.' blocked in both classifiers',true,$decision['confirmation_status']!=='confirmed'&&!in_array($eligibility['state'],['automatically_eligible','verified_eligible'],true));
}
check('E02 string false does not exclude',null,EligibilityPolicy::exclusionReason($base,['excluded_from_scoring'=>'false']));
check('E03 mismatched responsible employee held',true,EligibilityPolicy::exclusionReason(['module'=>'Error Log','employee_id'=>102],['responsibility_type'=>'employee_error','responsibility_confirmed'=>true,'responsible_employee_id'=>101])!==null);
sql('INSERT INTO epi_employee_evidence VALUES(1,?,101,?,?)',['excluded-evidence','Orders',json_encode(['excluded_from_scoring'=>true,'system_error'=>true])]);
sql("UPDATE epi_performance_score_events SET confirmation_status='excused',automatic_status='automatically_excluded',evidence_uuid='excluded-evidence' WHERE id=1");attempt(function()use($db){(new PerformanceScore($db))->reviewEvent(1,'confirmed',999,'test reviewer attempting restore');});
$confirmedMethod=new ReflectionMethod(PerformanceScore::class,'confirmedEvents');$confirmedMethod->setAccessible(true);
check('E04 excluded evidence cannot reach monthly confirmed input',0,count($confirmedMethod->invoke(new PerformanceScore($db),101,'2026-09-01','2026-09-30')));

// Deterministic calendar tests with an explicit synthetic holiday, not actual HR policy.
$time=new BusinessTimeEngine($db);
$cases=['2026-10-01 07:59:00'=>'2026-10-01 08:30:00','2026-10-01 08:00:00'=>'2026-10-01 08:30:00','2026-10-01 16:59:00'=>'2026-10-02 08:29:00','2026-10-01 17:00:00'=>'2026-10-02 08:30:00','2026-10-01 20:00:00'=>'2026-10-02 08:30:00','2026-10-02 18:00:00'=>'2026-10-03 09:30:00','2026-10-03 09:00:00'=>'2026-10-03 09:30:00','2026-10-03 13:00:00'=>'2026-10-05 08:30:00','2026-10-04 12:00:00'=>'2026-10-05 08:30:00','2026-10-05 08:00:00'=>'2026-10-05 08:30:00','2026-10-31 13:00:00'=>'2026-11-02 08:30:00','2026-12-31 16:59:00'=>'2027-01-01 08:29:00'];
foreach($cases as$start=>$expected)check('C01 business calendar '.$start,$expected,$time->addWorkingMinutes($start,30)->format('Y-m-d H:i:s'));
sql("INSERT INTO epi_employee_business_calendar VALUES('2027-01-01',0,NULL,NULL)");$time=new BusinessTimeEngine($db);check('C02 configured holiday rolls to Saturday','2027-01-02 09:29:00',$time->addWorkingMinutes('2026-12-31 16:59:00',30)->format('Y-m-d H:i:s'));

// Failure injection after INSERT and before state UPDATE must roll back both.
resetObjects();owner();deadline();$db->exec("CREATE TRIGGER audit_fail_before_deadline_update BEFORE UPDATE ON epi_v2_operational_deadlines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit injected failure'");
check('F01 injected update failure is surfaced',false,attempt(function()use($engine){$engine->processDue('2026-10-01 09:00:00');}));check('F02 inserted incident rolls back',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));check('F03 failed deadline stays open','open',scalar('SELECT state FROM epi_v2_operational_deadlines'));
$db->exec('DROP TRIGGER audit_fail_before_deadline_update');$engine->processDue('2026-10-01 09:00:00');check('F04 retry produces exactly one incident',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));

// Independent PHP processes exercise actual InnoDB row locks and uniqueness.
resetObjects();for($n=1;$n<=20;$n++){owner('R-'.$n);deadline(['object_reference'=>'R-'.$n]);}
$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
// Linux packages ship PDO/mysqlnd as shared dependencies; Windows embeds them.
foreach(['mysqlnd','pdo','json'] as $dependency)if(PHP_OS_FAMILY!=='Windows'&&is_file(ini_get('extension_dir').'/'.$dependency.'.so'))array_push($command,'-d','extension='.$dependency);
array_push($command,'-d','extension=pdo_mysql',__FILE__,'--worker',$database);
$workers=[];for($n=0;$n<2;$n++){$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
foreach($workers as$n=>[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)fwrite(STDERR,$out.$err);check('F05 concurrent worker '.$n.' exits clean',0,$exit);}
check('F06 two watchdogs exactly twenty roots',20,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));

resetObjects();owner();deadline();$db->beginTransaction();$db->query('SELECT * FROM epi_v2_operational_deadlines FOR UPDATE')->fetchAll();
$pipes=[];$worker=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($worker);$db->rollBack();
check('F07 actual lock wait timeout surfaces',true,$status!==0 && strpos($out.$err,'1205')!==false);
$engine->processDue('2026-10-01 18:00:00');check('F08 timeout retry one incident',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));

resetObjects();owner();deadline();$signal='audit_disconnect_'.substr($database,-12);
$db->exec("CREATE TRIGGER audit_disconnect_before_update BEFORE UPDATE ON epi_v2_operational_deadlines FOR EACH ROW BEGIN DO GET_LOCK('$signal',0); DO SLEEP(20); END");
$pipes=[];$worker=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
$connection=0;$until=microtime(true)+5;while(microtime(true)<$until){$connection=(int)scalar('SELECT IS_USED_LOCK(?)',[$signal]);if($connection)break;usleep(20000);}
check('F09 worker reached insert-before-update boundary',true,$connection>0);
if($connection)$db->exec('KILL CONNECTION '.$connection);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($worker);
$db->exec('DROP TRIGGER audit_disconnect_before_update');check('F10 terminated DB session rolls back incident',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
$engine->processDue('2026-10-01 18:00:00');check('F11 session failure retry one incident',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));

// Read-only service invocation under an enforced read-only transaction.
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
check('R01 score constructor read-only',true,attempt(function()use($db){new PerformanceScore($db);}));
check('R02 V2 query methods read-only',true,attempt(function()use($query){$query->personalRisk(101);$query->teamRisk('front_desk');$query->history(101,'2026-10-01','2026-10-31');$query->explain((string)scalar('SELECT incident_uuid FROM epi_v2_performance_incidents LIMIT 1'));}));$db->rollBack();

require __DIR__.'/epi-v2-p0-remediation-cases.php';
require __DIR__.'/epi-v2-shadow-activation.php';
require __DIR__.'/epi-front-coverage-db.php';
require __DIR__.'/epi-orders-stages-db.php';
require __DIR__.'/epi-front-roster-db.php';
require __DIR__.'/epi-handover-checklist-db.php';
require __DIR__.'/epi-stage-readiness-db.php';
$summary=['database'=>$database,'server'=>scalar('SELECT VERSION()'),'timezone'=>'Africa/Windhoek','production_changes'=>false,'pass'=>count(array_filter($results,fn($r)=>$r['status']==='PASS')),'fail'=>count(array_filter($results,fn($r)=>$r['status']==='FAIL')),'tests'=>$results];
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($summary['fail']?1:0);
