<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** A POS cash tender is an expected method, not a receipt, while Paid is unticked.
 * Reading never changes allocations. Reconciliation replaces such expectations
 * with the actual receipt and retains both snapshots in the payment audit.
 */
final class OrderCollectionPayment
{
    public static function unconfirmedCash(array $order,array $allocation):bool
    {
        return array_key_exists('portal_paid_confirmed',$order)
            && $order['portal_paid_confirmed']!==null && !(bool)$order['portal_paid_confirmed']
            && strtolower(trim((string)($allocation['payment_method']??'')))==='cash'
            && ($allocation['source']??'')!=='delivery';
    }
    public static function paid(array $order,array $allocations,int $total):int
    {
        $paid=0;$raw=0;
        foreach($allocations as $row){
            $amount=(int)$row['amount_cents'];
            if($amount<0)throw new \DomainException('Order allocations need review.');
            $raw+=$amount;
            if(!self::unconfirmedCash($order,$row))$paid+=$amount;
        }
        if($raw>$total)throw new \DomainException('Order is overpaid; review it in Orders.');
        if(array_key_exists('portal_paid_confirmed',$order)&&$order['portal_paid_confirmed']!==null&&(bool)$order['portal_paid_confirmed'])return $total;
        if(!$allocations){
            if(self::unconfirmedCash($order,['payment_method'=>$order['payment_method']??'','source'=>$order['payment_source']??'']))return 0;
            if(($order['payment_status']??'')==='paid')return $total;
            if(($order['payment_status']??'')==='partial')throw new \DomainException('Partial Order payment has no verified allocation. Review it in Orders.');
        }
        return $paid;
    }
}
