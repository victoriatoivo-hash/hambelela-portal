<?php
declare(strict_types=1);
use Hambelela\EPI\{V2OperationalBridge,DeadlineEngine};
resetObjects();
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_results_enabled'");
duty(102,'2026-10-01 08:00:00','2026-10-01 17:00:00');
sql("INSERT INTO ops_whatsapp_conversations VALUES(91,NULL,'2026-09-01 08:00:00',NULL,'2026-10-01 08:00:00','awaiting_response')");
V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_received',91,
 ['employee_id'=>0,'direction'=>'inbound','occurred_at'=>'2026-10-01 08:00:00','event_uuid'=>'message-a']);
check('COMM09 fresh inbound on existing conversation creates prospective clock',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE obligation_key='customer_response'"));
sql("UPDATE ops_whatsapp_conversations SET last_customer_message_at='2026-10-01 08:10:00' WHERE id=91");
V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_received',91,
 ['employee_id'=>0,'direction'=>'inbound','occurred_at'=>'2026-10-01 08:10:00','event_uuid'=>'message-b']);
check('COMM10 message burst cannot duplicate or restart response obligation','2026-10-01 08:30:00',scalar("SELECT due_at FROM epi_v2_operational_deadlines WHERE obligation_key='customer_response'"));
(new DeadlineEngine($db))->processDue('2026-10-01 08:31:00');
check('COMM11 unattended shared conversation follows accepted Front duty',102,(int)scalar('SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents'));
V2OperationalBridge::record($db,'ops_whatsapp_conversation','whatsapp_message_saved',91,
 ['employee_id'=>103,'direction'=>'outbound','occurred_at'=>'2026-10-01 08:40:00']);
check('COMM12 actual responder resolves old conversation obligation','fulfilled',scalar('SELECT state FROM epi_v2_operational_deadlines'));
$db->exec("CREATE TABLE epi_v2_front_plans(id INT PRIMARY KEY,accepted_at DATETIME,planned_start DATETIME,primary_employee_id INT,coverage_employee_id INT)");
sql("INSERT INTO epi_v2_front_plans VALUES(501,'2026-10-01 11:00:00','2026-10-01 12:00:00',102,103)");
V2OperationalBridge::record($db,'front_plan','handover_accepted',501,
 ['employee_id'=>103,'occurred_at'=>'2026-10-01 11:00:00']);
check('HAND01 accepted handover creates expected start obligation',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE obligation_key='complete_handover'"));
(new DeadlineEngine($db))->processDue('2026-10-01 12:01:00');
check('HAND02 missing handover measured without further action',102,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE event_key='internal_handover_missed'"));
V2OperationalBridge::record($db,'front_plan','handover_started',501,
 ['employee_id'=>102,'occurred_at'=>'2026-10-01 12:05:00']);
check('HAND03 later handover resolves risk, preserves breach','fulfilled',scalar("SELECT state FROM epi_v2_operational_deadlines WHERE obligation_key='complete_handover'"));
check('HAND04 original handover breach remains',1,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE event_key='internal_handover_missed'"));
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_results_enabled'");
