<?php
declare(strict_types=1);
// Included only inside the isolated integration transaction; no production records.
require_once __DIR__.'/../shared/epi/BookkeepingControlBridge.php';
use Hambelela\EPI\{BookkeepingControlBridge,DeadlineEngine,PerformanceRefreshRuntime,EmployeePerformanceService};
resetObjects();
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_front_roster_enabled'");
$times=['opening_after_open_minutes'=>30,'closing_before_close_minutes'=>15];
check('DAILY01 non-owner cannot approve',false,attempt(fn()=>BookkeepingControlBridge::approveProvisional($db,$times,101,'not owner','2030-10-01 00:00:00')));
check('DAILY02 historical policy backdate rejected',false,attempt(fn()=>BookkeepingControlBridge::approveProvisional($db,$times,999,'backdate','2020-01-01')));
check('DAILY03 no implicit daily setting',null,BookkeepingControlBridge::provisional($db,'2030-09-30 23:59:59'));
$daily=BookkeepingControlBridge::approveProvisional($db,$times,999,'Owner approved prospective daily cash controls','2030-10-01 00:00:00');
check('DAILY04 approved offsets retained',$times,$daily['controls']);
check('DAILY05 cannot be used for financial decisions',false,$daily['financial_use_allowed']);
check('DAILY06 activation retry preserves effective date',$daily,BookkeepingControlBridge::approveProvisional($db,$times,999,'same activation retry','2030-10-02 00:00:00'));
$cashPolicy=['version'=>'provisional-daily-control-fixture','role'=>'front_desk','status'=>'provisional','financial_use_allowed'=>false,
 'provisional_capture'=>['enabled'=>true,'authorised_by'=>999,'authorised_at'=>'2030-10-01 07:00:00','from'=>'2030-10-01 08:00:00'],
 'categories'=>['bookkeeping'=>['weight_hundredths'=>10000,'metrics'=>['controls'=>['weight_hundredths'=>10000,'minimum_volume'=>1,'moderate_volume'=>20,'high_volume'=>100,'direction'=>'success']]]]];
$j=json_encode($cashPolicy);sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$cashPolicy['version'],'front_desk',$j,hash('sha256',$j)]);
sql("INSERT INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,assigned_by) VALUES(104,'2030-10-01',?,999)",[$cashPolicy['version']]);
duty(104,'2030-10-01 08:00:00','2030-10-01 17:00:00');
$run=BookkeepingControlBridge::reconcile($db,'2030-10-01 08:31:00');
check('DAILY07 prospective daily controls schedule without clicks',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01'"));
check('DAILY08 opening due 08:30','2030-10-01 08:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='record_opening_balance'"));
check('DAILY09 pre-close due 16:45','2030-10-01 16:45:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='complete_cash_reconciliation'"));
(new DeadlineEngine($db))->processDue('2030-10-01 08:31:00');
check('DAILY10 omission attributed to duty owner',104,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='CASH-DAY-2030-10-01' AND event_key='cash_opening_sla_breached'"));
sql("INSERT INTO ops_cash_book_entries(id,cash_in,status,recorded_by,created_at,transaction_date,transaction_type,source) VALUES(9921,200,'active',103,'2030-10-01 08:40:00','2030-10-01 08:00:00','opening_balance','opening_balance')");
sql("INSERT INTO hambelela_cashbook_recon VALUES(9921,'2030-10-01',200,190,-10,103,'2030-10-01 16:30:00')");
BookkeepingControlBridge::reconcile($db,'2030-10-01 16:31:00');
check('DAILY11 real transaction_date proves opening; no invented entry_date','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='record_opening_balance'"));
check('DAILY12 early reconciliation not yet accepted as pre-close',null,scalar("SELECT fulfilled_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='complete_cash_reconciliation'"));
sql("INSERT INTO ops_cash_book_entries(id,cash_in,status,recorded_by,created_at,transaction_date,transaction_type,source) VALUES(9922,50,'active',104,'2030-10-01 16:40:00','2030-10-01 16:40:00','cash_received','manual_cash_entry')");
BookkeepingControlBridge::reconcile($db,'2030-10-01 16:46:00');
(new DeadlineEngine($db))->processDue('2030-10-01 16:46:00');
check('DAILY13 stale reconciliation cannot satisfy pre-close','breached',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='complete_cash_reconciliation'"));
sql("INSERT INTO hambelela_cashbook_recon VALUES(9922,'2030-10-01',250,240,-10,103,'2030-10-01 16:50:00')");
sql("INSERT INTO ops_cash_book_entries(id,cash_in,status,recorded_by,created_at,transaction_date,transaction_type,source) VALUES(9923,25,'active',104,'2030-10-01 16:55:00','2030-10-01 16:55:00','cash_received','manual_cash_entry')");
BookkeepingControlBridge::reconcile($db,'2030-10-01 17:00:00');
check('DAILY14 late native count resolves Current Risk','resolved',scalar("SELECT current_risk_state FROM epi_v2_performance_incidents WHERE object_reference='CASH-DAY-2030-10-01' AND event_key='cash_closing_sla_breached'"));
check('DAILY15 helper is fulfiller only',103,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='complete_cash_reconciliation'"));
check('DAILY16 original breach owner remains',104,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='CASH-DAY-2030-10-01' AND event_key='cash_closing_sla_breached'"));
check('DAILY17 post-checkpoint receipt does not invalidate the checkpoint','2030-10-01 16:50:00',scalar("SELECT fulfilled_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-01' AND obligation_key='complete_cash_reconciliation'"));
BookkeepingControlBridge::reconcile($db,'2030-10-06 12:00:00');
check('DAILY18 Saturday pre-close 12:45','2030-10-05 12:45:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-05' AND obligation_key='complete_cash_reconciliation'"));
check('DAILY19 Sunday paused',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='CASH-DAY-2030-10-06'"));
PerformanceRefreshRuntime::run($db);$dailyResult=(new EmployeePerformanceService($db))->read(104,'2030-10-01');
check('DAILY20 provisional controls cannot create an official score',null,$dailyResult['official_score_hundredths']);
check('DAILY21 variance does not create an automatic extra incident',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE object_reference='CASH-DAY-2030-10-01'"));
