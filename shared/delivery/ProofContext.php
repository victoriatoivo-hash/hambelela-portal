<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAssignments.php';
require_once __DIR__.'/ProofPolicy.php';

final class ProofContext
{
    public static function row(\PDO $db,string $sql,array $params=[]):?array {$s=$db->prepare($sql);$s->execute($params);return $s->fetch(\PDO::FETCH_ASSOC)?:null;}
    public static function read(\PDO $db,int $driver,int $id):array
    {
        EmployeeDriverSession::actor($db,$driver);
        $job=self::row($db,'SELECT * FROM delivery_jobs WHERE id=? AND driver_employee_id=?',[$id,$driver]);
        if(!$job)throw new \DomainException('Delivery unavailable or reassigned. Return to Deliveries.');
        $receipt=self::receipt($db,$id);
        $arrival=self::row($db,"SELECT created_at,metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='arrived' ORDER BY id DESC LIMIT 1",[$id]);
        if($arrival){$m=json_decode($arrival['metadata_json'],true);if((int)($m['driver_id']??0)!==$driver||(int)($m['assignment_revision']??-1)!==self::revision($db,$id))$arrival=null;}
        $assignment=null;$reason=null;
        try{$assignment=DriverAssignments::find(DriverAssignments::read($db,$driver,null,$id),$id);}catch(\DomainException $e){$reason='Delivery is outside your current day or is cancelled. Contact dispatch.';}
        if(!$reason&&($job['status']!=='out_for_delivery'||empty($assignment['collectedAt'])))$reason='Start delivery and have dispatch record parcel collection before completing.';
        if(!$reason&&$assignment['stage']!=='on_route')$reason=$assignment['reviewReason']?:'Delivery requires dispatch review.';
        $operationalReason=$reason;
        $paid=$assignment&&$assignment['paymentVerified'];
        if(!$reason&&!$paid)$reason='Payment or cash reconciliation needs dispatch review before completion.';
        if(DeliveryIssueState::latest($db,$id)['blocking']??false)$reason=$operationalReason='Issue reported. Await dispatch approval before confirming arrival or delivery.';
        if($job['delivered_at']||$receipt)$reason='Delivery handover is already recorded. Do not submit it again.';
        $order=self::row($db,'SELECT order_number FROM ops_orders WHERE id=?',[$job['order_id']]);
        return ['job'=>$job,'reference'=>$order['order_number']??$job['public_reference'],'name'=>$job['customer_name'],
            'version'=>(int)$job['version'],'arrival'=>$arrival['created_at']??null,'payment_verified'=>(bool)$paid,
            'payment_label'=>$assignment['paymentLabel']??'Payment needs review','due'=>$assignment['codAmountDueNad']??null,
            'reason'=>$reason,'operational_reason'=>$operationalReason,'receipt'=>$receipt,'policy'=>ProofPolicy::configured()];
    }
    public static function revision(\PDO $db,int $id):int
    {
        $r=self::row($db,"SELECT MAX(id) id FROM delivery_events WHERE delivery_id=? AND (event_type IN ('arranged','partner_arranged','revised','delivery_issue_reported') OR (event_type='dispatch_updated' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.field'))='driver'))",[$id]);return (int)($r['id']??0);
    }
    public static function receipt(\PDO $db,int $id):?array
    {
        $r=self::row($db,"SELECT metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='delivered' ORDER BY id DESC LIMIT 1",[$id]);
        return $r?(json_decode($r['metadata_json'],true)['proof']??null):null;
    }
    public static function assertLocked(\PDO $db,array $job,array $actor,int $version,bool $arrivalRequired=true):array
    {
        if(!$db->inTransaction())throw new \LogicException('Completion needs locked state.');
        // Lock the source order before re-reading allocations/status. No remote calls in this transaction.
        if($job['order_id']){
            $order=self::row($db,'SELECT * FROM ops_orders WHERE id=? FOR UPDATE',[$job['order_id']]);
            if(!$order||!empty($order['deleted_at'])||!empty($order['archived_at'])||(float)($order['refund_total']??0)>0||in_array(strtolower((string)$order['status']),['cancelled','canceled','refunded','failed','trash','deleted'],true)||in_array(strtolower((string)$order['payment_status']),['refunded','reversed'],true))throw new \DomainException('Source order changed or needs review. Contact dispatch.');
        }
        if((int)$job['version']!==$version)throw new \DomainException('Delivery changed. Reload and check the details before retrying.');
        $ctx=self::read($db,(int)$actor['id'],(int)$job['id']);
        $reason=$arrivalRequired?$ctx['reason']:$ctx['operational_reason'];
        if($reason)throw new \DomainException($reason);
        if($arrivalRequired&&!$ctx['arrival'])throw new \DomainException('Record your arrival at the destination first.');
        return $ctx;
    }
}
