<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
final class OrderSuggestions
{
    public static function search(\PDO $db,string $query,bool $suggest):array
    {
        if(strlen($query)>80)throw new \DomainException('Search text is too long.');
        $where="o.deleted_at IS NULL AND o.archived_at IS NULL";$sort="o.id DESC";
        if($suggest){
            $where.=" AND COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type)='delivery'
                AND o.status NOT IN ('completed','cancelled','canceled','refunded','dispatched','out_for_delivery','delivered','ready_for_collection','ready_for_courier')
                AND NOT EXISTS(SELECT 1 FROM delivery_jobs d WHERE d.order_id=o.id AND d.status<>'cancelled')";
            $sort="CASE WHEN o.status='ready_for_delivery' THEN 0 WHEN o.status IN ('verified','packed') THEN 1 ELSE 2 END,o.id ASC";
        }
        $s=$db->prepare("SELECT o.id,o.order_number,o.customer_name,o.customer_contact,o.total_amount,o.status,o.payment_status,o.notes,j.id open_delivery_id,COALESCE(j.id,(SELECT d.id FROM delivery_jobs d WHERE d.order_id=o.id AND d.status='completed' ORDER BY d.id DESC LIMIT 1)) active_delivery_id FROM ops_orders o LEFT JOIN delivery_jobs j ON j.active_primary_order_id=o.id WHERE $where AND (LOCATE(?,o.order_number)>0 OR LOCATE(?,o.customer_name)>0 OR LOCATE(?,o.customer_contact)>0 OR o.id=?) ORDER BY $sort LIMIT ".($suggest?'8':'30'));
        $s->execute([$query,$query,$query,ctype_digit($query)?(int)$query:0]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
}
