<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAuth.php';
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/WooShippingSource.php';
final class PortalOrderRepository
{
    public static function shippingAddress(string $notes):string
    {
        return preg_match('/^Shipping address:\s*(.+)$/mi',$notes,$m)?trim($m[1]):'';
    }
    public static function shippingComponent(array $order):int
    {
        // A missing legacy column is unknown, not zero. Assuming zero here could
        // collect an already-included delivery fee for a second time.
        foreach(['shipping_total','shipping_tax_total'] as $field){
            if(!array_key_exists($field,$order)||$order[$field]===null||$order[$field]==='')
                throw new \DomainException('Verified Order shipping breakdown is unavailable. Review the Order before arranging delivery.');
        }
        return DeliveryPolicy::cents((string)$order['shipping_total'])+DeliveryPolicy::cents((string)$order['shipping_tax_total']);
    }
    public static function resolve(\PDO $db,int $id,array $input,?array $shippingEvidence=null):?array
    {
        if(!$db->inTransaction())throw new \LogicException('Order must be locked during arrangement.');
        $s=$db->prepare('SELECT * FROM ops_orders WHERE id=? AND deleted_at IS NULL AND archived_at IS NULL FOR UPDATE');$s->execute([$id]);$order=$s->fetch(\PDO::FETCH_ASSOC);if(!$order)return null;
        $address=trim((string)($input['address']??''));if($address==='')$address=self::shippingAddress((string)($order['notes']??''));
        if($address===''||strlen($address)>2000)throw new \DomainException('Confirm the delivery address.');
        $mobile=DriverAuth::mobile(trim((string)($input['mobile']??''))?:trim((string)$order['customer_contact']));
        $zoneId=(int)($input['zone_id']??0);$zone=null;
        if($zoneId){$s=$db->prepare('SELECT * FROM delivery_zones WHERE id=? AND active=1 FOR UPDATE');$s->execute([$zoneId]);$zone=$s->fetch(\PDO::FETCH_ASSOC);if(!$zone)throw new \DomainException('Choose an active delivery area.');}
        $area=$zone?$zone['area']:trim((string)($input['area']??''));if($area===''||strlen($area)>190)throw new \DomainException('Delivery area is required.');
        $manual=trim((string)($input['manual_fee']??''));$reason=trim((string)($input['manual_reason']??''));
        if($manual!==''){$fee=DeliveryPolicy::cents($manual);if($reason===''||strlen($reason)>500)throw new \DomainException('Explain the manual delivery fee.');}
        elseif($zone)$fee=(int)$zone['fee_cents'];else throw new \DomainException('No delivery rate configured. Enter an authorized manual fee and reason.');
        if($shippingEvidence!==null){WooShippingSource::assertCurrent($order,$shippingEvidence);$included=$shippingEvidence['included_cents'];}
        else $included=self::shippingComponent($order);
        $additional=!empty($input['additional_delivery']);
        if(!$additional&&$included>$fee)throw new \DomainException('Order shipping exceeds this delivery fee; review before arranging.');
        $total=DeliveryPolicy::cents((string)$order['total_amount']);
        $s=$db->prepare('SELECT amount_cents FROM order_payment_allocations WHERE order_id=? FOR UPDATE');$s->execute([$id]);$rows=$s->fetchAll(\PDO::FETCH_COLUMN);$paid=array_sum(array_map('intval',$rows));
        if($paid<0||$paid>$total||(!$rows&&$order['payment_status']==='paid'))throw new \DomainException('Order payments need review.');
        if($additional){if($paid!==$total)throw new \DomainException('Additional delivery requires a fully reconciled original Order.');$included=0;}
        $prepaid=$included>0&&$included===$fee&&$paid===$total;
        if($included>0&&$paid>0&&!$prepaid)throw new \DomainException('Part-paid shipping needs verified allocation before arrangement.');
        $goods=$total-$paid-($prepaid?0:$included);if($goods<0)throw new \DomainException('Order balance needs review.');
        $notes=trim((string)($input['notes']??''));if(strlen($notes)>4000)throw new \DomainException('Notes are too long.');
        return ['customer_name'=>$order['customer_name'],'customer_mobile'=>$mobile,'address'=>$address,'area'=>$area,
            'goods_due'=>$goods,'fee_cents'=>$fee,'included_fee_cents'=>$included,'zone_id'=>$zoneId?:null,
            'manual_rate_reason'=>$manual!==''?$reason:null,'fee_payer'=>$prepaid?'already_paid':'customer',
            'prepaid_reference'=>$prepaid?'order:'.$id.':'.($order['payment_version']??'verified-allocations'):null,
            'urgent'=>!empty($input['urgent'])?1:0,'notes'=>$notes,'shipping_evidence'=>$shippingEvidence];
    }
}
