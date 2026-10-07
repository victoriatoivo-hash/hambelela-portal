<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

/** Draft integration adapter. Install sync/write guards before production use.
 * Called ONLY by DeliveryService::reconcile inside its owned transaction.
 * Never runs schema helpers, network calls or changes portal_paid_confirmed.
 */
final class OrderPaymentAdapter
{
    private array $methodLabels;
    public function __construct(array $methodLabels) {$this->methodLabels=$methodLabels;}

    public function apply(\PDO $db,array $job,array $receipt,array $actor): void
    {
        if(!$db->inTransaction())throw new \LogicException('Order reconciliation requires transaction.');
        if(!DeliveryPolicy::can($actor,'delivery_reconcile'))throw new \DomainException('Reconciliation forbidden.');
        if(($job['source']??'')!=='hambelela'||(int)($job['order_id']??0)<1)throw new \DomainException('Linked Hambelela order required.');
        if((int)$receipt['delivery_id']!==(int)$job['id']||$receipt['status']!=='collected'||(int)$receipt['partner_cod_cents']!==0)throw new \DomainException('Invalid order receipt.');
        $method=(string)$receipt['payment_method'];
        if(!isset($this->methodLabels[$method]))throw new \DomainException('Unapproved payment method.');
        $goods=(int)$receipt['order_goods_cents'];$fee=(int)$receipt['delivery_fee_cents'];
        if($goods<0||$fee<0)throw new \DomainException('Invalid receipt amounts.');
        $s=$db->prepare('SELECT total_amount,payment_status FROM ops_orders WHERE id=? FOR UPDATE');$s->execute([$job['order_id']]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$order)throw new \DomainException('Order unavailable.');
        $total=DeliveryPolicy::cents((string)$order['total_amount']);
        $component=(int)$job['fee_order_component_cents'];
        if($component<0||$component>(int)$job['fee_cents']||$component>$total)throw new \DomainException('Shipping component needs review.');
        $s=$db->prepare('SELECT COALESCE(SUM(delivery_fee_cents),0) FROM delivery_receipts WHERE delivery_id=? AND status=\'reconciled\'');$s->execute([$job['id']]);
        $previousFee=(int)$s->fetchColumn();
        // Only the fee already included in the Order total is credited to that Order.
        $includedFee=min($fee,max(0,$component-$previousFee));$credit=$goods+$includedFee;
        if($credit===0)return; // A separately charged delivery fee is not product payment.
        $s=$db->prepare('SELECT * FROM order_payment_allocations WHERE order_id=? ORDER BY id FOR UPDATE');$s->execute([$job['order_id']]);$previous=$s->fetchAll(\PDO::FETCH_ASSOC);
        $paid=0;$existing=null;
        foreach($previous as $r){if((int)$r['amount_cents']<0)throw new \DomainException('Order allocations need review.');$paid+=(int)$r['amount_cents'];if($r['payment_method']===$method)$existing=$r;}
        if($order['payment_status']==='paid' && !$previous)throw new \DomainException('Legacy paid order requires verified allocations.');
        if($paid+$credit>$total)throw new \DomainException('Order balance changed. Review collection before reconciliation.');
        $version='delivery-receipt:'.(int)$receipt['id'];
        if($existing){
            // Preserve previous reference/source provenance in the audit; never replace other methods.
            $db->prepare('UPDATE order_payment_allocations SET amount_cents=amount_cents+?,source=\'delivery\',source_version=?,updated_by_employee_id=? WHERE id=?')->execute([$credit,$version,$actor['id'],$existing['id']]);
        }else{
            $db->prepare('INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,transaction_reference,source,source_version,updated_by_employee_id) VALUES(?,?,?,?,\'delivery\',?,?)')->execute([$job['order_id'],$method,$credit,$receipt['transaction_reference']?:null,$version,$actor['id']]);
        }
        $s=$db->prepare('SELECT * FROM order_payment_allocations WHERE order_id=? ORDER BY id');$s->execute([$job['order_id']]);$after=$s->fetchAll(\PDO::FETCH_ASSOC);
        $labels=array_map(fn($r)=>$this->methodLabels[$r['payment_method']]??$r['payment_method'],$after);
        $status=$paid+$credit===$total?'paid':'partial';
        $db->prepare('UPDATE ops_orders SET payment_method=?,payment_status=?,payment_source=\'delivery\',payment_version=?,payment_updated_by_employee_id=?,payment_updated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([implode(' / ',$labels),$status,$version,$actor['id'],$job['order_id']]);
        $db->prepare('INSERT INTO order_payment_allocation_audit(order_id,previous_allocations_json,new_allocations_json,changed_by_employee_id,source) VALUES(?,?,?,?,\'delivery\')')->execute([$job['order_id'],json_encode($previous,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$actor['id']]);
    }
}
