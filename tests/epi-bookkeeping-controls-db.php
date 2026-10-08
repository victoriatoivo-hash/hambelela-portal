<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/epi/BookkeepingControlBridge.php';
use Hambelela\EPI\{BookkeepingControlBridge,V2Store,DeadlineEngine,PerformanceRefreshRuntime,EmployeePerformanceService};
resetObjects();
check('CONTROL01 unconfigured controls do not invent deadlines','not_configured',BookkeepingControlBridge::reconcile($db,'2026-10-01 08:00:00')['status']);
// Explicitly approved synthetic scorecard, never a production/default update.
$controlPolicy=['version'=>'cash-control-verification','role'=>'front_desk','status'=>'approved','categories'=>[
    'bookkeeping'=>['weight_hundredths'=>10000,'metrics'=>['controls'=>[
        'weight_hundredths'=>10000,'minimum_volume'=>1,'direction'=>'success',
        'source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']]]]]];
$controlPolicy['operational_controls']['cash']=['opening_after_open_minutes'=>30,'closing_before_close_minutes'=>15];
$json=json_encode($controlPolicy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',
    [$controlPolicy['version'],'front_desk',$json,hash('sha256',$json)]);
sql('UPDATE epi_v2_scorecard_assignments SET scorecard_version=? WHERE employee_id=104',[$controlPolicy['version']]);
$db->exec("ALTER TABLE ops_cash_book_entries ADD entry_date DATE NULL, ADD cash_out DECIMAL(12,2) DEFAULT 0,
    ADD transaction_type VARCHAR(40) NULL, ADD source VARCHAR(40) NULL;
    CREATE TABLE hambelela_cashbook_recon(id INT PRIMARY KEY,recon_date DATE,system_balance DECIMAL(12,2),
    counted_total DECIMAL(12,2),variance DECIMAL(12,2),logged_by INT,created_at DATETIME)");
duty(104,'2026-10-01 08:00:00','2026-10-01 17:00:00');
$controls=BookkeepingControlBridge::reconcile($db,'2026-10-01 08:31:00');
check('CONTROL02 unattended business day schedules both controls',2,$controls['scheduled']);
(new DeadlineEngine($db))->processDue('2026-10-01 08:31:00');
check('CONTROL03 no click creates one opening breach',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE event_key='cash_opening_sla_breached'"));
check('CONTROL04 accepted Front duty owns opening omission',104,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE event_key='cash_opening_sla_breached'"));
sql("INSERT INTO ops_cash_book_entries(id,related_order_id,cash_in,status,recorded_by,created_at,entry_date,transaction_type,source)
    VALUES(7210,NULL,200,'active',103,'2026-10-01 08:40:00','2026-10-01','opening_balance','opening_balance')");
sql("INSERT INTO hambelela_cashbook_recon VALUES(7211,'2026-10-01',200,190,-10,103,'2026-10-01 16:30:00')");
$controls=BookkeepingControlBridge::reconcile($db,'2026-10-01 16:46:00');
(new DeadlineEngine($db))->processDue('2026-10-01 16:46:00');
PerformanceRefreshRuntime::run($db);
check('CONTROL05 native control evidence fulfils both obligations',2,$controls['fulfilled']);
check('CONTROL06 timely proof processed before breach evaluation',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE event_key='cash_closing_sla_breached'"));
check('CONTROL07 helper never inherits original omission',104,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE event_key='cash_opening_sla_breached'"));
check('CONTROL08 helper retained separately',103,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE obligation_key='record_opening_balance'"));
check('CONTROL09 current risk resolved history remains','resolved',scalar("SELECT current_risk_state FROM epi_v2_performance_incidents WHERE event_key='cash_opening_sla_breached'"));
$controlResult=(new EmployeePerformanceService($db))->read(104,'2026-10-01');
check('CONTROL10 one timely control in two real obligations',5000,$controlResult['official_score_hundredths']);
check('CONTROL11 variance not an automatic additional employee failure',1,count(array_filter($controlResult['evidence'],static function(array $e):bool{return $e['outcome']==='failure';})));
check('CONTROL12 proof audit retains native record',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_audits WHERE scope_key LIKE 'cash-control|%'"));
$replay=BookkeepingControlBridge::reconcile($db,'2026-10-01 16:47:00');
check('CONTROL13 repeated watchdog creates no duplicate obligations',0,$replay['scheduled']);
check('CONTROL14 repeated watchdog creates no duplicate proof',0,$replay['fulfilled']);
BookkeepingControlBridge::reconcile($db,'2026-10-04 12:00:00');
check('CONTROL15 Sunday schedules no cash controls',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2026-10-04'"));
check('CONTROL16 Saturday follows configured business opening','2026-10-03 09:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2026-10-03' AND obligation_key='record_opening_balance'"));
