<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/BusinessHealthPerformance.php';
use Hambelela\EPI\BusinessHealthPerformance;
use Hambelela\EPI\EmployeePerformanceService;
$checks=0;
function check($condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
$person=['id'=>2,'name'=>'Synthetic employee','metrics'=>[['label'=>'Orders Outstanding','value'=>99]],'summary_score'=>98];
$missing=BusinessHealthPerformance::person($person,['status'=>'not_configured'],'2026-10',0);
check($missing['summary_score']===null,'Missing result must not become zero or legacy percentage');
check($missing['summary_empty']==='Not Calculated','Missing policy explained');
check(count($missing['metrics'])===1&&$missing['metrics'][0]['value']===0,'Shared 99-order queue must not attach to employee');
$result=['status'=>'provisional','official_score_hundredths'=>null,'provisional_score_hundredths'=>8234,'result_id'=>31,'scorecard_version'=>'synthetic-v1','data_may_be_stale'=>true,
 'categories'=>['orders'=>['label'=>'Orders','official_score_hundredths'=>null,'provisional_score_hundredths'=>7500], 'tasks'=>['label'=>'Tasks','official_score_hundredths'=>null,
     'metrics'=>['completion'=>['label'=>'Completed attributable orders','numerator'=>15,'eligible_volume'=>20]]]]];
$r=BusinessHealthPerformance::person($person,$result,'2026-10',2);
check($r['summary_score']===82.34,'Provisional result must match stored hundredths exactly');
check($r['performance_result_id']===31,'Published revision preserved');
check($r['scorecard_version']==='synthetic-v1','Rule version preserved');
check(strpos($r['summary_label'],'Provisional')!==false,'Provisional label required');
check(strpos($r['summary_note'],'Update pending')!==false,'Stale result explained');
check($r['metrics'][0]['value']===2,'Use personal risk');
check($r['metrics'][1]['value']==='75.0%','Category result unchanged');
check($r['metrics'][2]['value']==='Not measured','Missing category not zero');
check($r['metrics'][3]['label']==='Observed work · provisional, not a score','Published observations have a distinct non-score label');
check($r['metrics'][3]['rows'][0]['value']==='15 / 20','Observed numerator and denominator stay visible when category score is withheld');
$result['official_score_hundredths']=0;
$zero=BusinessHealthPerformance::person($person,$result,'2026-10',0);
check($zero['summary_score']===0,'Genuine calculated zero must survive');
check($zero['summary_label']==='Official V2 monthly result','Official zero not provisional fallback');
$range=BusinessHealthPerformance::person($person,['status'=>'select_month'],null,0);
check($range['summary_score']===null&&$range['summary_empty']==='Select one month','Do not blend multiple months');
check(strpos(EmployeePerformanceService::measurementExplanation(['eligible_volume'=>20,'reason'=>'incomplete_source']),'coverage is not verified')!==false,
    'Observed work explains why a final KPI remains unavailable');
check(strpos(EmployeePerformanceService::measurementExplanation(['eligible_volume'=>3,'reason'=>'low_sample','minimum_volume'=>5]),'minimum of 5')!==false,
    'Low-volume KPI shows its actual minimum');
$source=file_get_contents(__DIR__.'/../apps/operations/reports-data.php');
check(substr_count($source,'BusinessHealthPerformance::attach(db(),')===2,'Cache HIT and MISS both use fresh authoritative result');
check(strpos($source,'readfile($cacheFile)')===false,'Cache cannot bypass fresh published result');
$client=file_get_contents(__DIR__.'/../assets/js/reports-business-health.js');
check(strpos($client,"person.summary_score !== null")!==false,'JS must not coerce null to zero');
check(strpos($client,'100 * Number(ratio.numerator)')===false,'No client-side alternative score formula');
check(strpos($client,'&month=${encodeURIComponent(person.performance_month)}')!==false,'Profile deep link preserves result month');
echo "PASS: $checks Business Health result checks\n";
