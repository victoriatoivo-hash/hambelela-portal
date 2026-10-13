<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/OrderDispatchBalance.php';

final class OrderBalanceAdapter
{
    /** Read current allocations; business writes must call within the service transaction. */
    public function read(\PDO $db,array $job):array
    {
        if($job['source']==='partner'){
            $s=$db->prepare("SELECT COALESCE(SUM(delivery_fee_cents),0) fee,COALESCE(SUM(partner_cod_cents),0) cod FROM delivery_receipts WHERE delivery_id=? AND status='reconciled'");$s->execute([$job['id']]);$paid=$s->fetch(\PDO::FETCH_ASSOC);
            $due=['order_goods'=>0,'delivery_fee'=>($job['fee_payer']==='customer'?(int)$job['fee_cents']:0)-(int)$paid['fee'],'partner_cod'=>(int)$job['partner_cod_due_cents']-(int)$paid['cod']];
            if(min($due)<0)throw new \DomainException('Partner collections need review.');return $due;
        }
        if($job['source']!=='hambelela')throw new \DomainException('Unknown delivery source.');
        $lock=$db->inTransaction()?' FOR UPDATE':'';
        $s=$db->prepare('SELECT id,total_amount,payment_status FROM ops_orders WHERE id=?'.$lock);$s->execute([$job['order_id']]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$order)throw new \DomainException('Order unavailable.');
        $s=$db->prepare("SELECT metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='arranged' ORDER BY id LIMIT 1");$s->execute([$job['id']]);$metadata=json_decode((string)$s->fetchColumn(),true);
        if(!empty($metadata['order_dispatch'])){
            $s=$db->prepare("SELECT COALESCE(SUM(delivery_fee_cents),0) FROM delivery_receipts WHERE delivery_id=? AND status='reconciled'");$s->execute([$job['id']]);
            $balance=OrderDispatchBalance::read($db,$order,(int)$job['fee_cents'],(int)$s->fetchColumn());
            if($balance['total_cents']!==(int)$metadata['order_dispatch']['total_cents'])throw new \DomainException('Order total changed after dispatch. Review before collection.');
            return ['order_goods'=>$balance['goods_due'],'delivery_fee'=>$balance['fee_due'],'partner_cod'=>0];
        }
        $s=$db->prepare('SELECT amount_cents,source FROM order_payment_allocations WHERE order_id=?'.$lock);$s->execute([$job['order_id']]);$allocations=$s->fetchAll(\PDO::FETCH_ASSOC);
        $paid=0;$external=0;
        foreach($allocations as $a){$amount=(int)$a['amount_cents'];if($amount<0)throw new \DomainException('Order allocations need review.');$paid+=$amount;if($a['source']!=='delivery')$external+=$amount;}
        if(!$allocations&&$order['payment_status']==='paid')throw new \DomainException('Legacy paid Order needs verified allocations.');
        $total=DeliveryPolicy::cents((string)$order['total_amount']);$included=(int)$job['fee_order_component_cents'];
        if($included<0||$included>(int)$job['fee_cents']||$included>$total||$paid>$total)throw new \DomainException('Order balance needs review.');
        // External mixed product/shipping payments have no verified component attribution yet.
        $prepaid=$job['fee_payer']==='already_paid';
        if($prepaid&&$included>0&&$paid<$total)throw new \DomainException('Prepaid Order changed; review required.');
        if($included>0&&$external>0&&!$prepaid)throw new \DomainException('Existing shipping payment needs allocation review before collection.');
        $s=$db->prepare("SELECT COALESCE(SUM(delivery_fee_cents),0) FROM delivery_receipts WHERE delivery_id=? AND status='reconciled'");$s->execute([$job['id']]);$receivedFee=(int)$s->fetchColumn();
        $goods=$total-$paid-($prepaid?0:max(0,$included-min($included,$receivedFee)));
        $fee=($job['fee_payer']==='customer'?(int)$job['fee_cents']:0)-$receivedFee;
        if($goods<0||$fee<0)throw new \DomainException('Payment components require review.');
        return ['order_goods'=>$goods,'delivery_fee'=>$fee,'partner_cod'=>0];
    }
}
