<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAssignments.php';
require_once __DIR__.'/DeliveryCustody.php';

/** Phase 03 read model. No writes, external lookups, or customer-note disclosure. */
final class DriverOrderDetailsData
{
    public static function read(\PDO $db,int $employeeId,int $assignmentId,?\DateTimeImmutable $now=null):array
    {
        // Both identity and current assignment are checked, not a client driverId.
        $list=DriverAssignments::read($db,$employeeId,$now,$assignmentId);
        try{$summary=DriverAssignments::find($list,$assignmentId);}
        catch(\DomainException $e){throw new \OutOfBoundsException('Delivery unavailable.');}
        // The shared authorized selector also retains unresolved issues from earlier days.
        $q=$db->prepare("SELECT id,version,source,order_id,customer_mobile,address,area,status,driver_employee_id,additional_delivery FROM delivery_jobs WHERE id=? AND driver_employee_id=? AND status<>'cancelled'");
        $q->execute([$assignmentId,$employeeId]);$job=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$job)throw new \OutOfBoundsException('Delivery unavailable.');
        $q=$db->prepare('SELECT id,event_type,employee_id,metadata_json,created_at FROM delivery_events WHERE delivery_id=? ORDER BY id');
        $q->execute([$assignmentId]);$events=$q->fetchAll(\PDO::FETCH_ASSOC);
        $release=null;$revision=0;$addressApproved=false;
        foreach($events as $e){
            $meta=self::metadata($e);
            if(in_array($e['event_type'],['arranged','partner_arranged'],true))$release=$e;
            if($e['event_type']==='revised'||($e['event_type']==='dispatch_updated'&&($meta['field']??'')==='driver'))$revision=(int)$e['id'];
            // An explicit dispatch arrangement/revision validates its saved contact/address.
            if(in_array($e['event_type'],['arranged','partner_arranged','revised'],true))$addressApproved=true;
        }
        if(!$release)throw new \OutOfBoundsException('Delivery unavailable.');
        $cutoff=max((int)$release['id'],$revision);
        $timeline=self::timeline($events,$summary,$employeeId,$cutoff,$list['businessDate']);
        $stageAt=null;
        foreach($timeline as $event)if($event['state']==='current')$stageAt=$event['occurredAt'];
        $phone=self::phone((string)$job['customer_mobile']);
        $street=trim((string)$job['address']);$area=trim((string)$job['area']);
        // Region-only placeholders and an area repeated as a street are insufficient.
        $validAddress=$addressApproved&&mb_strlen($street)>=8&&mb_strlen($street)<=2000
            &&!preg_match('/^(?:[A-Z]{2}|Namibia)(?:\s*,\s*(?:[A-Z]{2}|Namibia))*$/iD',$street)
            &&mb_strtolower($street)!==mb_strtolower($area);
        $items=[];$duplicateConflict=false;
        if($job['source']==='hambelela'&&$job['order_id']){
            $columns=$db->query('SHOW COLUMNS FROM ops_order_items')->fetchAll(\PDO::FETCH_COLUMN);
            $select='id,product_name,quantity';
            foreach(['sku','woo_order_line_id','woo_variation_id'] as $column)if(in_array($column,$columns,true))$select.=','.$column;
            $q=$db->prepare('SELECT '.$select.' FROM ops_order_items WHERE order_id=? ORDER BY id');$q->execute([$job['order_id']]);
            $seen=[];
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){
                $source=(int)($row['woo_order_line_id']??0);$fingerprint=[$row['product_name'],(string)$row['quantity'],(string)($row['sku']??'')];
                if($source&&isset($seen[$source])){if($seen[$source]===$fingerprint)continue;$duplicateConflict=true;}
                if($source)$seen[$source]=$fingerprint;
                $quantity=is_numeric($row['quantity'])&&(float)$row['quantity']>0?(string)$row['quantity']:null;
                $items[]=['lineId'=>(int)$row['id'],'name'=>trim((string)$row['product_name'])?:'Product name not recorded','sku'=>trim((string)($row['sku']??''))?:null,
                    'variationId'=>(int)($row['woo_variation_id']??0)?:null,'orderedQuantity'=>$quantity,'quantity'=>null,'packSize'=>null,'variant'=>null];
            }
        }
        // The current schema has no immutable parcel line manifest. Never count ordered
        // units as physically released units, especially on additional/partial deliveries.
        $itemNotice=$items?'Order quantities shown for reference. Parcel quantities and any partial handover need dispatch confirmation.':'No parcel item list is recorded. Ask dispatch to confirm the contents.';
        if($duplicateConflict)$itemNotice='Conflicting source item lines need dispatch review. No parcel quantity total is confirmed.';
        $parts=preg_split('/\s+/u',trim($summary['customerDisplayName']));$initials='';
        foreach(array_slice($parts,0,2) as $p)$initials.=mb_strtoupper(mb_substr($p,0,1));
        $paymentStatus=$summary['paymentVerified']?'verified':($summary['codAmountDueNad']!==null?'cod_due':'pending');
        if($summary['paymentVerified']&&$summary['paymentLabel']==='Paid · verified'){
            $q=$db->prepare('SELECT COUNT(DISTINCT payment_method) FROM order_payment_allocations WHERE order_id=? AND amount_cents>0');$q->execute([$job['order_id']]);
            if((int)$q->fetchColumn()>1)$paymentStatus='split';
        }
        if($summary['displayStatus']==='Order needs review'){
            $paymentStatus='unknown';$summary['paymentVerified']=false;$summary['codAmountDueNad']=null;$summary['paymentLabel']='Payment needs review';
        }
        // The list and details use the same verified next step, including older assignments.
        $action=$summary['action'];$actionLabel=$summary['actionLabel'];$handoverNotice=$summary['handoverNotice'];
        return ['action'=>$action,'actionLabel'=>$actionLabel,'handoverNotice'=>$handoverNotice,'version'=>(int)$job['version'],'issue'=>$summary['issue']??null,'assignmentId'=>$assignmentId,'orderId'=>$summary['orderId'],'orderNumber'=>$summary['orderNumber'],'driverId'=>$employeeId,'timezone'=>'Africa/Windhoek','dataStatus'=>'live',
            'customer'=>['displayName'=>$summary['customerDisplayName'],'initials'=>$initials?:'C','phone'=>$phone,'messagingContact'=>null],
            'payment'=>['method'=>$summary['paymentMethod'],'status'=>$paymentStatus,'verified'=>$summary['paymentVerified'],'label'=>$paymentStatus==='split'?'Split payment · verified':$summary['paymentLabel'],'codAmountDueNad'=>$summary['codAmountDueNad']],
            'delivery'=>['stage'=>$summary['stage'],'label'=>$summary['displayStatus'],'reviewReason'=>$summary['reviewReason'],'lastStageChangedAt'=>$stageAt,
                'address'=>['area'=>$area,'street'=>$validAddress?$street:null,'latitude'=>null,'longitude'=>null],
                'navigationAvailable'=>$validAddress&&in_array($summary['stage'],['to_collect','on_route'],true)],
            'items'=>$items,'itemsSource'=>'order_reference_only','itemsNotice'=>$itemNotice,'dispatchedQuantityTotal'=>null,'timeline'=>$timeline,'unreadNotifications'=>null];
    }

    public static function phone(string $value):?string
    {
        $value=preg_replace('/[\s().-]/u','',trim($value));
        // Do not guess an international prefix or accept extensions/URI instructions.
        return preg_match('/^(?:\+[1-9][0-9]{7,14}|0[0-9]{8,10})$/D',$value)?$value:null;
    }
    private static function metadata(array $event):array
    {
        $value=json_decode((string)$event['metadata_json'],true);return is_array($value)?$value:[];
    }
    public static function timestamp(?string $value):?string
    {
        if(!$value)return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$value)return null;
        return $date->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format('c');
    }
    private static function timeline(array $events,array $summary,int $driver,int $cutoff,string $day):array
    {
        $rows=[];$currentType=null;
        if($summary['stage']==='to_collect')$currentType=['arranged','revised','dispatch_updated'];
        elseif($summary['stage']==='on_route')$currentType=['parcel_collected','started'];
        elseif($summary['stage']==='done')$currentType=['delivered','reconciled'];
        // Payment/source exceptions have no trustworthy delivery-stage timestamp.
        elseif($summary['displayStatus']==='Delivery exception')$currentType=['failed'];
        elseif(!empty($summary['issue']['blocking']))$currentType=['delivery_issue_reported','dispatch_resolution_recorded'];
        elseif(in_array($summary['displayStatus'],['Handover under review','Handover unconfirmed'],true))$currentType=['delivered'];
        $currentIndex=null;
        foreach($events as $e){
            if((int)$e['id']<$cutoff)continue;
            $type=$e['event_type'];$m=self::metadata($e);$label=null;$detail='';
            if($type==='arranged'||$type==='partner_arranged'){$label='Delivery assigned';$detail='Released by dispatch. Payment is shown separately above.';}
            elseif($type==='revised'||($type==='dispatch_updated'&&($m['field']??'')==='driver')){$label='Assignment updated';$detail='Dispatch revised this assignment.';}
            elseif($type==='parcel_collected'&&(int)($m['driver_id']??0)===$driver){$label='Parcel collected';$detail='Physical handover to your driver profile recorded.';}
            elseif($type==='started'&&(int)$e['employee_id']===$driver){$label='Delivery started';$detail='Start recorded; this alone does not confirm shop handover.';}
            elseif($type==='delivered'){
                $confirmed=!empty($m['delivery_payment_confirmed'])||(!empty($m['manual'])&&mb_strlen((string)($m['reason']??''))>=10);
                $label=$confirmed?'Customer handover recorded':'Handover requires review';$detail='Payment and completion are assessed separately.';
            }
            elseif($type==='reconciled'){$label='Reconciliation recorded';$detail='Dispatch recorded a reconciliation event.';}
            elseif($type==='failed'){$label='Delivery exception';$detail='Contact dispatch before proceeding.';}
            elseif($type==='delivery_issue_reported'){$label='Issue reported';$detail='Incident '.($m['incident_id']??'').' · Awaiting dispatch review.';}
            elseif($type==='dispatch_issue_acknowledged'){$label='Dispatch acknowledged issue';$detail='Review recorded by dispatch.';}
            elseif($type==='dispatch_resolution_recorded'){$label=DeliveryIssueState::DECISIONS[$m['decision']]??'Dispatch response';$detail=$m['instruction']??'';}
            if(!$label)continue;
            $at=self::timestamp($e['created_at']);$time=$at?(new \DateTimeImmutable($at))->format(substr($at,0,10)===$day?'H:i':'j M Y · H:i'):'Time not recorded';
            $rows[]=['eventId'=>(int)$e['id'],'eventType'=>$type,'label'=>$label,'detail'=>$detail,'occurredAt'=>$at,'timeLabel'=>$time,'state'=>'complete'];
            if($currentType&&in_array($type,$currentType,true))$currentIndex=count($rows)-1;
        }
        if($currentIndex!==null)$rows[$currentIndex]['state']='current';
        return $rows;
    }
}
