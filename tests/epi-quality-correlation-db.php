<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/IncidentCorrelation.php';
use Hambelela\EPI\{IncidentCorrelation,V2OperationalBridge,PerformanceRefreshRuntime,EmployeePerformanceService};
resetObjects();
$policy=['version'=>'communication-acceptance-fixture','status'=>'approved','categories'=>['communication'=>[
    'weight_hundredths'=>10000,'metrics'=>['accuracy'=>['weight_hundredths'=>10000,'minimum_volume'=>1,
    'direction'=>'success','source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']]]]]];
$json=json_encode($policy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$policy['version'],'front_desk',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from='2026-10-01' WHERE employee_id IN(101,102,103)",[$policy['version']]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
foreach([72,73,74] as $id){
    sql("INSERT INTO ops_whatsapp_conversations VALUES(?,102,'2026-10-01 08:00:00',NULL,NULL,'awaiting_response')",[$id]);
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$id,
        ['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:00']);
    sql("UPDATE ops_whatsapp_conversations SET status='resolved' WHERE id=?",[$id]);
    V2OperationalBridge::record($db,'ops_whatsapp_conversation','save_whatsapp_conversation',$id,
        ['employee_id'=>102,'occurred_at'=>'2026-10-01 09:00:00']);
}
PerformanceRefreshRuntime::run($db);
$service=new EmployeePerformanceService($db);
check('QUAL01 native completed conversations form denominator',3,$service->read(102,'2026-10-01')['categories']['communication']['metrics']['accuracy']['eligible_volume']);
foreach([11,12,13] as $id){
    sql("INSERT INTO ops_error_logs VALUES(?,'employee',102,102,103,1,999,999,'2026-10-01 10:00:00',
        '2026-10-01 10:00:00','2026-10-01 08:30:00','open','2026-10-01 10:00:00','incorrect_payment_information','medium')",[$id]);
    V2OperationalBridge::record($db,'error_log','error_owner_reviewed',$id,
        ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 10:00:00']);
}
PerformanceRefreshRuntime::run($db);
check('QUAL02 unlinked confirmed incidents block perfect official rate',null,$service->read(102,'2026-10-01')['official_score_hundredths']);
foreach([11=>72,12=>73,13=>72] as $error=>$conversation){
    IncidentCorrelation::link($db,['source_key'=>'error:'.$error,'root_incident_id'=>'customer-failure:'.$conversation,
        'opportunity_key'=>'ops_whatsapp_conversation:'.$conversation.':0','category_key'=>'communication',
        'metric_key'=>'accuracy','employee_id'=>102],999,'Verified records describe this same conversation failure','2026-10-01 10:05:00');
}
PerformanceRefreshRuntime::run($db);$result=$service->read(102,'2026-10-01');
check('QUAL03 two failures in three real opportunities',3333,$result['official_score_hundredths']);
check('QUAL04 three evidence records do not create extra denominator',3,$result['categories']['communication']['metrics']['accuracy']['eligible_volume']);
check('QUAL05 reporter never receives the failures',0,count($service->read(103,'2026-10-01')['evidence']));
check('QUAL06 duplicate root retains both supporting records',2,count(array_values(array_filter($result['evidence'],static function(array $r):bool{
    return $r['root_incident_id']==='customer-failure:72';
}))[0]['supporting_evidence']));
check('QUAL07 unapproved reporter cannot link score incidents',false,attempt(function()use($db){
    IncidentCorrelation::link($db,['source_key'=>'error:99','root_incident_id'=>'new-root','opportunity_key'=>'x',
        'category_key'=>'communication','metric_key'=>'accuracy','employee_id'=>101],102,'Not owner','2026-10-01 11:00:00');
}));
sql("UPDATE ops_error_logs SET attribution_type='system',affects_kpi_accuracy=0 WHERE id=12");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',12,
    ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 11:00:00']);
PerformanceRefreshRuntime::run($db);
check('QUAL08 corrected system responsibility removes employee failure',6667,$service->read(102,'2026-10-01')['official_score_hundredths']);
check('QUAL09 prior quality revisions remain',4,(int)scalar('SELECT COUNT(*) FROM epi_v2_quality_revisions'));
IncidentCorrelation::correctRoot($db,'customer-failure:72',[
    'employee_id'=>102,'opportunity_key'=>'ops_whatsapp_conversation:74:0',
    'category_key'=>'communication','metric_key'=>'accuracy'],999,'Corrected referenced conversation','2026-10-01 12:00:00');
check('QUAL10 root correction updates all duplicate proofs',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_incident_correlations WHERE root_incident_id='customer-failure:72' AND opportunity_key='ops_whatsapp_conversation:74:0' AND superseded_at IS NULL"));
check('QUAL11 root correction preserves old links',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_incident_correlations WHERE root_incident_id='customer-failure:72' AND superseded_at IS NOT NULL"));
check('QUAL12 invalid destination correction rolls back',false,attempt(function()use($db){
    IncidentCorrelation::correctRoot($db,'customer-failure:72',[
        'employee_id'=>99999,'opportunity_key'=>'missing','category_key'=>'communication','metric_key'=>'accuracy'],
        999,'Invalid synthetic employee','2026-10-01 12:01:00');
}));
check('QUAL13 failed correction retains active links',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_incident_correlations WHERE root_incident_id='customer-failure:72' AND superseded_at IS NULL"));
IncidentCorrelation::link($db,['source_key'=>'error:12','root_incident_id'=>'customer-failure:73',
    'opportunity_key'=>'missing-unit','category_key'=>'communication','metric_key'=>'accuracy','employee_id'=>102],
    999,'Test missing operational evidence','2026-10-01 12:02:00');
sql("UPDATE ops_error_logs SET attribution_type='employee',affects_kpi_accuracy=1 WHERE id=12");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',12,
    ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 12:03:00']);
PerformanceRefreshRuntime::run($db);
check('QUAL14 dangling evidence link cannot leave official perfect score',null,$service->read(102,'2026-10-01')['official_score_hundredths']);
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
