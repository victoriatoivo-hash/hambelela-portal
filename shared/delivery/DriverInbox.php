<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/ErrandInbox.php';
require_once __DIR__.'/DispatchInbox.php';
require_once __DIR__.'/CashInbox.php';
final class DriverInbox
{
    // Both recipient and CURRENT assignment are checked on every read and write.
    private const SCOPE=" FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN delivery_jobs j ON j.id=n.related_id LEFT JOIN delivery_outbox o ON n.deduplication_key=CONCAT('delivery:',o.event_key) AND o.delivery_id=j.id AND o.recipient_employee_id=r.employee_id WHERE r.employee_id=? AND j.driver_employee_id=? AND n.module='delivery' AND n.related_type='delivery_job' AND r.cleared_at IS NULL";
    private static function guard(array $a):int {
        if(($a['role']??'')!=='delivery_driver'||!DeliveryPolicy::can($a,'delivery_view_assigned'))throw new \DomainException('Driver access required.');
        return (int)$a['id'];
    }
    private static function kind():string {
        return "CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload_json,'$.kind'))='assignment' AND o.event_key LIKE 'assigned:%:%' THEN 'reassigned' WHEN o.event_key=CONCAT('assigned:',j.id) THEN 'assignment' ELSE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.payload_json,'$.kind')),'info') END";
    }
    public static function bucket(string $kind,bool $urgent):string {
        if($urgent||in_array($kind,['cancelled','failed','deadline_late'],true))return 'alerts';
        return in_array($kind,['assignment','reassigned'],true)?'orders':'updates';
    }
    public static function feed(\PDO $db,array $a,int $before=0,string $tab='all'):array {
        $delivery=self::deliveryFeed($db,$a,$before,$tab);$errands=ErrandInbox::feed($db,self::guard($a),$before,$tab);
        $chat=DispatchInbox::feed($db,self::guard($a),$before,$tab);
        $cash=CashInbox::feed($db,self::guard($a),$before,$tab);$chat['items']=array_merge($chat['items'],$cash['items']);$chat['more']=$chat['more']||$cash['more'];$chat['through']=max($chat['through'],$cash['through']);foreach($chat['counts'] as $k=>$v)$chat['counts'][$k]+=$cash['counts'][$k];
        $errands['items']=array_merge($errands['items'],$chat['items']);$errands['more']=$errands['more']||$chat['more'];$errands['through']=max($errands['through'],$chat['through']);foreach($errands['counts'] as $k=>$v)$errands['counts'][$k]+=$chat['counts'][$k];
        $items=array_merge($delivery['items'],$errands['items']);usort($items,static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);
        $delivery['more']=$delivery['more']||$errands['more']||count($items)>40;$delivery['items']=array_slice($items,0,40);
        foreach($delivery['counts'] as $k=>$v)$delivery['counts'][$k]+=$errands['counts'][$k];$delivery['through']=max($delivery['through'],$errands['through']);return $delivery;
    }
    private static function deliveryFeed(\PDO $db,array $a,int $before=0,string $tab='all'):array {
        $id=self::guard($a);$k=self::kind();
        $max=$db->prepare('SELECT MAX(n.id),MAX(CASE WHEN ('.$k.") IN ('assignment','reassigned') AND r.read_at IS NULL THEN n.id END)".self::SCOPE);$max->execute([$id,$id]);[$through,$assignment]=$max->fetch(\PDO::FETCH_NUM);
        $g=$db->prepare("SELECT $k kind,n.priority,COUNT(*) qty".self::SCOPE." AND n.id<=? AND r.read_at IS NULL GROUP BY kind,n.priority");
        $g->execute([$id,$id,(int)$through]);$counts=['all'=>0,'orders'=>0,'updates'=>0,'alerts'=>0];foreach($g as $r){$n=(int)$r['qty'];$counts['all']+=$n;$counts[self::bucket($r['kind'],$r['priority']==='urgent')]+=$n;}
        $bucket="CASE WHEN n.priority='urgent' OR ($k) IN ('cancelled','failed','deadline_late') THEN 'alerts' WHEN ($k) IN ('assignment','reassigned') THEN 'orders' ELSE 'updates' END";
        $params=[$id,$id,(int)$through];$where=' AND n.id<=?';if($before>0){$where.=' AND n.id<?';$params[]=$before;}if($tab!=='all'){$where.=" AND ($bucket)=?";$params[]=$tab;}
        $q=$db->prepare("SELECT n.id,n.related_id job,n.title,n.priority,UNIX_TIMESTAMP(n.created_at) epoch,r.read_at,$k kind,COALESCE(NULLIF((SELECT order_number FROM ops_orders WHERE id=j.order_id),''),j.public_reference) ref".self::SCOPE.$where.' ORDER BY n.id DESC LIMIT 41');
        $q->execute($params);$items=$q->fetchAll(\PDO::FETCH_ASSOC);$more=count($items)>40;if($more)array_pop($items);
        return ['items'=>$items,'more'=>$more,'counts'=>$counts,'through'=>(int)$through,'latest_assignment'=>$assignment?['id'=>(int)$assignment,'kind'=>'assignment','read_at'=>null]:null,'actor'=>$id];
    }
    public static function paymentChanged(\PDO $db,int $order,int $audit,array $previous,array $current):void {
        // Source/version metadata changes alone do not constitute a payment update.
        $terms=static function(array $rows):array{$v=array_map(static fn($r)=>[(string)$r['method'],(int)$r['amount_cents'],(string)($r['transaction_reference']??'')],$rows);sort($v);return $v;};
        if($audit<=0||$terms($previous)===$terms($current))return;
        if(!$db->inTransaction())throw new \LogicException('Payment audit transaction required.');
        $db->prepare("INSERT IGNORE INTO delivery_outbox(event_key,delivery_id,recipient_employee_id,payload_json,next_attempt_at) SELECT CONCAT('order-payment:',?,':',j.id),j.id,j.driver_employee_id,JSON_OBJECT('kind','payment'),UTC_TIMESTAMP() FROM delivery_jobs j WHERE j.order_id=? AND j.source='hambelela' AND j.status IN ('ready','out_for_delivery','failed') AND j.driver_employee_id IS NOT NULL")->execute([$audit,$order]);
    }
    public static function mark(\PDO $db,array $a,int $id,bool $all):void {
        ErrandInbox::mark($db,self::guard($a),$id,$all);
        CashInbox::mark($db,self::guard($a),$id,$all);
        $actor=self::guard($a);if($id<1)throw new \DomainException('Notification required.');
        $s=$db->prepare("UPDATE notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN delivery_jobs j ON j.id=n.related_id SET r.read_at=UTC_TIMESTAMP() WHERE r.read_at IS NULL AND r.cleared_at IS NULL AND r.employee_id=? AND j.driver_employee_id=? AND n.module='delivery' AND n.related_type='delivery_job' AND n.id".($all?'<=?':'=?'));$s->execute([$actor,$actor,$id]);
    }
}
