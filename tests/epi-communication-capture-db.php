<?php
declare(strict_types=1);
use Hambelela\EPI\{OrderCommunicationCapture,EvidenceStatus,OwnershipPeriodEngine,DeadlineEngine,V2Store};
require_once dirname(__DIR__).'/shared/epi/OrderCommunicationCapture.php';
require_once dirname(__DIR__).'/shared/epi/EvidenceStatus.php';
// Fixture-only DDL, never production schema reuse.
$db->exec("ALTER TABLE ops_whatsapp_conversations MODIFY id INT NOT NULL AUTO_INCREMENT,
 ADD COLUMN IF NOT EXISTS platform ENUM('whatsapp','instagram','facebook','call','email') DEFAULT 'whatsapp',
 ADD COLUMN IF NOT EXISTS source VARCHAR(30),ADD COLUMN IF NOT EXISTS customer_name VARCHAR(190),ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(80),
 ADD COLUMN IF NOT EXISTS order_id INT,ADD COLUMN IF NOT EXISTS created_by INT,ADD COLUMN IF NOT EXISTS first_customer_message_at DATETIME,
 ADD COLUMN IF NOT EXISTS first_response_at DATETIME,ADD COLUMN IF NOT EXISTS last_staff_response_at DATETIME,ADD COLUMN IF NOT EXISTS last_message_at DATETIME;
 ALTER TABLE ops_orders ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
 CREATE TABLE IF NOT EXISTS ops_whatsapp_messages(id INT AUTO_INCREMENT PRIMARY KEY,conversation_id INT,platform ENUM('whatsapp','instagram','facebook','call','email'),wa_message_id VARCHAR(120) UNIQUE,direction VARCHAR(30),message_type VARCHAR(40),message_text TEXT,message_at DATETIME,employee_id INT,raw_payload LONGTEXT);
 CREATE TABLE IF NOT EXISTS ops_whatsapp_followups(id INT AUTO_INCREMENT PRIMARY KEY,conversation_id INT,due_at DATETIME,reason VARCHAR(1500),created_by INT,status VARCHAR(30) DEFAULT 'pending',completed_at DATETIME)");
$db->exec("ALTER TABLE ops_whatsapp_conversations MODIFY platform ENUM('whatsapp','instagram','facebook','custom') DEFAULT 'whatsapp'; ALTER TABLE ops_whatsapp_messages MODIFY platform ENUM('whatsapp','instagram','facebook','custom') DEFAULT 'whatsapp'; ALTER TABLE ops_cash_book_entries ADD COLUMN IF NOT EXISTS description VARCHAR(255)");
OrderCommunicationCapture::install($db);OrderCommunicationCapture::install($db);
check('CONTACT00 additive channel migration preserves pre-existing enum values',true,strpos((string)scalar("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ops_whatsapp_messages' AND COLUMN_NAME='platform'"),"'custom'")!==false);
$db->beginTransaction();
try{
    sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version='provisional-acceptance-test',assigned_by=999 WHERE employee_id IN(101,102) AND period_start='2026-10-01'");
    sql("UPDATE epi_v2_employee_result_heads SET locked_at=NULL WHERE employee_id IN(101,102) AND period_start='2026-10-01'");
    sql("INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES('epi_v2_manual_communication_from','2026-10-01 08:00:00') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    sql("INSERT INTO ops_orders(id,order_number,fulfilment_mode,order_type,customer_name,customer_contact,created_at,status) VALUES(10901,'COMM10901','collection','collection','Synthetic customer','0810000000','2026-10-01 08:00:00','new_order')");
    $uuid=static function(int $n):string{return sprintf('00000000-0000-4000-8000-%012d',$n);};
    $input=['event_uuid'=>$uuid(1),'contact_type'=>'inquiry','channel'=>'call','outcome'=>'Customer asked about availability','evidence_reference'=>'Synthetic call register','responsible_employee_id'=>101,'contact_at'=>'2026-10-01T08:00','follow_up_required'=>'no'];
    check('CONTACT01 missing evidence rejected',false,attempt(fn()=>OrderCommunicationCapture::save($db,10901,101,array_replace($input,['evidence_reference'=>'']),false,'2026-10-01 08:00:00')));
    $a=OrderCommunicationCapture::save($db,10901,101,$input,false,'2026-10-01 08:00:00');
    check('CONTACT02 actual Call channel persisted','call',scalar('SELECT platform FROM ops_whatsapp_messages WHERE id=?',[$a['message_id']]));
    $ref='CONVERSATION-'.$a['conversation_id'];
    check('CONTACT03 real inquiry starts configured response deadline','2026-10-01 08:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference=? AND obligation_key='customer_response'",[$ref]));
    check('CONTACT04 retry does not duplicate contact',true,OrderCommunicationCapture::save($db,10901,101,$input,false,'2026-10-01 08:00:00')['duplicate']);
    (new DeadlineEngine($db))->processDue('2026-10-01 08:31:00');
    check('CONTACT05 no-click breach belongs to assigned employee',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference=? AND event_key=?',[$ref,'customer_response_sla_breached']));
    $response=array_replace($input,['event_uuid'=>$uuid(2),'conversation_id'=>$a['conversation_id'],'contact_type'=>'response','channel'=>'email','contact_at'=>'2026-10-01T08:40','outcome'=>'Responded with verified availability','follow_up_required'=>'yes','promised_follow_up_at'=>'2026-10-01T10:00']);
    $b=OrderCommunicationCapture::save($db,10901,102,$response,false,'2026-10-01 08:40:00');
    check('CONTACT06 helper is recorded separately',102,(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE object_reference=? AND obligation_key=?',[$ref,'customer_response']));
    check('CONTACT07 original breach retained after helper response',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference=? AND event_key=?',[$ref,'customer_response_sla_breached']));
    check('CONTACT08 promise uses exact promised timestamp','2026-10-01 10:00:00',scalar('SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference=? AND obligation_key=?',[$ref,'customer_followup']));
    $key='ops_whatsapp_conversation:'.$a['conversation_id'].':contact:'.$b['message_id'];
    check('CONTACT09 a logged response does not imply good quality',false,json_decode(scalar('SELECT source_snapshot_json FROM epi_v2_completed_work_units WHERE opportunity_key=?',[$key]),true)['native_measurement']['measured']);
    $request=array_replace($input,['event_uuid'=>$uuid(3),'conversation_id'=>$a['conversation_id'],'contact_type'=>'handover_requested','contact_at'=>'2026-10-01T09:00','transfer_to'=>102]);
    $h=OrderCommunicationCapture::save($db,10901,101,$request,false,'2026-10-01 09:00:00');
    check('CONTACT10 request alone does not change ownership',101,(int)(new OwnershipPeriodEngine($db))->ownerAt('Orders',$ref,'2026-10-01 09:01:00')['employee_id']);
    $accept=array_replace($request,['event_uuid'=>$uuid(4),'contact_type'=>'handover_accepted','contact_at'=>'2026-10-01T09:05','handover_message_id'=>$h['message_id']]);
    check('CONTACT11 wrong employee cannot accept',false,attempt(fn()=>OrderCommunicationCapture::save($db,10901,103,$accept,false,'2026-10-01 09:05:00')));
    OrderCommunicationCapture::save($db,10901,102,$accept,false,'2026-10-01 09:05:00');
    check('CONTACT12 incoming ownership starts at acceptance',102,(int)(new OwnershipPeriodEngine($db))->ownerAt('Orders',$ref,'2026-10-01 09:05:00')['employee_id']);
    check('CONTACT13 original promise was not extended','2026-10-01 10:00:00',scalar('SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference=? AND obligation_key=?',[$ref,'customer_followup']));
    (new DeadlineEngine($db))->processDue('2026-10-01 10:01:00');
    check('CONTACT14 transferred missed follow-up belongs to accepted owner',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference=? AND event_key=?',[$ref,'customer_followup_breached']));
    $finish=array_replace($input,['event_uuid'=>$uuid(5),'conversation_id'=>$a['conversation_id'],'contact_type'=>'followup_completed','contact_at'=>'2026-10-01T10:10']);
    OrderCommunicationCapture::save($db,10901,102,$finish,false,'2026-10-01 10:10:00');
    check('CONTACT15 completed follow-up stores actual contact time','2026-10-01 10:10:00',scalar('SELECT completed_at FROM ops_whatsapp_followups WHERE conversation_id=?',[$a['conversation_id']]));
    OrderCommunicationCapture::reviewQuality($db,$b['message_id'],999,'accurate','Synthetic reviewed email','Owner verified the exact information','2026-10-01 11:00:00');
    check('CONTACT16 reviewed accuracy is measurable',true,json_decode(scalar('SELECT source_snapshot_json FROM epi_v2_completed_work_units WHERE opportunity_key=?',[$key]),true)['native_measurement']['measured']);
    check('CONTACT17 recorder cannot self-approve quality',false,attempt(fn()=>OrderCommunicationCapture::reviewQuality($db,$b['message_id'],101,'accurate','Proof','Self approval','2026-10-01 11:00:00')));
    sql("INSERT INTO ops_orders(id,order_number,fulfilment_mode,order_type,customer_name,created_at,status) VALUES(10902,'COMM10902','courier','courier','Synthetic new','2026-10-01 08:00:00','in_progress'),(10903,'COMM10903','walk_in','walk_in','Synthetic walk in','2026-10-01 08:00:00','new_order')");
    foreach([10901,10902,10903] as $oid)sql("INSERT INTO epi_v2_outbox(event_key,entity_type,entity_id,action,payload_json,state) VALUES(?,'order',?,'order_created',?,'done')",['synthetic-contact-required-'.$oid,$oid,json_encode(['metadata'=>['occurred_at'=>'2026-10-01 08:00:00']])]);
    check('CONTACT18 communication is not a courier completion prerequisite',true,attempt(fn()=>OrderCommunicationCapture::requireCompletion($db,[10902])));
    check('CONTACT19 recorded customer outcome allows collection completion',true,attempt(fn()=>OrderCommunicationCapture::requireCompletion($db,[10901])));
    check('CONTACT20 walk-in completion is unaffected',true,attempt(fn()=>OrderCommunicationCapture::requireCompletion($db,[10903])));
    check('CONTACT21 future contact rejected',false,attempt(fn()=>OrderCommunicationCapture::save($db,10901,101,array_replace($input,['event_uuid'=>$uuid(6),'contact_at'=>'2026-10-01T12:00']),false,'2026-10-01 11:00:00')));
    check('CONTACT22 bulk completion does not require communication records',true,attempt(fn()=>OrderCommunicationCapture::requireCompletion($db,[10901,10902])));
    $originalReview=OrderCommunicationCapture::read($db,10901)['records'];
    check('CONTACT23 original contact remains separate from current quality review',1,count(array_filter($originalReview,fn($r)=>($r['quality_review']['decision']??'')==='accurate')));
    $scope=['scope'=>['kind'=>'front_desk_coverage']];
    check('CONTACT24 accepted order communication is a proven Front function',true,\Hambelela\EPI\OperationalPerformanceProjector::withinFunctionScope($db,$scope,(new OwnershipPeriodEngine($db))->ownerAt('Orders',$ref,'2026-10-01 09:05:00')));
    $db->exec('SAVEPOINT capture_read_only_probe');
    $before=(int)scalar('SELECT COUNT(*) FROM epi_v2_ownership_audits');
    \Hambelela\EPI\OrderCommunicationCapture::read($db,10901);\Hambelela\EPI\OrderCommunicationCapture::qualityCandidates($db,101,'2026-10-01');
    check('CONTACT25 capture reads do not create audits',$before,(int)scalar('SELECT COUNT(*) FROM epi_v2_ownership_audits'));
    check('CONTACT26 late transcription does not backdate accepted handover',true,attempt(fn()=>OrderCommunicationCapture::save($db,10901,102,array_replace($response,['event_uuid'=>$uuid(26),'contact_at'=>'2026-10-01T08:41:00','follow_up_required'=>'no']),false,'2026-10-01 11:00:00')));
    check('CONTACT27 late transcription leaves current owner unchanged',102,(int)(new OwnershipPeriodEngine($db))->ownerAt('Orders',$ref,'2026-10-01 11:00:00')['employee_id']);
    check('STATUS01 unresolved unproven ownership is not an employee failure','not_measurable',EvidenceStatus::deadline(['state'=>'needs_attribution','due_at'=>'2026-10-01 08:30:00'],'2026-10-01 11:00:00')['key']);
    check('STATUS02 completed late keeps historical breach label','Completed after breach',EvidenceStatus::deadline(['state'=>'fulfilled','fulfilled_at'=>'2026-10-01 08:40:00','breach_incident_uuid'=>'fixture'],'2026-10-01 11:00:00')['label']);
    check('STATUS03 not yet due is pending, not zero','pending',EvidenceStatus::deadline(['state'=>'open','due_at'=>'2026-10-01 12:00:00'],'2026-10-01 11:00:00')['key']);
}finally{$db->rollBack();}
