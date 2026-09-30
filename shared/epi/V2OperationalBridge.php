<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use Throwable;

/**
 * Shadow-mode bridge from committed operational changes to V2 obligations.
 * It is called only after a successful save and is fail-safe for operations.
 */
final class V2OperationalBridge
{
    public static function record(PDO$pdo,string$entityType,string$action,int$entityId,array$metadata=[]):void
    {
        if($entityId<=0||!self::enabled($pdo))return;
        try{
            if($entityType==='order')self::order($pdo,$action,$entityId,$metadata);
            elseif($entityType==='checklist_task')self::task($pdo,$action,$entityId,$metadata);
        }catch(Throwable$error){self::failure($pdo,$error,$entityType,$entityId,$action);}
    }

    private static function order(PDO$pdo,string$action,int$id,array$metadata):void
    {
        $stmt=$pdo->prepare('SELECT * FROM ops_orders WHERE id=? LIMIT 1');$stmt->execute([$id]);$order=$stmt->fetch(PDO::FETCH_ASSOC);if(!$order)return;
        $reference=trim((string)($order['order_number']??''))?:'ORDER-'.$id;
        $mode=self::fulfilmentMode($order);$at=Support::timestamp($metadata['occurred_at']??null);
        $deadlines=new DeadlineEngine($pdo);$ownership=new OwnershipPeriodEngine($pdo);
        if($action==='order_created'){
            $owner=$ownership->dutyAt('front_desk',$at);
            if($owner)$ownership->assign(['module'=>'Orders','object_reference'=>$reference,'employee_id'=>(int)$owner['employee_id'],'role_key'=>'front_desk_admin','ownership_reason'=>'front_desk_duty','effective_from'=>$at,'assigned_by'=>(int)$owner['assigned_by'],'accepted_by'=>(int)$owner['employee_id'],'accepted_at'=>$owner['accepted_at'],'source'=>'duty:'.$owner['duty_uuid'],'shift_id'=>$owner['shift_id']??null,'exception_id'=>$owner['exception_id']??null]);
            $minutes=self::settingInt($pdo,'epi_v2_order_ack_business_minutes',30);$due=(new BusinessTimeEngine($pdo))->addWorkingMinutes($at,$minutes);
            foreach([['acknowledge_order','order_acknowledgement_sla_breached'],['move_order_out_of_new','order_new_sla_breached']]as$obligation)$deadlines->schedule(['module'=>'Orders','object_reference'=>$reference,'obligation_key'=>$obligation[0],'breach_event_key'=>$obligation[1],'responsible_employee_id'=>$owner['employee_id']??null,'responsible_team'=>'front_desk','ownership_snapshot'=>$owner,'source_event'=>'order_created','starts_at'=>$at,'due_at'=>$due]);
            if(!empty($metadata['due_at']))$deadlines->schedule(['module'=>'Orders','object_reference'=>$reference,'obligation_key'=>'complete_order','breach_event_key'=>'order_completion_sla_breached','responsible_employee_id'=>$owner['employee_id']??null,'responsible_team'=>'front_desk','ownership_snapshot'=>$owner,'source_event'=>'order_created','starts_at'=>$at,'due_at'=>$metadata['due_at']]);
            self::recordLifecycle($pdo,$reference,'NEW',$at,null,$mode,$metadata);
            return;
        }
        $before=strtolower(trim((string)($metadata['old_value']??$metadata['previous_status']??'')));$after=strtolower(trim((string)($metadata['new_value']??$metadata['status']??$order['status']??'')));
        $actor=(int)($metadata['employee_id']??(function_exists('ops_current_employee_id')?ops_current_employee_id():0));
        if(in_array($action,['status_changed','order_completed','bulk_status_updated'],true)||($metadata['field']??'')==='status'){
            if(in_array($before,['','new','new_order','pending'],true)&&!in_array($after,['','new','new_order','pending'],true)){
                $deadlines->fulfilObject('Orders',$reference,'acknowledge_order',$actor,$at);$deadlines->fulfilObject('Orders',$reference,'move_order_out_of_new',$actor,$at);
            }
            if(in_array($after,['complete','completed','packed','verified'],true))$deadlines->fulfilObject('Orders',$reference,'complete_order',$actor,$at);
            self::recordLifecycle($pdo,$reference,self::stage($after),$at,$actor?:null,$mode,$metadata+['previous_status'=>$before]);
        }
    }

