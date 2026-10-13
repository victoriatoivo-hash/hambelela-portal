<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

/** Order totals already include shipping. Allocate existing paid value to goods first. */
final class OrderDispatchBalance
{
    public static function read(\PDO $db,array $order,int $fee,int $receivedFee=0):array
    {
        $total=DeliveryPolicy::cents((string)$order['total_amount']);
        if($fee<0||$fee>$total||$receivedFee<0||$receivedFee>$fee)throw new \DomainException('Order shipping balance needs review.');
        $s=$db->prepare('SELECT amount_cents FROM order_payment_allocations WHERE order_id=?'.($db->inTransaction()?' FOR UPDATE':''));$s->execute([$order['id']]);$rows=$s->fetchAll(\PDO::FETCH_COLUMN);
        $paid=0;foreach($rows as $amount){if((int)$amount<0)throw new \DomainException('Order allocations need review.');$paid+=(int)$amount;}
        if(!$rows){if(($order['payment_status']??'')==='paid')$paid=$total;elseif(($order['payment_status']??'')==='partial')throw new \DomainException('Partial Order payment has no verified allocation. Review it in Orders.');}
        if($paid>$total)throw new \DomainException('Order is overpaid; review it in Orders.');
        $due=$total-$paid;$feeDue=min($due,$fee-$receivedFee);
        return ['total_cents'=>$total,'paid_cents'=>$paid,'product_cents'=>$total-$fee,'goods_due'=>$due-$feeDue,'fee_due'=>$feeDue,'collect_cents'=>$due,'payment_status'=>$due===0?'paid':($paid>0?'partial':'unpaid')];
    }
}
