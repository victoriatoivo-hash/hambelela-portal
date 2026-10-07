<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Serialize manual total changes with reconciliation; never silently alter a receipt. */
final class OrderTotalGuard
{
    public static function update(\PDO $db,array $ids,callable $write):int
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);
        if(!$ids||min($ids)<1)throw new \DomainException('Invalid order selection.');
        $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
        try{
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $s=$db->prepare("SELECT id,payment_source FROM ops_orders WHERE id IN ($marks) ORDER BY id FOR UPDATE");
            $s->execute($ids);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);$s->closeCursor();
            if(count($rows)!==count($ids))throw new \DomainException('Order selection changed. Refresh and try again.');
            foreach($rows as $row)if($row['payment_source']==='delivery')throw new \DomainException('This order contains reconciled Driver payments. Its amount is protected; ask the Owner to review the payment instead.');
            $count=(int)$write();if($owned)$db->commit();return $count;
        }catch(\Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
    }
}
