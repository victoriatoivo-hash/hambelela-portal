<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/RateScoreCalculator.php';
use Hambelela\EPI\RateScoreCalculator;

$passed = 0;
function check($condition, string $name): void {
    global $passed;
    if (!$condition) throw new RuntimeException($name);
    $passed++;
}
$policy = ['version'=>'synthetic-1','categories'=>['orders'=>['weight_hundredths'=>10000,
    'metrics'=>['sla'=>['weight_hundredths'=>10000,'minimum_volume'=>10,'direction'=>'success']]]]];
$sample = ['orders'=>['sla'=>['eligible_volume'=>83,'numerator'=>76,'source_complete'=>true]]];
$result = RateScoreCalculator::calculate($policy,$sample);
check($result['official_score_hundredths'] === 9157, '76 of 83 rate');
check($result['categories']['orders']['metrics']['sla']['eligible_volume'] === 83, 'Volume retained');
check(RateScoreCalculator::calculate($policy,[])['official_score_hundredths'] === null, 'No data is not 100');
$sample['orders']['sla'] = ['eligible_volume'=>2,'numerator'=>2,'source_complete'=>true];
$result = RateScoreCalculator::calculate($policy,$sample);
check($result['official_score_hundredths'] === null, 'Low volume not official');
check($result['categories']['orders']['metrics']['sla']['score_hundredths'] === 10000, 'Provisional rate visible');
$sample['orders']['sla'] = ['eligible_volume'=>100,'numerator'=>98];
check(RateScoreCalculator::calculate($policy,$sample)['official_score_hundredths'] === null, 'Coverage fails closed');
$sample['orders']['sla']['source_complete'] = true;
check(RateScoreCalculator::calculate($policy,$sample)['official_score_hundredths'] === 9800, 'Sufficient rate');
$policy['categories']['orders']['metrics']['sla']['direction'] = 'error';
$sample['orders']['sla']['numerator'] = 9;
$sample['orders']['sla']['eligible_volume'] = 312;
check(RateScoreCalculator::calculate($policy,$sample)['official_score_hundredths'] === 9712, 'Error rate normalised');
$mixed = ['version'=>'synthetic-2','categories'=>[
    'orders'=>['weight_hundredths'=>7500,'metrics'=>[
        'sla'=>['weight_hundredths'=>5000,'minimum_volume'=>10,'direction'=>'success'],
        'accuracy'=>['weight_hundredths'=>5000,'minimum_volume'=>10,'direction'=>'error']]],
    'tasks'=>['weight_hundredths'=>2500,'metrics'=>[
        'sla'=>['weight_hundredths'=>10000,'minimum_volume'=>10,'direction'=>'success']]]]];
$mixedSamples = ['orders'=>[
    'sla'=>['eligible_volume'=>100,'numerator'=>80,'source_complete'=>true],
    'accuracy'=>['eligible_volume'=>100,'numerator'=>0,'source_complete'=>true]],
    'tasks'=>['sla'=>['eligible_volume'=>100,'numerator'=>60,'source_complete'=>true]]];
check(RateScoreCalculator::calculate($mixed,$mixedSamples)['official_score_hundredths'] === 8250, 'Nested configured weights');
unset($mixedSamples['tasks']);
check(RateScoreCalculator::calculate($mixed,$mixedSamples)['official_score_hundredths'] === null, 'No silent weight redistribution');
$sampleZero = ['orders'=>['sla'=>['eligible_volume'=>0,'numerator'=>0,'source_complete'=>true]]];
check(RateScoreCalculator::calculate($policy,$sampleZero)['official_score_hundredths'] === null, 'Zero workload not perfect');
$targetPolicy=$policy;$targetPolicy['categories']['orders']['metrics']['sla']['target_hundredths']=250;
check(RateScoreCalculator::calculate($targetPolicy,$sample)['categories']['orders']['metrics']['sla']['target_hundredths']===250,'Configured target retained');
check(RateScoreCalculator::calculate($policy,$sample)['categories']['orders']['metrics']['sla']['target_hundredths']===null,'No invented target');
check(RateScoreCalculator::calculate($targetPolicy,$sample)['official_score_hundredths']===RateScoreCalculator::calculate($policy,$sample)['official_score_hundredths'],'Target display does not change rate formula');
foreach (['weight','counts','version','minimum','direction','target'] as $invalid) {
    $p=$policy; $s=$sample;
    if ($invalid==='weight') $p['categories']['orders']['weight_hundredths']=9999;
    if ($invalid==='counts') $s['orders']['sla']['numerator']=313;
    if ($invalid==='version') unset($p['version']);
    if ($invalid==='minimum') $p['categories']['orders']['metrics']['sla']['minimum_volume']=0;
    if ($invalid==='direction') $p['categories']['orders']['metrics']['sla']['direction']='unknown';
    if ($invalid==='target') $p['categories']['orders']['metrics']['sla']['target_hundredths']=10001;
    $rejected=false;
    try { RateScoreCalculator::calculate($p,$s); } catch (InvalidArgumentException $e) { $rejected=true; }
    check($rejected,'Reject '.$invalid);
}
// Partial results are points out of supported weight, not a fabricated /100.
$partialPolicy=['version'=>'partial-synthetic','categories'=>[
    'orders'=>['weight_hundredths'=>2500,'metrics'=>['sla'=>['weight_hundredths'=>10000,'minimum_volume'=>5,'direction'=>'success']]],
    'other'=>['weight_hundredths'=>7500,'metrics'=>['work'=>['weight_hundredths'=>10000,'minimum_volume'=>5,'direction'=>'success']]]]];
