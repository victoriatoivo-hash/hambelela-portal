<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAuth.php';
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/WooShippingSource.php';
require_once __DIR__.'/OrderDispatchBalance.php';
final class PortalOrderRepository
{
    /** Customer data validation must never use a driver's authentication errors. */
    private static function customerMobile(array $candidates):string
    {
        foreach($candidates as $input){
            $mobile=preg_replace('/[\s().-]/','',trim((string)$input));
            if(strncmp($mobile,'00',2)===0)$mobile='+'.substr($mobile,2);
            if(preg_match('/^0[0-9]{9}$/D',$mobile))$mobile='+264'.substr($mobile,1);
            if(preg_match('/^264[0-9]{9}$/D',$mobile))$mobile='+'.$mobile;
            if(preg_match('/^\+[1-9][0-9]{7,14}$/D',$mobile))return $mobile;
        }
        return '';
    }
    private static function usableAddress(string $address):string
    {
        // Imported region/country placeholders are not a delivery street address.
        $address=trim($address);
        return preg_match('/^(?:[A-Z]{2}|Namibia)(?:\s*,\s*(?:[A-Z]{2}|Namibia))*$/iD',$address)?'':$address;
    }
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
        if(!empty($input['source_order'])){
            $mode=strtolower(trim((string)($order['fulfilment_mode']??'')))?:strtolower(trim((string)($order['order_type']??'')));
            if($mode!==''&&$mode!=='delivery')throw new \DomainException('Only Delivery-mode orders can be arranged here.');
            if(in_array(strtolower((string)($order['status']??'')),['completed','cancelled','canceled','refunded','dispatched','out_for_delivery','delivered'],true))throw new \DomainException('This Order is completed, cancelled or already dispatched.');
            if(!empty($input['additional_delivery']))throw new \DomainException('An additional trip requires separate authorized review.');
            if($shippingEvidence!==null){WooShippingSource::assertCurrent($order,$shippingEvidence);$fee=(int)$shippingEvidence['included_cents'];}
            else $fee=self::shippingComponent($order);
            $address=self::usableAddress(trim((string)($shippingEvidence['address']??''))?:self::shippingAddress((string)($order['notes']??'')));
            if($address==='')$address=self::usableAddress((string)($input['address']??''));
            if(strlen($address)>2000)throw new \DomainException('Address is too long.');
            if($address===''&&empty($input['preview']))throw new \DomainException('Delivery address missing. Add an address before dispatch.');
            $storedMobile=self::customerMobile([$order['customer_contact']??'', $shippingEvidence['mobile']??'']);
            $mobile=$storedMobile;
            if($mobile===''&&!empty($input['mobile']))$mobile=self::customerMobile([$input['mobile']]);
            if($mobile===''&&empty($input['preview']))throw new \DomainException('Customer mobile is missing or invalid. Enter a valid number before arranging delivery.');
            $notes=trim((string)($input['notes']??''));if(strlen($notes)>4000)throw new \DomainException('Notes are too long.');
            $area=trim((string)($shippingEvidence['area']??''))?:mb_substr($address,0,190);
            $money=OrderDispatchBalance::read($db,$order,$fee);
            $prepaid=$money['collect_cents']===0;
            return $money+['customer_name'=>$order['customer_name'],'customer_mobile'=>$mobile,'address'=>$address,'area'=>$area,
                'order_number'=>$order['order_number']??('#'.$id),'payment_method'=>$order['payment_method']??'',
                'fee_cents'=>$fee,'included_fee_cents'=>$fee,'zone_id'=>null,'manual_rate_reason'=>null,
                'fee_payer'=>$prepaid?'already_paid':'customer','prepaid_reference'=>$prepaid?'order:'.$id:null,
                'contact_snapshot'=>$storedMobile,'urgent'=>!empty($input['urgent'])?1:0,'notes'=>$notes,'customer_instructions'=>trim((string)($shippingEvidence['customer_instructions']??'').'\n'.(string)($order['notes']??'')),'shipping_evidence'=>$shippingEvidence,'source_order'=>true];
        }
        $address=trim((string)($input['address']??''));if($address==='')$address=self::shippingAddress((string)($order['notes']??''));
        if($address===''||strlen($address)>2000)throw new \DomainException('Confirm the delivery address.');
        $mobile=self::customerMobile([trim((string)($input['mobile']??''))?:trim((string)$order['customer_contact']),$shippingEvidence['mobile']??'']);
        if($mobile==='')throw new \DomainException('Customer mobile is missing or invalid. Enter a valid number before arranging delivery.');
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
