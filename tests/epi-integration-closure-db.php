<?php
declare(strict_types=1);
// Uses only the disposable loopback fixture built by epi-v2-p0-forensic-db.php.
use Hambelela\EPI\{V2OperationalBridge,DeadlineEngine,PerformanceRefreshRuntime,EmployeePerformanceService,
    PerformanceIncidentReview,PerformanceCapturePolicy,TaskCorrectionDeadlineBridge,V2Store};
require_once __DIR__.'/../shared/epi/TaskCorrectionDeadlineBridge.php';
$db->exec('ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS active_correction_id INT NULL,
 ADD COLUMN IF NOT EXISTS correction_round_count INT DEFAULT 0,
 ADD COLUMN IF NOT EXISTS correction_due_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS released_at DATETIME NULL');
$db->exec('ALTER TABLE hambelela_waybills ADD COLUMN IF NOT EXISTS uploaded_by INT NULL,
 ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL,ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL;
 CREATE TABLE IF NOT EXISTS hambelela_waybill_orders(id INT AUTO_INCREMENT PRIMARY KEY,waybill_id INT,order_id INT,
 linked_by_employee_id INT,linked_at DATETIME,UNIQUE KEY(waybill_id,order_id)) ENGINE=InnoDB');
$db->beginTransaction();
try{
    resetObjects();
    foreach(['epi_v2_completed_work_units','epi_v2_incident_correlations','epi_v2_deadline_completion_eligibility',
        'epi_v2_employee_result_heads','epi_v2_employee_results','epi_v2_performance_refresh_queue'] as $t)$db->exec('DELETE FROM '.$t);
    foreach(['epi_v2_results_enabled'=>1,'epi_v2_provisional_capture_enabled'=>1,'epi_v2_official_capture_enabled'=>0,
        'epi_v2_front_roster_enabled'=>0,'epi_v2_orders_sla_enabled'=>0] as $k=>$v)
        sql('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$k,(string)$v]);
    $metric=['weight_hundredths'=>10000,'minimum_volume'=>1,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success'];
    $p=['version'=>'integration-closure-provisional','status'=>'provisional','role'=>'front_desk','financial_use_allowed'=>false,
        'provisional_capture'=>['enabled'=>true,'authorised_by'=>999,'authorised_at'=>'2026-10-01 07:00:00','from'=>'2026-10-01 08:00:00'],
        'categories'=>['tasks'=>['weight_hundredths'=>5000,'metrics'=>['completion'=>$metric]],
            'communication'=>['weight_hundredths'=>5000,'metrics'=>['accuracy'=>$metric]]]];
    $json=json_encode($p);sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$p['version'],'front_desk',$json,hash('sha256',$json)]);
    sql('UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,assigned_by=999,official_from=NULL,validation_approved_at=NULL,validation_approved_by=NULL',[$p['version']]);
    check('CLOSE01 provisional capture authorised',2,PerformanceCapturePolicy::captureMode($db,101,'2026-10-01 08:00:00'));
    sql("INSERT INTO ops_whatsapp_conversations VALUES(9801,101,'2026-10-01 08:00:00',NULL,NULL,'awaiting_response')");
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',9801,['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    sql("UPDATE ops_whatsapp_conversations SET status='resolved' WHERE id=9801");
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',9801,['employee_id'=>102,'occurred_at'=>'2026-10-01 09:00:00']);
    sql("INSERT INTO ops_error_logs(id,attribution_type,attributed_employee_id,responsible_employee_id,logged_by,affects_kpi_accuracy,accuracy_verified_by,attribution_verified_by,accuracy_verified_at,attribution_verified_at,occurred_at,status,updated_at,category,severity)
        VALUES(9801,'employee',101,101,102,1,999,999,'2026-10-01 10:00:00','2026-10-01 10:00:00','2026-10-01 08:30:00','resolved','2026-10-01 10:00:00','incorrect_stock_information','medium')");
    V2OperationalBridge::record($db,'error_log','error_owner_reviewed',9801,['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 10:00:00']);
    $review=PerformanceIncidentReview::read($db,101,'2026-10-01');
    check('CLOSE02 provisional work is selectable in existing profile',1,count($review['work']));
    check('CLOSE03 provisional choice retains provisional label','provisional',$review['work'][0]['capture_status']);
    $unit=$review['work'][0]['key'];$snap=json_decode(scalar('SELECT source_snapshot_json FROM epi_v2_completed_work_units WHERE opportunity_key=?',[$unit]),true);
    check('CLOSE04 no official capture fabricated',false,$snap['score_eligible_at_capture']);
    $input=['source_key'=>'error:9801','revision_id'=>$review['incidents'][0]['revision_id'],'opportunity_key'=>$unit,
        'category_key'=>'communication','metric_key'=>'accuracy','root_incident_id'=>'error:9801','reason'=>'Owner-confirmed stock-information error for this exact conversation'];
    PerformanceIncidentReview::save($db,101,'2026-10-01',$input,999,'2026-10-01 10:01:00');
    PerformanceRefreshRuntime::run($db);$svc=new EmployeePerformanceService($db);$r=$svc->read(101,'2026-10-01');
    check('CLOSE05 confirmed provisional communication error affects correct employee',0,$r['categories']['communication']['metrics']['accuracy']['rate_hundredths']);
    check('CLOSE06 reporter and fulfiller receive no inherited error',0,count($svc->read(102,'2026-10-01')['evidence']));
    check('CLOSE07 official result remains unavailable',null,$r['official_score_hundredths']);
    sql("UPDATE epi_v2_completed_work_units SET source_snapshot_json=JSON_SET(source_snapshot_json,'$.capture_mode',0) WHERE opportunity_key=?",[$unit]);
    check('CLOSE08 shadow units cannot be promoted by review',0,count(PerformanceIncidentReview::read($db,101,'2026-10-01')['work']));
    sql("UPDATE epi_v2_completed_work_units SET source_snapshot_json=JSON_SET(source_snapshot_json,'$.capture_mode',2,'$.management_review_required',true) WHERE opportunity_key=?",[$unit]);
    check('CLOSE09 management-corrected work stays review-gated',0,count(PerformanceIncidentReview::read($db,101,'2026-10-01')['work']));

    sql("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,date_assigned,released_at,deadline,checklist_items,checked_items,completion_note_required,completion_evidence_required,completion_note)
        VALUES(9801,101,999,'2026-10-01 08:00:00','2026-10-01 08:00:00','2026-10-01 09:00:00','[]','[]',0,0,'')");
    V2OperationalBridge::record($db,'checklist_task','task_created',9801,['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    V2OperationalBridge::record($db,'checklist_task','task_completed',9801,['employee_id'=>101,'occurred_at'=>'2026-10-01 08:20:00']);
    $original=scalar("SELECT deadline_uuid FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9801' AND obligation_key='complete_task'");
    sql("UPDATE ops_checklist_tasks SET active_correction_id=8801,correction_round_count=1,correction_due_at='2026-10-01 11:00:00',deadline='2026-10-01 11:00:00',released_at='2026-10-01 08:00:00' WHERE id=9801");
    $meta=['employee_id'=>101,'actor_employee_id'=>999,'correction_id'=>8801,'correction_round'=>1,'correction_due_at'=>'2026-10-01 11:00:00','occurred_at'=>'2026-10-01 10:00:00'];
    V2OperationalBridge::record($db,'checklist_task','task_correction_requested',9801,$meta);
    check('CLOSE10 new correction creates independent obligations',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE source_event='task_correction_requested'"));
    check('CLOSE11 original completed deadline preserved','fulfilled',scalar('SELECT state FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$original]));
    check('CLOSE12 owner actor is not replaced by target employee',999,(int)json_decode(scalar("SELECT policy_snapshot_json FROM epi_v2_operational_deadlines WHERE source_event='task_correction_requested' LIMIT 1"),true)['source_evidence']['requested_by']);
    $revision=['employee_id'=>999,'actor_employee_id'=>999,'correction_id'=>8801,'correction_due_at'=>'2026-10-01 11:30:00','occurred_at'=>'2026-10-01 10:15:00'];
    V2OperationalBridge::record($db,'checklist_task','task_correction_due_date_changed',9801,$revision);
    check('CLOSE13 advance correction due-date adjustment recorded','2026-10-01 11:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE source_event='task_correction_requested' AND obligation_key='complete_task'"));
    (new DeadlineEngine($db))->processDue('2026-10-01 12:00:00');
    check('CLOSE14 untouched correction creates breach without a click',101,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='TASK-9801' AND event_key='task_completion_sla_breached'"));
    V2OperationalBridge::record($db,'checklist_task','task_correction_due_date_changed',9801,array_replace($revision,['correction_due_at'=>'2026-10-01 14:00:00','occurred_at'=>'2026-10-01 12:10:00']));
    check('CLOSE15 late extension cannot erase deadline','2026-10-01 11:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE source_event='task_correction_requested' AND obligation_key='complete_task'"));
    V2OperationalBridge::record($db,'checklist_task','task_correction_completed',9801,['employee_id'=>102,'actor_employee_id'=>102,'occurred_at'=>'2026-10-01 12:15:00']);
    check('CLOSE16 correction helper recorded as fulfiller',102,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE source_event='task_correction_requested' AND obligation_key='complete_task'"));
    check('CLOSE17 late helper cannot inherit A historical breach',101,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='TASK-9801' AND event_key='task_completion_sla_breached'"));
    check('CLOSE18 correction completion uses its own release cohort','2026-10-01 10:00:00',scalar("SELECT starts_at FROM epi_v2_completed_work_units WHERE opportunity_key='checklist_task:9801:1'"));
    check('CLOSE19 original completion timestamp remains intact','2026-10-01 08:20:00',scalar("SELECT completed_at FROM epi_v2_completed_work_units WHERE opportunity_key='checklist_task:9801:0'"));
    check('CLOSE20 historical correction breach remains resolved not deleted','resolved',scalar("SELECT current_risk_state FROM epi_v2_performance_incidents WHERE object_reference='TASK-9801' AND event_key='task_completion_sla_breached'"));
    sql('UPDATE ops_checklist_tasks SET active_correction_id=8802,correction_round_count=2 WHERE id=9801');
    V2OperationalBridge::record($db,'checklist_task','task_correction_requested',9801,array_replace($meta,['correction_id'=>8802,'correction_round'=>2,'correction_due_at'=>'2026-10-01 15:00:00','occurred_at'=>'2026-10-01 13:00:00']));
    V2OperationalBridge::record($db,'checklist_task','task_correction_cancelled',9801,['employee_id'=>999,'actor_employee_id'=>999,'correction_id'=>8802,'occurred_at'=>'2026-10-01 13:15:00']);
    check('CLOSE21 cancellation retains prior historical failure',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE object_reference='TASK-9801' AND event_key='task_completion_sla_breached'"));
    check('CLOSE22 cancelled round removed from current risk',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9801' AND state IN('open','breached','needs_attribution')"));
    check('CLOSE23 correction capture has no failed outbox events',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'"));
    check('CLOSE24 non-owner cannot request directed correction',false,attempt(function()use($db,$meta){TaskCorrectionDeadlineBridge::capture($db,'task_correction_requested',row('SELECT * FROM ops_checklist_tasks WHERE id=9801'),array_replace($meta,['actor_employee_id'=>102]));}));
    $h=(new Hambelela\EPI\V2PerformanceQuery($db))->watchdogHealth('2030-10-01 12:00:00');
    check('CLOSE25 stopped or disabled scheduler is not healthy',true,!$h['enabled']||$h['unhealthy']);
    $calc=Hambelela\EPI\RateScoreCalculator::calculate(['version'=>'coverage-detail','categories'=>['quality'=>['weight_hundredths'=>10000,'metrics'=>['accuracy'=>$metric]]]],['quality'=>['accuracy'=>['eligible_volume'=>2,'numerator'=>1,'source_complete'=>false,'source_coverage_reason'=>'A quantity variance awaits confirmed responsibility.']]]);
    check('CLOSE26 specific source blocker retained in result','A quantity variance awaits confirmed responsibility.',$calc['categories']['quality']['metrics']['accuracy']['source_coverage_reason']);
    $config=json_decode(scalar("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'"),true);
    $frontEntry=array_values(array_filter($config['employees'],static fn(array $e):bool=>(int)$e['employee_id']===101))[0];
    $oldPolicy=scalar('SELECT policy_json FROM epi_v2_scorecard_documents WHERE version=?',[$frontEntry['version']]);
    $current=Hambelela\EPI\Support::timestamp()->format('Y-m-01');$next=Hambelela\EPI\Support::timestamp()->modify('first day of next month')->format('Y-m-01');
    sql('UPDATE epi_v2_scorecard_assignments SET scorecard_version=? WHERE employee_id=101 AND period_start=?',[$frontEntry['version'],$current]);
    sql('INSERT IGNORE INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,assigned_by) VALUES(101,?,?,999)',[$next,$frontEntry['version']]);
    sql('INSERT IGNORE INTO epi_v2_employee_result_heads(employee_id,period_start,locked_at) VALUES(101,?,NOW())',[$next]);
    sql('UPDATE epi_v2_employee_result_heads SET locked_at=NOW() WHERE employee_id=101 AND period_start=?',[$next]);
    $lockedVersion=scalar('SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=101 AND period_start=?',[$next]);
    $packerVersion=scalar('SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=102 AND period_start=?',[$current]);
    $revision=Hambelela\EPI\ProvisionalScorecardAssignments::reviseFrontOrders($db,999,'Explicit owner approval of provisional 85/15 Front Orders allocation');
    $newVersion=scalar('SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=101 AND period_start=?',[$current]);
    $newPolicy=json_decode(scalar('SELECT policy_json FROM epi_v2_scorecard_documents WHERE version=?',[$newVersion]),true);
    check('CLOSE27 Front overall Orders category weight unchanged',2500,$newPolicy['categories']['orders']['weight_hundredths']);
    check('CLOSE28 Front completion SLA uses approved provisional 85%',8500,$newPolicy['categories']['orders']['metrics']['completion']['weight_hundredths']);
    check('CLOSE29 packer-controlled New measures removed from Front',false,isset($newPolicy['categories']['orders']['metrics']['progression']));
    check('CLOSE30 unrelated Front category weight retained',2000,$newPolicy['categories']['communication']['weight_hundredths']);
    check('CLOSE31 original scorecard document immutable',$oldPolicy,scalar('SELECT policy_json FROM epi_v2_scorecard_documents WHERE version=?',[$frontEntry['version']]));
    check('CLOSE32 locked month keeps its scorecard version',$lockedVersion,scalar('SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=101 AND period_start=?',[$next]));
    check('CLOSE33 packer scorecard unchanged',$packerVersion,scalar('SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=102 AND period_start=?',[$current]));
    check('CLOSE34 repeated policy approval is idempotent',[],Hambelela\EPI\ProvisionalScorecardAssignments::reviseFrontOrders($db,999,'Same owner approval replay')['changed']);
    sql("INSERT IGNORE INTO ops_roles(id,role_key,name) VALUES(4,'marketing_sales','Marketing & Sales')");
    sql('UPDATE ops_employees SET role_id=4 WHERE id=103');
    foreach(['epi_v2_employee_result_heads','epi_v2_employee_results','epi_v2_performance_refresh_queue','epi_v2_scorecard_assignments'] as $t)sql('DELETE FROM '.$t.' WHERE employee_id=103');
    $coverage=Hambelela\EPI\ProvisionalScorecardAssignments::addFrontCoverage($db,999,103,'Previously authorised Marketing assistant Front Desk coverage tracking');
    $coveragePolicy=json_decode(scalar('SELECT policy_json FROM epi_v2_scorecard_documents WHERE version=?',[$coverage['version']]),true);
    check('CLOSE35 Marketing has explicit function scope not generic role score','front_desk_coverage',$coveragePolicy['scope']['kind']);
    check('CLOSE36 Marketing coverage retains authorised Front split',8500,$coveragePolicy['categories']['orders']['metrics']['completion']['weight_hundredths']);
    check('CLOSE37 unrelated Marketing task ownership excluded',false,Hambelela\EPI\OperationalPerformanceProjector::withinFunctionScope($db,$coveragePolicy,['employee_id'=>103,'source'=>'approved_directed_task_assignment']));
    check('CLOSE38 accepted Front duty is eligible function scope',true,Hambelela\EPI\OperationalPerformanceProjector::withinFunctionScope($db,$coveragePolicy,['employee_id'=>103,'duty_key'=>'front_desk']));
    check('CLOSE39 accepted counter order is eligible function scope',true,Hambelela\EPI\OperationalPerformanceProjector::withinFunctionScope($db,$coveragePolicy,['employee_id'=>103,'source'=>'accepted_counter_order_assignment']));
    check('CLOSE40 coverage activation is idempotent','already_configured',Hambelela\EPI\ProvisionalScorecardAssignments::addFrontCoverage($db,999,103,'Same coverage activation replay')['status']);
    $frontOwners=new Hambelela\EPI\OwnershipPeriodEngine($db);
    duty(101,'2026-10-01 08:00:00','2026-10-01 17:00:00');
    $counter=['id'=>9810,'created_at'=>'2026-10-01 08:00:00','order_type'=>'walk_in','fulfilment_mode'=>'walk_in','status'=>'new_order','portal_paid_confirmed'=>0,'assigned_packer_id'=>101];
    sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_orders_sla_enabled'");
    Hambelela\EPI\OrdersStageBridge::capture($db,'order_created',$counter,['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    check('CLOSE41 automatic Packed By default does not pin Front duty',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_periods WHERE module='Orders' AND object_reference='ORDER-9810'"));
    $counter['assigned_packer_id']=103;
    Hambelela\EPI\OrdersStageBridge::capture($db,'field_updated',$counter,['employee_id'=>101,'actor_employee_id'=>101,'field'=>'assigned_packer_id','occurred_at'=>'2026-10-01 09:00:00']);
    check('CLOSE42 naming someone else does not fabricate acceptance',101,(int)$frontOwners->ownerAt('Orders','ORDER-9810','2026-10-01 09:01:00')['employee_id']);
    Hambelela\EPI\OrdersStageBridge::capture($db,'field_updated',$counter,['employee_id'=>103,'actor_employee_id'=>103,'field'=>'assigned_packer_id','occurred_at'=>'2026-10-01 10:00:00']);
    check('CLOSE43 explicit counter self-assignment transfers responsibility',103,(int)$frontOwners->ownerAt('Orders','ORDER-9810','2026-10-01 10:01:00')['employee_id']);
    check('CLOSE44 earlier responsibility is not rewritten',101,(int)$frontOwners->ownerAt('Orders','ORDER-9810','2026-10-01 09:59:59')['employee_id']);
    check('CLOSE45 counter transfer does not change walk-in deadline','2026-10-01 13:00:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE module='Orders' AND object_reference='ORDER-9810'"));
    check('CLOSE46 counter transfer cannot alter order identity','ORDER-9810',scalar('SELECT object_reference FROM epi_v2_orders_tracking WHERE order_id=9810'));
    $currentCourier=array_replace($counter,['fulfilment_mode'=>'courier','order_type'=>'courier']);
    check('CLOSE47 preserve newer live current-mode payment guard',false,attempt(function()use($db,$currentCourier){Hambelela\EPI\OrdersStageBridge::assertStatusAllowed($db,$currentCourier,'in_progress');}));
    check('CLOSE48 original historical classification remains immutable','walk_in',scalar('SELECT classification FROM epi_v2_orders_tracking WHERE order_id=9810'));
    require __DIR__.'/epi-courier-upload-db.php';
    require __DIR__.'/epi-provisional-cash-controls-db.php';
}finally{$db->rollBack();}
