<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAssignments.php';

/** Driver-only, read-only route candidate snapshot. Never calls a paid provider. */
final class DriverLiveRouteData
{
    public static function read(\PDO $db,int $employeeId,?\DateTimeImmutable $now=null):array
    {
        $now=($now??new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        // One consistent database snapshot for count, version and stop facts.
        $ownTransaction=!$db->inTransaction();
        if($ownTransaction){$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();}
        try{
            $list=DriverAssignments::read($db,$employeeId,$now);
            $pendingIssue=DeliveryIssueState::pendingPredicate();
            $q=$db->prepare("SELECT id,version,status,address,area,updated_at FROM delivery_jobs j WHERE driver_employee_id=? AND (scheduled_date=? OR $pendingIssue) AND status<>'cancelled' ORDER BY id");
            $q->execute([$employeeId,$list['businessDate']]);$jobs=[];
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $job)$jobs[(int)$job['id']]=$job;
            $stops=[];$held=[];$revisions=[];
            foreach($list['deliveries'] as $summary){
                $id=$summary['assignmentId'];$job=$jobs[$id]??null;
                if(!$job)continue;
                // Physical handover already happened even if payment reconciliation is pending.
                if(in_array($job['status'],['delivered','awaiting_reconciliation','completed'],true)||$summary['stage']==='done')continue;
                $street=self::address((string)$job['address'],(string)$job['area']);
                $row=['issue'=>$summary['issue']??null,'stop_id'=>'delivery-'.$id,'assignment_id'=>$id,'order_reference'=>$summary['orderNumber'],
                    'customer_display_name'=>$summary['customerDisplayName'],'area'=>$summary['deliveryArea'],
                    'address_display'=>$street,'coordinates'=>null,'status'=>$summary['stage'],
                    'status_label'=>$summary['displayStatus'],'review_reason'=>$summary['reviewReason'],
                    'pickup_confirmed_at'=>$summary['collectedAt'],'delivery_window'=>$summary['scheduledTimeLocal'],
                    'urgent'=>$summary['dispatchPriority']===1,'payment_label'=>$summary['paymentLabel'],
                    'cod_amount_due_nad'=>$summary['codAmountDueNad'],'sequence'=>null,'is_next'=>false];
                if(in_array($summary['stage'],['to_collect','on_route'],true))$stops[]=$row;else $held[]=$row;
                $revisions[$id]=[(int)$job['version'],$job['updated_at']];
            }
            // Stable assignment-id display order is NOT a dispatcher-approved visit order.
            $sort=static fn($a,$b)=>$a['assignment_id']<=>$b['assignment_id'];usort($stops,$sort);usort($held,$sort);ksort($revisions);
            $routeId='driver-day-'.substr(hash('sha256',$employeeId.'|'.$list['businessDate']),0,24);
            $revision=hash('sha256',json_encode([$routeId,$stops,$held,$revisions],JSON_THROW_ON_ERROR));
            // 52-bit content revision is numeric and JavaScript-safe, NOT a persisted sequence counter.
            // Any future mutation must compare the full revision token inside a DB transaction.
            $data=['route_id'=>$routeId,'version'=>(int)hexdec(substr($revision,0,13)),'revision_token'=>$revision,
                'driver_id'=>$employeeId,'date'=>$list['businessDate'],'timezone'=>'Africa/Windhoek',
                'data_status'=>($stops||$held)?'available':'no_route','remaining_stops'=>count($stops),'held_stops'=>count($held),
                'sequence_status'=>'unconfirmed','next_stop_id'=>null,'stops'=>$stops,'needs_review'=>$held,
                'estimates'=>['available'=>false,'distance_metres'=>null,'duration_seconds'=>null,'updated_at'=>null,'route_version'=>null],
                'map'=>['state'=>'integration_pending','provider'=>'google','location_state'=>'not_requested'],
                'capabilities'=>['map'=>false,'locate'=>false,'optimise'=>false,'save_order'=>false,'navigate_next'=>false],
                'updated_at'=>$now->format('c')];
            if($ownTransaction)$db->commit();
            return $data;
        }catch(\Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
    }

    private static function address(string $street,string $area):?string
    {
        $street=trim($street);
        if(mb_strlen($street)<8||mb_strlen($street)>2000||preg_match('/[\x00-\x1f\x7f]|[a-z]+:\/\//i',$street))return null;
        if(mb_strtolower($street)===mb_strtolower(trim($area)))return null;
        if(preg_match('/^(?:[A-Z]{2}|Namibia)(?:\s*,\s*(?:[A-Z]{2}|Namibia))*$/iD',$street))return null;
        return $street;
    }
}
