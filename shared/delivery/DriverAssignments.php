<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/OrderBalanceAdapter.php';
require_once __DIR__.'/DeliveryIssueState.php';
require_once __DIR__.'/DeliveryCustody.php';

/** Read-only, current Windhoek-day selector shared by the driver Dashboard and Deliveries. */
final class DriverAssignments
{
    public static function read(\PDO $db,int $employeeId,?\DateTimeImmutable $now=null,?int $assignmentId=null):array
    {
        EmployeeDriverSession::actor($db,$employeeId);
        $now=($now??new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        $date=$now->format('Y-m-d');
        $orderColumns=$db->query('SHOW COLUMNS FROM ops_orders')->fetchAll(\PDO::FETCH_COLUMN);
        $refundColumn=in_array('refund_total',$orderColumns,true)?'o.refund_total':'NULL';
        $paidTickColumn=in_array('portal_paid_confirmed',$orderColumns,true)?'o.portal_paid_confirmed':'NULL';
        $archiveColumn=in_array('archived_at',$orderColumns,true)?'o.archived_at':'NULL';$pendingIssue=DeliveryIssueState::pendingPredicate();
        $assignmentWhere=$assignmentId===null?'':' AND j.id=?';
        $q=$db->prepare("SELECT j.*,o.id linked_order,o.order_number,o.status order_status,o.payment_status,o.payment_method,o.total_amount,o.deleted_at,$archiveColumn archived_at,$refundColumn refund_total,$paidTickColumn portal_paid_confirmed,
            (SELECT COUNT(*) FROM ops_order_items i WHERE i.order_id=j.order_id) item_count,
            (SELECT MAX(e.id) FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type IN ('arranged','partner_arranged')) release_event,
            (SELECT e.metadata_json FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type IN ('arranged','partner_arranged') ORDER BY e.id DESC LIMIT 1) release_json,
            (SELECT MAX(e.id) FROM delivery_events e WHERE e.delivery_id=j.id AND (e.event_type='revised' OR (e.event_type='dispatch_updated' AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.field'))='driver'))) revision_event,
            (SELECT MAX(e.id) FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type='parcel_collected' AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.driver_id'))=CAST(j.driver_employee_id AS CHAR)) pickup_event,
            (SELECT MAX(e.created_at) FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type='parcel_collected' AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.driver_id'))=CAST(j.driver_employee_id AS CHAR)) pickup_at,
            (SELECT e.metadata_json FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type='delivered' ORDER BY e.id DESC LIMIT 1) handover_json,
            (SELECT e.metadata_json FROM delivery_events e WHERE e.delivery_id=j.id AND e.event_type='deadline_updated' ORDER BY e.id DESC LIMIT 1) deadline_json,
            (SELECT COUNT(*) FROM delivery_receipts r WHERE r.delivery_id=j.id AND r.status IN ('collected','issue')) pending_receipts,
            (SELECT COUNT(*) FROM delivery_receipts r WHERE r.delivery_id=j.id AND r.status='reversed') reversed_receipts
            FROM delivery_jobs j LEFT JOIN ops_orders o ON o.id=j.order_id
            WHERE j.driver_employee_id=? AND (j.scheduled_date=? OR $pendingIssue) AND j.status<>'cancelled' $assignmentWhere ORDER BY j.id");
        $params=[$employeeId,$date];if($assignmentId!==null)$params[]=$assignmentId;
        $q->execute($params);$deliveries=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){
            // A job with no dispatch audit is not a released assignment.
            if(!$row['release_event'])continue;
            $deliveries[]=self::present($db,$row);
        }
        usort($deliveries,static function($a,$b){
            $rank=['on_route'=>0,'to_collect'=>1,'exception'=>2,'done'=>3];
            return [$rank[$a['stage']],-($a['dispatchPriority']??0),$a['scheduledSort']??PHP_INT_MAX,$a['assignmentId']]
                <=>[$rank[$b['stage']],-($b['dispatchPriority']??0),$b['scheduledSort']??PHP_INT_MAX,$b['assignmentId']];
        });
        $counts=['all'=>count($deliveries),'toCollect'=>0,'onRoute'=>0,'done'=>0,'exceptions'=>0];
        foreach($deliveries as $d){$key=['to_collect'=>'toCollect','on_route'=>'onRoute','done'=>'done','exception'=>'exceptions'][$d['stage']];$counts[$key]++;}
        return ['businessDate'=>$date,'dateLabel'=>$now->format('l j F'),'timezone'=>'Africa/Windhoek','driverId'=>$employeeId,'counts'=>$counts,'deliveries'=>$deliveries,'dataStatus'=>$deliveries?'live':'empty','lastUpdatedAt'=>$now->format('c')];
    }

    public static function find(array $data,int $assignmentId):array
    {
        foreach($data['deliveries'] as $row)if($row['assignmentId']===$assignmentId)return $row;
        throw new \DomainException('Delivery unavailable.');
    }

    private static function present(\PDO $db,array $r):array
    {
        $release=json_decode((string)$r['release_json'],true)?:[];
        $handover=json_decode((string)$r['handover_json'],true)?:[];
        $deadline=(json_decode((string)$r['deadline_json'],true)?:[])['deadline']??[];
        $picked=(int)$r['pickup_event']>max((int)$r['revision_event'],(int)$r['release_event']);
        $handoverProof=!empty($r['delivered_at'])&&(!empty($handover['delivery_payment_confirmed'])||(!empty($handover['manual'])&&mb_strlen((string)($handover['reason']??''))>=10));
        $stage='exception';$label='Needs dispatch review';$reason='Assignment state needs confirmation.';
        if($r['status']==='completed'&&$handoverProof&&!empty($r['completed_at'])&&(int)$r['pending_receipts']===0){$stage='done';$label='Delivered';$reason='';}
        elseif(in_array($r['status'],['delivered','awaiting_reconciliation','completed'],true)){$stage=$handoverProof?'done':'exception';$label=$handoverProof?'Delivered · reconciliation pending':'Handover unconfirmed';$reason=$handoverProof?'Customer handover is recorded; financial reconciliation remains separate. Do not deliver again.':'A completed status alone is not proof of customer handover.';}
        elseif($r['status']==='failed'){$label='Delivery exception';$reason='Contact dispatch about the failed or rescheduled delivery.';}
        elseif($r['status']==='out_for_delivery'&&$picked){$stage='on_route';$label='Out for delivery';$reason='';}
        elseif($r['status']==='ready'&&$picked){$stage='to_collect';$label='Ready to start';$reason='';}
        elseif($r['status']==='ready'){$stage='to_collect';$label='At store';$reason='';}
        elseif($r['status']==='out_for_delivery'){$label='Pickup unconfirmed';$reason='Delivery was started, but shop handover to this driver is not recorded.';}
        if($r['source']==='partner'&&$stage==='to_collect'){$stage='exception';$label='Partner pickup unconfirmed';$reason='A verified partner pickup source is not available. This is not a collection from the Hambelela shop.';}
        if((int)$r['reversed_receipts']>0||($r['source']==='hambelela'&&(!$r['linked_order']||$r['deleted_at']||$r['archived_at']||(float)$r['refund_total']>0||in_array(strtolower((string)$r['order_status']),['cancelled','canceled','refunded','failed','trash','deleted'],true)||in_array(strtolower((string)$r['payment_status']),['refunded','reversed'],true)))){
            $stage='exception';$label='Order needs review';$reason='The source order was removed, cancelled, refunded or failed. Check with dispatch before proceeding.';
        }
        $scheduled=null;$sort=null;
        try{
            if(in_array($deadline['mode']??'', ['before','window','appointment'],true)&&!empty($deadline['end_at'])){
                $end=new \DateTimeImmutable($deadline['end_at'],new \DateTimeZone('UTC'));$end=$end->setTimezone(new \DateTimeZone('Africa/Windhoek'));
                $sort=$end->getTimestamp();$scheduled=($deadline['mode']==='before'?'Before ':'').$end->format('g:i A');
                if($deadline['mode']==='window'&&!empty($deadline['start_at'])){$start=(new \DateTimeImmutable($deadline['start_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'));$scheduled=$start->format('g:i').'–'.$end->format('g:i A');}
            }
        }catch(\Exception $e){$scheduled=null;$sort=null;}
        $parts=preg_split('/\s+/u',trim((string)$r['customer_name']));$customer=$parts[0]?:'Customer';if(count($parts)>1)$customer.=' '.mb_substr(end($parts),0,1).'.';
        $payment=self::payment($db,$r,$release);
        if($stage==='done'&&!$payment['paymentVerified']){$label='Delivered · payment review';$reason='Customer handover is recorded; payment review remains with dispatch. Do not deliver again.';}
        // Dispatch has already released this assignment. Payment uncertainty is
        // financial information, not a reason to hide acceptance/pickup/start.
        // Keep source cancellations, custody and explicit operational issues above.
        $issue=DeliveryIssueState::latest($db,(int)$r['id']);
        if($issue&&$issue['blocking']){$stage='exception';$label='Issue reported · Awaiting dispatch';$reason=$issue['latest_decision']['instruction']??'Dispatch must approve the next step. The order remains open.';}
        $action=null;$actionLabel=null;$handoverNotice=null;
        if($r['status']==='ready'&&$stage==='to_collect'){
            $custody=DeliveryCustody::read($db,$r);
            if(!$custody['acceptance']){$action='accept';$actionLabel='Accept order';}
            elseif(!$custody['handover'])$handoverNotice='Waiting for Front Desk to record the parcel handover. This updates automatically.';
            else{$action='start';$actionLabel='Start delivery';}
        }
        return ['action'=>$action,'actionLabel'=>$actionLabel,'handoverNotice'=>$handoverNotice,'issue'=>$issue?['reference'=>$issue['incident_id'],'blocking'=>$issue['blocking'],'instruction'=>$issue['latest_decision']['instruction']??null]:null,'assignmentId'=>(int)$r['id'],'orderId'=>$r['order_id']?(int)$r['order_id']:null,'orderNumber'=>$r['order_number']?:($r['partner_reference']?:$r['public_reference']),
            'customerDisplayName'=>$customer,'deliveryArea'=>trim((string)$r['area'])?:'Area not recorded','itemCount'=>$r['source']==='hambelela'?(int)$r['item_count']:null,
            'stage'=>$stage,'displayStatus'=>$label,'reviewReason'=>$reason,'dispatchPriority'=>!empty($r['urgent'])?1:null,'routeSequence'=>null,'isNextStop'=>false,
            'scheduledTimeLocal'=>$scheduled,'scheduledSort'=>$sort,'distanceKm'=>null,'collectedAt'=>$picked?$r['pickup_at']:null,'deliveredAt'=>$r['delivered_at'],
            'proofOfDeliveryStatus'=>$handoverProof?'recorded':'missing']+$payment;
    }

    private static function method(string $method):string
    {
        $value=strtolower(trim($method));
        if(in_array($value,['cash','cash on delivery','cod'],true))return 'CASH';
        if(in_array($value,['eft','bacs','bank transfer','bank_transfer'],true))return 'EFT';
        if(in_array($value,['dpo','dpo pay','dpo_pay'],true))return 'DPO';
        return $value===''?'UNKNOWN':'OTHER';
    }

    private static function payment(\PDO $db,array $r,array $release):array
    {
        $result=['paymentMethod'=>'UNKNOWN','paymentVerified'=>false,'codAmountDueNad'=>null,'paymentLabel'=>'Payment pending'];
        try{
            $allocations=[];$allocated=0;$methods=[];
            if($r['source']==='hambelela'){
                $q=$db->prepare('SELECT amount_cents,payment_method FROM order_payment_allocations WHERE order_id=?');$q->execute([$r['order_id']]);$allocations=$q->fetchAll(\PDO::FETCH_ASSOC);
                foreach($allocations as $a){if((int)$a['amount_cents']<0)throw new \DomainException('Reversed allocation.');if((int)$a['amount_cents']>0){$allocated+=(int)$a['amount_cents'];$methods[self::method($a['payment_method'])]=true;}}
                // Do not inherit OrderDispatchBalance's legacy paid-status fallback as verified money.
                if(!$allocations&&$r['payment_status']==='paid'&&DeliveryPolicy::cents((string)$r['total_amount'])>0
                    &&!(($release['order_dispatch']['payment_policy']??'')==='portal_cash_confirmation'&&!empty($r['portal_paid_confirmed'])))throw new \DomainException('No verified allocation.');
            }
            $due=array_sum((new OrderBalanceAdapter())->read($db,$r));
            if((int)$r['pending_receipts']>0){$result['paymentLabel']='Payment under review';return $result;}
            if($due===0){
                $result['paymentVerified']=true;
                $method=count($methods)===1?array_key_first($methods):'OTHER';
                $result['paymentMethod']=$method;
                $result['paymentLabel']=$allocated>0?(in_array($method,['EFT','DPO','CASH'],true)?($method==='CASH'?'Cash':$method).' paid':'Paid · verified'):'No collection due';
            }elseif(($release['payment_arrangement']??null)==='cod'){
                $result['paymentMethod']='CASH';$result['codAmountDueNad']=$due/100;$result['paymentLabel']='Cash N$'.number_format($due/100,2);
            }
        }catch(\DomainException $e){$result['paymentLabel']='Payment needs review';}
        return $result;
    }
}
