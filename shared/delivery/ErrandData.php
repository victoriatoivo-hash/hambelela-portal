<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/FrontSession.php';
require_once __DIR__.'/ProofStorage.php';

/** Same three canonical errand tables as the existing front-desk collection workflow. */
final class ErrandData
{
    public static function actor(\PDO $db,int $id):array
    {
        $s=$db->prepare('SELECT r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?');$s->execute([$id]);
        if($s->fetchColumn()==='delivery_driver')return EmployeeDriverSession::actor($db,$id);
        $a=FrontSession::actor($db,$id);
        if(!DeliveryPolicy::can($a,'delivery_arrange'))throw new \DomainException('Errand dispatch access required.');
        return $a;
    }
    public static function query(\PDO $db,string $sql,array $args=[]):array {$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    public static function fingerprint(array $j):string {return hash('sha256',json_encode(array_intersect_key($j,array_flip(['driver_id','place','contact_name','contents'])),JSON_THROW_ON_ERROR));}
    public static function row(\PDO $db,int $id):array
    {
        $rows=self::query($db,"SELECT j.*,COALESCE(d.revision,1) revision,COALESCE(d.state,'open') state,d.amount,COALESCE(d.payer,'') payer,COALESCE(d.note,'') note FROM delivery_errands j LEFT JOIN delivery_errand_details d ON d.errand_id=j.id WHERE j.id=?",[$id]);
        if(!$rows)throw new \DomainException('Errand unavailable or access removed.');return $rows[0];
    }
    public static function authorized(\PDO $db,int $employee,int $id):array
    {
        $a=self::actor($db,$employee);$j=self::row($db,$id);
        if($a['role']==='delivery_driver'&&(int)$j['driver_id']!==$employee)throw new \DomainException('Errand unavailable or access removed.');
        return $j;
    }
    public static function events(\PDO $db,int $id):array
    {
        $events=self::query($db,'SELECT ev.id,ev.action,ev.actor_id,ev.created_at,ev.after_json,e.full_name actor_name FROM delivery_errand_events ev LEFT JOIN ops_employees e ON e.id=ev.actor_id WHERE ev.errand_id=? ORDER BY ev.id',[$id]);
        foreach($events as &$e){$e['data']=json_decode($e['after_json'],true,512,JSON_THROW_ON_ERROR);unset($e['after_json']);}return $events;
    }
    public static function policy(array $j,array $events):?array
    {
        $p=null;foreach($events as $e)if($e['action']==='policy_set')$p=$e['data'];
        return $p&&hash_equals($p['fingerprint']??'',self::fingerprint($j))?$p['policy']:null;
    }
    public static function status(array $j):string {return $j['state']==='cancelled'?'cancelled':($j['collected_at']?'completed':($j['state']==='blocked'?'needs_review':($j['state']==='started'?'in_progress':'assigned')));}
    public static function local(?string $utc):?string {return $utc?(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format('d M Y, H:i'):null;}
    public static function detail(\PDO $db,int $employee,int $id):array
    {
        $j=self::authorized($db,$employee,$id);$events=self::events($db,$id);$policy=self::policy($j,$events);$started=null;$result=null;$proof=[];
        foreach($events as $e){if($e['action']==='started')$started=$e['created_at'];if($e['action']==='edit'||$e['action']==='reopen')$started=null;if(in_array($e['action'],['completed','issue_reported','dispatch_resumed','dispatch_cancelled'],true))$result=$e;foreach($e['data']['evidence']??[] as $f)$proof[]=['id'=>$f['id'],'kind'=>$f['kind'],'event_id'=>$e['id']];}
        return ['id'=>$id,'driver_id'=>(int)$j['driver_id'],'reference'=>'ER-'.str_pad((string)$id,3,'0',STR_PAD_LEFT),'revision'=>(int)$j['revision'],'status'=>self::status($j),'title'=>$policy['title']??$j['contents'],'place'=>$j['place'],'contact'=>$j['contact_name'],'instructions'=>$j['contents'],'policy'=>$policy,'started_at'=>self::local($started),'completed_at'=>self::local($j['collected_at']),'overdue'=>$policy&&$policy['due_at']&&strtotime($policy['due_at'].' UTC')<time()&&!$j['collected_at'],'due'=>self::local($policy['due_at']??null),'payment_state'=>$j['payment_state'],'requested_amount'=>$j['amount'],'payment_instructions'=>$j['payer'],'history'=>array_map(static fn($e)=>['id'=>$e['id'],'action'=>$e['action'],'at'=>self::local($e['created_at']),'actor'=>$e['actor_name']??'Employee','note'=>$e['data']['notes']??$e['data']['note']??''],$events),'result'=>$result?['id'=>$result['id'],'action'=>$result['action'],'at'=>self::local($result['created_at']),'notes'=>$result['data']['notes']??'','actual'=>$result['data']['actual']??null,'recipient'=>$result['data']['recipient']??null,'items'=>$result['data']['policy']['items']??null]:null,'evidence'=>$proof];
    }
    public static function week(?\DateTimeImmutable $now=null):array
    {
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        $start=$now->setTime(0,0)->modify('-'.((int)$now->format('N')-1).' days');
        return [$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$start->modify('+7 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }
    public static function board(\PDO $db,int $employee):array
    {
        $a=self::actor($db,$employee);$driver=$a['role']==='delivery_driver';[$from,$to]=self::week();
        $rows=self::query($db,"SELECT j.id FROM delivery_errands j LEFT JOIN delivery_errand_details d ON d.errand_id=j.id WHERE ".($driver?'j.driver_id=? AND ':'')."COALESCE(d.state,'open')<>'cancelled' AND (j.collected_at IS NULL OR (j.collected_at>=? AND j.collected_at<?)) ORDER BY j.id",$driver?[$employee,$from,$to]:[$from,$to]);
        $items=[];$counts=['assigned'=>0,'in_progress'=>0,'completed'=>0];foreach($rows as $row){$d=self::detail($db,$employee,(int)$row['id']);$counts[$d['status']==='needs_review'?'in_progress':$d['status']]++;$items[]=$d;}
        usort($items,static function($a,$b){$x=$a['policy'];$y=$b['policy'];return (($x['dispatch_order']??PHP_INT_MAX)<=>($y['dispatch_order']??PHP_INT_MAX))?:((int)($y['priority']??false)<=>(int)($x['priority']??false))?:(($x['due_at']??'9999')<=>($y['due_at']??'9999'))?:($a['id']<=>$b['id']);});
        $requests=self::query($db,'SELECT * FROM delivery_errand_requests'.($driver?' WHERE requested_by=?':'')." ORDER BY id DESC LIMIT 100",$driver?[$employee]:[]);
        foreach($requests as &$r){$r['history']=self::query($db,'SELECT actor_id,action,metadata_json,created_at FROM delivery_errand_request_events WHERE request_id=? ORDER BY id',[$r['id']]);foreach($r['history'] as &$event){$event['data']=json_decode($event['metadata_json'],true);unset($event['metadata_json']);}}
        $drivers=$driver?[]:self::query($db,"SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name");
        return ['actor'=>$employee,'driver'=>$driver,'drivers'=>$drivers,'counts'=>$counts,'items'=>$items,'requests'=>$requests,'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('j F'),'week'=>[$from,$to],'pending'=>$counts['assigned']+$counts['in_progress']];
    }
}
