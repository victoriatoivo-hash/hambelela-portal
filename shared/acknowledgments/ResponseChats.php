<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;

final class ResponseChats {
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function q(string $s,array $p):\PDOStatement{$q=$this->db->prepare($s);$q->execute($p);return $q;}
    private function now():string{return (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');}
    private function key(array $p):string{$k=$p['request_key']??null;if(!is_string($k)||!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD',$k))throw new \DomainException('Refresh and retry.');return $k;}
    private function body(array $p):string{$b=$p['message']??null;if(!is_string($b)||trim($b)===''||strlen(trim($b))>6000)throw new \DomainException('Enter a written message (up to 6,000 bytes).');return trim($b);}
    private function tx(callable $fn):array{$this->db->beginTransaction();try{$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e){$this->db->rollBack();throw $e;}}
    private function append(int $id,int $actor,string $key,string $text):void{
        $this->q('INSERT INTO portal_ack_response_chat_messages(chat_id,sender_id,request_key,body,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$key,$text,$this->now()]);
        $this->q('UPDATE portal_ack_response_chats SET last_id=? WHERE id=?',[(int)$this->db->lastInsertId(),$id]);
    }
    public function start(int $actor,array $p):array{
        $s=new Service($this->db);$employee=(int)($p['employee']??0);
        if($s->actor($actor)['role_key']!=='owner_admin'||$employee===$actor||$s->actor($employee)['role_key']==='owner_admin')throw new \DomainException('Choose an active employee.');
        $key=$this->key($p);$text=$this->body($p);
        return $this->tx(function()use($actor,$employee,$key,$text){
            $this->q('INSERT INTO portal_ack_response_chats(owner_id,employee_id,create_key,created_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$actor,$employee,$key,$this->now()]);
            $c=$this->q('SELECT * FROM portal_ack_response_chats WHERE owner_id=? AND create_key=? FOR UPDATE',[$actor,$key])->fetch();
            $id=(int)$c['id'];if((int)$c['employee_id']!==$employee)throw new \DomainException('That send has already been used.');
            if(!$c['last_id'])$this->append($id,$actor,$key,$text);
            elseif($this->q('SELECT body FROM portal_ack_response_chat_messages WHERE chat_id=? ORDER BY id LIMIT 1',[$id])->fetchColumn()!==$text)throw new \DomainException('That send has already been used.');
            return ['id'=>$id];
        });
    }
    public function rows(int $actor,bool $alerts=false):array{
        $sql="SELECT c.id,c.owner_id,c.employee_id,c.created_at,c.last_id,c.completed_at,e.full_name employee_name,
          LEFT(o.body,80) title,LEFT(m.body,220) latest_message,m.created_at latest_date,
          CASE WHEN c.completed_at IS NOT NULL THEN 'Completed' WHEN c.deferred_id=c.last_id THEN 'Respond Later'
               WHEN m.sender_id=c.owner_id THEN 'Awaiting Employee Response' ELSE 'Awaiting Admin Response' END status,
          (m.sender_id<>? AND c.last_id>IF(c.owner_id=?,c.owner_seen,c.employee_seen)) unread
          FROM portal_ack_response_chats c JOIN ops_employees e ON e.id=c.employee_id
          JOIN portal_ack_response_chat_messages o ON o.chat_id=c.id AND o.sender_id=c.owner_id AND o.request_key=c.create_key
          JOIN portal_ack_response_chat_messages m ON m.id=c.last_id WHERE (c.owner_id=? OR c.employee_id=?)";
        return $this->q($sql.($alerts?' AND c.completed_at IS NULL HAVING unread=1 ORDER BY c.last_id LIMIT 1':' ORDER BY c.last_id DESC'),[$actor,$actor,$actor,$actor])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function thread(int $actor,array $p):array{
        return $this->tx(function()use($actor,$p){
            $id=(int)($p['id']??0);$c=$this->q('SELECT * FROM portal_ack_response_chats WHERE id=? AND (owner_id=? OR employee_id=?) FOR UPDATE',[$id,$actor,$actor])->fetch();
            if(!$c)throw new \DomainException('Private conversation unavailable.');
            $owner=(int)$c['owner_id']===$actor;$op=$p['op']??'open';$last=(int)$c['last_id'];
            $writer=(int)$this->q('SELECT sender_id FROM portal_ack_response_chat_messages WHERE chat_id=? AND id=?',[$id,$last])->fetchColumn();
            if($op==='reply'){
                $key=$this->key($p);$text=$this->body($p);$prior=$this->q('SELECT body FROM portal_ack_response_chat_messages WHERE chat_id=? AND sender_id=? AND request_key=?',[$id,$actor,$key])->fetchColumn();
                if($prior!==false){if($prior!==$text)throw new \DomainException('Message retry differs.');}
                elseif($c['completed_at'])throw new \DomainException('This chat is completed.');
                else{$this->append($id,$actor,$key,$text);$last=(int)$this->q('SELECT last_id FROM portal_ack_response_chats WHERE id=?',[$id])->fetchColumn();}
            }elseif($op==='later'||$op==='complete'){
                if((int)($p['expected']??0)!==$last)throw new \DomainException('A newer message arrived. Read it and try again.');
                if($op==='complete'){
                    if(!$owner||$writer===$actor)throw new \DomainException('An employee response is required before resolving.');
                    $this->q('UPDATE portal_ack_response_chats SET completed_at=COALESCE(completed_at,?) WHERE id=?',[$this->now(),$id]);
                }elseif(!$c['completed_at']&&!$owner&&$writer!==$actor)$this->q('UPDATE portal_ack_response_chats SET deferred_id=? WHERE id=?',[$last,$id]);
            }elseif(!in_array($op,['open','poll'],true))throw new \DomainException('Unknown chat action.');
            $seen=$owner?'owner_seen':'employee_seen';
            if((int)$c[$seen]<$last)$this->q("UPDATE portal_ack_response_chats SET $seen=? WHERE id=?",[$last,$id]);
            $closed=(bool)$this->q('SELECT completed_at FROM portal_ack_response_chats WHERE id=?',[$id])->fetchColumn();
            if($op==='poll'&&(int)($p['after']??0)===$last&&(bool)($p['closed']??false)===$closed)return ['unchanged'=>true];
            $messages=$this->q('SELECT m.id,m.body,m.created_at,e.full_name sender,(m.sender_id=?) mine FROM portal_ack_response_chat_messages m JOIN ops_employees e ON e.id=m.sender_id WHERE m.chat_id=? ORDER BY m.id',[$actor,$id])->fetchAll(\PDO::FETCH_ASSOC);
            return ['messages'=>$messages,'last'=>$last,'closed'=>$closed,'can_complete'=>$owner&&!$closed&&!end($messages)['mine']];
        });
    }
}
