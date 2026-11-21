<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Existing immutable event stream is the source of custody, not the current assignee alone. */
final class DeliveryCustody
{
    public static function read(\PDO $db,array $job):array
    {
        $q=$db->prepare('SELECT id,event_type,employee_id,created_at,metadata_json FROM delivery_events WHERE delivery_id=? ORDER BY id');$q->execute([$job['id']]);
        $out=['tracking_version'=>0,'acceptance'=>null,'handover'=>null,'collection'=>null,'exception'=>null];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $e){
            $e['data']=json_decode((string)$e['metadata_json'],true)?:[];unset($e['metadata_json']);$m=$e['data'];$type=$e['event_type'];
            if($type==='arranged')$out['tracking_version']=(int)($m['tracking_version']??0);
            // A new assignment or rescheduled attempt requires fresh custody evidence.
            if($type==='arranged'||$type==='revised'||($type==='dispatch_updated'&&($m['field']??'')==='driver')){
                foreach(['acceptance','handover','collection','exception'] as $k)$out[$k]=null;
            }
            if($type==='driver_accepted'&&(int)$e['employee_id']===(int)$job['driver_employee_id'])$out['acceptance']=$e;
            if($type==='parcel_collected'&&(int)($m['driver_id']??0)===(int)$job['driver_employee_id'])$out['handover']=$e;
            if($type==='driver_collection_confirmed'&&(int)$e['employee_id']===(int)$job['driver_employee_id'])$out['collection']=$e;
            if($type==='handover_exception'&&(int)($m['driver_id']??0)===(int)$job['driver_employee_id'])$out['exception']=$e;
        }
        return $out;
    }
}
