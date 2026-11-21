<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAssignments.php';
require_once __DIR__.'/FrontSession.php';
final class IssueContext
{
    public static function read(\PDO $db,array $actor,array $job):array
    {
        if(!DeliveryPolicy::canView($actor,$job))throw new \DomainException('Delivery unavailable.');
        $driver=$actor['role']==='delivery_driver';$id=(int)$job['id'];$issue=DeliveryIssueState::latest($db,$id);$blocked=null;
        if(!in_array($job['status'],['ready','out_for_delivery'],true)||$job['delivered_at']||$job['completed_at'])$blocked='This delivery is closed or needs dispatch review. No new issue can be reported.';
        if($driver&&!$blocked){try{$row=DriverAssignments::find(DriverAssignments::read($db,(int)$actor['id'],null,$id),$id);if(!in_array($row['stage'],['to_collect','on_route'],true))$blocked=$row['reviewReason'];}catch(\DomainException $e){$blocked='This delivery is not in your current day. Contact dispatch.';}}
        $order=DeliveryIssueState::row($db,'SELECT order_number FROM ops_orders WHERE id=?',[$job['order_id']]);
        if($issue){
            $person=DeliveryIssueState::row($db,'SELECT full_name FROM ops_employees WHERE id=?',[$issue['reported_by']]);$issue['driver_name']=$person['full_name']??'Driver';
            $notice=DeliveryIssueState::row($db,'SELECT COUNT(*) total,SUM(delivered_at IS NOT NULL) delivered FROM delivery_outbox WHERE delivery_id=? AND event_key LIKE ?',[$id,'issue:'.$issue['event_id'].':%']);
            $issue['dispatch_delivered']=(int)($notice['delivered']??0)>0;
        }
        return ['reference'=>$order['order_number']??$job['public_reference'],'name'=>$job['customer_name'],'version'=>(int)$job['version'],'driver'=>$driver,'can_decide'=>!$driver&&DeliveryPolicy::can($actor,'delivery_arrange')&&!$blocked,'blocked'=>$blocked,'incident'=>$issue];
    }
}
