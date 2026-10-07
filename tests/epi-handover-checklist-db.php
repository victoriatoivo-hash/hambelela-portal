<?php
// Synthetic fixtures only, included after the other integration cases.
use Hambelela\EPI\{FrontHandoverChecklist,DeadlineEngine,V2Store};
$shadow->exec('DELETE FROM employee_user_links WHERE id=3'); // Remove prior ambiguity fixture.
$at='2026-11-02 11:00:00';
$dutyEngine->assignDuty(['duty_key'=>'front_desk','employee_id'=>2,'assigned_by'=>2,'accepted_by'=>2,'effective_from'=>'2026-11-02 08:00:00','effective_to'=>'2026-11-02 17:00:00']);
$dutyEngine->assign(['module'=>'Orders','object_reference'=>'CHECKLIST-1','employee_id'=>2,'role_key'=>'front_desk_admin','assigned_by'=>2,'accepted_by'=>2,'effective_from'=>'2026-11-02 08:00:00']);
$checklistEngine=new DeadlineEngine($shadow,$shadow);
$item=$checklistEngine->schedule(['module'=>'Orders','object_reference'=>'CHECKLIST-1','obligation_key'=>'complete_order','breach_event_key'=>'order_completion_sla_breached','starts_at'=>'2026-11-02 08:00:00','due_at'=>'2026-11-02 14:00:00','responsible_team'=>'front_desk']);
$notes=['waiting_customers'=>'Customer at counter','customer_promises'=>'Call customer after packing'];
$coverage->command(2,'plan',['coverage_employee_id'=>4,'start'=>'12:00','end'=>'13:00','handover_notes'=>json_encode($notes)],$at);
$view=$coverage->status(4,$at)['handover'];
check('HAND01 attributed obligation shown',true,in_array($item,array_column($view['items'],'deadline_uuid'),true));
check('HAND02 promises persist in audit-backed read model','Call customer after packing',$view['notes']['customer_promises']);
check('HAND03 acceptance requires actual review',false,attempt(function()use($coverage,$at){$coverage->command(4,'accept',[],$at);}));
$stale=reviewedCoverage($coverage,4,$at);
$unowned=$checklistEngine->schedule(['module'=>'Orders','object_reference'=>'CHECKLIST-UNOWNED','obligation_key'=>'complete_order','breach_event_key'=>'order_completion_sla_breached','starts_at'=>'2026-11-02 08:00:00','due_at'=>'2026-11-02 15:00:00','responsible_team'=>'front_desk']);
check('HAND04 stale checklist rejected',false,attempt(function()use($coverage,$stale,$at){$coverage->command(4,'accept',$stale,$at);}));
$view=$coverage->status(4,$at)['handover'];
check('HAND05 unowned team work separated',true,in_array($unowned,array_column($view['unattributed_team_items'],'deadline_uuid'),true));
$coverage->command(4,'accept',reviewedCoverage($coverage,4,$at),$at);
$audit=V2Store::one($shadow,"SELECT after_json FROM epi_v2_ownership_audits WHERE scope_key='front-plan|2026-11-02' ORDER BY id DESC LIMIT 1");
$saved=json_decode($audit['after_json'],true);
check('HAND06 acceptance keeps exact evidence reviewed',$view['review_token'],$saved['reviewed_work']['review_token']);
check('HAND07 acceptance keeps customer promises','Call customer after packing',$saved['handover_notes']['customer_promises']);
$coverage->command(2,'start',reviewedCoverage($coverage,2,'2026-11-02 12:00:00'),'2026-11-02 12:00:00');
check('HAND08 verified work transfers at actual start',4,(int)$dutyEngine->ownerAt('Orders','CHECKLIST-1','2026-11-02 12:00:00')['employee_id']);
check('HAND09 deadline is not reset','2026-11-02 14:00:00',V2Store::one($shadow,'SELECT due_at FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$item])['due_at']);
check('HAND10 unowned work remains unassigned',null,$dutyEngine->ownerAt('Orders','CHECKLIST-UNOWNED','2026-11-02 12:00:00'));
$shadow->exec('SET TRANSACTION READ ONLY');$shadow->beginTransaction();
check('HAND11 checklist reads cause no writes',true,attempt(function()use($shadow){FrontHandoverChecklist::read($shadow,4,'2026-11-02 12:01:00');}));$shadow->rollBack();
check('HAND12 oversized notes rejected',false,attempt(function(){FrontHandoverChecklist::validateNotes(json_encode(['customer_promises'=>str_repeat('x',1001)]));}));
check('HAND13 no official score changes',87,(int)$shadow->query('SELECT value FROM official_score')->fetchColumn());
