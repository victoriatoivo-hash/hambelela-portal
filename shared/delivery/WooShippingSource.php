<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

/** Server-fetched evidence only. No writes and no HTTP requests inside DB locks. */
final class WooShippingSource
{
    public static function fingerprint(array $order):string
    {
        $fields=[];foreach(['id','woo_order_id','total_amount','payment_version','updated_at'] as $key)$fields[$key]=(string)($order[$key]??'');
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
    }
    public static function fetch(\PDO $db,int $id,\Closure $get):array
    {
        if($db->inTransaction())throw new \LogicException('Fetch shipping before opening the assignment transaction.');
        $s=$db->prepare('SELECT * FROM ops_orders WHERE id=? AND deleted_at IS NULL AND archived_at IS NULL');$s->execute([$id]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$order||(int)($order['woo_order_id']??0)<1)throw new \DomainException('This Order has no verified website/POS link. Shipping must be reviewed before delivery can be arranged.');
        try{$remote=$get('orders/'.(int)$order['woo_order_id']);}
        catch(\Throwable $e){throw new \DomainException('Shipping verification is temporarily unavailable. No delivery was created. Please retry.');}
        if(!is_array($remote))throw new \DomainException('Invalid shipping response. No delivery was created.');
        return self::verify($order,$remote);
    }
    public static function verify(array $order,array $remote):array
    {
        if((int)($order['woo_order_id']??0)<1||(int)($remote['id']??0)!==(int)$order['woo_order_id'])throw new \DomainException('Shipping source does not match this Order.');
        if(($remote['currency']??'')!=='NAD')throw new \DomainException('Delivery requires a verified NAD Order.');
        if(!in_array($remote['status']??'',['pending','on-hold','processing','completed'],true))throw new \DomainException('Order status requires review before delivery.');
        if(!isset($remote['refunds'])||!is_array($remote['refunds'])||$remote['refunds'])throw new \DomainException('Refunded or unverified Order requires review before delivery.');
        foreach(['total','shipping_total','shipping_tax'] as $field){
            if(!isset($remote[$field])||!is_string($remote[$field])||$remote[$field]==='')throw new \DomainException('Order shipping breakdown is incomplete.');
        }
        $total=DeliveryPolicy::cents($remote['total']);
        if($total!==DeliveryPolicy::cents((string)$order['total_amount']))throw new \DomainException('Website/POS and portal totals differ. Review or synchronise the Order before arranging delivery.');
        $net=DeliveryPolicy::cents($remote['shipping_total']);$tax=DeliveryPolicy::cents($remote['shipping_tax']);
        if(!isset($remote['shipping_lines'])||!is_array($remote['shipping_lines']))throw new \DomainException('Shipping lines could not be verified.');
        $lineNet=0;$lineTax=0;
        foreach($remote['shipping_lines'] as $line){
            if(!is_array($line)||!isset($line['total'],$line['total_tax'])||!is_string($line['total'])||!is_string($line['total_tax']))throw new \DomainException('Shipping line amounts could not be verified.');
            $lineNet+=DeliveryPolicy::cents($line['total']);$lineTax+=DeliveryPolicy::cents($line['total_tax']);
        }
        if($lineNet!==$net||$lineTax!==$tax||$net+$tax>$total)throw new \DomainException('Order shipping totals need review.');
        return ['source'=>'woocommerce','woo_order_id'=>(int)$remote['id'],'order_total_cents'=>$total,
            'shipping_cents'=>$net,'shipping_tax_cents'=>$tax,'included_cents'=>$net+$tax,
            'verified_at'=>time(),'portal_fingerprint'=>self::fingerprint($order),
            'source_modified_gmt'=>(string)($remote['date_modified_gmt']??'')];
    }
    public static function assertCurrent(array $order,array $evidence):void
    {
        if(($evidence['source']??'')!=='woocommerce'||!isset($evidence['portal_fingerprint'])||
            !hash_equals(self::fingerprint($order),(string)$evidence['portal_fingerprint'])||
            (int)($evidence['woo_order_id']??0)!==(int)($order['woo_order_id']??0)||
            time()-(int)($evidence['verified_at']??0)>60||(int)($evidence['verified_at']??0)>time()+5)
            throw new \DomainException('Order changed during shipping verification. Refresh and retry; nothing was saved.');
    }
    public static function forJob(\PDO $db,array $actor,int $id,string $action,\Closure $get):?array
    {
        $s=$db->prepare('SELECT * FROM delivery_jobs WHERE id=?');$s->execute([$id]);$job=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$job||!DeliveryPolicy::canView($actor,$job))throw new \DomainException('Delivery access denied.');
        if($action==='reconcile'){
            if(!DeliveryPolicy::can($actor,'delivery_reconcile'))throw new \DomainException('Reconciliation forbidden.');
        }else DeliveryPolicy::requireAssignedDriver($actor,$job);
        if($job['source']==='partner')return null;
        $evidence=self::fetch($db,(int)$job['order_id'],$get);
        $s=$db->prepare("SELECT metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='arranged' ORDER BY id LIMIT 1");$s->execute([$id]);$metadata=json_decode((string)$s->fetchColumn(),true);
        $original=$metadata['shipping_verification']??null;
        $expectedIncluded=!empty($job['additional_delivery'])?($original['included_cents']??null):(int)$job['fee_order_component_cents'];
        if(!$original||$evidence['order_total_cents']!==($original['order_total_cents']??null)||$evidence['included_cents']!==$expectedIncluded)
            throw new \DomainException('The Order or shipping changed after assignment. Front must review it before collection or reconciliation.');
        return $evidence;
    }
    public static function assertLockedJob(\PDO $db,array $job,array $evidence):void
    {
        if(!$db->inTransaction())throw new \LogicException('Locked shipping check requires a transaction.');
        $s=$db->prepare('SELECT * FROM ops_orders WHERE id=? FOR UPDATE');$s->execute([$job['order_id']]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$order)throw new \DomainException('Order unavailable.');
        self::assertCurrent($order,$evidence);
    }
}
