<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Outbox belongs to the caller transaction; failed capture remains replayable. */
final class V2OperationalBridge
{
 public static function record(PDO $db,string $type,string $action,int $id,array $meta=[]):void {
    $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_capture_enabled'");
    if(!$flag||$flag['setting_value']!=='1'||!V2Store::activation($db)||!in_array($type,['order','checklist_task','error_log'],true))return;
    $table=['order'=>'ops_orders','checklist_task'=>'ops_checklist_tasks','error_log'=>'ops_error_logs'][$type];
    $record=V2Store::one($db,"SELECT * FROM $table WHERE id=?",[$id]);if(!$record)return;
    $meta['occurred_at']=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
    $meta['employee_id']=$meta['employee_id']??(function_exists('ops_current_employee_id')?ops_current_employee_id():null);
    $payload=['metadata'=>$meta,'record'=>$record];
    $key=Support::dedupe([$type,$id,$action,$meta['event_uuid']??$meta['activity_id']??Support::json($payload)]);
    $db->prepare('INSERT IGNORE INTO epi_v2_outbox(event_key,entity_type,entity_id,action,payload_json) VALUES(?,?,?,?,?)')->execute([$key,$type,$id,$action,Support::json($payload)]);
    self::consume($db,$key);
 }
 public static function consume(PDO $db,string $key):void {
    try{V2Store::transaction($db,function()use($db,$key){
        $e=V2Store::one($db,"SELECT * FROM epi_v2_outbox WHERE event_key=? AND state='pending'",[$key]);if(!$e)return;
        V2Store::lock($db,'outbox-object|'.$e['entity_type'].'|'.$e['entity_id']);
        $e=V2Store::one($db,"SELECT * FROM epi_v2_outbox WHERE event_key=? AND state='pending' FOR UPDATE",[$key]);if(!$e)return;
        if(V2Store::one($db,"SELECT id FROM epi_v2_outbox WHERE entity_type=? AND entity_id=? AND state='pending' AND id<? LIMIT 1",[$e['entity_type'],$e['entity_id'],$e['id']]))return;
        $p=json_decode($e['payload_json'],true);
        $p['metadata']['_outbox_id']=(int)$e['id'];
        if($e['entity_type']==='order')self::order($db,$e['action'],$p['record'],$p['metadata']);
        elseif($e['entity_type']==='error_log')V2QualityBridge::capture($db,$e['action'],(int)$e['entity_id'],$p['metadata'],$p['record']);
        else self::task($db,$e['action'],$p['record'],$p['metadata']);
        $db->prepare("UPDATE epi_v2_outbox SET state='done',attempts=attempts+1,last_error=NULL WHERE id=?")->execute([$e['id']]);
    });}catch(\Throwable $error){$db->prepare("UPDATE epi_v2_outbox SET attempts=attempts+1,last_error=? WHERE event_key=?")->execute([$error->getMessage(),$key]);error_log('EPI V2 capture pending: '.$error->getMessage());}
 }
 public static function replay(PDO $db,int $limit=100):void {
    $started=microtime(true);
    $limit=max(1,min(1000,$limit));$s=$db->query("SELECT event_key FROM epi_v2_outbox WHERE state='pending' ORDER BY attempts,id LIMIT $limit");
    foreach($s->fetchAll(PDO::FETCH_COLUMN)as$key){if(microtime(true)-$started>10)break;self::consume($db,$key);}
 }
 private static function order(PDO $db,string $action,array $order,array $meta):void {
    $reference=(string)($order['order_number']?:'ORDER-'.$order['id']);$at=Support::timestamp($meta['occurred_at']);
    $mode=self::fulfilmentMode($order);$engine=new DeadlineEngine($db);$owners=new OwnershipPeriodEngine($db);$policy=V2Store::policy($db);
    $actor=(int)($meta['employee_id']??0);
    if($action==='order_created'){
        $config=$policy['orders'][$mode]??null;
        if(!$config)return; // Unknown/unapproved modes have no arbitrary SLA.
        $start=$at;$original=$meta['original_created_at']??$order['created_at']??$at->format('Y-m-d H:i:s');
        $activation=V2Store::activation($db);$historical=$original<$activation['enforcement_start_at'];
        // Old backlog is analysis-only unless explicitly accepted as a NEW prospective obligation.
        if($historical&&empty($meta['accepted_backlog_at']))$backfill=true;else $backfill=false;
        if($historical&&!empty($meta['accepted_backlog_at']))$start=Support::timestamp(max($activation['enforcement_start_at'],$meta['accepted_backlog_at']));
        $owner=$owners->dutyAt('front_desk',$start);
        $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Orders',?,'front_desk')")->execute([$reference]);
        if($owner&&!$owners->ownerAt('Orders',$reference,$start)){
            // Duty lookup through delegation is sufficient; object projection is optional.
        }
        if($owner&&!V2Store::one($db,'SELECT id FROM epi_v2_ownership_periods WHERE module=? AND object_reference=?',['Orders',$reference])){
            $owners->assign(['module'=>'Orders','object_reference'=>$reference,'employee_id'=>(int)$owner['employee_id'],'assigned_by'=>(int)$owner['assigned_by'],'accepted_by'=>(int)$owner['employee_id'],'effective_from'=>$start,'effective_to'=>$owner['effective_to'],'accepted_at'=>$owner['accepted_at'],'ownership_reason'=>'front_desk_duty','source'=>'duty:'.$owner['duty_uuid']]);
        }
        // Progression includes acknowledgement: one root, not two deductions for the same transition.
        foreach(['move_order_out_of_new'=>['new_minutes','order_new_sla_breached'],'complete_order'=>['completion_minutes','order_completion_sla_breached']]as$obligation=>$rule){
            if(empty($config[$rule[0]]))throw new RuntimeException('Approved mode policy must define both deadlines');
            $due=(new BusinessTimeEngine($db))->addWorkingMinutes($start,(int)$config[$rule[0]]);
            $engine->schedule(['module'=>'Orders','object_reference'=>$reference,'obligation_key'=>$obligation,'breach_event_key'=>$rule[1],'starts_at'=>$start,'due_at'=>$due,'source_event'=>'order_created','responsible_employee_id'=>$owner['employee_id']??null,'responsible_team'=>'front_desk','ownership_snapshot'=>$owner,'original_created_at'=>$original,'historical_record'=>$historical,'historical_backfill'=>$backfill,'cycle_id'=>$meta['cycle_id']??'initial']);
        }
    }elseif(in_array($action,['status_changed','order_completed','bulk_status_updated'],true)||($meta['field']??'')==='status'){
        $status=strtolower((string)($meta['new_value']??$meta['status']??$order['status']??''));
        if(in_array($status,['cancelled','canceled'],true)){$engine->cancelObject('Orders',$reference,$actor,$at);return;}
        if(!in_array($status,['new','new_order','pending',''],true))$engine->fulfilObject('Orders',$reference,'move_order_out_of_new',$actor,$at);
        if(in_array($status,['complete','completed'],true))$engine->fulfilObject('Orders',$reference,'complete_order',$actor,$at);
    }
 }
 private static function task(PDO $db,string $action,array $task,array $meta):void {
    $ref='TASK-'.$task['id'];$at=Support::timestamp($meta['occurred_at']);$time=$at->format('Y-m-d H:i:s');
    $employee=(int)($task['assigned_employee_id']??0);$engine=new DeadlineEngine($db);$owners=new OwnershipPeriodEngine($db);$policy=V2Store::policy($db);
    $actor=(int)($meta['employee_id']??$employee);
    if(in_array($action,['task_created','task_scheduled','task_assigned','task_reassigned'],true)){
        // Explicit activation policy authorizes directed assignment, not fictional employee acknowledgement.
        $activation=V2Store::activation($db);
        if(($policy['activation_scope']??'')==='approved_existing_rules'){
            // Undefined authority, unassigned work and old backlog stay out of this trial.
            if($employee<=0||(int)($task['created_by']??0)!==(int)$activation['approved_by'])return;
            if(($task['created_at']??$time)<$activation['enforcement_start_at'])return;
        }
        if(($policy['task_assignment_policy']??'')!=='owner_directed'||(int)($task['created_by']??0)!==(int)$activation['approved_by'])throw new RuntimeException('Task assignment requires approved authority or accepted handover');
        $existing=$owners->ownerAt('Tasks',$ref,$at);
        if(!$existing||(int)$existing['employee_id']!==$employee)$owners->assign(['module'=>'Tasks','object_reference'=>$ref,'employee_id'=>$employee,'assigned_by'=>(int)$activation['approved_by'],'accepted_by'=>(int)$activation['approved_by'],'authority'=>'owner_directed','effective_from'=>$time,'accepted_at'=>$time,'source'=>'approved_directed_task_assignment']);
        $starts=Support::timestamp($task['released_at']??$task['scheduled_at']??$task['date_assigned']??$time);
        if($action!=='task_reassigned'){
            $minutes=$policy['task_start_by_priority'][strtolower((string)($task['priority']??''))]??$policy['task_start_minutes']??null;
            if($minutes!==null&&(int)$minutes>0)$engine->schedule(['module'=>'Tasks','object_reference'=>$ref,'obligation_key'=>'start_task','breach_event_key'=>'task_start_sla_breached','source_event'=>$action,'starts_at'=>$starts,'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($starts,(int)$minutes),'responsible_employee_id'=>$employee]);
            elseif(($policy['activation_scope']??'')!=='approved_existing_rules')throw new RuntimeException('Task start policy not configured');
            if(!empty($task['deadline']))$engine->schedule(['module'=>'Tasks','object_reference'=>$ref,'obligation_key'=>'complete_task','breach_event_key'=>'task_completion_sla_breached','source_event'=>$action,'starts_at'=>$starts,'due_at'=>$task['deadline'],'responsible_employee_id'=>$employee]);
        }
    }
    if(in_array($action,['task_acknowledged','task_correction_started','task_completed','task_correction_completed'],true)||in_array($meta['status']??'',['in_progress','complete'],true))$engine->fulfilObject('Tasks',$ref,'start_task',$actor,$at);
    if(in_array($action,['task_completed','task_correction_completed'],true)||($meta['status']??'')==='complete')$engine->fulfilObject('Tasks',$ref,'complete_task',$actor,$at);
 }
 private static function fulfilmentMode(array $r):string {
    $raw=strtolower(trim((string)(($r['fulfilment_mode']??'')?:($r['order_type']??''))));
    $raw=['walk-in'=>'walk_in','walk in'=>'walk_in','delivery'=>'windhoek_delivery','windhoek delivery'=>'windhoek_delivery'][$raw]??$raw;
    return in_array($raw,['walk_in','collection','windhoek_delivery','courier','showgrounds'],true)?$raw:'other';
 }
}
