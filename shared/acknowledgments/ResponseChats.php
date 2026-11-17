<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;
require_once __DIR__.'/Mailbox.php';

/** A response request is never an acknowledgment recipient or assistance receipt. */
final class ResponseChats {
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function query(string $sql,array $args):\PDOStatement {
        $q=$this->db->prepare($sql);$q->execute($args);return $q;
    }
    private function actor(int $id):array {return (new Service($this->db))->actor($id);}
    private function now():string {return (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');}
    private function allowed(array $row,int $actor):bool {
        return (int)$row['employee_id']===$actor || ((int)$row['owner_id']===$actor && $this->actor($actor)['role_key']==='owner_admin');
    }
    private function one(int $actor,int $id,bool $lock=false):array {
        $r=$this->query('SELECT * FROM portal_ack_response_chats WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetch(\PDO::FETCH_ASSOC);
        if(!$r||!$this->allowed($r,$actor))throw new \DomainException('Conversation unavailable.');
        return $r;
    }
    private static function ownerVisible():string {
        return "NOT EXISTS(SELECT 1 FROM portal_ack_owner_chat_deletions h WHERE h.owner_id=c.owner_id AND h.kind='chat' AND h.thread_id=c.id AND h.through_id=(SELECT MAX(m.id) FROM portal_ack_response_messages m WHERE m.thread_id=c.id))";
    }
    public function listing(int $actor):array {
        $owner=$this->actor($actor)['role_key']==='owner_admin';
        return $this->query('SELECT c.id,c.title,c.state,c.revision,c.updated_at,c.owner_id,c.employee_id,e.full_name employee_name FROM portal_ack_response_chats c JOIN ops_employees e ON e.id=c.employee_id WHERE c.employee_id=?'.($owner?' OR (c.owner_id=? AND '.self::ownerVisible().')':'').' ORDER BY c.updated_at DESC,c.id DESC',$owner?[$actor,$actor]:[$actor])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function prompt(int $actor):array {
        $owner=$this->actor($actor)['role_key']==='owner_admin';
        $rows=$this->query("SELECT id,revision,state FROM portal_ack_response_chats c WHERE (employee_id=? AND state IN ('employee','later'))".($owner?" OR (owner_id=? AND state='admin' AND ".self::ownerVisible().")":'').' ORDER BY updated_at,id',$owner?[$actor,$actor]:[$actor])->fetchAll(\PDO::FETCH_ASSOC);
        return ['count'=>count($rows),'incoming'=>array_values(array_filter($rows,static function($r){return $r['state']!=='later';}))];
    }
    public function detail(int $actor,int $id):array {
        $r=$this->one($actor,$id);
        return ['title'=>$r['title'],'state'=>$r['state'],'revision'=>(int)$r['revision'],'owner'=>(int)$r['owner_id']===$actor,
            'can_remove'=>(int)$r['owner_id']===$actor && Mailbox::forPortal($this->db)->isDesignatedOwner($actor),
            'messages'=>$this->query('SELECT sender_id,body,created_at FROM portal_ack_response_messages WHERE thread_id=? ORDER BY id',[$id])->fetchAll(\PDO::FETCH_ASSOC),'owner_id'=>(int)$r['owner_id']];
    }
    private function message(array $d):string {
        $s=trim(is_string($d['message']??null)?$d['message']:'');
        if($s===''||strlen($s)>6000)throw new \DomainException('Enter a message of up to 6,000 bytes.');
        return $s;
    }
    private function key(array $d):string {
        $k=$d['request_key']??'';
        if(!is_string($k)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$k))throw new \DomainException('Invalid request key.');
        return $k;
    }
    public function write(int $actor,array $d):array {
        $identity=$this->actor($actor);$op=$d['operation']??'';$id=(int)($d['id']??0);
        $this->db->beginTransaction();
        try {
            if($op==='create'){
                if($identity['role_key']!=='owner_admin')throw new \DomainException('Owner permission is required.');
                $emp=(int)($d['employee']??0);$this->actor($emp);
                if($emp===$actor)throw new \DomainException('Select another employee.');
                $title=trim((string)($d['title']??''));if($title===''||strlen($title)>190)throw new \DomainException('Enter a subject of up to 190 bytes.');
                $body=$this->message($d);$key=$this->key($d);$now=$this->now();
                $this->query('INSERT INTO portal_ack_response_chats(owner_id,employee_id,create_key,title,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$actor,$emp,$key,$title,$now]);
                $id=(int)$this->db->lastInsertId();$r=$this->one($actor,$id,true);
                $old=$this->query('SELECT body FROM portal_ack_response_messages WHERE thread_id=? ORDER BY id LIMIT 1',[$id])->fetchColumn();
                if((int)$r['employee_id']!==$emp||$title!==$r['title']||($old!==false&&$old!==$body))throw new \DomainException('This send request was already used.');
                if($old===false)$this->query('INSERT INTO portal_ack_response_messages(thread_id,sender_id,request_key,body,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$key,$body,$now]);
            }else{
                $r=$this->one($actor,$id,true);$owner=(int)$r['owner_id']===$actor;
                if($op==='remove'){
                    if(!$owner||!Mailbox::forPortal($this->db)->isDesignatedOwner($actor))throw new \DomainException('Only the designated Owner can delete a chat.');
                    if((int)($d['revision']??0)!==(int)$r['revision'])throw new \DomainException('The conversation changed. Review the latest messages before deleting.');
                    $this->query("INSERT INTO portal_ack_owner_chat_deletions(owner_id,kind,thread_id,through_id,removed_at) SELECT ?,'chat',?,MAX(id),UTC_TIMESTAMP() FROM portal_ack_response_messages WHERE thread_id=? ON DUPLICATE KEY UPDATE through_id=VALUES(through_id)",[$actor,$id,$id]);
                    $this->db->commit();return ['id'=>$id];
                }
                if($op==='reply'){
                    $body=$this->message($d);$key=$this->key($d);
                    $old=$this->query('SELECT body FROM portal_ack_response_messages WHERE thread_id=? AND sender_id=? AND request_key=?',[$id,$actor,$key])->fetchColumn();
                    if($old!==false){if($old!==$body)throw new \DomainException('This reply request was already used.');$this->db->commit();return ['id'=>$id];}
                }
                if($r['state']==='completed'||(int)($d['revision']??0)!==(int)$r['revision'])throw new \DomainException('The conversation changed. Review the latest thread before continuing.');
                if($op==='reply'){
                    $this->query('INSERT INTO portal_ack_response_messages(thread_id,sender_id,request_key,body,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$key,$body,$this->now()]);
                    $state=$owner?'employee':'admin';
                }elseif($op==='later'&&!$owner&&$r['state']==='employee')$state='later';
                elseif($op==='resolve'&&$owner&&$r['state']==='admin')$state='completed';
                else throw new \DomainException('This action is not available.');
                $this->query('UPDATE portal_ack_response_chats SET state=?,revision=revision+1,updated_at=? WHERE id=?',[$state,$this->now(),$id]);
            }
            $this->db->commit();return ['id'=>$id];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
}