    private static function task(PDO$pdo,string$action,int$id,array$metadata):void
    {
        $stmt=$pdo->prepare('SELECT * FROM ops_checklist_tasks WHERE id=? LIMIT 1');$stmt->execute([$id]);$task=$stmt->fetch(PDO::FETCH_ASSOC);if(!$task)return;
        $reference='TASK-'.$id;$at=Support::timestamp($metadata['occurred_at']??null);$employee=(int)($task['assigned_employee_id']??$metadata['assigned_employee_id']??0);$deadlines=new DeadlineEngine($pdo);$ownership=new OwnershipPeriodEngine($pdo);
        if(in_array($action,['task_created','task_scheduled','task_assigned','task_reassigned'],true)){
            $starts=!empty($task['released_at'])?Support::timestamp($task['released_at']):(!empty($task['scheduled_at'])?Support::timestamp($task['scheduled_at']):(!empty($task['date_assigned'])?Support::timestamp($task['date_assigned']):$at));
            $startDue=(new BusinessTimeEngine($pdo))->addWorkingMinutes($starts,self::settingInt($pdo,'epi_v2_task_start_business_minutes',30));
            $deadlines->schedule(['module'=>'Tasks','object_reference'=>$reference,'obligation_key'=>'start_task','breach_event_key'=>'task_start_sla_breached','responsible_employee_id'=>$employee?:null,'responsible_team'=>null,'source_event'=>$action,'starts_at'=>$starts,'due_at'=>$startDue]);
            if(!empty($task['deadline']))$deadlines->schedule(['module'=>'Tasks','object_reference'=>$reference,'obligation_key'=>'complete_task','breach_event_key'=>'task_completion_sla_breached','responsible_employee_id'=>$employee?:null,'source_event'=>$action,'starts_at'=>$starts,'due_at'=>$task['deadline']]);
        }
        if($action==='task_acknowledged'&&$employee>0){$assignedBy=(int)($task['created_by']??$employee);$ownership->assign(['module'=>'Tasks','object_reference'=>$reference,'employee_id'=>$employee,'role_key'=>null,'ownership_reason'=>'task_acknowledged','effective_from'=>$at,'assigned_by'=>$assignedBy?:$employee,'accepted_by'=>$employee,'accepted_at'=>$at,'source'=>'task_acknowledgement']);}
        $actor=(int)($metadata['employee_id']??(function_exists('ops_current_employee_id')?ops_current_employee_id():0));
        if(in_array($action,['task_acknowledged','task_correction_started'],true)||($metadata['status']??'')==='in_progress')$deadlines->fulfilObject('Tasks',$reference,'start_task',$actor?:$employee,$at);
        if(in_array($action,['task_completed','task_correction_completed'],true)||($metadata['status']??'')==='complete')$deadlines->fulfilObject('Tasks',$reference,'complete_task',$actor?:$employee,$at);
    }

    private static function recordLifecycle(PDO$pdo,string$reference,string$stage,\DateTimeImmutable$at,?int$actor,string$mode,array$metadata):void
    {$uuid=Support::uuidFromHash(Support::dedupe(['order-lifecycle',$reference,$stage,$at->format('Y-m-d H:i:s'),$actor]));$stmt=$pdo->prepare('INSERT IGNORE INTO epi_v2_order_lifecycle_events(event_uuid,object_reference,stage_key,employee_id,fulfilment_mode,occurred_at,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,NOW())');$stmt->execute([$uuid,$reference,$stage,$actor,$mode,$at->format('Y-m-d H:i:s'),Support::json($metadata)]);}
    private static function fulfilmentMode(array$order):string{$raw=strtolower(trim((string)($order['fulfilment_mode']??$order['order_type']??'')));$aliases=['delivery'=>'windhoek_delivery','windhoek delivery'=>'windhoek_delivery','walk-in'=>'walk_in','walk in'=>'walk_in'];$raw=$aliases[$raw]??$raw;return in_array($raw,['walk_in','collection','windhoek_delivery','courier','showgrounds','other'],true)?$raw:'other';}
    private static function stage(string$status):string{$map=['new'=>'NEW','new_order'=>'NEW','assigned'=>'ACKNOWLEDGED','in_progress'=>'IN_PROGRESS','packed'=>'PACKED','ready_for_collection'=>'READY_FOR_COLLECTION','ready_for_courier'=>'READY_FOR_COURIER','complete'=>'COMPLETED','completed'=>'COMPLETED','cancelled'=>'CANCELLED','canceled'=>'CANCELLED'];return$map[$status]??strtoupper($status?:'UNKNOWN');}
    private static function enabled(PDO$pdo):bool{try{$stmt=$pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_capture_enabled' LIMIT 1");$stmt->execute();return in_array(strtolower(trim((string)$stmt->fetchColumn())),['1','true','yes','on','enabled'],true);}catch(Throwable$error){return false;}}
    private static function settingInt(PDO$pdo,string$key,int$default):int{try{$stmt=$pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=? LIMIT 1');$stmt->execute([$key]);$value=$stmt->fetchColumn();return$value===false?$default:max(1,(int)$value);}catch(Throwable$error){return$default;}}
    private static function failure(PDO$pdo,Throwable$error,string$type,int$id,string$action):void{error_log('EPI V2 bridge: '.$error->getMessage());try{$stmt=$pdo->prepare('INSERT INTO epi_performance_logs(level,component,message,context_json) VALUES(?,?,?,?)');$stmt->execute(['error','v2_operational_bridge',$error->getMessage(),Support::json(['entity_type'=>$type,'entity_id'=>$id,'action'=>$action])]);}catch(Throwable$ignored){}}
}
