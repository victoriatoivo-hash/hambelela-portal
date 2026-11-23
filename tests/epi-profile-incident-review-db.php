<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/PerformanceIncidentReview.php';
use Hambelela\EPI\{PerformanceIncidentReview,PerformanceRefreshRuntime,V2OperationalBridge,EmployeePerformanceService};
$db->exec('START TRANSACTION READ ONLY');
$review=PerformanceIncidentReview::read($db,104,'2026-10-01');
check('REVIEW01 quality detail reads without database mutation',1,count($review['incidents']));
check('REVIEW02 reporter shown separately by name','Synthetic C',$review['incidents'][0]['reporter_name']);
check('REVIEW03 verified work choices reflect real allocations',4,count($review['work']));
$db->exec('ROLLBACK');
$input=['source_key'=>'error:71','revision_id'=>$review['incidents'][0]['revision_id'],
    'opportunity_key'=>'cash_entry:7102:order:7102','category_key'=>'bookkeeping','metric_key'=>'accuracy',
    'root_incident_id'=>'error:71','reason'=>'Verified this is a distinct amount-information incident'];
check('REVIEW04 stale quality revision rejected',false,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,104,'2026-10-01',array_replace($input,['revision_id'=>$input['revision_id']+1]),999,'2026-10-01 13:00:00');
}));
check('REVIEW05 arbitrary work key rejected',false,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,104,'2026-10-01',array_replace($input,['opportunity_key'=>'made-up']),999,'2026-10-01 13:00:00');
}));
check('REVIEW06 unrelated KPI allocation rejected',false,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,104,'2026-10-01',array_replace($input,['category_key'=>'orders','metric_key'=>'progression']),999,'2026-10-01 13:00:00');
}));
check('REVIEW07 reporter cannot approve performance link',false,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,104,'2026-10-01',$input,103,'2026-10-01 13:00:00');
}));
check('REVIEW08 other employee cannot receive incident through work link',false,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,103,'2026-10-01',$input,999,'2026-10-01 13:00:00');
}));
check('REVIEW09 owner links real reviewed incident to verified work',true,attempt(function()use($db,$input){
    PerformanceIncidentReview::save($db,104,'2026-10-01',$input,999,'2026-10-01 13:00:00');
}));
check('REVIEW10 original root link audit retained',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_incident_correlations WHERE source_key='error:71' AND superseded_at IS NOT NULL"));
PerformanceRefreshRuntime::run($db);
check('REVIEW11 re-link preserves one failure and four real units',7500,(new EmployeePerformanceService($db))->read(104,'2026-10-01')['official_score_hundredths']);
$profile=(new EmployeePerformanceService($db))->profile(104,'2026-10-01','2026-10-01 13:05:00');
check('REVIEW14 history explains published score effect',true,strpos($profile['historical_incidents'][0]['published_score_effect'],'Included in published result: bookkeeping')===0);
PerformanceIncidentReview::save($db,104,'2026-10-01',array_replace($input,['reason'=>'Second review confirms the same allocation']),999,'2026-10-01 13:10:00');
check('REVIEW15 repeated confirmation preserves new review reason',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_audits WHERE scope_key='performance-review|error:71' AND reason='Second review confirms the same allocation'"));
check('REVIEW16 repeated confirmation genuinely queues refresh',true,(bool)(new EmployeePerformanceService($db))->read(104,'2026-10-01')['data_may_be_stale']);
sql("UPDATE ops_error_logs SET attribution_type='system',affects_kpi_accuracy=0 WHERE id=71");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',71,
    ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 14:00:00']);
$systemReview=PerformanceIncidentReview::read($db,104,'2026-10-01');
check('REVIEW12 corrected system evidence remains visible',1,count($systemReview['incidents']));
check('REVIEW13 system evidence cannot be re-scored by owner link',false,attempt(function()use($db,$input,$systemReview){
    PerformanceIncidentReview::save($db,104,'2026-10-01',array_replace($input,['revision_id'=>$systemReview['incidents'][0]['revision_id']]),999,'2026-10-01 14:01:00');
}));
PerformanceRefreshRuntime::run($db);
$excludedCash=(new EmployeePerformanceService($db))->read(104,'2026-10-01');
check('REVIEW17 reviewed system amount variance does not penalise employee',10000,$excludedCash['official_score_hundredths']);
check('REVIEW18 excluded variance is not counted as successful work',3,$excludedCash['categories']['bookkeeping']['metrics']['accuracy']['eligible_volume']);
check('REVIEW19 external variance remains auditable',1,count(array_filter($excludedCash['excluded'],static function(array $row):bool{
    return ($row['exclusion_reason']??'')==='reviewed_non_employee_variance';
})));
