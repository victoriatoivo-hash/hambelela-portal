<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/DeliveryCustody.php';

/** Read-only pending reminders, derived from the durable, assignment-scoped event stream. */
final class HandoverReminders
{
    public static function pending(\PDO $db,array $actor):array
    {
        if(!DeliveryPolicy::can($actor,'delivery_arrange'))throw new \DomainException('Arrangement permission required.');
        // Notify the actual arranger, never all employees sharing the Front role.
        $s=$db->prepare("SELECT j.*,e.full_name driver_name FROM delivery_jobs j
            JOIN ops_employees e ON e.id=j.driver_employee_id
            WHERE j.source='hambelela' AND j.arranged_by_employee_id=?
              AND j.status IN ('ready','out_for_delivery')
              AND EXISTS(SELECT 1 FROM delivery_events v WHERE v.delivery_id=j.id AND v.event_type='driver_accepted')
            ORDER BY j.id");
        $s->execute([(int)$actor['id']]);$items=[];
        foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $job){
            $c=DeliveryCustody::read($db,$job);
            if(!$c['acceptance']||$c['handover']||$c['collection']||$c['exception'])continue;
            $items[]=['id'=>(int)$job['id'],'reference'=>$job['public_reference'],
                'customer'=>$job['customer_name'],'driver_id'=>(int)$job['driver_employee_id'],
                'driver'=>$job['driver_name'],'acceptance_event_id'=>(int)$c['acceptance']['id'],
                'accepted_at'=>$c['acceptance']['created_at']];
            if(count($items)>=20)break;
        }
        return $items;
    }
}
