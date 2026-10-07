<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

/** Review external snapshots without silently applying or deleting payment records. */
final class PaymentReviewService
{
    private \PDO $db;
    public function __construct(\PDO $db) {$this->db=$db;}
    private static function requireView(array $actor): void
    {
        if (!DeliveryPolicy::can($actor,'delivery_reconcile')) throw new \DomainException('Payment review access denied.');
    }
    private function current(int $orderId, bool $lock=false): array
    {
        $s=$this->db->prepare('SELECT id,order_number,total_amount,payment_status,payment_source FROM ops_orders WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->execute([$orderId]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if (!$order) throw new \DomainException('Order unavailable.');
        $s=$this->db->prepare('SELECT payment_method,amount_cents FROM order_payment_allocations WHERE order_id=? ORDER BY payment_method'.($lock?' FOR UPDATE':''));
        $s->execute([$orderId]);$amounts=[];
        foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $a)$amounts[$a['payment_method']]=(int)$a['amount_cents'];
        return ['order_number'=>$order['order_number'],'total_cents'=>DeliveryPolicy::cents((string)$order['total_amount']),
            'payment_status'=>$order['payment_status'],'payment_source'=>$order['payment_source'],'allocations'=>$amounts];
    }
    private static function token(array $review,array $current): string
    {
        return hash('sha256',json_encode([$review['id'],$review['snapshot_hash'],$review['state'],$review['last_seen_at'],$current],JSON_THROW_ON_ERROR));
    }
    public function pending(array $actor): array
    {
        self::requireView($actor);
        $rows=$this->db->query("SELECT * FROM delivery_order_sync_reviews WHERE state='pending' ORDER BY first_seen_at,id LIMIT 50")->fetchAll(\PDO::FETCH_ASSOC);
        $result=[];
        foreach($rows as $r){$current=$this->current((int)$r['order_id']);$result[]=[
            'id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],'current'=>$current,
            'incoming'=>json_decode($r['snapshot_json'],true,32,JSON_THROW_ON_ERROR),
            'first_seen_at'=>$r['first_seen_at'],'last_seen_at'=>$r['last_seen_at'],'version'=>self::token($r,$current)];}
        return ['reviews'=>$result,'pending_count'=>(int)$this->db->query("SELECT COUNT(*) FROM delivery_order_sync_reviews WHERE state='pending'")->fetchColumn(),
            'can_resolve'=>DeliveryPolicy::can($actor,'delivery_accounting_manage')];
    }
    public function keepVerifiedPortalPayment(array $actor,int $id,string $version,string $note): void
    {
        self::requireView($actor);
        if(!DeliveryPolicy::can($actor,'delivery_accounting_manage'))throw new \DomainException('Owner accounting permission required.');
        $note=trim($note);
        if(strlen($note)<10||strlen($note)>450)throw new \DomainException('Enter a verification reason between 10 and 450 characters.');
        if($this->db->inTransaction())throw new \LogicException('Payment review owns its transaction.');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT order_id FROM delivery_order_sync_reviews WHERE id=?');$s->execute([$id]);$orderId=(int)$s->fetchColumn();
            if(!$orderId)throw new \DomainException('Review unavailable.');
            // Match importer lock order: Order, allocations, then review record.
            $current=$this->current($orderId,true);
            $s=$this->db->prepare('SELECT * FROM delivery_order_sync_reviews WHERE id=? FOR UPDATE');$s->execute([$id]);$review=$s->fetch(\PDO::FETCH_ASSOC);
            if(!$review||$review['state']!=='pending')throw new \DomainException('This review is already closed. Refresh the queue.');
            if(!hash_equals(self::token($review,$current),$version))throw new \DomainException('The payment or snapshot changed. Refresh and review it again.');
            $s=$this->db->prepare("UPDATE delivery_order_sync_reviews SET state='resolved',resolved_by_employee_id=?,resolved_at=UTC_TIMESTAMP(),resolution_note=? WHERE id=? AND state='pending'");
            $s->execute([$actor['id'],'Keep verified portal payment: '.$note,$id]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
