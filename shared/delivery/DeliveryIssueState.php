<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Immutable delivery_events are the sole issue source; no second status column. */
final class DeliveryIssueState
{
    public const REASONS=['not_answering'=>'Customer not answering','wrong_address'=>'Wrong or missing address','unavailable'=>'Customer unavailable','other'=>'Other issue'];
    public const DECISIONS=['retry_today'=>'Retry today','request_address'=>'Request updated address','reschedule'=>'Customer reschedule','return_shop'=>'Return to shop','escalate'=>'Escalate / other'];
    public static function row(\PDO $db,string $sql,array $args=[]):?array {$s=$db->prepare($sql);$s->execute($args);return $s->fetch(\PDO::FETCH_ASSOC)?:null;}
    public static function latest(\PDO $db,int $id):?array
    {
        $r=self::row($db,"SELECT * FROM delivery_events WHERE delivery_id=? AND event_type='delivery_issue_reported' ORDER BY id DESC LIMIT 1",[$id]);
        if(!$r)return null;
        $issue=json_decode($r['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        $issue['event_id']=(int)$r['id'];$issue['reported_at']=$r['created_at'];$issue['reported_by']=(int)$r['employee_id'];$issue['blocking']=true;$issue['decisions']=[];$issue['acknowledged_at']=null;
        $q=$db->prepare("SELECT * FROM delivery_events WHERE delivery_id=? AND id>? AND event_type IN ('dispatch_issue_acknowledged','dispatch_resolution_recorded') ORDER BY id");$q->execute([$id,$r['id']]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $e){
            $m=json_decode($e['metadata_json'],true,512,JSON_THROW_ON_ERROR);
            if(($m['incident_id']??'')!==$issue['incident_id'])continue;
            if($e['event_type']==='dispatch_issue_acknowledged'){$issue['acknowledged_at']=$e['created_at'];$issue['acknowledged_by']=(int)$e['employee_id'];continue;}
            $m['created_at']=$e['created_at'];$m['employee_id']=(int)$e['employee_id'];$issue['decisions'][]=$m;$issue['blocking']=empty($m['resume_authorized']);
        }
        $issue['latest_decision']=$issue['decisions']?end($issue['decisions']):null;
        return $issue;
    }
    public static function assertClear(\PDO $db,int $id):void
    {
        if(self::latest($db,$id)['blocking']??false)throw new \DomainException('Issue reported. Await dispatch approval before starting or completing this delivery.');
    }
    /** Same event projection as latest(), for retaining held jobs across day boundaries. Alias j is fixed. */
    public static function pendingPredicate():string
    {
        return "EXISTS(SELECT 1 FROM delivery_events i WHERE i.delivery_id=j.id AND i.event_type='delivery_issue_reported' AND i.id=(SELECT MAX(ii.id) FROM delivery_events ii WHERE ii.delivery_id=j.id AND ii.event_type='delivery_issue_reported') AND NOT EXISTS(SELECT 1 FROM delivery_events r WHERE r.delivery_id=j.id AND r.id>i.id AND r.event_type='dispatch_resolution_recorded' AND JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.incident_id'))=JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,'$.incident_id')) AND JSON_UNQUOTE(JSON_EXTRACT(r.metadata_json,'$.resume_authorized'))='true'))";
    }
    public static function text(string $s):string
    {
        $s=trim($s);
        if(mb_strlen($s)<12||mb_strlen($s)>1500||preg_match_all('/[\p{L}\p{N}]/u',$s)<8)throw new \DomainException('Add useful supporting details (12–1500 characters).');
        return $s;
    }
    public static function report(array $input):array
    {
        $reason=(string)($input['reason']??'');if(!isset(self::REASONS[$reason]))throw new \DomainException('Select what happened.');
        $contact=($input['contact_attempted']??'')==='1';
        if(in_array($reason,['not_answering','unavailable'],true)&&!$contact)throw new \DomainException('Confirm that you attempted to contact the customer.');
        return ['reason'=>$reason,'notes'=>self::text((string)($input['notes']??'')),'contact_attempted'=>$contact,'urgent'=>($input['urgent']??'')==='1'];
    }
    /** Caller already holds the job lock. Keep lock order job -> source order. */
    public static function assertOpen(\PDO $db,array $job,int $version):void
    {
        if(!$db->inTransaction())throw new \LogicException('Issue action requires a locked delivery.');
        if((int)$job['version']!==$version)throw new \DomainException('Delivery changed. Reload and review its current details.');
        if(!in_array($job['status'],['ready','out_for_delivery'],true)||!empty($job['delivered_at'])||!empty($job['completed_at']))throw new \DomainException('This delivery is no longer open for an issue report or decision.');
        if($job['source']==='hambelela'){
            $o=self::row($db,'SELECT * FROM ops_orders WHERE id=? FOR UPDATE',[$job['order_id']]);
            if(!$o||!empty($o['deleted_at'])||!empty($o['archived_at'])||(float)($o['refund_total']??0)>0||in_array(strtolower((string)$o['status']),['cancelled','canceled','refunded','failed','trash','deleted'],true)||in_array(strtolower((string)$o['payment_status']),['refunded','reversed'],true))throw new \DomainException('Source order is unavailable or locked for review. Contact dispatch.');
        }
    }
    public static function localTime(string $utc):string {return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format('d M Y, H:i');}
}
