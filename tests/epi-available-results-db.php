<?php
declare(strict_types=1);
use Hambelela\EPI\{EmployeePerformanceService,PerformanceCapturePolicy,PerformanceSourceCoverage};
require_once __DIR__.'/../shared/epi/PerformanceSourceCoverage.php';
$db->beginTransaction();
try{
    $m=['weight_hundredths'=>10000,'minimum_volume'=>5,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success'];
    $p=['version'=>'available-points-synthetic','status'=>'provisional','categories'=>[
        'orders'=>['label'=>'Orders','weight_hundredths'=>2500,'metrics'=>['completion'=>$m+['label'=>'Completion']]],
        'communication'=>['label'=>'Communication','weight_hundredths'=>7500,'metrics'=>['response'=>$m+['label'=>'Response']]]]];
    $json=json_encode($p);sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$p['version'],'synthetic',$json,hash('sha256',$json)]);
    sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from=NULL,validation_approved_at=NULL WHERE employee_id=103 AND period_start='2026-10-01'",[$p['version']]);
    sql("UPDATE epi_v2_employee_result_heads SET locked_at=NULL WHERE employee_id=103 AND period_start='2026-10-01'");
    $items=[];
    for($i=0;$i<5;$i++)$items[]=['employee_id'=>103,'category'=>'orders','metric'=>'completion','opportunity_key'=>'available-fixture-'.$i,
        'root_incident_id'=>$i===0?'available-root':null,'module'=>'Orders','source_reference'=>'Synthetic order '.$i,
        'starts_at'=>'2026-10-01 08:00:00','due_at'=>'2026-10-01 10:00:00','fulfilled_by'=>$i===0?102:103,
        'responsibility_confirmed'=>true,'eligible'=>true,'outcome'=>$i===0?'failure':'success',
        'metadata'=>PerformanceCapturePolicy::metadata(2),'expected'=>'Fulfil by deadline','actual'=>$i===0?'Late helper completion; original breach retained':'On time',
        'ownership'=>['employee_id'=>103,'effective_from'=>'2026-10-01 08:00:00','effective_to'=>null,'accepted_at'=>'2026-10-01 08:00:00']];
    $cover=['orders'=>['completion'=>['complete'=>false]],'communication'=>['response'=>['complete'=>false]]];
    $svc=new EmployeePerformanceService($db);$r=$svc->recalculate(103,'2026-10-01',$items,$cover);
    check('AVAILABLE01 real supported weighted points',2000,$r['available_points_hundredths']);
    check('AVAILABLE02 missing 75 points not redistributed',2500,$r['available_weight_hundredths']);
    check('AVAILABLE03 incomplete official stays absent',null,$r['official_score_hundredths']);
    check('AVAILABLE04 incomplete full provisional stays absent',null,$r['provisional_score_hundredths']);
    check('AVAILABLE05 fraction presentation','20.0 / 25.0 pts · Provisional',EmployeePerformanceService::availableDisplay($r));
    check('AVAILABLE06 fulfiller does not inherit breach',103,$r['evidence'][0]['employee_id']);
    check('AVAILABLE07 export contract columns',count(EmployeePerformanceService::exportHeaders()),count(EmployeePerformanceService::exportRows($r)[0]));
    $revision=$r['result_id'];$svc->recalculate(103,'2026-10-01',array_reverse($items),$cover);
    check('AVAILABLE08 repeat worker idempotent',$revision,$svc->read(103,'2026-10-01')['result_id']);
    sql("UPDATE epi_v2_employee_result_heads SET locked_at='2026-11-01 08:00:00',locked_by=999 WHERE employee_id=103 AND period_start='2026-10-01'");
    $old=(string)scalar('SELECT result_json FROM epi_v2_employee_results WHERE id=?',[$revision]);
    $items[1]['outcome']='failure';$items[1]['root_incident_id']='available-root-2';
    $svc->recalculate(103,'2026-10-01',$items,$cover);
    check('AVAILABLE09 locked pointer unchanged',$revision,$svc->read(103,'2026-10-01')['result_id']);
    check('AVAILABLE10 locked JSON unchanged',$old,(string)scalar('SELECT result_json FROM epi_v2_employee_results WHERE id=?',[$revision]));
    $coverageReadDb=connectAudit($database);$coverageReadDb->exec('SET TRANSACTION READ ONLY');$coverageReadDb->beginTransaction();
    check('AVAILABLE11 source resolver under DB-enforced read-only transaction',true,attempt(function()use($coverageReadDb,$p,$cover){PerformanceSourceCoverage::resolve($coverageReadDb,$p,'2026-10-01',$cover);}));$coverageReadDb->rollBack();
}finally{$db->rollBack();}
