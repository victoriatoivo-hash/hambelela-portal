<?php
declare(strict_types=1);
// Included by the isolated forensic runner; never loads portal/production configuration.
require_once __DIR__.'/../shared/epi/EmployeePerformanceService.php';
require_once __DIR__.'/../shared/epi/ScorecardCatalog.php';
require_once __DIR__.'/../shared/epi/PerformanceRefreshQueue.php';
use Hambelela\EPI\EmployeePerformanceService;
use Hambelela\EPI\ScorecardCatalog;
use Hambelela\EPI\RateScoreCalculator;
use Hambelela\EPI\PerformanceRefreshQueue;

$db->exec(file_get_contents(__DIR__.'/../operations-epi-performance-service-migration.sql'));
$db->exec(file_get_contents(__DIR__.'/../operations-epi-performance-service-migration.sql'));
$service=new EmployeePerformanceService($db);
$metric=['weight_hundredths'=>10000,'minimum_volume'=>1,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success'];
$policy=['version'=>'service-test-1','status'=>'approved','categories'=>[
    'orders'=>['weight_hundredths'=>8500,'metrics'=>['sla'=>$metric]],
    'bookkeeping'=>['weight_hundredths'=>1500,'metrics'=>['accuracy'=>$metric]]]];
$json=json_encode($policy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$policy['version'],'synthetic',$json,hash('sha256',$json)]);
foreach([101,102,103] as $id) sql("INSERT INTO epi_v2_scorecard_assignments
    (employee_id,period_start,scorecard_version,assigned_by,validation_approved_at,validation_approved_by,official_from)
    VALUES(?,'2026-10-01','service-test-1',999,'2026-10-01 00:00:00',999,'2026-10-01')",[$id]);
$coverage=['orders'=>['sla'=>['complete'=>true]],'bookkeeping'=>['accuracy'=>['complete'=>true]]];
$make=static function(int $id,string $key,string $outcome='success',string $category='orders',string $metric='sla'):array{
    return ['employee_id'=>$id,'opportunity_key'=>$key,'category'=>$category,'metric'=>$metric,
        'starts_at'=>'2026-10-01 08:00:00','due_at'=>'2026-10-01 10:00:00','module'=>'Orders','source_reference'=>'Order '.$key,
        'responsibility_confirmed'=>true,'eligible'=>true,'outcome'=>$outcome,
        'root_incident_id'=>$outcome==='failure'?'root:'.$key:null,
        'expected'=>'Progress by deadline','actual'=>$outcome==='success'?'Progressed on time':'Untouched past deadline',
        'ownership'=>['employee_id'=>$id,'effective_from'=>'2026-10-01 08:00:00',
            'effective_to'=>'2026-10-01 17:00:00','accepted_at'=>'2026-10-01 08:00:00']];
};
$samples=[$make(101,'order1'),$make(101,'cash1','success','bookkeeping','accuracy')];
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
check('SVC01 complete configured rate',10000,$result['official_score_hundredths']);
check('SVC02 financial use not enabled by calculation',false,$result['financial_use_allowed']);
$originalId=$result['result_id'];
$service->recalculate(101,'2026-10-01',array_reverse($samples),$coverage);
check('SVC03 replay/order independent revision',1,(int)scalar('SELECT COUNT(*) FROM epi_v2_employee_results'));
$missing=$coverage;$missing['bookkeeping']['accuracy']['complete']=false;
$result=$service->recalculate(101,'2026-10-01',$samples,$missing);
check('SVC04 missing 15 percent blocks official score',null,$result['official_score_hundredths']);
check('SVC05 missing weight disclosed',1500,$result['missing_required_weight_hundredths']);
check('SVC06 remaining weight not expanded',8500,$result['measured_weight_hundredths']);
check('SVC07 no renormalisation',false,$result['renormalised']);
$samples[]=$make(101,'untouched','failure');
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
check('SVC08 untouched failure reduces result',5750,$result['official_score_hundredths']);
$samples[2]['actual']='Later completed by B; historical breach retained';
$samples[2]['fulfilled_by']=102;
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
check('SVC09 later completer does not change rate',5750,$result['official_score_hundredths']);
$bResult=$service->recalculate(102,'2026-10-01',$samples,$coverage);
check('SVC10 B does not inherit A observations',0,$bResult['categories']['orders']['metrics']['sla']['eligible_volume']);
$duplicate=$samples[2];$duplicate['opportunity_key']='same-failure-error-record';
$duplicate['source_reference']='Error log supporting same order breach';
$result=$service->recalculate(101,'2026-10-01',array_merge($samples,[$duplicate]),$coverage);
check('SVC11 same root is one failure',2,$result['categories']['orders']['metrics']['sla']['eligible_volume']);
check('SVC12 same root same result',5750,$result['official_score_hundredths']);
$cross=$duplicate;$cross['category']='bookkeeping';$cross['metric']='accuracy';
check('SVC13 ambiguous cross-category root rejected',false,attempt(function()use($service,$samples,$cross,$coverage){
    $service->recalculate(101,'2026-10-01',array_merge($samples,[$cross]),$coverage);
}));
$excluded=$make(101,'system','failure');$excluded['metadata']=['system_error'=>true];
$result=$service->recalculate(101,'2026-10-01',array_merge($samples,[$excluded]),$coverage);
check('SVC14 system incident not counted',2,$result['categories']['orders']['metrics']['sla']['eligible_volume']);
$unknown=$make(101,'unknown','failure');$unknown['ownership']['employee_id']=102;
$result=$service->recalculate(101,'2026-10-01',array_merge($samples,[$unknown]),$coverage);
check('SVC15 unreliable attribution blocks completeness',null,$result['official_score_hundredths']);
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
$lockedId=$result['result_id'];
sql("UPDATE epi_v2_employee_result_heads SET locked_at='2026-11-01 08:00:00',locked_by=999 WHERE employee_id=101");
$lockedJson=scalar('SELECT result_json FROM epi_v2_employee_results WHERE id=?',[$lockedId]);
$samples[]=$make(101,'late-discovery','failure');
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
check('SVC16 locked publication unchanged',$lockedId,$result['result_id']);
check('SVC17 locked content unchanged',$lockedJson,scalar('SELECT result_json FROM epi_v2_employee_results WHERE id=?',[$lockedId]));
check('SVC18 proposed revision visible',true,$result['correction_pending']);
$db->exec('START TRANSACTION READ ONLY');
check('SVC19 read performs no writes',true,attempt(function()use($service){$service->read(101,'2026-10-01');}));
$db->exec('ROLLBACK');
check('SVC20 unknown role no fallback','not_configured',$service->read(999,'2026-10-01')['status']);
foreach(ScorecardCatalog::templates() as $template){
    $empty=RateScoreCalculator::calculate($template,[]);
    check('SVC21 '.$template['role'].' empty official',null,$empty['official_score_hundredths']);
    check('SVC22 '.$template['role'].' complete missing weight',10000,$empty['missing_required_weight_hundredths']);
}
check('SVC23 communication taxonomy',13,count(ScorecardCatalog::communicationTypes()));
$zero=$service->recalculate(103,'2026-10-01',[],$coverage);
check('SVC24 zero workload not perfect',null,$zero['official_score_hundredths']);
check('SVC25 zero workload reason','no_workload',$zero['categories']['orders']['metrics']['sla']['reason']);
$large=[$make(102,'cash-large','success','bookkeeping','accuracy')];
for($i=0;$i<200;$i++)$large[]=$make(102,'large-'.$i,$i<4?'failure':'success');
$largeResult=$service->recalculate(102,'2026-10-01',$large,$coverage);
$small=[$make(103,'cash-small','success','bookkeeping','accuracy')];
for($i=0;$i<20;$i++)$small[]=$make(103,'small-'.$i,$i<4?'failure':'success');
$smallResult=$service->recalculate(103,'2026-10-01',$small,$coverage);
check('SVC26 200 opportunities 4 failures accuracy',9800,$largeResult['categories']['orders']['metrics']['sla']['rate_hundredths']);
check('SVC27 20 opportunities 4 failures accuracy',8000,$smallResult['categories']['orders']['metrics']['sla']['rate_hundredths']);
check('SVC28 high sample confidence','high',$largeResult['categories']['orders']['metrics']['sla']['confidence']);
check('SVC29 moderate sample confidence','moderate',$smallResult['categories']['orders']['metrics']['sla']['confidence']);
$notApplicable=$coverage;$notApplicable['bookkeeping']['accuracy']['applicable']=false;
$result=$service->recalculate(102,'2026-10-01',$large,$notApplicable);
check('SVC30 not applicable not missing source','not_applicable',$result['categories']['bookkeeping']['metrics']['accuracy']['status']);
check('SVC31 not applicable cannot silently redistribute',null,$result['official_score_hundredths']);
$beforeCount=(int)scalar('SELECT COUNT(*) FROM epi_v2_employee_results');
$conflict=$large[1];$conflict['actual']='Conflicting unreviewed source';
check('SVC32 conflicting same opportunity rejected',false,attempt(function()use($service,$large,$conflict,$coverage){
    $service->recalculate(102,'2026-10-01',array_merge($large,[$conflict]),$coverage);
}));
check('SVC33 failed projection leaves no partial result',$beforeCount,(int)scalar('SELECT COUNT(*) FROM epi_v2_employee_results'));
$changed=$policy;$changed['version']='service-test-2';$changed['categories']['orders']['weight_hundredths']=5000;
$changed['categories']['bookkeeping']['weight_hundredths']=5000;$json=json_encode($changed);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$changed['version'],'synthetic',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version='service-test-2' WHERE employee_id=101");
$result=$service->recalculate(101,'2026-10-01',$samples,$coverage);
check('SVC34 new scorecard does not mutate locked version','service-test-1',$result['scorecard_version']);
check('SVC35 original result retained',true,(bool)scalar('SELECT id FROM epi_v2_employee_results WHERE id=?',[$originalId]));
sql('UPDATE epi_v2_scorecard_documents SET policy_hash=? WHERE version=?',[str_repeat('0',64),'service-test-2']);
check('SVC36 corrupt policy rejected',false,attempt(function()use($service,$samples,$coverage){
    $service->recalculate(101,'2026-10-01',$samples,$coverage);
}));
PerformanceRefreshQueue::request($db,102,'2026-10-01','order breach');
check('SVC37 pending refresh marked stale',true,$service->read(102,'2026-10-01')['data_may_be_stale']);
$worker=PerformanceRefreshQueue::drain($db,static function()use($large,$coverage):array{
    return ['opportunities'=>$large,'coverage'=>$coverage];
});
check('SVC38 worker calculates without owner action',1,$worker['processed']);
check('SVC39 completed refresh clears stale marker',false,$service->read(102,'2026-10-01')['data_may_be_stale']);
PerformanceRefreshQueue::request($db,102,'2026-10-01','new event');
$worker=PerformanceRefreshQueue::drain($db,static function()use($db,$large,$coverage):array{
    PerformanceRefreshQueue::request($db,102,'2026-10-01','event arrived during calculation');
    return ['opportunities'=>$large,'coverage'=>$coverage];
});
check('SVC40 concurrent generation remains pending',1,(int)PerformanceRefreshQueue::health($db)['backlog']);
$worker=PerformanceRefreshQueue::drain($db,static function():array{throw new RuntimeException('Expected fixture projection failure');});
check('SVC41 failure remains retryable',1,$worker['failed']);
check('SVC42 worker failure is visible',1,(int)PerformanceRefreshQueue::health($db)['failures']);
PerformanceRefreshQueue::drain($db,static function()use($large,$coverage):array{return ['opportunities'=>$large,'coverage'=>$coverage];});
check('SVC43 retry clears error',0,(int)PerformanceRefreshQueue::health($db)['failures']);
check('SVC44 drained queue no pending work',0,(int)PerformanceRefreshQueue::health($db)['backlog']);
$db->beginTransaction();PerformanceRefreshQueue::request($db,103,'2026-10-01','rolled-back operation');$db->rollBack();
check('SVC45 rolled-back operation does not queue',0,(int)scalar('SELECT COUNT(*) FROM epi_v2_performance_refresh_queue WHERE employee_id=103'));
$db->exec('START TRANSACTION READ ONLY');
check('SVC46 health is read only',true,attempt(function()use($db){PerformanceRefreshQueue::health($db);}));$db->exec('ROLLBACK');
$historical=$make(102,'pre-activation','failure');$historical['starts_at']='2026-09-30 16:00:00';
$result=$service->recalculate(102,'2026-10-01',array_merge($large,[$historical]),$coverage);
check('SVC47 pre-enforcement obligation excluded',200,$result['categories']['orders']['metrics']['sla']['eligible_volume']);
$flagged=$make(102,'excluded-shadow','failure');$flagged['metadata']=['excluded_from_scoring'=>true];
$result=$service->recalculate(102,'2026-10-01',array_merge($large,[$flagged]),$coverage);
check('SVC48 explicit scoring exclusion enforced',200,$result['categories']['orders']['metrics']['sla']['eligible_volume']);
sql('UPDATE epi_v2_scorecard_assignments SET official_from=NULL WHERE employee_id=102');
$result=$service->recalculate(102,'2026-10-01',$large,$coverage);
check('SVC49 approval without cutover remains validation','validation',$result['status']);
check('SVC50 approval without cutover no official number',null,$result['official_score_hundredths']);
