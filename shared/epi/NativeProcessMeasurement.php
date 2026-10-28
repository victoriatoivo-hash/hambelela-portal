<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/OperationalMeasurementPolicy.php';

/** Native, immutable process observations. No historical replay or invented proof. */
final class NativeProcessMeasurement
{
    /** Capture queue facts with the mutation, never reconstruct yesterday's queue
     * from today's rows during an outbox retry. */
    public static function metadata(PDO $db,string $type,array $r,array $meta):array
    {
        $at=$meta['occurred_at'];$policy=OperationalMeasurementPolicy::current($db,$at);
        if(!$policy)return $meta;
        if($type==='courier_waybill'){
            $q=$db->prepare('SELECT l.order_id,l.linked_at,o.order_number,o.customer_name FROM hambelela_waybill_orders l JOIN ops_orders o ON o.id=l.order_id WHERE l.waybill_id=? AND l.linked_at<=?');
            $q->execute([(int)$r['id'],$at]);$orders=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $checks=['document'=>trim((string)($r['file_path']??''))!=='','courier'=>trim((string)(($r['courier_name']??'')?:($r['courier_names']??'')))!=='',
                'linked_order'=>(bool)$orders,'parcel_count'=>(int)($r['number_of_waybills']??0)>0,
                'customer_reference'=>(bool)$orders&&!array_filter($orders,static function($o){return trim((string)$o['customer_name'])==='';})];
            $meta['dispatch_source_at_capture']=['at'=>$at,'checks'=>$checks,'linked_orders'=>$orders,'complete'=>!in_array(false,$checks,true)];return $meta;
        }
        if($type!=='packing_task'||($meta['field']??'')!=='packing_status')return $meta;
        $q=$db->prepare("SELECT id,priority,packing_status,assigned_employee_id FROM ops_packing_tasks WHERE assigned_employee_id=? AND id<>? AND deleted_at IS NULL AND packing_status IN('not_started','new','new_order','pending')");
        $q->execute([(int)($r['assigned_employee_id']??0),(int)$r['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $owners=new OwnershipPeriodEngine($db);$peers=[];
        foreach($rows as $peer){$owner=$owners->ownerAt('Packing List','PACK-'.$peer['id'],$at);
            if($owner&&(int)$owner['employee_id']===(int)($r['assigned_employee_id']??0))$peers[]=$peer+['ownership'=>$owner];}
        $meta['priority_queue_at_capture']=['at'=>$at,'peers'=>$peers];return $meta;
    }
    public static function capture(PDO $db,string $type,string $action,array $r,array $meta):void
    {
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $policy=OperationalMeasurementPolicy::current($db,$at);
        if(!$policy||!PerformanceRefreshRuntime::enabled($db))return;
        $actor=(int)($meta['actor_employee_id']??$meta['employee_id']??0);
        if($actor<1)return;
        if($type==='packing_task'){
            $ref='PACK-'.(int)$r['id'];
            $release=V2Store::one($db,"SELECT starts_at FROM epi_v2_operational_deadlines WHERE module='Packing List' AND object_reference=? AND obligation_key='start_packing' AND historical_backfill=0 ORDER BY id LIMIT 1",[$ref]);
            if(!$release||$release['starts_at']<$policy['effective_from'])return;
            $status=(string)($r['packing_status']??'');
            $transition=($meta['field']??'')==='packing_status'||in_array($action,['packing_item_started','packing_item_completed','packing_status_updated'],true);
            if(!$transition)return;
            $done=in_array($status,['done','website','packed_label_needed','done_needs_label','label_created'],true);
            if($done||in_array($status,['packing','in_progress'],true)){
                $rank=$policy['priority_rank'][(string)($r['priority']??'')]??null;
                $peers=[];
                $queue=$meta['priority_queue_at_capture']??null;
                if($rank!==null&&$queue&&$queue['at']===$at){
                    foreach($queue['peers'] as $peer){
                        $pRank=$policy['priority_rank'][$peer['priority']]??null;
                        $owner=$peer['ownership'];
                        if($pRank!==null&&$pRank<$rank&&$owner&&(int)$owner['employee_id']===(int)($r['assigned_employee_id']??0))$peers[]=$peer;
                    }
                }
                self::store($db,'packing_priority:'.$r['id'],'Packing List',$ref,$release['starts_at'],$at,$actor,$policy,
                    ['category'=>'packing_list','metric'=>'priority','measured'=>$rank!==null&&$queue!==null&&!$peers,'outcome'=>'success',
                     'expected'=>'Handle the highest-priority available assigned Packing List work first',
                     'actual'=>!$queue?'A durable priority queue snapshot is missing':($rank===null?'Priority is not classified':($peers?'Higher-priority assigned work existed; availability/preemption requires review':'No higher-priority open personally assigned work existed at the recorded start')),
                     'review_required'=>(bool)$peers,'higher_priority_work'=>$peers,'record'=>$r]);
            }
            if($done){
                $checks=['quantity_recorded'=>trim((string)($r['quantity_packed']??''))!=='',
                    'packer_website_confirmation'=>(int)($r['packing_website_confirmed']??0)===1,
                    'label_ready'=>!in_array($status,['packed_label_needed','done_needs_label'],true)];
                $ok=!in_array(false,$checks,true);
                self::store($db,'packing_compliance:'.$r['id'].':'.(int)($r['correction_round_count']??0),'Packing List',$ref,$release['starts_at'],$at,$actor,$policy,
                    ['category'=>'packing_list','metric'=>'compliance','measured'=>true,'outcome'=>$ok?'success':'failure',
                     'expected'=>'Record packed quantity, complete the packer stock/website confirmation and finish required labelling before declaring work complete',
                     'actual'=>$ok?'All required native process checks were recorded at completion':'Missing completion checks: '.implode(', ',array_keys(array_filter($checks,static function($v){return !$v;}))),
                     'checks'=>$checks,'record'=>$r]);
            }
        }elseif($type==='courier_waybill'&&$action==='courier_waybill_sent'){
            if(empty($r['uploaded_at'])||$r['uploaded_at']<$policy['effective_from'])return;
            $source=$meta['dispatch_source_at_capture']??[];$orders=$source['linked_orders']??[];$checks=$source['checks']??[];
            $ok=($source['complete']??false)===true;
            self::store($db,'dispatch_compliance:'.$r['id'],'Courier','WAYBILL-'.$r['id'],$r['uploaded_at'],$at,$actor,$policy,
                ['category'=>'courier','metric'=>'compliance','measured'=>$ok,'outcome'=>$ok?'success':'failure',
                 'expected'=>'Send a recorded courier document with courier, parcel count and linked customer/order references',
                 'actual'=>$ok?'Required dispatch fields and order/customer links were present at sending':'Dispatch completeness/availability requires review; upstream missing information is not automatically a Front Desk failure. Missing: '.implode(', ',array_keys(array_filter($checks,static function($v){return !$v;}))),
                 'checks'=>$checks,'linked_orders'=>$orders,'record'=>$r]);
        }
    }
    private static function store(PDO $db,string $key,string $module,string $ref,string $start,string $at,int $actor,array $policy,array $observation):void
    {
        $owner=(new OwnershipPeriodEngine($db))->ownerAt($module,$ref,$at);
        if(($observation['outcome']??'')==='failure'&&(!$owner||(int)$owner['employee_id']!==$actor)){
            $observation['measured']=false;$observation['review_required']=true;
            $observation['actual'].=' The completing actor differs from the recorded responsible employee; fault requires review.';
        }
        $mode=$owner?PerformanceCapturePolicy::captureMode($db,(int)$owner['employee_id'],$start):0;
        $db->prepare('INSERT IGNORE INTO epi_v2_completed_work_units(opportunity_key,module,object_reference,employee_id,fulfiller_id,starts_at,completed_at,ownership_json,source_snapshot_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
            $key,$module,$ref,$owner['employee_id']??null,$actor,$start,$at,Support::json($owner),
            Support::json(['capture_mode'=>$mode,'score_eligible_at_capture'=>false,'native_measurement'=>$observation,'measurement_policy'=>$policy])]);
    }
}
