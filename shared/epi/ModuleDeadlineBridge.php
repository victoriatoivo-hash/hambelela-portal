<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshRuntime.php';

/** Native Packing List, website and courier stages. Durations require explicit policy. */
final class ModuleDeadlineBridge
{
    public static function capture(PDO $db,string $type,string $action,array $r,array $meta):void
    {
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $activation=V2Store::activation($db);$policy=V2Store::policy($db);
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $actor=(int)($meta['employee_id']??0);$engine=new DeadlineEngine($db);
        if($type==='packing_task'){
            $start=(string)($r['created_at']??$r['date_loaded']??'');
            if(!$start || $start<$activation['enforcement_start_at'])return;
            $ref='PACK-'.$r['id'];$ownerEngine=new OwnershipPeriodEngine($db);
            $employee=(int)($r['assigned_employee_id']??0);
            $owner=$ownerEngine->ownerAt('Packing List',$ref,$at);
            $created=$action==='packing_item_created';
            $assignment=($meta['field']??'')==='assigned_employee_id'||$created;
            $directed=$actor===(int)$activation['approved_by'] && ($policy['task_assignment_policy']??'')==='owner_directed';
            if($assignment && $employee>0 && (!$owner || (int)$owner['employee_id']!==$employee) && ($actor===$employee || $directed))
                $ownerEngine->assign(['module'=>'Packing List','object_reference'=>$ref,'employee_id'=>$employee,
                    'assigned_by'=>$actor,'accepted_by'=>$actor,'authority'=>$directed?'owner_directed':'employee_acceptance',
                    'effective_from'=>$at,'accepted_at'=>$at,'source'=>'packing_list_assignment']);
            foreach(['start_packing'=>'packing_list_start_minutes','complete_packing'=>'packing_list_completion_minutes'] as $obligation=>$setting){
                $minutes=(int)($policy[$setting]??0);
                if($created && $minutes>0)$engine->schedule(['module'=>'Packing List','object_reference'=>$ref,
                    'obligation_key'=>$obligation,'breach_event_key'=>$obligation.'_sla_breached','starts_at'=>$start,
                    'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($start,$minutes),
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'packers']);
            }
            $websiteMinutes=(int)($policy['website_update_minutes']??0);
            if($created && $websiteMinutes>0){
                $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Inventory',?,'front_desk')")->execute([$ref]);
                $engine->schedule(['module'=>'Inventory','object_reference'=>$ref,'obligation_key'=>'update_website_stock',
                    'breach_event_key'=>'website_stock_update_breached','starts_at'=>$start,
                    'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($start,$websiteMinutes),
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'front_desk']);
            }
            $status=$r['packing_status']??'';
            if($actor>0 && in_array($status,['packing','in_progress','done','website','packed_label_needed','done_needs_label','label_created'],true))
                $engine->fulfilObject('Packing List',$ref,'start_packing',$actor,$at);
            if($actor>0 && in_array($status,['done','website','packed_label_needed','done_needs_label','label_created'],true))
                $engine->fulfilObject('Packing List',$ref,'complete_packing',$actor,$at);
            if($actor>0 && $action==='frontdesk_website_update_confirmed')
                $engine->fulfilObject('Inventory',$ref,'update_website_stock',$actor,$at);
        }elseif($type==='courier_waybill'){
            $start=(string)($r['uploaded_at']??'');$minutes=(int)($policy['courier_front_send_minutes']??0);
            $ref='WAYBILL-'.$r['id'];
            if($start && $start>=$activation['enforcement_start_at'] && $minutes>0 && $action==='courier_waybill_uploaded'){
                $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Courier',?,'front_desk')")->execute([$ref]);
                $engine->schedule(['module'=>'Courier','object_reference'=>$ref,'obligation_key'=>'send_courier_documents',
                    'breach_event_key'=>'courier_send_sla_breached','starts_at'=>$start,
                    'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($start,$minutes),
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'front_desk']);
            }
            if($actor>0 && $action==='courier_waybill_sent')$engine->fulfilObject('Courier',$ref,'send_courier_documents',$actor,$at);
        }
    }
}
