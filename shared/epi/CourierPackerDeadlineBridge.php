<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Packer-controlled documents, separate from Front Desk sending. No backfill.
 * Uses the already-approved weekday 13:30 dispatch policy, not a guessed SLA. */
final class CourierPackerDeadlineBridge
{
    private const FLAG='epi_v2_courier_upload_from';

    public static function enable(PDO $db,int $owner,string $reason,$now=null):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Approving owner and reason required.');
        return V2Store::transaction($db,function()use($db,$owner,$reason,$now){
            V2Store::lock($db,'courier-upload-activation');
            foreach(['hambelela_waybill_orders'=>['waybill_id','order_id','linked_at','linked_by_employee_id'],
                'hambelela_waybills'=>['id','uploaded_at','uploaded_by','file_path','deleted_at','archived_at']] as $table=>$required){
                $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
                if(array_diff($required,$columns))throw new RuntimeException('Structured courier document source is incomplete: '.$table);
            }
            $db->prepare("INSERT IGNORE INTO epi_v2_event_registry(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,severity_handling,sla_obligation_key,category_key,applicable_roles_json) VALUES('courier_upload_sla_breached','Packing','Required linked courier documents were unavailable at the eligible dispatch cutoff.','owner_at_due','negative',1,0,'capped','upload_waybill','courier','[\"packer\",\"packer_production_staff\"]')")->execute();
            $event=(new CanonicalEventRegistry($db))->event('courier_upload_sla_breached');
            if($event['module']!=='Packing'||$event['sla_obligation_key']!=='upload_waybill'||!(int)$event['score_eligible'])throw new RuntimeException('Existing courier-upload event contract differs; review before activation.');
            $old=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[self::FLAG]);
            if($old)return ['effective_from'=>$old['setting_value'],'already_active'=>true];
            $from=Support::timestamp($now)->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?)')->execute([self::FLAG,$from]);
            V2Store::audit($db,'courier-upload-activation',$owner,$reason,null,['effective_from'=>$from,'dispatch_days'=>[1,2,3,4,5],'cutoff'=>'13:30','provisional'=>true,'financial_use_allowed'=>false]);
            return ['effective_from'=>$from,'already_active'=>false];
        });
    }

    public static function capture(PDO $db,string $type,string $action,array $record,array $meta):void
    {
        if(!in_array($type,['order','courier_waybill'],true))return;
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $flag=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[self::FLAG]);
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        if(!$flag||$at<$flag['setting_value'])return;
        $engine=new DeadlineEngine($db);
        if($type==='order'){
            $id=(int)$record['id'];$tracking=V2Store::one($db,'SELECT * FROM epi_v2_orders_tracking WHERE order_id=?',[$id]);
            if(!$tracking||$tracking['classification']!=='courier'||empty($tracking['paid_at'])||$tracking['removed_at'])return;
            $ref=$tracking['object_reference'];$ready=$tracking['progressed_at']??null;
            // Only the actual new packing-ready event starts this obligation.
            // Editing an old order must not create a fresh retroactive deadline.
            if($ready===$at&&$ready>=$flag['setting_value']&&$tracking['dispatch_at']){
                $version=V2Store::one($db,'SELECT * FROM epi_v2_orders_policy WHERE version=?',[$tracking['policy_version']]);
                if(!$version)throw new RuntimeException('Original courier dispatch policy is unavailable.');
                $policy=json_decode($version['policy_json'],true,512,JSON_THROW_ON_ERROR);
                $due=self::due($ready,$tracking['dispatch_at'],$policy);
                $engine->schedule(['module'=>'Packing','object_reference'=>$ref,'obligation_key'=>'upload_waybill',
                    'breach_event_key'=>'courier_upload_sla_breached','starts_at'=>$ready,'due_at'=>$due,
                    'cycle_id'=>'dispatch:'.$due,'source_event'=>'courier_packing_ready','responsible_team'=>'packers',
                    'source_evidence'=>['order_id'=>$id,'packing_ready_at'=>$ready,'eligible_dispatch_at'=>$due,'upstream_dispatch_at'=>$tracking['dispatch_at']],
                    'stage_policy'=>['version'=>$version['version'],'approved_by'=>$version['approved_by'],'rules'=>$policy]]);
                self::fulfilLinked($db,$engine,$id,$at);
            }
            if($action==='courier_order_upload_linked')self::fulfilLinked($db,$engine,$id,$at);
        }elseif($type==='courier_waybill'&&in_array($action,['courier_waybill_uploaded','courier_waybill_orders_assigned'],true)){
            if(!self::hasLinks($db))return;
            $q=$db->prepare('SELECT order_id FROM hambelela_waybill_orders WHERE waybill_id=? ORDER BY order_id');
            $q->execute([(int)$record['id']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
            foreach($ids as $id)self::fulfilLinked($db,$engine,(int)$id,$at);
        }
    }

    public static function due(string $ready,string $original,array $policy):string
    {
        if($original>$ready)return $original;
        // Upstream packing lateness is not inherited as an already-expired upload
        // deadline. Saturday/Sunday have no collection; use the next eligible cutoff.
        return OrdersSlaPolicy::dispatch($ready,Support::timestamp($ready)->modify('+1 second'),$policy)['dispatch_at'];
    }
    private static function hasLinks(PDO $db):bool
    {
        return (int)V2Store::one($db,"SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hambelela_waybill_orders'")['n']===1;
    }
    private static function fulfilLinked(PDO $db,DeadlineEngine $engine,int $order,string $at):void
    {
        if(!self::hasLinks($db))return;
        $ref='ORDER-'.$order;
        $d=V2Store::one($db,"SELECT * FROM epi_v2_operational_deadlines WHERE module='Packing' AND object_reference=? AND obligation_key='upload_waybill' AND state IN('open','breached','needs_attribution') ORDER BY id LIMIT 1",[$ref]);
        if(!$d)return;
        $q=$db->prepare('SELECT w.id,w.uploaded_at,w.uploaded_by,l.linked_at,l.linked_by_employee_id FROM hambelela_waybill_orders l JOIN hambelela_waybills w ON w.id=l.waybill_id WHERE l.order_id=? AND w.deleted_at IS NULL AND w.archived_at IS NULL AND LENGTH(TRIM(w.file_path))>0 ORDER BY GREATEST(w.uploaded_at,l.linked_at),w.id');
        $q->execute([$order]);$proofs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($proofs as $proof){
            if(empty($proof['uploaded_at'])||empty($proof['linked_at']))continue;
            $available=max($proof['uploaded_at'],$proof['linked_at']);if($available>$at)continue;
            $actor=(int)($proof['linked_at']>=$proof['uploaded_at']?$proof['linked_by_employee_id']:$proof['uploaded_by']);
            if($actor<1)continue;
            $fulfilled=max($d['starts_at'],$available);
            if($engine->fulfil($d['deadline_uuid'],$actor,$fulfilled))V2Store::audit($db,'courier-documents|'.$d['deadline_uuid'],$actor,
                'Structured order/waybill link proves document availability; breach ownership remains separate',null,
                ['waybill_id'=>(int)$proof['id'],'uploaded_at'=>$proof['uploaded_at'],'linked_at'=>$proof['linked_at'],'available_at'=>$available,'fulfilled_at'=>$fulfilled]);
            return;
        }
    }
}
