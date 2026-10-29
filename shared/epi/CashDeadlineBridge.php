<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshRuntime.php';
require_once __DIR__.'/ProvisionalTimingPolicy.php';

/** Cash receipt obligations use structured allocations, never names or description matches. */
final class CashDeadlineBridge
{
    public static function capture(PDO $db,string $type,string $action,array $record,array $meta):void
    {
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $policy=ProvisionalTimingPolicy::policy($db,$at);
        $minutes=(int)($policy['cash_entry_minutes']??0);
        $activation=V2Store::activation($db);
        if(!$activation || $at<$activation['enforcement_start_at'])return;
        if($type==='order' && in_array($action,['order_created','payment_changed','payment_status_updated','payment_status_auto_walk_in'],true)){
            if($minutes<1)return;
            // A method edit, import or automatic walk-in tick is not evidence of receipt.
            // New timing uses the explicit staff Paid confirmation plus real cash allocation.
            if(!empty($policy['_timing_version'])&&empty($meta['cash_received_at']))return;
            if(!in_array($record['payment_status']??'',['paid','partial'],true))return;
            // Values were captured into the durable outbox with the order mutation.
            $cash=(int)($meta['cash_receipt_cents']??0);
            if($cash<=0)return;
            // A verified new receipt is a new obligation even for an older order.
            // Do not mistake the order's database TIMESTAMP for receipt time.
            if(empty($policy['_timing_version'])&&(empty($record['created_at'])||$record['created_at']<$activation['enforcement_start_at']))return;
            $receiptAt=!empty($policy['_timing_version'])?Support::timestamp($meta['cash_received_at'])->format('Y-m-d H:i:s'):$at;
            if($receiptAt>$at||(!empty($policy['_timing_version'])&&$receiptAt<$policy['_timing_version']['effective_from']))return;
            $ref='ORDER-'.$record['id'];
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Bookkeeping',?,'front_desk')")->execute([$ref]);
            // One received-cash obligation: later edits do not restart its clock.
            if(V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND object_reference=? AND obligation_key='enter_cash_transaction'",[$ref]))return;
            (new DeadlineEngine($db))->schedule(['module'=>'Bookkeeping','object_reference'=>$ref,
                'obligation_key'=>'enter_cash_transaction','breach_event_key'=>'cash_entry_missing',
                'starts_at'=>$receiptAt,'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($receiptAt,$minutes),
                'cycle_id'=>'initial','source_event'=>$action,'responsible_team'=>'front_desk',
                'source_evidence'=>['expected_cash_cents'=>$cash,'cash_received_at'=>$receiptAt,'receipt_source'=>$meta['cash_receipt_source']??'legacy_fixture']]+ProvisionalTimingPolicy::deadlineOptions($policy,'cash_entry_minutes'));
        }elseif($type==='cash_entry' && in_array($action,['created','edited','historical_allocation'],true)){
            if(($record['status']??'active')!=='active' || !empty($record['deleted_at']) || (float)($record['cash_in']??0)<=0)return;
            $order=(int)($meta['confirmed_order_id']??$record['related_order_id']??0);
            if($order<1)return; // Candidate description matches must not clear an obligation.
            $actor=(int)($meta['employee_id']??0);
            if(!empty($policy['_timing_version'])){
                // Native created_at is TIMESTAMP and the live PDO session is UTC.
                // Use the original durable Namibia event, not the later review or
                // edit time, and do not rewrite an already captured work unit.
                $origin=V2Store::one($db,"SELECT payload_json FROM epi_v2_outbox WHERE entity_type='cash_entry' AND entity_id=? AND action='created' ORDER BY id LIMIT 1",[(int)$record['id']]);
                $original=json_decode($origin['payload_json']??'{}',true)?:[];
                $recordedAt=$original['metadata']['occurred_at']??($action==='created'?$at:null);
                if(!$recordedAt)return; // Missing original timing is not guessed.
                $record['created_at']=Support::timestamp($recordedAt)->format('Y-m-d H:i:s');
                if($action==='historical_allocation'&&!empty($original['record']['recorded_by']))$record['recorded_by']=$original['record']['recorded_by'];
            }
            if($action==='historical_allocation'){
                // Reviewer is not the fulfiller. Require the native entry's identity/time.
                if((int)($meta['employee_id']??0)!==(int)$activation['approved_by'])return;
                $actor=(int)($record['recorded_by']??0);
                if(empty($record['created_at']))return;
                $at=Support::timestamp($record['created_at'])->format('Y-m-d H:i:s');
            }
            if($actor<1)return;
            $deadline=V2Store::one($db,"SELECT policy_snapshot_json,starts_at,due_at,breach_incident_uuid FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND object_reference=? AND obligation_key='enter_cash_transaction'",['ORDER-'.$order]);
            if(!$deadline)return;
            $snapshot=json_decode($deadline['policy_snapshot_json'],true);
            $expected=(int)($snapshot['source_evidence']['expected_cash_cents']??0);
            $recorded=isset($meta['confirmed_cash_cents'])?(int)$meta['confirmed_cash_cents']:(int)round((float)$record['cash_in']*100);
            require_once __DIR__.'/CompletedWorkCapture.php';
            CompletedWorkCapture::cashObservation($db,$record,array_replace($meta,['action'=>$action]),$deadline,$order,$expected,$recorded);
            if($expected<=0 || $recorded!==$expected)return; // Partial/mismatched money needs reconciliation, not a false completion.
            if($at<$deadline['starts_at'])return;
            if($action==='historical_allocation')V2Store::audit($db,'cash-proof|'.$record['id'],(int)$meta['employee_id'],
                'Owner verified native cash entry and exact order allocation',$deadline,
                ['record'=>$record,'confirmed_order_id'=>$order,'confirmed_cash_cents'=>$recorded,'actual_fulfiller'=>$actor]);
            (new DeadlineEngine($db))->fulfilObject('Bookkeeping','ORDER-'.$order,'enter_cash_transaction',$actor,$at);
        }
    }
}
