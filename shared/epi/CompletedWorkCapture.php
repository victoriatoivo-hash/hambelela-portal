<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshRuntime.php';

/** Immutable denominator units from real completion transitions, not error counts. */
final class CompletedWorkCapture
{
    /** Called only after CashDeadlineBridge verifies a structured order allocation.
     * Preserve the first observation: correcting an amount cannot erase the
     * original discrepancy, and a reviewer is never substituted for its author.
     */
    public static function cashObservation(PDO $db,array $record,array $meta,array $deadline,int $order,int $expected,int $recorded):void
    {
        if($expected<=0 || $recorded<=0 || empty($record['created_at']))return;
        $at=Support::timestamp($record['created_at'])->format('Y-m-d H:i:s');
        if($at<$deadline['starts_at'])return;
        $author=(int)($record['recorded_by']??0);
        if($author<1)return;
        $record['cash_process']=['measured'=>$expected===$recorded,
            'expected_cents'=>$expected,'recorded_cents'=>$recorded,
            'entry_author_id'=>$author,'allocation_reviewed_by'=>($meta['action']??'')==='historical_allocation'?(int)($meta['employee_id']??0):null,
            'actual'=>$expected===$recorded?'Recorded cash matches the structured receipt allocation':'Cash allocation differs from the receipt; responsibility requires review'];
        self::store($db,'cash_entry','Bookkeeping','ORDER-'.$order,$deadline['starts_at'],$record,
            array_replace($meta,['occurred_at'=>$at,'employee_id'=>$author]),'cash_entry_observed',
            'cash_entry:'.$record['id'].':order:'.$order);
    }

    public static function capture(PDO $db,string $type,string $action,array $record,array $meta):void
    {
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $module=null;$ref=null;$start=null;$done=false;$keyType=$type;
        if($type==='order'){
            $staged=OrdersStageBridge::enabled($db);
            $module='Orders';$ref=$staged?'ORDER-'.$record['id']:((string)($record['order_number']??'')?:'ORDER-'.$record['id']);$start=$record['created_at']??null;
            $done=in_array($record['status']??'',['completed','complete'],true) &&
                (in_array($action,['order_completed','status_changed','bulk_status_updated'],true)||($meta['field']??'')==='status');
            if($staged && in_array($record['status']??'',['in_progress','packed','verified','ready_for_collection','ready_for_delivery','ready_for_courier','complete','completed'],true)){
                $packing=V2Store::one($db,"SELECT starts_at,fulfilled_at,fulfilled_by FROM epi_v2_operational_deadlines
                    WHERE module='Packing' AND object_reference=? AND obligation_key='pack_order' AND state='fulfilled'
                    ORDER BY fulfilled_at DESC LIMIT 1",[$ref]);
                $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
                // Only an actual fulfilled packing stage supplies a denominator.
                // Walk-ins / unpaid couriers have no such eligible packing stage.
                if($packing && $packing['fulfilled_at']===$at && (int)$packing['fulfilled_by']===(int)($meta['employee_id']??0)){
                    // A direct completion can fulfil both stages. Preserve both
                    // owners instead of allowing Front completion to erase packing.
                    self::store($db,'order_packing','Packing',$ref,$packing['starts_at'],$record,$meta,$action);
                }
            }
        }elseif($type==='checklist_task'){
            $module='Tasks';$ref='TASK-'.$record['id'];$start=$record['released_at']??$record['date_assigned']??null;
            $done=in_array($action,['task_completed','task_correction_completed'],true);
        }elseif($type==='ops_whatsapp_conversation'){
            $module='Orders';$ref='CONVERSATION-'.$record['id'];$start=$record['created_at']??null;
            $done=($record['status']??'')==='resolved' && $action==='save_whatsapp_conversation';
        }elseif($type==='packing_task'){
            $module='Packing List';$ref='PACK-'.$record['id'];$start=$record['date_loaded']??null;
            $done=in_array($record['packing_status']??'',['done','website','packed_label_needed','done_needs_label','label_created'],true)
                && (($meta['field']??'')==='packing_status'||in_array($action,['packing_item_completed','packing_status_updated'],true));
        }elseif($type==='courier_waybill'){
            $module='Courier';$ref='WAYBILL-'.$record['id'];$start=$record['uploaded_at']??null;
            $done=$action==='courier_waybill_sent' && !empty($record['sent_at']);
        }
        if(!$done || !$start)return;
        self::store($db,$keyType,$module,$ref,$start,$record,$meta,$action);
    }

    private static function store(PDO $db,string $keyType,string $module,string $ref,string $start,array $record,array $meta,string $action,?string $opportunityKey=null):void
    {
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $activation=V2Store::activation($db);
        if(!$activation || $start<$activation['enforcement_start_at'] || $start>$at)return;
        $owner=(new OwnershipPeriodEngine($db))->ownerAt($module,$ref,$at);
        $process=null;
        if($keyType==='checklist_task'){
            require_once __DIR__.'/TaskProcessEvidence.php';
            $process=TaskProcessEvidence::evaluate($record,$meta);
        }
        $packingProcess=null;
        if($keyType==='packing_task'){
            require_once __DIR__.'/PackingProcessEvidence.php';
            $packingProcess=PackingProcessEvidence::evaluate($record);
        }
        $key=$opportunityKey??($keyType.':'.$record['id'].':'.(int)($record['correction_round_count']??0));
        $db->prepare('INSERT IGNORE INTO epi_v2_completed_work_units(opportunity_key,module,object_reference,
            employee_id,fulfiller_id,starts_at,completed_at,ownership_json,source_snapshot_json) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$key,$module,$ref,$owner['employee_id']??null,$meta['employee_id']??null,$start,$at,
                Support::json($owner),Support::json(['record'=>$record,'action'=>$action,'metadata'=>$meta,'process'=>$process,'packing_process'=>$packingProcess,
                    'score_eligible_at_capture'=>$owner && PerformanceCapturePolicy::approved($db,(int)$owner['employee_id'],$start)])]);
    }
}
