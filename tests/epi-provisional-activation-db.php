<?php
declare(strict_types=1);
// Synthetic-only integration scenario, included by the isolated database suite.
use Hambelela\EPI\{PerformanceCapturePolicy,PerformanceRefreshRuntime,EmployeePerformanceService,
    DeadlineEngine,OperationalPerformanceProjector,V2OperationalBridge,IncidentCorrelation,V2PerformanceQuery};
resetObjects();
foreach(['epi_v2_employee_result_heads','epi_v2_employee_results','epi_v2_performance_refresh_queue',
    'epi_v2_completed_work_units','epi_v2_incident_correlations','epi_v2_deadline_completion_eligibility',
    'ops_whatsapp_conversations'] as $table)$db->exec('DELETE FROM '.$table);
$legacyBefore=$db->query('SELECT * FROM epi_employee_evidence ORDER BY id')->fetchAll();
$legacyScoreBefore=$db->query('SELECT * FROM epi_scoring_monthly_scores ORDER BY id')->fetchAll();
foreach(['epi_v2_results_enabled'=>1,'epi_v2_provisional_capture_enabled'=>1,'epi_v2_official_capture_enabled'=>0,
    'epi_v2_front_roster_enabled'=>0,'epi_v2_orders_sla_enabled'=>0] as $key=>$value)
    sql('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,(string)$value]);
$metric=['weight_hundredths'=>10000,'minimum_volume'=>5,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success'];
$policy=['version'=>'provisional-acceptance-test','status'=>'provisional','role'=>'front_desk','financial_use_allowed'=>false,
    'provisional_capture'=>['enabled'=>true,'authorised_by'=>999,'authorised_at'=>'2026-10-01 07:00:00','from'=>'2026-10-01 08:00:00'],
    'categories'=>['orders'=>['weight_hundredths'=>5000,'metrics'=>['progression'=>$metric]],
        'communication'=>['weight_hundredths'=>5000,'metrics'=>['accuracy'=>$metric]]]];
$json=json_encode($policy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$policy['version'],'front_desk',$json,hash('sha256',$json)]);
sql('UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,assigned_by=999,official_from=NULL,validation_approved_at=NULL,validation_approved_by=NULL WHERE employee_id IN(101,102,103)',[$policy['version']]);
check('PROVISIONAL01 before authorised start remains excluded',0,PerformanceCapturePolicy::captureMode($db,101,'2026-10-01 07:59:59'));
check('PROVISIONAL02 new work admitted provisionally',2,PerformanceCapturePolicy::captureMode($db,101,'2026-10-01 08:00:00'));
check('PROVISIONAL03 no official approval invented',false,PerformanceCapturePolicy::approved($db,101,'2026-10-01 08:00:00'));
$engine=new DeadlineEngine($db);$projector=new OperationalPerformanceProjector($db);$svc=new EmployeePerformanceService($db);
for($n=0;$n<5;$n++){
    $ref='PROVISIONAL-ORDER-'.$n;owner($ref,101);$uuid=deadline(['object_reference'=>$ref]);
    if($n===0)$late=$uuid;else $engine->fulfil($uuid,101,'2026-10-01 08:20:00');
}
$engine->processDue('2026-10-01 09:00:00');
PerformanceRefreshRuntime::run($db);$r=$svc->read(101,'2026-10-01');
check('PROVISIONAL04 no click creates one breach',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_incidents'));
check('PROVISIONAL05 neglected work retained in denominator',5,$r['categories']['orders']['metrics']['progression']['eligible_volume']);
check('PROVISIONAL06 on time numerator',4,$r['categories']['orders']['metrics']['progression']['numerator']);
check('PROVISIONAL07 measurable rate changes',8000,$r['categories']['orders']['metrics']['progression']['rate_hundredths']);
check('PROVISIONAL08 missing communication cannot become overall zero',null,$r['provisional_score_hundredths']);
check('PROVISIONAL09 incomplete October not official',null,$r['official_score_hundredths']);
$engine->fulfil($late,102,'2026-10-01 09:17:00');PerformanceRefreshRuntime::run($db);$r=$svc->read(101,'2026-10-01');
check('PROVISIONAL10 helper does not erase failure',8000,$r['categories']['orders']['metrics']['progression']['rate_hundredths']);
check('PROVISIONAL11 B is recorded as fulfiller',102,(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$late]));
check('PROVISIONAL12 B receives no inherited failure',0,count($projector->employee(102,'2026-10-01')['opportunities']));
check('PROVISIONAL13 risk clears',0,count((new V2PerformanceQuery($db))->personalRisk(101,'2026-10-01 10:00:00')));
check('PROVISIONAL14 historical breach retained',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
for($n=91;$n<96;$n++){
    sql("INSERT INTO ops_whatsapp_conversations(id,assigned_employee_id,created_at,follow_up_at,last_customer_message_at,status) VALUES(?,101,'2026-10-01 08:00:00',NULL,NULL,'awaiting_response')",[$n]);
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$n,['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    sql("UPDATE ops_whatsapp_conversations SET status='resolved' WHERE id=?",[$n]);
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$n,['employee_id'=>102,'occurred_at'=>'2026-10-01 09:00:00']);
}
foreach([901=>91,902=>92,903=>91,904=>93] as $error=>$conversation){
    sql("INSERT INTO ops_error_logs(id,attribution_type,attributed_employee_id,responsible_employee_id,logged_by,affects_kpi_accuracy,accuracy_verified_by,attribution_verified_by,accuracy_verified_at,attribution_verified_at,occurred_at,status,updated_at,category,severity)
        VALUES(?,'employee',101,101,102,1,999,999,'2026-10-01 10:00:00','2026-10-01 10:00:00','2026-10-01 08:30:00','resolved','2026-10-01 10:00:00','incorrect_payment_information','medium')",[$error]);
    V2OperationalBridge::record($db,'error_log','error_owner_reviewed',$error,['employee_id'=>999,'occurred_at'=>'2026-10-01 10:00:00']);
    IncidentCorrelation::link($db,['source_key'=>'error:'.$error,'root_incident_id'=>'provisional-communication:'.$conversation,
        'opportunity_key'=>'ops_whatsapp_conversation:'.$conversation.':0','category_key'=>'communication','metric_key'=>'accuracy','employee_id'=>101],999,'Synthetic verified same-root communication failure','2026-10-01 10:05:00');
}
sql("UPDATE ops_error_logs SET attribution_type='system',affects_kpi_accuracy=0 WHERE id=904");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',904,['employee_id'=>999,'occurred_at'=>'2026-10-01 10:10:00']);
PerformanceRefreshRuntime::run($db);$r=$svc->read(101,'2026-10-01');
check('PROVISIONAL15 communication denominator genuine conversations',5,$r['categories']['communication']['metrics']['accuracy']['eligible_volume']);
check('PROVISIONAL16 duplicate root and system failure excluded',6000,$r['categories']['communication']['metrics']['accuracy']['rate_hundredths']);
check('PROVISIONAL17 full observed two-category result provisional',7000,$r['provisional_score_hundredths']);
check('PROVISIONAL18 no official overall score',null,$r['official_score_hundredths']);
check('PROVISIONAL19 reporter/helper B not penalised',0,count($svc->read(102,'2026-10-01')['evidence']));
check('PROVISIONAL20 no source weight silently reallocated',10000,$r['missing_required_weight_hundredths']);
check('PROVISIONAL21 low sample confidence visible','low_sample',$r['categories']['orders']['metrics']['progression']['confidence']);
check('PROVISIONAL22 financial use prohibited',false,$r['financial_use_allowed']);
check('PROVISIONAL23 historical evidence unchanged',$legacyBefore,$db->query('SELECT * FROM epi_employee_evidence ORDER BY id')->fetchAll());
check('PROVISIONAL24 historical scores unchanged',$legacyScoreBefore,$db->query('SELECT * FROM epi_scoring_monthly_scores ORDER BY id')->fetchAll());
$csv=EmployeePerformanceService::exportRows($r);
check('PROVISIONAL25 CSV schema consistent',count(EmployeePerformanceService::exportHeaders()),count($csv[0]));
check('PROVISIONAL26 missing prior month remains excluded',0,PerformanceCapturePolicy::captureMode($db,101,'2026-09-30 12:00:00'));
sql("UPDATE epi_v2_employee_result_heads SET locked_at=NOW() WHERE employee_id=101 AND period_start='2026-10-01'");
$lockedId=$r['result_id'];
sql("UPDATE ops_error_logs SET attribution_type='business',affects_kpi_accuracy=0 WHERE id=902");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',902,['employee_id'=>999,'occurred_at'=>'2026-10-01 11:00:00']);
PerformanceRefreshRuntime::run($db);$r=$svc->read(101,'2026-10-01');
check('PROVISIONAL27 locked publication not rewritten',$lockedId,$r['result_id']);
check('PROVISIONAL28 audited correction proposed',true,$r['correction_pending']);
sql("UPDATE epi_v2_completed_work_units SET source_snapshot_json=JSON_SET(source_snapshot_json,'$.management_review_required',true) WHERE opportunity_key='ops_whatsapp_conversation:91:0'");
$correctedRows=array_values(array_filter($projector->employee(101,'2026-10-01')['opportunities'],static function(array $row):bool{return $row['opportunity_key']==='ops_whatsapp_conversation:91:0';}));
check('PROVISIONAL35 live management correction remains review-gated',false,$correctedRows[0]['eligible']);
check('PROVISIONAL36 linked incident cannot bypass correction review',true,$correctedRows[0]['metadata']['excluded_from_scoring']);

// Provisioning uses the same production service, with synthetic identities only.
require_once __DIR__.'/../shared/epi/ProvisionalScorecardAssignments.php';
$db->exec("UPDATE ops_employees SET status='active',role_id=CASE id WHEN 101 THEN 2 WHEN 102 THEN 3 ELSE 1 END;");
$config=Hambelela\EPI\ProvisionalScorecardAssignments::activate($db,999,[101,102]);
check('PROVISIONAL29 exact opt-in employees',2,count($config['employees']));
check('PROVISIONAL30 no official capture enabled','0',(string)scalar("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_official_capture_enabled'"));
check('PROVISIONAL31 prior locked assignment preserved','provisional-acceptance-test',scalar("SELECT scorecard_version FROM epi_v2_scorecard_assignments WHERE employee_id=101 AND period_start='2026-10-01'"));
$next=Hambelela\EPI\Support::timestamp()->modify('first day of next month')->format('Y-m-01');
check('PROVISIONAL32 next month assigned prospectively',2,(int)scalar('SELECT COUNT(*) FROM epi_v2_scorecard_assignments WHERE employee_id IN(101,102) AND period_start=?',[$next]));
$beforeAssignments=(int)scalar('SELECT COUNT(*) FROM epi_v2_scorecard_assignments');
Hambelela\EPI\ProvisionalScorecardAssignments::ensureCurrent($db);
check('PROVISIONAL33 scheduled renewal is idempotent',$beforeAssignments,(int)scalar('SELECT COUNT(*) FROM epi_v2_scorecard_assignments'));
check('PROVISIONAL34 policies remain provisional',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_scorecard_documents WHERE version LIKE '%-provisional-%' AND policy_json LIKE '%\"status\":\"provisional\"%'"));
$queueBefore=$db->query('SELECT * FROM epi_v2_performance_refresh_queue ORDER BY employee_id,period_start')->fetchAll();
PerformanceRefreshRuntime::invalidate($db,'checklist_task:task_reminder_generated');
check('PROVISIONAL37 reminder alone does not stale scores',$queueBefore,$db->query('SELECT * FROM epi_v2_performance_refresh_queue ORDER BY employee_id,period_start')->fetchAll());
$generationBefore=(int)scalar('SELECT SUM(requested_generation) FROM epi_v2_performance_refresh_queue');
PerformanceRefreshRuntime::invalidate($db,'checklist_task:task_completed');
check('PROVISIONAL38 actual completion still requests refresh',true,(int)scalar('SELECT SUM(requested_generation) FROM epi_v2_performance_refresh_queue')>$generationBefore);

// Native courier due dates work without a new guessed EPI timing policy.
$db->beginTransaction();
try {
    $waybill=['id'=>991,'uploaded_at'=>'2026-10-01 08:00:00','due_by'=>'2026-10-01 11:00:00','batch_id'=>'synthetic-native'];
    $nativeDue=Hambelela\EPI\ModuleDeadlineBridge::courierDue($db,$waybill,[]);
    check('PROVISIONAL48 existing explicit EPI duration retains priority','2026-10-01 08:30:00',Hambelela\EPI\ModuleDeadlineBridge::courierDue($db,$waybill,['courier_front_send_minutes'=>30]));
    $nativeRef='WAYBILL-991';
    (new Hambelela\EPI\OwnershipPeriodEngine($db))->assign(['module'=>'Courier','object_reference'=>$nativeRef,'employee_id'=>101,
        'assigned_by'=>999,'accepted_by'=>101,'effective_from'=>'2026-10-01 08:00:00','accepted_at'=>'2026-10-01 08:00:00','source'=>'synthetic-native']);
    $nativeInput=['module'=>'Courier','object_reference'=>$nativeRef,'obligation_key'=>'send_courier_documents',
        'breach_event_key'=>'courier_send_sla_breached','starts_at'=>$waybill['uploaded_at'],'due_at'=>$nativeDue,
        'source_evidence'=>['deadline_source'=>'hambelela_waybills.due_by'],'cycle_id'=>'initial'];
    $engine->schedule($nativeInput);
    $native=row("SELECT * FROM epi_v2_operational_deadlines WHERE module='Courier' AND object_reference=?",[$nativeRef]);
    check('PROVISIONAL39 Courier reuses saved due date','2026-10-01 11:00:00',$native['due_at']);
    $snap=json_decode($native['policy_snapshot_json'],true);
    check('PROVISIONAL40 native deadline source auditable','hambelela_waybills.due_by',$snap['source_evidence']['deadline_source']);
    $engine->schedule($nativeInput);
    check('PROVISIONAL41 duplicate upload replay creates one obligation',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference=?',[$nativeRef]));
    $engine->processDue('2026-10-01 11:01:00');
    check('PROVISIONAL42 unattended native courier breach owner',101,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE deadline_uuid=?',[$native['deadline_uuid']]));
    Hambelela\EPI\ModuleDeadlineBridge::capture($db,'courier_waybill','courier_waybill_sent',$waybill,['employee_id'=>102,'occurred_at'=>'2026-10-01 11:10:00']);
    check('PROVISIONAL43 courier helper is only fulfiller',102,(int)scalar('SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$native['deadline_uuid']]));
    check('PROVISIONAL44 courier risk resolves, original owner retained','resolved',scalar('SELECT current_risk_state FROM epi_v2_performance_incidents WHERE deadline_uuid=?',[$native['deadline_uuid']]));
    check('PROVISIONAL45 expired upstream deadline is not inherited',null,Hambelela\EPI\ModuleDeadlineBridge::nativeCourierDue(['uploaded_at'=>'2026-10-01 12:00:00','due_by'=>'2026-10-01 11:00:00']));
    check('PROVISIONAL46 missing due date not guessed',null,Hambelela\EPI\ModuleDeadlineBridge::nativeCourierDue(['uploaded_at'=>'2026-10-01 08:00:00']));
    check('PROVISIONAL47 invalid native calendar date rejected',null,Hambelela\EPI\ModuleDeadlineBridge::nativeCourierDue(['uploaded_at'=>'2026-10-01 08:00:00','due_by'=>'2026-10-32 11:00:00']));
} finally {$db->rollBack();}
