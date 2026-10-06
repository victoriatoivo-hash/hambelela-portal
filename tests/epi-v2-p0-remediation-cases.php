<?php
// Included by the loopback-only forensic harness. Uses its synthetic connection.
use Hambelela\EPI\{Support,V2Store,V2Watchdog,V2OperationalBridge,QualityActivityBridge,DeadlineEngine};

check('N02 activation cannot be configured twice',false,attempt(function()use($db){V2Store::activate($db,'2020-01-01',999,['version'=>'bad','calendar_version'=>'bad']);}));
check('N03 database activation update blocked',false,attempt(function(){sql("UPDATE epi_v2_activation SET enforcement_start_at='2020-01-01'");}));
check('N04 database activation deletion blocked',false,attempt(function(){sql('DELETE FROM epi_v2_activation');}));

resetObjects();owner();$id=deadline(['due_at'=>'2026-10-01 10:00:00']);$engine->fulfil($id,102,'2026-10-01 10:17:00');$engine->processDue('2026-10-01 10:20:00');
check('N05 late helper completion before scan one resolved breach',[1,'resolved',101,102,'17.00'],[(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'),scalar('SELECT current_risk_state FROM epi_v2_performance_incidents'),(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'),(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines'),scalar('SELECT late_business_minutes FROM epi_v2_operational_deadlines')]);
check('N06 late evidence snapshot stores 17 minutes',17,json_decode(scalar('SELECT metadata_json FROM epi_v2_performance_incidents'),true)['late_business_minutes']);

resetObjects();owner();duty();$h=$owners->initiateHandover(['duty_key'=>'front_desk','outgoing_employee_id'=>101,'incoming_employee_id'=>102,'initiated_by'=>101,'initiated_at'=>'2026-10-01 13:55:00','transfer_reason'=>'coverage','open_orders'=>['O-1']]);
check('N07 unaccepted handover keeps A',101,(int)$owners->ownerAt('Orders','O-1','2026-10-01 14:05:00')['employee_id']);
$owners->acceptHandover($h,102,'2026-10-01 14:10:00');
check('N08 accepted handover boundary A then B',[101,102],[(int)$owners->ownerAt('Orders','O-1','2026-10-01 14:05:00')['employee_id'],(int)$owners->ownerAt('Orders','O-1','2026-10-01 14:15:00')['employee_id']]);
deadline(['due_at'=>'2026-10-01 14:15:00']);$engine->processDue('2026-10-01 14:16:00');check('N09 short coverage preserves attribution but holds eligibility',[102,'needs_review'],[(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'),scalar('SELECT eligibility_state FROM epi_v2_performance_incidents')]);

resetObjects();duty();orderEvent();V2Store::approveAbsence($db,101,'2026-10-01 08:00:00','2026-10-01 17:00:00',999,'approved sick leave','HR fixture','2026-10-01 07:00:00');
check('N10 absent with no coverage is unattributed',null,$owners->ownerAt('Orders','O-1','2026-10-01 08:30:00'));
$engine->processDue('2026-10-01 08:31:00');check('N11 absent A receives no attributed breach',null,scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
resetObjects();duty();orderEvent();V2Store::approveAbsence($db,101,'2026-10-01 08:00:00','2026-10-01 17:00:00',999,'approved sick leave','HR fixture','2026-10-01 07:00:00');duty(102,'2026-10-01 08:00:00','2026-10-01 17:00:00','coverage');
$engine->processDue('2026-10-01 08:31:00');check('N12 accepted full-shift absence coverage belongs to B',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));

resetObjects();duty();sql("INSERT INTO ops_orders(id,order_number,fulfilment_mode,order_type,status) VALUES(1,'O-1','courier','courier','new_order')");
V2OperationalBridge::record($db,'order','order_created',1,['occurred_at'=>'2026-10-01 08:00:00','original_created_at'=>'2026-09-30 10:00:00','employee_id'=>101]);$engine->processDue('2026-10-01 10:00:00');
check('N13 recovered open backlog never eligible',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE eligibility_state='pending_rule'"));
resetObjects();duty();sql("INSERT INTO ops_orders(id,order_number,fulfilment_mode,order_type,status) VALUES(1,'O-1','courier','courier','new_order')");
V2OperationalBridge::record($db,'order','order_created',1,['occurred_at'=>'2026-10-01 08:00:00','original_created_at'=>'2026-09-30 10:00:00','accepted_backlog_at'=>'2026-10-01 08:00:00','employee_id'=>101]);
check('N14 explicitly accepted backlog starts prospectively','2026-10-01 08:00:00',scalar('SELECT MIN(starts_at) FROM epi_v2_operational_deadlines'));
$snap=json_decode(scalar('SELECT policy_snapshot_json FROM epi_v2_operational_deadlines LIMIT 1'),true);check('N15 backlog preserves original provenance',['2026-09-30 10:00:00',true],[$snap['original_created_at'],$snap['historical_record']]);

resetObjects();sql("INSERT INTO ops_error_logs VALUES(1,'employee',101,101,102,1,999,999,'2026-10-01 09:00:00','2026-10-01 09:00:00','2026-10-01 08:00:00','open','2026-10-01 09:00:00','poor_communication','medium')");QualityActivityBridge::record($db,'error_owner_reviewed',1,['actor_employee_id'=>999]);sql("UPDATE ops_error_logs SET attributed_employee_id=102,attribution_verified_at='2026-10-01 09:10:00' WHERE id=1");QualityActivityBridge::record($db,'error_attribution_corrected',1,['actor_employee_id'=>999]);sql("UPDATE ops_error_logs SET severity='high' WHERE id=1");QualityActivityBridge::record($db,'error_updated',1,['actor_employee_id'=>999]);
check('N16 quality corrections one root three revisions',[1,3,1],[(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'),(int)scalar('SELECT COUNT(*) FROM epi_v2_quality_revisions'),(int)scalar('SELECT COUNT(*) FROM epi_v2_quality_revisions WHERE eligible=1')]);
check('N17 old quality identity still audit-visible',101,json_decode(scalar('SELECT snapshot_json FROM epi_v2_quality_revisions ORDER BY id LIMIT 1'),true)['responsible_employee_id']);
check('N18 second active quality revision rejected by DB',false,attempt(function(){sql("INSERT INTO epi_v2_quality_revisions(root_incident_id,revision_hash,employee_id,eligible,snapshot_json) VALUES('error:1',REPEAT('a',64),101,1,'{}')");}));

resetObjects();duty();$db->exec("CREATE TRIGGER audit_outbox_failure BEFORE INSERT ON epi_v2_operational_deadlines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='transient fixture capture failure'");orderEvent();
check('N19 failed capture retained for replay',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'"));
$db->exec('DROP TRIGGER audit_outbox_failure');V2OperationalBridge::replay($db);check('N20 replay recovers both obligations exactly',[2,0],[(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines'),(int)scalar("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'")]);
resetObjects();duty();$db->beginTransaction();orderEvent();$db->rollBack();check('N21 caller rollback leaves no orphan outbox/deadlines',[0,0],[(int)scalar('SELECT COUNT(*) FROM epi_v2_outbox'),(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines')]);

resetObjects();owner();deadline();check('N22 watchdog disabled after migration','disabled',V2Watchdog::run($db,'2026-10-01 09:00:00')['status']);sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_watchdog_enabled'");
$result=V2Watchdog::run($db,'2026-10-01 09:00:00');check('N23 worker persists successful health','success',scalar('SELECT status FROM epi_v2_watchdog_runs ORDER BY id DESC LIMIT 1'));
check('N24 worker persists last success',true,!empty($query->watchdogHealth()['last_success']));
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_watchdog_enabled'");

// Real InnoDB deadlock, not a mocked exception. Make the parent transaction
// heavier, so the small worker becomes the victim while holding a deadline row.
resetObjects();owner();deadline();
$db->exec('CREATE TABLE audit_deadlock_locks(id INT PRIMARY KEY,v INT) ENGINE=InnoDB');
for($n=1;$n<=100;$n++)sql('INSERT INTO audit_deadlock_locks VALUES(?,0)',[$n]);
$deadlockSignal='deadlock_'.substr($database,-12);
$db->exec("CREATE TRIGGER audit_deadlock_cycle BEFORE INSERT ON epi_v2_performance_incidents FOR EACH ROW BEGIN DECLARE held INT; DO GET_LOCK('$deadlockSignal',0); SELECT id INTO held FROM audit_deadlock_locks WHERE id=1 FOR UPDATE; END");
$db->beginTransaction();$db->exec('UPDATE audit_deadlock_locks SET v=1');
$pipes=[];$child=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
$reached=0;$until=microtime(true)+5;while(microtime(true)<$until){$reached=(int)scalar('SELECT IS_USED_LOCK(?)',[$deadlockSignal]);if($reached)break;usleep(10000);}
check('N25 actual deadlock boundary reached',true,$reached>0);
$parentError='';try{$db->exec('UPDATE epi_v2_operational_deadlines SET updated_at=updated_at');$db->commit();}catch(Throwable $e){$parentError=$e->getMessage();if($db->inTransaction())$db->rollBack();}
$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($child);$db->exec('DROP TRIGGER audit_deadlock_cycle');
check('N26 real 1213 deadlock detected',true,strpos($output.$parentError,'1213')!==false);
$engine->processDue('2026-10-01 18:00:00');check('N27 deadlock retry one root',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));

// All P0 records remain explicitly shadow-only, including eligible candidates.
check('N28 P0 incident cannot claim official inclusion',true,(bool)json_decode(scalar('SELECT metadata_json FROM epi_v2_performance_incidents LIMIT 1'),true)['excluded_from_scoring']);

resetObjects();sql("INSERT INTO ops_error_logs VALUES(1,'employee',101,101,102,1,999,999,'2026-10-01 09:00:00','2026-10-01 09:00:00','2026-10-01 08:00:00','open','2026-10-01 09:00:00','poor_communication','medium')");
$db->exec("CREATE TRIGGER audit_quality_retry BEFORE INSERT ON epi_v2_quality_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='quality capture unavailable'");
QualityActivityBridge::record($db,'error_owner_reviewed',1,['actor_employee_id'=>999]);
sql("UPDATE ops_error_logs SET attributed_employee_id=102,attribution_verified_at='2026-10-01 09:10:00' WHERE id=1");QualityActivityBridge::record($db,'error_attribution_corrected',1,['actor_employee_id'=>999]);
check('N29 newer quality event waits for failed predecessor',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
$db->exec('DROP TRIGGER audit_quality_retry');V2OperationalBridge::replay($db);V2OperationalBridge::replay($db);
check('N30 quality retry ordering preserves final B',[102,1],[(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'),(int)scalar('SELECT COUNT(*) FROM epi_v2_quality_revisions WHERE eligible=1')]);

resetObjects();owner();deadline();$engine->processDue('2026-10-01 09:00:00');$root=scalar('SELECT incident_uuid FROM epi_v2_performance_incidents');
sql("INSERT INTO epi_v2_exceptions(module,object_reference,effective_from,effective_to,approved_at,approved_by,reason,source,kind) VALUES('Orders','O-1','2026-10-01 08:00:00','2026-10-01 12:00:00','2026-10-01 10:00:00',999,'reviewed outage','owner evidence','obligation_excusal')");$exception=(int)$db->lastInsertId();
$engine->processDue('2026-10-01 10:05:00');check('N31 late exception does not silently excuse','pending_rule',scalar('SELECT eligibility_state FROM epi_v2_performance_incidents'));
$engine->excuseIncident($root,$exception,999,'Owner reviewed outage evidence');
check('N32 audited late excusal preserves historical root',[$root,'breach','excluded'],[scalar('SELECT incident_uuid FROM epi_v2_performance_incidents'),scalar('SELECT historical_state FROM epi_v2_performance_incidents'),scalar('SELECT eligibility_state FROM epi_v2_performance_incidents')]);
check('N33 exception review audit persisted',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_audits WHERE scope_key=?",['incident|'.$root]));
