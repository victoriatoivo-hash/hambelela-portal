<?php
declare(strict_types=1);
use Hambelela\EPI\{CashDeadlineBridge,EmployeePerformanceService,PerformanceRefreshRuntime,IncidentCorrelation,V2OperationalBridge};

resetObjects();
sql("INSERT INTO ops_employees(id,full_name,role_id,status) VALUES(104,'Synthetic Cash Duty',2,'active')");
$cashPolicy=['version'=>'cash-accuracy-verification','role'=>'front_desk','status'=>'approved','categories'=>[
    'bookkeeping'=>['weight_hundredths'=>10000,'metrics'=>['accuracy'=>[
        'weight_hundredths'=>10000,'minimum_volume'=>1,'direction'=>'success',
        'source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']]]]]];
$json=json_encode($cashPolicy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$cashPolicy['version'],'front_desk',$json,hash('sha256',$json)]);
sql("INSERT INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,official_from,assigned_by,validation_approved_by,validation_approved_at) VALUES(104,'2026-10-01',?,'2026-10-01',999,999,'2026-09-30 08:00:00')",[$cashPolicy['version']]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key IN('epi_v2_results_enabled','epi_v2_official_capture_enabled')");
duty(104,'2026-10-01 08:00:00','2026-10-01 17:00:00');
foreach([7101=>150,7102=>100] as $id=>$cash){
    CashDeadlineBridge::capture($db,'order','order_created',[
        'id'=>$id,'created_at'=>'2026-10-01 08:00:00','payment_status'=>'paid'],
        ['employee_id'=>104,'occurred_at'=>'2026-10-01 08:00:00','cash_receipt_cents'=>15000]);
    sql("INSERT INTO ops_cash_book_entries VALUES(?,?,?,'active',NULL,103,'2026-10-01 08:30:00')",[$id,$id,$cash]);
    V2OperationalBridge::record($db,'cash_entry','created',$id,
        ['employee_id'=>103,'occurred_at'=>'2026-10-01 08:30:00']);
}
$unit=json_decode(scalar("SELECT source_snapshot_json FROM epi_v2_completed_work_units WHERE opportunity_key='cash_entry:7102:order:7102'"),true);
check('AMOUNT01 mismatched amount retained as evidence',10000,$unit['record']['cash_process']['recorded_cents']);
check('AMOUNT02 mismatch is not automatic employee fault',false,$unit['record']['cash_process']['measured']);
check('AMOUNT03 separate cash author retained',103,$unit['record']['cash_process']['entry_author_id']);
check('AMOUNT04 cash duty owner is not assumed to be author',104,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='cash_entry:7102:order:7102'"));
PerformanceRefreshRuntime::run($db);
$cashService=new EmployeePerformanceService($db);
$cashResult=$cashService->read(104,'2026-10-01');
check('AMOUNT05 unreviewed mismatch prevents false perfect score',null,$cashResult['official_score_hundredths']);
check('AMOUNT06 exact amount is measurable',1,$cashResult['categories']['bookkeeping']['metrics']['accuracy']['numerator']);
sql("UPDATE ops_cash_book_entries SET cash_in=150 WHERE id=7102");
V2OperationalBridge::record($db,'cash_entry','edited',7102,['employee_id'=>103,'occurred_at'=>'2026-10-01 08:45:00']);
$unit=json_decode(scalar("SELECT source_snapshot_json FROM epi_v2_completed_work_units WHERE opportunity_key='cash_entry:7102:order:7102'"),true);
check('AMOUNT07 later correction preserves original discrepancy',10000,$unit['record']['cash_process']['recorded_cents']);
check('AMOUNT08 amount correction clears cash completion risk','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND object_reference='ORDER-7102'"));
sql("INSERT INTO ops_error_logs VALUES(71,'employee',104,104,103,1,999,999,'2026-10-01 10:00:00',
    '2026-10-01 10:00:00','2026-10-01 08:30:00','open','2026-10-01 10:00:00','incorrect_payment_information','medium')");
V2OperationalBridge::record($db,'error_log','error_owner_reviewed',71,
    ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 10:00:00']);
IncidentCorrelation::link($db,['source_key'=>'error:71','root_incident_id'=>'cash-amount:7102',
    'opportunity_key'=>'cash_entry:7102:order:7102','category_key'=>'bookkeeping','metric_key'=>'accuracy','employee_id'=>104],
    999,'Evidence confirms duty owner supplied the incorrect amount; entry author reported it','2026-10-01 10:01:00');
PerformanceRefreshRuntime::run($db);
$cashResult=$cashService->read(104,'2026-10-01');
check('AMOUNT09 reviewed amount discrepancy affects accuracy',5000,$cashResult['official_score_hundredths']);
check('AMOUNT10 amount denominator is real cash entries',2,$cashResult['categories']['bookkeeping']['metrics']['accuracy']['eligible_volume']);
$failure=array_values(array_filter($cashResult['evidence'],static function(array $r):bool{return $r['outcome']==='failure';}));
check('AMOUNT11 reporter is not scored responsible employee',104,$failure[0]['employee_id']);
V2OperationalBridge::record($db,'cash_entry','edited',7102,['employee_id'=>103,'occurred_at'=>'2026-10-01 11:00:00']);
check('AMOUNT12 repeated edits do not inflate denominator',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_completed_work_units WHERE opportunity_key='cash_entry:7102:order:7102'"));
// One banked cash entry may cover two verified orders. Keep both allocations.
sql("INSERT INTO ops_cash_book_entries VALUES(7110,NULL,300,'active',NULL,103,'2026-10-01 11:30:00')");
foreach([7111,7112] as $id){
    CashDeadlineBridge::capture($db,'order','order_created',[
        'id'=>$id,'created_at'=>'2026-10-01 11:00:00','payment_status'=>'paid'],
        ['employee_id'=>104,'occurred_at'=>'2026-10-01 11:00:00','cash_receipt_cents'=>15000]);
    V2OperationalBridge::record($db,'cash_entry','historical_allocation',7110,
        ['employee_id'=>999,'occurred_at'=>'2026-10-01 12:00:00','confirmed_order_id'=>$id,'confirmed_cash_cents'=>15000]);
}
check('AMOUNT13 shared entry retains both structured allocations',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_completed_work_units WHERE opportunity_key LIKE 'cash_entry:7110:order:%'"));
check('AMOUNT14 shared allocation review retains native entry author',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_completed_work_units WHERE opportunity_key LIKE 'cash_entry:7110:order:%' AND fulfiller_id<>103"));
PerformanceRefreshRuntime::run($db);
check('AMOUNT15 accuracy measures four allocated receipt opportunities',4,$cashService->read(104,'2026-10-01')['categories']['bookkeeping']['metrics']['accuracy']['eligible_volume']);
