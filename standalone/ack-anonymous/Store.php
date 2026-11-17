<?php
declare(strict_types=1);
namespace Hambelela\AnonymousIntake;
/** Capability-based intake. No reporter identity, session, private code or exact time in the thread. */
final class Store {
    private \PDO $db;
    private int $owner;
    public function __construct(\PDO $db,int $owner){$this->db=$db;$this->owner=$owner;}
    private function sql(string $q,array $p):\PDOStatement{$s=$this->db->prepare($q);$s->execute($p);return $s;}
    private static function day():string{return (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');}
    private static function text($v):string{if(!is_string($v)||trim($v)===''||strlen(trim($v))>6000)throw new \DomainException('Enter a message of up to 6,000 bytes.');return trim($v);}
    private function append(string $id,string $side,string $text,string $key):void{
        $existing=$this->sql('SELECT side,body FROM portal_ack_report_messages WHERE report_id=? AND message_key=?',[$id,$key])->fetch(\PDO::FETCH_ASSOC);
        if($existing){if($existing['side']!==$side||$existing['body']!==$text)throw new \DomainException('This request was already used.');return;}
        if($this->closed($id))throw new \DomainException('This report conversation is closed.');
        $this->sql('INSERT INTO portal_ack_report_messages(report_id,side,body,message_key,submitted_on,owner_read,alerted) VALUES(?,?,?,?,?,?,?)',[$id,$side,$text,$key,self::day(),(int)($side==='owner'),(int)($side==='owner')]);
    }
    private function key(array $data):string{
        $k=$data['request_key']??'';if(!is_string($k)||!preg_match('/^[a-f0-9-]{36}$/D',$k))throw new \DomainException('Refresh the form and retry.');return $k;
    }
    private function messages(string $id):array{return $this->sql('SELECT side,body,submitted_on FROM portal_ack_report_messages WHERE report_id=? ORDER BY id',[$id])->fetchAll(\PDO::FETCH_ASSOC);}
    private function closed(string $id):?string{
        $key=$this->sql("SELECT message_key FROM portal_ack_report_messages WHERE report_id=? AND message_key IN ('close:management','close:ack') LIMIT 1",[$id])->fetchColumn();
        return !$key?null:($key==='close:management'?'Closed by Management':'Closed – Acknowledged');
    }
    public function run(array $data):array{
        $code=is_string($data['code']??null)?strtoupper(str_replace('-','',trim($data['code']))):'';
        if(!preg_match('/^[A-F0-9]{32}$/D',$code)||$this->owner<1)throw new \DomainException('Check the private reply code.');
        $hash=hash('sha256',$code);$action=$data['action']??'';
        if(!in_array($action,['submit','check','thread','followup','ack'],true))throw new \DomainException('Choose a valid action.');
        $this->db->beginTransaction();
        try{
            if($action==='submit'){
                $text=self::text($data['body']??null);
                try{$this->sql('INSERT INTO portal_ack_anonymous(report_id,lookup_hash,owner_id,body) VALUES(?,?,?,?)',[bin2hex(random_bytes(16)),$hash,$this->owner,$text]);}
                catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
            }
            $write=in_array($action,['submit','followup','ack'],true);
            $r=$this->sql('SELECT report_id,body FROM portal_ack_anonymous WHERE owner_id=? AND lookup_hash=?'.($write?' FOR UPDATE':''),[$this->owner,$hash])->fetch(\PDO::FETCH_ASSOC);
            if(!$r)throw new \DomainException('No report was found for that code.');
            $id=$r['report_id'];
            if($action==='submit'){
                if($r['body']!==$text)throw new \DomainException('This code already belongs to another report.');
                $this->append($id,'reporter',$text,'opening');$out=['saved'=>true];
            }elseif($action==='followup'){$this->append($id,'reporter',self::text($data['body']??null),$this->key($data));$out=['saved'=>true];}
            elseif($action==='ack'){
                if(!$this->closed($id)){
                    $history=$this->messages($id);if(!$history||end($history)['side']!=='owner'||count($history)!==(int)($data['expected']??0))throw new \DomainException('Read the latest management reply before acknowledging.');
                    $this->append($id,'reporter','Closed – Acknowledged','close:ack');
                }$out=['closed'=>$this->closed($id)];
            }
            elseif($action==='thread'){$out=['messages'=>$this->messages($id),'closed'=>$this->closed($id)];}
            else{$reply=$this->sql("SELECT body FROM portal_ack_report_messages WHERE report_id=? AND side='owner' ORDER BY id DESC LIMIT 1",[$id])->fetchColumn();$out=['reply'=>$reply===false?null:$reply];}
            $this->db->commit();return $out;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    // These methods are called only through Mailbox's designated-owner gate.
    public function summaries():array{
        return $this->sql("SELECT r.report_id,m.side,CASE m.message_key WHEN 'close:management' THEN 'Closed by Management' WHEN 'close:ack' THEN 'Closed – Acknowledged' ELSE NULL END closure,LEFT(m.body,240) latest_message,m.submitted_on latest_date,o.submitted_on submitted_date,
            EXISTS(SELECT 1 FROM portal_ack_report_messages u WHERE u.report_id=r.report_id AND u.owner_read=0) unread
            FROM portal_ack_anonymous r JOIN portal_ack_report_messages o ON o.report_id=r.report_id AND o.message_key='opening'
            JOIN portal_ack_report_messages m ON m.id=(SELECT MAX(t.id) FROM portal_ack_report_messages t WHERE t.report_id=r.report_id)
            WHERE r.owner_id=? ORDER BY m.id DESC",[$this->owner])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function ownerThread(string $id,array $body):array{
        if(isset($body['after'])&&!isset($body['message'])){
            $head=$this->sql('SELECT MAX(m.id) FROM portal_ack_anonymous r JOIN portal_ack_report_messages m ON m.report_id=r.report_id WHERE r.owner_id=? AND r.report_id=?',[$this->owner,$id])->fetchColumn();
            if($head!==null&&(int)$head===(int)$body['after'])return ['unchanged'=>true];
        }
        $this->db->beginTransaction();try{
            if(!$this->sql('SELECT report_id FROM portal_ack_anonymous WHERE owner_id=? AND report_id=? FOR UPDATE',[$this->owner,$id])->fetchColumn())throw new \DomainException('Report unavailable.');
            if(!empty($body['end'])){
                if(!$this->closed($id)){
                    $latest=$this->sql('SELECT MAX(id) FROM portal_ack_report_messages WHERE report_id=?',[$id])->fetchColumn();
                    if((int)$latest!==(int)($body['expected']??0))throw new \DomainException('Read the latest message before ending this conversation.');
                    $this->append($id,'owner','Closed by Management','close:management');
                }
            }elseif(isset($body['message']))$this->append($id,'owner',self::text($body['message']),$this->key($body));
            $messages=$this->messages($id);
            $this->sql('UPDATE portal_ack_report_messages SET owner_read=1,alerted=1 WHERE report_id=? AND owner_read=0',[$id]);
            $cursor=(int)$this->sql('SELECT MAX(id) FROM portal_ack_report_messages WHERE report_id=?',[$id])->fetchColumn();
            $closed=$this->closed($id);$this->db->commit();return ['report_id'=>$id,'messages'=>$messages,'cursor'=>$cursor,'closed'=>$closed];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function alert():?array{
        $r=$this->sql("SELECT m.report_id,m.id message_id,LEFT(m.body,160) preview FROM portal_ack_report_messages m JOIN portal_ack_anonymous r ON r.report_id=m.report_id WHERE r.owner_id=? AND m.alerted=0 AND m.id=(SELECT MAX(t.id) FROM portal_ack_report_messages t WHERE t.report_id=m.report_id) ORDER BY m.id LIMIT 1",[$this->owner])->fetch(\PDO::FETCH_ASSOC);
        return $r?:null;
    }
    public function dismiss(string $id,int $through):void{
        $this->sql('UPDATE portal_ack_report_messages m JOIN portal_ack_anonymous r ON r.report_id=m.report_id SET m.alerted=1 WHERE r.owner_id=? AND r.report_id=? AND m.id<=?',[$this->owner,$id,$through]);
    }
}