$partialSamples=['orders'=>['sla'=>['eligible_volume'=>10,'numerator'=>8,'source_complete'=>false]]];
$partial=RateScoreCalculator::calculate($partialPolicy,$partialSamples);
check($partial['available_points_hundredths']===2000,'80 percent of original 25 points is 20, not reweighted 80');
check($partial['available_weight_hundredths']===2500,'Available maximum is original supported 25 points');
check($partial['official_score_hundredths']===null&&$partial['provisional_score_hundredths']===null,'Partial points do not fabricate a complete percentage');
check($partial['categories']['orders']['available_points_hundredths']===8000,'Supported category retains its real 80 of 100 points');
$partialSamples['orders']['sla']['eligible_volume']=4;$partialSamples['orders']['sla']['numerator']=4;
$low=RateScoreCalculator::calculate($partialPolicy,$partialSamples);
check($low['available_points_hundredths']===null,'Below-minimum rates do not contribute score points');
check($low['categories']['orders']['metrics']['sla']['rate_hundredths']===10000,'Low-sample observed rate is retained separately');
$partialSamples['orders']['sla']=['eligible_volume'=>0,'numerator'=>0,'source_complete'=>true];
$idle=RateScoreCalculator::calculate($partialPolicy,$partialSamples);
check($idle['categories']['orders']['metrics']['sla']['sample_state']==='no_eligible_work','Verified no-workload is explicit');
check($idle['no_workload_weight_hundredths']===2500,'No-workload weight disclosed but not redistributed');
check($idle['available_points_hundredths']===null&&$idle['official_score_hundredths']===null,'No-workload earns neither zero nor perfect score');
$partialSamples['orders']['sla']['source_complete']=false;
check(RateScoreCalculator::calculate($partialPolicy,$partialSamples)['categories']['orders']['metrics']['sla']['sample_state']==='missing_evidence','Unverified emptiness is missing evidence, not zero work');
$partialSamples['orders']['sla']=['eligible_volume'=>0,'numerator'=>0,'source_complete'=>true,'pending_observations'=>2];
check(RateScoreCalculator::calculate($partialPolicy,$partialSamples)['categories']['orders']['metrics']['sla']['sample_state']==='not_due','Pending assigned work is not called no workload');
require_once __DIR__.'/../shared/epi/PerformanceSourceCoverage.php';
$window=Hambelela\EPI\PerformanceSourceCoverage::describe('2026-10-01 00:00:00','2026-11-01 00:00:00','2026-10-28 20:00:00','2026-10-27 18:42:04',true,0,false);
check($window['state']==='partial_period'&&!$window['full_period_verified'],'Prospective capture never reconstructs October');
$window=Hambelela\EPI\PerformanceSourceCoverage::describe('2026-11-01 00:00:00','2026-12-01 00:00:00','2026-11-10 20:00:00','2026-10-27 18:42:04',true,0,false);
check($window['state']==='unverified_period'&&!$window['full_period_verified'],'Connected healthy feed does not auto-approve complete responsibility coverage');
$window=Hambelela\EPI\PerformanceSourceCoverage::describe('2026-11-01 00:00:00','2026-12-01 00:00:00','2026-11-10 20:00:00','2026-10-27 18:42:04',true,2,true);
check($window['state']==='capture_pending'&&!$window['full_period_verified'],'Pending operational capture blocks completeness');
echo json_encode(['passed'=>$passed,'failed'=>0]).PHP_EOL;
