<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/OrderCollectionPayment.php';

/** Order totals already include shipping. Allocate existing paid value to goods first. */
final class OrderDispatchBalance
{
    public static function read(\PDO $db,array $order,int $fee,int $receivedFee=0):array
    {
        $total=DeliveryPolicy::cents((string)$order['total_amount']);
        if($fee<0||$fee>$total||$receivedFee<0||$receivedFee>$fee)throw new \DomainException('Order shipping balance needs review.');
        $s=$db->prepare('SELECT * FROM order_payment_allocations WHERE order_id=?'.($db->inTransaction()?' FOR UPDATE':''));$s->execute([$order['id']]);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);
        $paid=OrderCollectionPayment::paid($order,$rows,$total);
        $due=$total-$paid;$feeDue=min($due,$fee-$receivedFee);
        return ['total_cents'=>$total,'paid_cents'=>$paid,'product_cents'=>$total-$fee,'goods_due'=>$due-$feeDue,'fee_due'=>$feeDue,'collect_cents'=>$due,'payment_status'=>$due===0?'paid':($paid>0?'partial':'unpaid')];
    }
}
