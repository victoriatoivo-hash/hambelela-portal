<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ErrandData.php';
require_once __DIR__.'/DispatchFiles.php';

/** Dispatch-only scope. Never reads or changes private Owner assistance/acknowledgements. */
final class DispatchChat
{
    public function __construct(private \PDO $db) {}
    private function rows(string $sql,array $v=[]):array {return ErrandData::query($this->db,$sql,$v);}
    private function exec(string $sql,array $v=[]):void {$this->db->prepare($sql)->execute($v);}
    public static function actor(\PDO $db,int $id):array {return ErrandData::actor($db,$id);}
    public static function ready(\PDO $db):bool {try{$db->query('SELECT id FROM delivery_chat_threads LIMIT 0');return true;}catch(\PDOException $e){if($e->getCode()==='42S02')return false;throw $e;}}
    public function pool():array {
        $out=[];foreach($this->rows("SELECT id,full_name FROM ops_employees WHERE status='active' ORDER BY full_name") as $r){try{$a=self::actor($this->db,(int)$r['id']);if($a['role']!=='delivery_driver')$out[]=['id'=>(int)$r['id'],'name'=>$r['full_name']];}catch(\DomainException $e){}}
        return $out;
    }
    public function driver(int $employee,int $requested=0):int {
        $a=self::actor($this->db,$employee);
        if($a['role']==='delivery_driver') {if($requested!==0&&$requested!==$employee)throw new \DomainException('This conversation is private.');return $employee;}
        if($requested<1)throw new \DomainException('Select a verified driver.');EmployeeDriverSession::actor($this->db,$requested);return $requested;
    }
    private function thread(int $driver,bool $create=false):?array {
        if($create)$this->exec('INSERT INTO delivery_chat_threads(driver_id,created_at) VALUES(?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE driver_id=VALUES(driver_id)',[$driver]);
        return $this->rows('SELECT * FROM delivery_chat_threads WHERE driver_id=?'.($this->db->inTransaction()?' FOR UPDATE':''),[$driver])[0]??null;
    }
    private function audit(int $thread,int $actor,string $action,array $data):void {
        if(!$this->db->inTransaction())throw new \LogicException('Audit requires the thread transaction.');
        $previous=$this->rows('SELECT audit_head FROM delivery_chat_threads WHERE id=? FOR UPDATE',[$thread])[0]['audit_head'];
        $time=$this->rows('SELECT UTC_TIMESTAMP(6) stamp')[0]['stamp'];$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $hash=hash_hmac('sha256',json_encode([$thread,$actor,$action,$json,$time,$previous],JSON_THROW_ON_ERROR),DispatchFiles::auditKey());
        $this->exec('INSERT INTO delivery_chat_audit(thread_id,actor_id,action,data_json,created_at,previous_hash,event_hash) VALUES(?,?,?,?,?,?,?)',[$thread,$actor,$action,$json,$time,$previous,$hash]);
        $this->exec('UPDATE delivery_chat_threads SET audit_head=? WHERE id=?',[$hash,$thread]);
    }
    public function context(int $driver,?string $type,?int $id):?array {
        if($type===null&&$id===null)return null;
        if(!$id||$id<1||!in_array($type,['delivery','errand'],true))throw new \DomainException('Invalid assignment reference.');
        if($type==='delivery'){$r=$this->rows('SELECT j.id,COALESCE(NULLIF(o.order_number,\'\'),j.public_reference) label FROM delivery_jobs j LEFT JOIN ops_orders o ON o.id=j.order_id WHERE j.id=? AND j.driver_employee_id=?',[$id,$driver])[0]??null;}
        else {$r=$this->rows('SELECT id,CONCAT(\'Errand ER-\',id) label FROM delivery_errands WHERE id=? AND driver_id=?',[$id,$driver])[0]??null;}
        if(!$r)throw new \DomainException('Assignment is unavailable or has been reassigned.');
        return ['type'=>$type,'id'=>$id,'label'=>$r['label'],'url'=>'/apps/delivery/index.php?'.($type==='delivery'?'delivery=':'errands=1&errand=').$id];
    }
    public function contexts(int $driver):array {
        $out=[];foreach($this->rows("SELECT id FROM delivery_jobs WHERE driver_employee_id=? AND status IN('ready','out_for_delivery','failed') ORDER BY id DESC LIMIT 50",[$driver]) as $r)$out[]=$this->context($driver,'delivery',(int)$r['id']);
        foreach($this->rows("SELECT e.id FROM delivery_errands e LEFT JOIN delivery_errand_details d ON d.errand_id=e.id WHERE e.driver_id=? AND e.collected_at IS NULL AND COALESCE(d.state,'open')<>'cancelled' ORDER BY e.id DESC LIMIT 50",[$driver]) as $r)$out[]=$this->context($driver,'errand',(int)$r['id']);return $out;
    }
    private function message(array $r,int $actor,int $driver):array {
        $context=null;if($r['context_type']!==null){try{$context=$this->context($driver,$r['context_type'],(int)$r['context_id']);}catch(\DomainException $e){$context=['unavailable'=>true,'label'=>'Assignment no longer available'];}}
        $attachment=$r['attachment_json']?json_decode($r['attachment_json'],true,16,JSON_THROW_ON_ERROR):null;
        if($attachment)$attachment=array_intersect_key($attachment,array_flip(['id','name','mime','bytes','expires_at']));
        $receipt=['delivered'=>$r['delivered_time'],'seen'=>$r['read_time']];
        return ['id'=>(int)$r['id'],'thread'=>(int)$r['thread_id'],'senderId'=>(int)$r['sender_id'],'sender'=>$r['sender_name'],'outgoing'=>(int)$r['sender_id']===$actor,'body'=>$r['body'],'urgent'=>(bool)$r['urgent'],'createdAt'=>str_replace(' ','T',$r['created_at']).'Z','state'=>$receipt['seen']?'read':($receipt['delivered']?'delivered':'accepted'),'readAt'=>$receipt['seen']?str_replace(' ','T',$receipt['seen']).'Z':null,'context'=>$context,'attachment'=>$attachment,'handledBy'=>$r['handled_name']??null,'handledAt'=>$r['handled_at']];
    }
    public function unread(int $actor,int $driver=0):int {
        $driver=$this->driver($actor,$driver);return (int)$this->rows('SELECT COUNT(*) n FROM delivery_chat_messages m JOIN delivery_chat_threads t ON t.id=m.thread_id LEFT JOIN delivery_chat_receipts r ON r.message_id=m.id AND r.employee_id=? WHERE t.driver_id=? AND m.sender_id<>? AND r.read_at IS NULL',[$actor,$driver,$actor])[0]['n'];
    }
    public function inbox(int $employee):array {
        $a=self::actor($this->db,$employee);if($a['role']==='delivery_driver')throw new \DomainException('Dispatch access required.');$out=[];
        foreach($this->rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name") as $r){
            $n=$this->rows('SELECT COUNT(*) n FROM delivery_chat_messages m JOIN delivery_chat_threads t ON t.id=m.thread_id WHERE t.driver_id=? AND m.urgent=1 AND m.handled_at IS NULL',[$r['id']])[0]['n'];
            $out[]=['id'=>(int)$r['id'],'name'=>$r['full_name'],'unread'=>$this->unread($employee,(int)$r['id']),'urgent'=>(int)$n];
        }usort($out,static fn($a,$b)=>($b['urgent']<=>$a['urgent'])?:($b['unread']<=>$a['unread'])?:strcmp($a['name'],$b['name']));return $out;
    }
    public function feed(int $employee,int $requested=0,int $after=0,int $before=0,array $visible=[]):array {
        $driver=$this->driver($employee,$requested);$t=$this->thread($driver);$messages=[];$more=false;
        if($t){$where='m.thread_id=?';$v=[$t['id']];if($after>0){$where.=' AND m.id>?';$v[]=$after;}elseif($before>0){$where.=' AND m.id<?';$v[]=$before;}
            $q='SELECT m.*,e.full_name sender_name,h.full_name handled_name,(SELECT MIN(delivered_at) FROM delivery_chat_receipts WHERE message_id=m.id AND employee_id<>m.sender_id) delivered_time,(SELECT MIN(read_at) FROM delivery_chat_receipts WHERE message_id=m.id AND employee_id<>m.sender_id) read_time FROM delivery_chat_messages m JOIN ops_employees e ON e.id=m.sender_id LEFT JOIN ops_employees h ON h.id=m.handled_by WHERE ';
            $rows=$this->rows($q.$where.' ORDER BY m.id '.($after?'ASC':'DESC').' LIMIT 41',$v);$more=count($rows)>40;if($more)array_pop($rows);if(!$after)$rows=array_reverse($rows);
            // Receipt refresh is bounded to already displayed IDs; never fetch unrelated threads.
            $ids=array_values(array_unique(array_map('intval',array_slice($visible,0,200))));if($ids)$rows=array_merge($rows,$this->rows($q.'m.thread_id=? AND m.id IN('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$t['id']],$ids)));
            $map=[];foreach($rows as $r)$map[$r['id']]=$this->message($r,$employee,$driver);ksort($map);$messages=array_values($map);
        }
        return ['actor'=>$employee,'driver'=>$driver,'messages'=>$messages,'more'=>$more,'unread'=>$this->unread($employee,$driver),'contexts'=>$this->contexts($driver),'dispatchers'=>$this->pool()];
    }
    public function send(int $employee,int $requested,array $input,?array $upload=null,bool $testFile=false):array {
        $driver=$this->driver($employee,$requested);$key=$input['key']??'';$body=$input['body']??null;$urgent=$input['urgent']??false;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$key)||!is_string($body)||!mb_check_encoding($body,'UTF-8')||!is_bool($urgent))throw new \DomainException('Invalid message.');
        $body=trim($body);if(mb_strlen($body)>2000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$body)||($body===''&&!$upload))throw new \DomainException('Write a message up to 2,000 characters or attach a file.');
        $type=$input['contextType']??null;$cid=$input['contextId']??null;if(($type!==null&&!is_string($type))||($cid!==null&&!is_int($cid)))throw new \DomainException('Invalid context.');
        $rawHash=$upload?DispatchFiles::sourceHash($upload,$testFile):null;
        $hash=hash('sha256',json_encode([$driver,$body,$urgent,$type,$cid,$rawHash],JSON_THROW_ON_ERROR));$saved=null;$keep=false;$commitAttempted=false;
        if($this->db->inTransaction())throw new \LogicException('Send owns its transaction.');
        $this->db->beginTransaction();
        try{
            // Serialize all messages from one actor, including cross-thread retries and rate limits.
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$employee]);$this->driver($employee,$requested);
            $old=$this->rows('SELECT id,thread_id,payload_hash FROM delivery_chat_messages WHERE sender_id=? AND request_key=?',[$employee,$key])[0]??null;
            if($old){if(!hash_equals($old['payload_hash'],$hash))throw new \DomainException('This retry belongs to a different draft. Start a new send.');$this->db->commit();return ['id'=>(int)$old['id'],'state'=>'accepted','replayed'=>true];}
            $rate=$this->rows('SELECT COUNT(*) n,SUM(attachment_json IS NOT NULL) files FROM delivery_chat_messages WHERE sender_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 MINUTE',[$employee])[0];
            if((int)$rate['n']>=20||($upload&&(int)$rate['files']>=5))throw new \DomainException('Please wait a minute before sending again.');
            $this->context($driver,$type,$cid);$t=$this->thread($driver,true);$recipients=$employee===$driver?array_column($this->pool(),'id'):[$driver];$recipients=array_values(array_diff($recipients,[$employee]));
            if(!$recipients)throw new \DomainException('No active dispatch recipient. Nothing was sent.');
            if($upload)$saved=DispatchFiles::prepare($upload,$testFile);
            $this->exec('INSERT INTO delivery_chat_messages(thread_id,sender_id,request_key,payload_hash,body,urgent,context_type,context_id,attachment_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$t['id'],$employee,$key,$hash,$body,(int)$urgent,$type,$cid,$saved?json_encode($saved,JSON_THROW_ON_ERROR):null]);$id=(int)$this->db->lastInsertId();
            $this->audit((int)$t['id'],$employee,'sent',['message'=>$id,'payload_hash'=>$hash,'attachment'=>$saved,'recipients'=>$recipients]);
            // Existing inbox is durable within this same transaction; no queue/delivery fiction.
            $this->exec("INSERT INTO notifications(title,message,module,related_type,related_id,priority,deduplication_key,action_link,created_at) VALUES(?,?,'delivery','delivery_chat',?,?,?,?,UTC_TIMESTAMP())",[$urgent?'Urgent dispatch message':'New dispatch message','A private message is waiting. Open Dispatch chat to read it.',$id,$urgent?'urgent':'normal','dispatch-chat:'.$id,'/apps/delivery/index.php?dispatch=1&driver='.$driver]);$notice=(int)$this->db->lastInsertId();
            foreach($recipients as $recipient)$this->exec('INSERT INTO notification_recipients(notification_id,employee_id) VALUES(?,?)',[$notice,$recipient]);
            $commitAttempted=true;$this->db->commit();$keep=true;return ['id'=>$id,'state'=>'accepted','replayed'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{
            // An interrupted COMMIT acknowledgement may still have committed. Retain its
            // private file for idempotent recovery instead of destroying persisted evidence.
            if($saved&&!$keep&&!$commitAttempted)DispatchFiles::discard($saved);
        }
    }
    public function receipt(int $employee,int $requested,array $ids,string $kind):void {
        $driver=$this->driver($employee,$requested);if(!in_array($kind,['delivered','read'],true)||count($ids)>200||!$ids)throw new \DomainException('Invalid receipt.');foreach($ids as $id)if(!is_int($id)||$id<1)throw new \DomainException('Invalid message reference.');
        $this->db->beginTransaction();try{$t=$this->thread($driver);if(!$t)throw new \DomainException('Conversation unavailable.');
            $rows=$this->rows('SELECT m.id,r.delivered_at,r.read_at FROM delivery_chat_messages m LEFT JOIN delivery_chat_receipts r ON r.message_id=m.id AND r.employee_id=? WHERE m.thread_id=? AND m.sender_id<>? AND m.id IN('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$employee,$t['id'],$employee],$ids));$changed=[];
            foreach($rows as $r){if(($kind==='read'?$r['read_at']:$r['delivered_at'])!==null)continue;
                $this->exec('INSERT INTO delivery_chat_receipts(message_id,employee_id,delivered_at,read_at) VALUES(?,?,UTC_TIMESTAMP(6),'.($kind==='read'?'UTC_TIMESTAMP(6)':'NULL').') ON DUPLICATE KEY UPDATE delivered_at=COALESCE(delivered_at,VALUES(delivered_at)),read_at=COALESCE(read_at,VALUES(read_at))',[$r['id'],$employee]);$changed[]=(int)$r['id'];
                if($kind==='read')$this->exec("UPDATE notification_recipients r JOIN notifications n ON n.id=r.notification_id SET r.read_at=COALESCE(r.read_at,UTC_TIMESTAMP()) WHERE r.employee_id=? AND n.related_type='delivery_chat' AND n.related_id=?",[$employee,$r['id']]);
            }if($changed)$this->audit((int)$t['id'],$employee,$kind,['messages'=>$changed]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function handle(int $employee,int $requested,int $message,int $assignee):void {
        $a=self::actor($this->db,$employee);if($a['role']==='delivery_driver')throw new \DomainException('Dispatch handling required.');$driver=$this->driver($employee,$requested);
        if(!in_array($assignee,array_column($this->pool(),'id'),true))throw new \DomainException('Choose an active authorized dispatcher.');
        $this->db->beginTransaction();try{$t=$this->thread($driver);if(!$t)throw new \DomainException('Conversation unavailable.');$m=$this->rows('SELECT * FROM delivery_chat_messages WHERE id=? AND thread_id=? AND urgent=1',[$message,$t['id']])[0]??null;if(!$m)throw new \DomainException('Urgent message unavailable.');
            if((int)$m['handled_by']!==$assignee){$this->exec('UPDATE delivery_chat_messages SET handled_by=?,handled_at=UTC_TIMESTAMP(6) WHERE id=?',[$assignee,$message]);$this->audit((int)$t['id'],$employee,'urgent_handling',['message'=>$message,'assigned_to'=>$assignee]);}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function attachment(int $employee,int $requested,int $message):array {
        $driver=$this->driver($employee,$requested);$r=$this->rows('SELECT m.* FROM delivery_chat_messages m JOIN delivery_chat_threads t ON t.id=m.thread_id WHERE m.id=? AND t.driver_id=?',[$message,$driver])[0]??null;
        if(!$r||!$r['attachment_json'])throw new \DomainException('Attachment unavailable.');$f=json_decode($r['attachment_json'],true,16,JSON_THROW_ON_ERROR);DispatchFiles::path($f);
        $this->db->beginTransaction();try{$this->thread($driver);$this->audit((int)$r['thread_id'],$employee,'attachment_opened',['message'=>$message,'attachment'=>$f['id']]);$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}return $f;
    }
}
