<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

final class DeliveryArrangement
{
    /** resolveOrder must lock the Order and return verified customer/address/rate/balance fields. */
    private \PDO $db;
    private \Closure $resolveOrder;
    public function __construct(\PDO $db,\Closure $resolveOrder){$this->db=$db;$this->resolveOrder=$resolveOrder;}
    public static function requestHash(int $orderId,int $driverId,string $date,array $details):string
    {
        return hash('sha256',json_encode([$orderId,$driverId,$date,$details],JSON_THROW_ON_ERROR));
    }
    public static function replay(\PDO $db,array $actor,int $orderId,int $driverId,string $date,string $uuid,array $details):?array
    {
        if(!DeliveryPolicy::can($actor,'delivery_arrange'))throw new \DomainException('Arrangement forbidden.');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request ID.');
        $s=$db->prepare('SELECT action,request_hash,response_json FROM delivery_requests WHERE actor_key=? AND request_uuid=?');$s->execute(['employee:'.(int)$actor['id'],$uuid]);$old=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$old)return null;
        if($old['action']!=='arranged'||!hash_equals($old['request_hash'],self::requestHash($orderId,$driverId,$date,$details)))throw new \DomainException('Request ID already used.');
        return json_decode($old['response_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public function arrange(array $actor,int $orderId,int $driverId,string $date,string $uuid,array $details=[]):array
    {
        if(!DeliveryPolicy::can($actor,'delivery_arrange'))throw new \DomainException('Arrangement forbidden.');
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Africa/Windhoek'));
        if(!$day||$day->format('Y-m-d')!==$date)throw new \DomainException('Valid delivery date required.');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request ID.');
        if($this->db->inTransaction())throw new \LogicException('Arrangement owns its transaction.');
        $this->db->beginTransaction();
        try{
            $order=($this->resolveOrder)($this->db,$orderId);
            if(!$order)throw new \DomainException('Order unavailable for delivery.');
            $actorKey='employee:'.(int)$actor['id'];$hash=self::requestHash($orderId,$driverId,$date,$details);
            $s=$this->db->prepare('SELECT * FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE');$s->execute([$actorKey,$uuid]);$old=$s->fetch(\PDO::FETCH_ASSOC);
            if($old){if($old['action']!=='arranged'||!hash_equals($old['request_hash'],$hash))throw new \DomainException('Request ID already used.');$result=json_decode($old['response_json'],true,512,JSON_THROW_ON_ERROR);$this->db->commit();return $result;}
            if($day<new \DateTimeImmutable('today',new \DateTimeZone('Africa/Windhoek')))throw new \DomainException('Choose today or a future date.');
            $s=$this->db->prepare('SELECT employee_id FROM delivery_driver_profiles WHERE employee_id=? AND active=1 FOR UPDATE');$s->execute([$driverId]);
            if(!$s->fetchColumn())throw new \DomainException('Choose a verified active driver.');
            $s=$this->db->prepare("SELECT id FROM delivery_jobs WHERE order_id=? AND status NOT IN ('completed','cancelled')");$s->execute([$orderId]);
            if($s->fetchColumn())throw new \DomainException('This order already has an active delivery.');
            $additional=!empty($details['additional_delivery']);$additionalReason=trim((string)($details['additional_reason']??''));
            $s=$this->db->prepare("SELECT id FROM delivery_jobs WHERE order_id=? AND status='completed' LIMIT 1");$s->execute([$orderId]);
            $completed=(bool)$s->fetchColumn();
            if($completed&&!$additional)throw new \DomainException('This order already has a completed delivery. An additional delivery requires authorized review.');
            if($additional&&(!$completed||strlen($additionalReason)<5||strlen($additionalReason)>500||$order['goods_due']!==0||$order['included_fee_cents']!==0))throw new \DomainException('Additional delivery requires a completed, reconciled original and a verification reason.');
            foreach(['customer_name','customer_mobile','address','area'] as $field)if(trim((string)($order[$field]??''))==='')throw new \DomainException('Order contact and address require review.');
            foreach(['goods_due','fee_cents','included_fee_cents'] as $field)if(!is_int($order[$field]??null)||$order[$field]<0)throw new \DomainException('Verified balance and delivery fee required.');
            if($order['included_fee_cents']>$order['fee_cents'])throw new \DomainException('Shipping component needs review.');
            $ref='DEL-'.strtoupper(bin2hex(random_bytes(6)));
            $s=$this->db->prepare("INSERT INTO delivery_jobs(public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_order_component_cents,fee_payer,order_due_snapshot_cents,scheduled_date,driver_employee_id,arranged_by_employee_id) VALUES(?,'hambelela',?,?,?,?,?,?,?,'customer',?,?,?,?)");
            $s->execute([$ref,$orderId,$order['customer_name'],$order['customer_mobile'],$order['address'],$order['area'],$order['fee_cents'],$order['included_fee_cents'],$order['goods_due'],$date,$driverId,$actor['id']]);$id=(int)$this->db->lastInsertId();
            $payer=$order['fee_payer']??'customer';if(!in_array($payer,['customer','already_paid'],true))throw new \DomainException('Invalid Hambelela fee payer.');
            $this->db->prepare('UPDATE delivery_jobs SET zone_id=?,manual_rate_reason=?,fee_payer=?,prepaid_reference=?,urgent=?,notes=? WHERE id=?')->execute([$order['zone_id']??null,$order['manual_rate_reason']??null,$payer,$order['prepaid_reference']??null,$order['urgent']??0,$order['notes']??null,$id]);
            if($additional)$this->db->prepare('UPDATE delivery_jobs SET additional_delivery=1,additional_delivery_reason=? WHERE id=?')->execute([$additionalReason,$id]);
            $result=['id'=>$id,'reference'=>$ref,'status'=>'ready'];$json=json_encode($result,JSON_THROW_ON_ERROR);
            $metadata=$result;$metadata['additional_delivery']=$additional;$metadata['additional_reason']=$additionalReason;if(!empty($order['shipping_evidence']))$metadata['shipping_verification']=$order['shipping_evidence'];
            $this->db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'arranged',?,?)")->execute([$id,$actor['id'],json_encode($metadata,JSON_THROW_ON_ERROR)]);
            $this->db->prepare("INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,'arranged',?,?)")->execute([$actorKey,$uuid,$hash,$json]);
            $this->db->prepare('INSERT INTO delivery_outbox(event_key,delivery_id,recipient_employee_id,payload_json,next_attempt_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute(['assigned:'.$id,$id,$driverId,$json]);
            $this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
