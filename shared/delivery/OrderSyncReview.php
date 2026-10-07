<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
final class OrderSyncReview
{
    /** Capture differing financial snapshots BEFORE the importer upsert. No external calls/DDL. */
    public static function capture(\PDO $db,int $wooId,string $total,array $allocations,string $source):void
    {
        if(!$db->inTransaction())throw new \LogicException('Sync review requires importer transaction.');
        $s=$db->prepare('SELECT id,payment_source FROM ops_orders WHERE woo_order_id=? FOR UPDATE');$s->execute([$wooId]);$order=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$order||$order['payment_source']!=='delivery')return;
        $s=$db->prepare('SELECT total_amount FROM ops_orders WHERE id=?');$s->execute([$order['id']]);$order['total_amount']=$s->fetchColumn();
        $incoming=[];foreach($allocations as $a)$incoming[(string)$a['method']]=(int)$a['amount_cents'];ksort($incoming);
        $s=$db->prepare('SELECT payment_method,amount_cents FROM order_payment_allocations WHERE order_id=? ORDER BY payment_method FOR UPDATE');$s->execute([$order['id']]);$current=[];foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $a)$current[$a['payment_method']]=(int)$a['amount_cents'];ksort($current);
        $totalCents=DeliveryPolicy::cents($total);
        if($totalCents===DeliveryPolicy::cents((string)$order['total_amount'])&&$incoming===$current)return;
        $json=json_encode(['total_cents'=>$totalCents,'allocations'=>$incoming,'source'=>$source],JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
        $db->prepare('INSERT INTO delivery_order_sync_reviews(order_id,snapshot_hash,snapshot_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP()')->execute([$order['id'],$hash,$json]);
    }
}
