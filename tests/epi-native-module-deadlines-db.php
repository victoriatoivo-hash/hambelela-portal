<?php
declare(strict_types=1);
use Hambelela\EPI\{V2OperationalBridge,DeadlineEngine};
resetObjects();
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_results_enabled'");
$db->exec("CREATE TABLE ops_packing_tasks(id INT PRIMARY KEY,created_at DATETIME,date_loaded DATETIME,
 assigned_employee_id INT,packing_status VARCHAR(40));
 CREATE TABLE hambelela_waybills(id INT PRIMARY KEY,batch_id VARCHAR(40),uploaded_at DATETIME,
 sent_at DATETIME NULL,status VARCHAR(30))");
duty(102,'2026-10-01 08:00:00','2026-10-01 17:00:00');
sql("INSERT INTO ops_packing_tasks VALUES(81,'2026-10-01 08:00:00','2026-10-01 08:00:00',101,'not_started')");
V2OperationalBridge::record($db,'packing_task','packing_item_created',81,
 ['employee_id'=>999,'occurred_at'=>'2026-10-01 08:00:02']);
check('MOD01 actual creation tolerates audit timestamp difference',3,(int)scalar('SELECT COUNT(*) FROM epi_v2_operational_deadlines'));
(new DeadlineEngine($db))->processDue('2026-10-01 09:01:00');
check('MOD02 untouched packing start belongs to packer',101,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE module='Packing List'"));
sql("UPDATE ops_packing_tasks SET packing_status='done' WHERE id=81");
V2OperationalBridge::record($db,'packing_task','packing_packing_status_updated',81,
 ['employee_id'=>103,'occurred_at'=>'2026-10-01 10:00:00','field'=>'packing_status']);
check('MOD03 packing helper does not inherit original breach',101,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE module='Packing List'"));
check('MOD04 completed packing records actual helper',103,(int)scalar("SELECT fulfiller_id FROM epi_v2_completed_work_units WHERE opportunity_key='packing_task:81:0'"));
V2OperationalBridge::record($db,'packing_task','frontdesk_website_update_confirmed',81,
 ['employee_id'=>102,'occurred_at'=>'2026-10-01 10:05:00']);
check('MOD05 website fulfilment separate from packing',102,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE module='Inventory'"));
sql("INSERT INTO hambelela_waybills VALUES(91,'batch91','2026-10-01 10:10:00',NULL,'pending')");
V2OperationalBridge::record($db,'courier_waybill','courier_waybill_uploaded',91,
 ['employee_id'=>101,'occurred_at'=>'2026-10-01 10:10:00']);
check('MOD06 Front clock starts at document availability','2026-10-01 10:40:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE module='Courier'"));
(new DeadlineEngine($db))->processDue('2026-10-01 10:41:00');
check('MOD07 Front dispatch breach not charged to uploader',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE module='Courier'"));
sql("UPDATE hambelela_waybills SET status='sent',sent_at='2026-10-01 10:45:00' WHERE id=91");
V2OperationalBridge::record($db,'courier_waybill_batch','courier_waybill_sent',0,
 ['employee_id'=>103,'occurred_at'=>'2026-10-01 10:45:00','batch_id'=>'batch91']);
check('MOD08 batch action fulfils individual deadlines','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE module='Courier'"));
check('MOD09 dispatch helper separate from breach employee',103,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE module='Courier'"));
check('MOD10 module captures all consumed',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'"));
sql("INSERT INTO ops_orders(id,order_number,created_at,payment_status,status) VALUES(501,'CASH501','2026-10-01 11:00:00','paid','new_order')");
sql("INSERT INTO order_payment_allocations VALUES(501,'cash',15000)");
V2OperationalBridge::record($db,'order','payment_status_updated',501,
 ['employee_id'=>102,'occurred_at'=>'2026-10-01 11:00:00']);
check('CASH01 received structured cash creates deadline','2026-10-01 12:00:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
V2OperationalBridge::record($db,'order','payment_status_updated',501,
 ['employee_id'=>102,'occurred_at'=>'2026-10-01 11:10:00']);
check('CASH02 repeated paid update cannot restart cash clock',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
(new DeadlineEngine($db))->processDue('2026-10-01 12:01:00');
check('CASH03 missing cash entry creates omission breach',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE module='Bookkeeping'"));
$db->exec("CREATE TABLE ops_cash_book_entries(id INT PRIMARY KEY,related_order_id INT,cash_in DECIMAL(12,2),status VARCHAR(30),deleted_at DATETIME NULL,recorded_by INT,created_at DATETIME)");
sql("INSERT INTO ops_cash_book_entries VALUES(601,NULL,150,'active',NULL,103,'2026-10-01 12:05:00')");
V2OperationalBridge::record($db,'cash_entry','created',601,['employee_id'=>103,'occurred_at'=>'2026-10-01 12:05:00']);
check('CASH04 unlinked cash entry cannot clear breach','breached',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
V2OperationalBridge::record($db,'cash_entry','historical_allocation',601,['employee_id'=>999,
 'occurred_at'=>'2026-10-01 12:10:00','confirmed_order_id'=>501,'confirmed_cash_cents'=>10000]);
check('CASH05 partial amount cannot falsely fulfil cash obligation','breached',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
V2OperationalBridge::record($db,'cash_entry','historical_allocation',601,['employee_id'=>999,
 'occurred_at'=>'2026-10-01 12:15:00','confirmed_order_id'=>501,'confirmed_cash_cents'=>15000]);
check('CASH06 reviewed exact link resolves current risk','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
check('CASH07 late review keeps responsible employee breach',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE module='Bookkeeping'"));
check('CASH08 allocation reviewer is not entry fulfiller',103,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
check('CASH09 actual entry time survives later allocation review','2026-10-01 12:05:00',scalar("SELECT fulfilled_at FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'"));
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_results_enabled'");
