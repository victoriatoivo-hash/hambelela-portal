<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/OrderBalanceAdapter.php';
require_once __DIR__.'/NotificationOutbox.php';

/** Operational Front Desk follow-up only. Never scores the independent driver or changes payments. */
final class DeliveryCodFollowup
{
    public static function check(\PDO $db,int $id):void
    {
        if($db->inTransaction())throw new \LogicException('Follow-up scan owns transaction.');
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM delivery_jobs WHERE id=? FOR UPDATE');$s->execute([$id]);$j=$s->fetch(\PDO::FETCH_ASSOC);
            if(!$j||$j['source']!=='hambelela'){$db->commit();return;}
            $s=$db->prepare("SELECT * FROM delivery_events WHERE delivery_id=? AND event_type IN ('parcel_collected','started','cod_followup_overdue','cod_followup_resolved') ORDER BY id");$s->execute([$id]);$events=$s->fetchAll(\PDO::FETCH_ASSOC);$breaches=[];$resolved=[];
            foreach($events as $e){$m=json_decode($e['metadata_json'],true)?:[];if($e['event_type']==='cod_followup_overdue')$breaches[(int)($m['dispatch_event_id']??0)]=true;if($e['event_type']==='cod_followup_resolved')$resolved[(int)($m['dispatch_event_id']??0)]=true;}
            $q=$db->prepare("SELECT COUNT(*) FROM delivery_receipts r WHERE r.delivery_id=? AND r.status IN ('collected','issue') AND (r.status='issue' OR LOWER(r.payment_method)<>'cash' OR NOT EXISTS(SELECT 1 FROM delivery_events h WHERE h.delivery_id=r.delivery_id AND h.event_type='cash_handover' AND JSON_EXTRACT(h.metadata_json,'$.receipt_id')=r.id))");$q->execute([$id]);$unverified=(int)$q->fetchColumn();
            $money=(new OrderBalanceAdapter())->read($db,$j);
            $q=$db->prepare("SELECT COALESCE(SUM(order_goods_cents+delivery_fee_cents+partner_cod_cents),0) FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')");$q->execute([$id]);$stillCollectible=array_sum($money)-(int)$q->fetchColumn();
            $clear=$j['status']==='cancelled'||($stillCollectible===0&&$unverified===0);
            foreach($events as $e){
                if(!in_array($e['event_type'],['parcel_collected','started'],true))continue;$m=json_decode($e['metadata_json'],true)?:[];$due=$m['front_cod_due_utc']??null;$event=(int)$e['id'];
                if(!$due||isset($resolved[$event]))continue;
                $coordinator=(int)($m['front_coordinator_id']??$m['recorded_by']??0);
                if(!$coordinator){error_log('COD follow-up missing Front coordinator for delivery '.$id);continue;}
                if($clear&&isset($breaches[$event])){$db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'cod_followup_resolved',?,?)")->execute([$id,$coordinator,json_encode(['system_generated'=>true,'dispatch_event_id'=>$event,'reason'=>$j['status']==='cancelled'?'Delivery cancelled; original breach retained':'Customer funds verified or cash handover recorded; original breach retained'],JSON_THROW_ON_ERROR)]);continue;}
                if($clear||isset($breaches[$event])||$due>=gmdate('Y-m-d H:i:s'))continue;
                $coordinator=(int)($m['front_coordinator_id']??$m['recorded_by']??0);
                $db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'cod_followup_overdue',?,?)")->execute([$id,$coordinator,json_encode(['system_generated'=>true,'dispatch_event_id'=>$event,'due_at'=>$due,'front_coordinator_id'=>$coordinator?:null,'responsibility_basis'=>$coordinator?'Recorded Front Desk dispatch/handover':'Not established','excluded_from_automatic_scoring'=>true,'reason'=>'COD coordination exceeded eight business working hours; review current responsibility before any employee scoring'],JSON_THROW_ON_ERROR)]);
                NotificationOutbox::notifyFront($db,$id,'cod-followup:'.$id.':'.$event,'cod_followup_overdue');
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
