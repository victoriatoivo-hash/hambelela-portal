<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshRuntime.php';
require_once __DIR__.'/ProvisionalTimingPolicy.php';

/** Native Packing List, website and courier stages. Durations require explicit policy. */
final class ModuleDeadlineBridge
{
    public static function capture(PDO $db,string $type,string $action,array $r,array $meta):void
    {
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $activation=V2Store::activation($db);
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $policy=ProvisionalTimingPolicy::policy($db,$at);
        $actor=(int)($meta['employee_id']??0);$engine=new DeadlineEngine($db);
        if($type==='packing_task'){
            $created=$action==='packing_item_created';
            $start=(string)($r['created_at']??$r['date_loaded']??'');
            // Native created_at is a database TIMESTAMP and may be returned in UTC.
            // A new release uses the durable event's explicit Namibia timestamp.
            if($created&&!empty($policy['_timing_version']))$start=$at;
            if(!$start || $start<$activation['enforcement_start_at'])return;
            $ref='PACK-'.$r['id'];$ownerEngine=new OwnershipPeriodEngine($db);
            $employee=(int)($r['assigned_employee_id']??0);
            $owner=$ownerEngine->ownerAt('Packing List',$ref,$at);
            $assignment=($meta['field']??'')==='assigned_employee_id'||$created;
            $directed=$actor===(int)$activation['approved_by'] && ($policy['task_assignment_policy']??'')==='owner_directed';
            if($assignment && $employee>0 && (!$owner || (int)$owner['employee_id']!==$employee) && ($actor===$employee || $directed))
                $ownerEngine->assign(['module'=>'Packing List','object_reference'=>$ref,'employee_id'=>$employee,
                    'assigned_by'=>$actor,'accepted_by'=>$actor,'authority'=>$directed?'owner_directed':'employee_acceptance',
                    'effective_from'=>$at,'accepted_at'=>$at,'source'=>'packing_list_assignment']);
            foreach(['start_packing'=>'packing_list_start_minutes','complete_packing'=>'packing_list_completion_minutes'] as $obligation=>$setting){
                $minutes=(int)($policy[$setting]??0);
                if($created && $minutes>0 && (empty($policy['_timing_version'])||$start>=$policy['_timing_version']['effective_from']))$engine->schedule(['module'=>'Packing List','object_reference'=>$ref,
                    'obligation_key'=>$obligation,'breach_event_key'=>$obligation.'_sla_breached','starts_at'=>$start,
                    'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($start,$minutes),
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'packers']+ProvisionalTimingPolicy::deadlineOptions($policy,$setting));
            }
            $websiteMinutes=(int)($policy['website_update_minutes']??0);
            $newTiming=!empty($policy['_timing_version']);
            $informationAvailable=trim((string)($r['item_name']??''))!=='' && trim((string)($r['quantity_planned']??''))!=='';
            $releasedAt=$created?$at:null;
            if($newTiming&&!$created&&$informationAvailable&&in_array($meta['field']??'',['item_name','quantity_planned'],true)){
                $release=V2Store::one($db,"SELECT payload_json FROM epi_v2_outbox WHERE entity_type='packing_task' AND entity_id=? AND action='packing_item_created' ORDER BY id LIMIT 1",[(int)$r['id']]);
                $releasedAt=json_decode($release['payload_json']??'{}',true)['metadata']['occurred_at']??null;
            }
            $websiteReady=$newTiming?($informationAvailable&&$releasedAt&&ProvisionalTimingPolicy::current($db,$releasedAt)&&($created||in_array($meta['field']??'',['item_name','quantity_planned'],true))):$created;
            if($websiteReady && $websiteMinutes>0 && !V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Inventory' AND object_reference=? AND obligation_key='update_website_stock'",[$ref])){
                $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Inventory',?,'front_desk')")->execute([$ref]);
                $engine->schedule(['module'=>'Inventory','object_reference'=>$ref,'obligation_key'=>'update_website_stock',
                    'breach_event_key'=>'website_stock_update_breached','starts_at'=>$newTiming?$at:$start,
                    'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($newTiming?$at:$start,$websiteMinutes),
                    'source_evidence'=>['stock_information_available_at'=>$newTiming?$at:$start,'item_name'=>$r['item_name']??null,'quantity_planned'=>$r['quantity_planned']??null],
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'front_desk']+ProvisionalTimingPolicy::deadlineOptions($policy,'website_update_minutes'));
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
            // The Courier page already stores the dispatch obligation's exact due_by.
            // Reuse that authoritative deadline when no separate EPI duration exists.
            // Never move an expired upstream deadline onto the receiving employee.
            $due=self::courierDue($db,$r,$policy);
            if($start && $start>=$activation['enforcement_start_at'] && $due && $action==='courier_waybill_uploaded'){
                $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Courier',?,'front_desk')")->execute([$ref]);
                $engine->schedule(['module'=>'Courier','object_reference'=>$ref,'obligation_key'=>'send_courier_documents',
                    'breach_event_key'=>'courier_send_sla_breached','starts_at'=>$start,
                    'due_at'=>$due,
                    'source_evidence'=>['deadline_source'=>$minutes>0?'versioned_epi_policy':'hambelela_waybills.due_by',
                        'uploaded_at'=>$start,'stored_due_by'=>$r['due_by']??null,'batch_id'=>$r['batch_id']??null],
                    'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'front_desk']);
            }
            if($actor>0 && $action==='courier_waybill_sent')$engine->fulfilObject('Courier',$ref,'send_courier_documents',$actor,$at);
        }
    }

    public static function courierDue(PDO $db,array $record,array $policy):?string
    {
        $minutes=(int)($policy['courier_front_send_minutes']??0);
        return $minutes>0&&!empty($record['uploaded_at'])
            ?(new BusinessTimeEngine($db))->addWorkingMinutes($record['uploaded_at'],$minutes)->format('Y-m-d H:i:s')
            :self::nativeCourierDue($record);
    }

    public static function nativeCourierDue(array $record):?string
    {
        $start=(string)($record['uploaded_at']??'');$due=(string)($record['due_by']??'');
        foreach([$start,$due] as $value){
            if(!preg_match('/^20\d{2}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return null;
            try{if(Support::timestamp($value)->format('Y-m-d H:i:s')!==$value)return null;}catch(\Throwable $e){return null;}
        }
        return $due>$start?$due:null;
    }
}
