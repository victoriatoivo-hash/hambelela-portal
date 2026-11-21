<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/DriverInbox.php';

/** A presentation adapter for the existing inbox, not another notification store. */
final class DriverNotificationsData
{
    public function __construct(private \PDO $db,private string $key) {if(strlen($key)<32)throw new \LogicException('Cursor key required.');}
    private function rows(string $sql,array $args=[]):array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    private function token(int $employee,string $filter,int $before,int $through,string $purpose):string {
        $v=base64_encode(json_encode([$employee,$filter,$before,$through,$purpose,time()+1800],JSON_THROW_ON_ERROR));
        return $v.'.'.hash_hmac('sha256',$v,$this->key);
    }
    private function decode(string $token,int $employee,string $filter,string $purpose):array {
        if(strlen($token)>600)throw new \DomainException('Invalid cursor. Refresh.');
        $p=explode('.',$token);if(count($p)!==2||!hash_equals(hash_hmac('sha256',$p[0],$this->key),$p[1]))throw new \DomainException('Invalid cursor. Refresh.');
        $v=json_decode(base64_decode($p[0],true)?:'',true,8,JSON_THROW_ON_ERROR);
        if(!is_array($v)||count($v)!==6||$v[0]!==$employee||$v[1]!==$filter||$v[4]!==$purpose||$v[5]<time())throw new \DomainException('Expired or changed view. Refresh.');
        return $v;
    }
    public static function display(array $row,int $now):array {
        $stamp=new \DateTimeImmutable($row['created_at'],new \DateTimeZone('UTC'));$local=$stamp->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Africa/Windhoek'));$read=$row['read_at']!==null;
        $group=!$read?'New':($local->format('Y-m-d')===$today->format('Y-m-d')?'Earlier today':($local->format('Y-m-d')===$today->modify('-1 day')->format('Y-m-d')?'Yesterday':$local->format('j F Y')));
        $age=max(0,$now-$stamp->getTimestamp());$time=!$read&&$age<3600?($age<60?'Just now':intdiv($age,60).' min ago'):$local->format('H:i');
        if(!$read&&$local->format('Y-m-d')!==$today->format('Y-m-d'))$time=$local->format('j M Y · H:i');
        $kind=$row['kind'];$category=in_array($kind,['errand','errand_request'],true)?'errands':(in_array($kind,['dispatch_chat','cash_handover'],true)?'other':'deliveries');
        $icon=$category==='errands'?'bag':($kind==='dispatch_chat'?'message':($kind==='cash_handover'?'card':($kind==='proof_completed'?'check':'box')));
        // Do not echo private chat text, payment values, arbitrary action_link or unverified route claims.
        $description=match($kind){'dispatch_chat'=>'New update in your private dispatch conversation.','cash_handover'=>'Review your recorded cash handover.','assignment','reassigned'=>$row['ref'].' assigned to you.','errand','errand_request'=>$row['ref'].' · View the recorded instructions.',default=>$row['ref'].' · View the recorded update.'};
        $title=match($kind){'assignment'=>'New delivery assigned','reassigned'=>'Delivery reassigned to you','completed'=>'Delivery status updated','dispatch_chat'=>'Dispatch message',default=>$row['title']};
        return ['id'=>(int)$row['id'],'title'=>$title,'description'=>$description,'category'=>$category,'icon'=>$icon,'unread'=>!$read,'group'=>$group,'time'=>$time,'created_at'=>$stamp->format('Y-m-d\TH:i:s\Z'),'urgent'=>$row['priority']==='urgent'];
    }
    public function page(int $employee,string $filter='all',string $cursor=''):array {
        if(!in_array($filter,['all','unread','deliveries','errands'],true))throw new \DomainException('Invalid filter.');
        $a=EmployeeDriverSession::actor($this->db,$employee);$before=0;$ceiling=null;
        if($cursor!==''){[,,$before,$ceiling]=$this->decode($cursor,$employee,$filter,'page');}
        $this->db->beginTransaction();
        try {
            $this->db->exec("SET time_zone='+00:00'");
            $now=(int)$this->db->query('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())')->fetchColumn();
            $feed=DriverInbox::feed($this->db,$a,$before,$filter);$ceiling??=$feed['through'];
            $items=array_values(array_filter($feed['items'],static fn($r)=>(int)$r['id']<=$ceiling));
            $last=$items?(int)end($items)['id']:0;
            $data=['items'=>array_map(static fn($r)=>self::display($r,$now),$items),'unread'=>$feed['counts']['all'],'server_time'=>$now,'filter'=>$filter,
                'next'=>$feed['more']&&$last>0?$this->token($employee,$filter,$last,$ceiling,'page'):null,
                'read_all_token'=>$this->token($employee,'all',0,$feed['through'],'read_all'),'preferences'=>$this->preferences($employee)];
            EmployeeDriverSession::actor($this->db,$employee);$this->db->commit();return $data;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function audit(int $actor,int $notice,int $through,string $action):void {
        $q=$this->db->prepare('INSERT IGNORE INTO delivery_notification_access(employee_id,notification_id,through_id,action,event_key,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$actor,$notice,$through,$action,hash('sha256',json_encode([$actor,$notice,$through,$action]))]);
    }
    public function open(int $employee,int $notice):array {
        if($notice<1||$notice>2147483647)throw new \DomainException('Notification unavailable.',404);
        $this->db->beginTransaction();try {
            // Lock identity, recipient and source assignment before rechecking visibility.
            $this->rows('SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? FOR UPDATE',[$employee]);
            $a=EmployeeDriverSession::actor($this->db,$employee);
            $r=$this->rows('SELECT n.related_type,n.related_id FROM notifications n JOIN notification_recipients r ON r.notification_id=n.id WHERE n.id=? AND r.employee_id=? AND r.cleared_at IS NULL FOR UPDATE',[$notice,$employee]);
            if(!$r)throw new \DomainException('Notification unavailable.',404);
            $source=$r[0];$table=match($source['related_type']){'delivery_job'=>['delivery_jobs','id'],'delivery_errand'=>['delivery_errands','id'],'delivery_errand_request'=>['delivery_errand_requests','id'],'delivery_chat'=>['delivery_chat_messages','id'],'delivery_cash_handover'=>['delivery_cash_handovers','id'],default=>null};
            if(!$table)throw new \DomainException('Notification unavailable.',404);
            $this->rows('SELECT id FROM '.$table[0].' WHERE id=? FOR UPDATE',[$source['related_id']]);
            $feed=DriverInbox::feed($this->db,$a,$notice+1);$item=null;foreach($feed['items'] as $v)if((int)$v['id']===$notice)$item=$v;
            if(!$item)throw new \DomainException('This update is no longer available to your account.',404);
            $target=match($source['related_type']){'delivery_job'=>'/apps/delivery/index.php?'.(in_array($item['kind'],['issue_reported','issue_decision'],true)?'issue':'delivery').'='.(int)$item['job'],'delivery_errand'=>'/apps/delivery/index.php?errands=1','delivery_errand_request'=>'/apps/delivery/index.php?errands=1','delivery_chat'=>'/apps/delivery/index.php?dispatch=1','delivery_cash_handover'=>'/apps/delivery/index.php?cash=1'};
            DriverInbox::mark($this->db,$a,$notice,false);$this->audit($employee,$notice,0,'read');$this->audit($employee,$notice,0,'target_requested');
            $this->db->commit();return ['target'=>$target];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function markAll(int $employee,string $token):void {
        [,,,$through]=$this->decode($token,$employee,'all','read_all');$a=EmployeeDriverSession::actor($this->db,$employee);
        if($through<1)return;$this->db->beginTransaction();try {DriverInbox::mark($this->db,$a,$through,true);$this->audit($employee,0,$through,'read_all');$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function preferences(int $employee):array {
        EmployeeDriverSession::actor($this->db,$employee);
        try{$r=$this->rows('SELECT * FROM notification_preferences WHERE employee_id=?',[$employee]);return ['state'=>$r?'saved':'not_saved','sound_enabled'=>$r?(bool)$r[0]['sound_enabled']:false,'desktop_enabled'=>$r?(bool)$r[0]['desktop_enabled']:null,'muted_when_unavailable'=>(bool)($r[0]['muted_when_unavailable']??false),'sound_volume'=>max(0,min(100,(int)($r[0]['sound_volume']??65)))];}
        catch(\PDOException $e){if($e->getCode()==='42S02')return ['state'=>'unavailable','sound_enabled'=>false,'desktop_enabled'=>null];throw $e;}
    }
    public function sound(int $employee,bool $enabled):array {
        $this->db->beginTransaction();try{
            $this->rows('SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? FOR UPDATE',[$employee]);
            EmployeeDriverSession::actor($this->db,$employee);
            $q=$this->db->prepare('INSERT INTO notification_preferences(employee_id,desktop_enabled,sound_enabled) VALUES(?,0,?) ON DUPLICATE KEY UPDATE sound_enabled=VALUES(sound_enabled)');$q->execute([$employee,(int)$enabled]);
            $result=$this->preferences($employee);$this->db->commit();return $result;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
}
