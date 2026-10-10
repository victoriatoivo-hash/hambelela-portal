<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;

/** One employee identity, immutable instruction versions and independent recipient responses. */
final class Service
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    public function actor(int $id):array{
        $r=$this->rows("SELECT e.id,e.full_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$id]);
        if(!$r)throw new \DomainException('Your active employee account is required.');return $r[0];
    }
    private function owner(int $id):void{if($this->actor($id)['role_key']!=='owner_admin')throw new \DomainException('Owner permission is required.');}
    private function now():string{return (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');}
    private function token(string $value):string{if(!preg_match('/^[a-f0-9-]{36}$/i',$value))throw new \DomainException('Refresh the form before submitting.');return strtolower($value);}
    public static function sanitize(string $html):string{
        if(strlen($html)>40000)throw new \DomainException('Instruction is too long.');
        $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
        $render=function($node)use(&$render):string{
            if($node instanceof \DOMText)return htmlspecialchars($node->nodeValue,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if(!($node instanceof \DOMElement))return '';
            $tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','svg','math'],true))return '';
            $children='';foreach($node->childNodes as $child)$children.=$render($child);
            if($tag==='br')return '<br>';if(in_array($tag,['p','strong','b','ol','ul','li','em'],true))return '<'.$tag.'>'.$children.'</'.$tag.'>';return $children;
        };
        return $render($document->documentElement);
    }
    public function listing(int $actor):array{
        $user=$this->actor($actor);$owner=$user['role_key']==='owner_admin';
        $records=$this->rows("SELECT r.*,i.title,i.version,i.sender_id,i.deadline,i.required,i.created_at,i.root_id,s.full_name sender_name FROM portal_ack_recipients r JOIN portal_ack_instructions i ON i.id=r.instruction_id LEFT JOIN ops_employees s ON s.id=i.sender_id".($owner?'':' WHERE r.employee_id=?').' ORDER BY i.created_at DESC,r.id DESC',$owner?[]:[$actor]);
        $now=$this->now();foreach($records as &$r){$r['overdue']=$r['required']&&$r['status']!=='acknowledged'&&$r['deadline']&&$r['deadline']<$now;}unset($r);
        return ['owner'=>$owner,'employee_id'=>$actor,'records'=>$records,'employees'=>$owner?$this->rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' ORDER BY e.full_name"):[]];
    }
    public function detail(int $actor,int $id,bool $lock=false):array{
        $user=$this->actor($actor);$rows=$this->rows('SELECT r.*,i.title,i.message_html,i.version,i.root_id,i.sender_id,i.deadline,i.required,i.created_at FROM portal_ack_recipients r JOIN portal_ack_instructions i ON i.id=r.instruction_id WHERE r.id=?'.($lock?' FOR UPDATE':''),[$id]);
        if(!$rows||($user['role_key']!=='owner_admin'&&(int)$rows[0]['employee_id']!==$actor))throw new \DomainException('This instruction is not assigned to you.');
        return $rows[0]+['events'=>$this->rows('SELECT v.id,v.actor_id,v.event_type,v.message,v.created_at,e.full_name actor_name FROM portal_ack_events v LEFT JOIN ops_employees e ON e.id=v.actor_id WHERE v.recipient_id=? ORDER BY v.id',[$id])];
    }
    private function event(int $recipient,int $actor,string $type,string $message,string $key):void{
        $this->db->prepare('INSERT INTO portal_ack_events(recipient_id,actor_id,event_type,message,request_key,created_at) VALUES(?,?,?,?,?,?)')->execute([$recipient,$actor,$type,$message,$key,$this->now()]);
    }
    public function send(int $actor,array $body):array{
        $this->owner($actor);$key=$this->token((string)($body['request_key']??''));$title=trim((string)($body['title']??''));$html=self::sanitize((string)($body['message_html']??''));
        if($title===''||strlen($title)>190||trim(strip_tags($html))==='')throw new \DomainException('Enter a title and instruction.');
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($body['recipients']??[])))));if(!$ids)throw new \DomainException('Select at least one employee.');
        $deadline=trim((string)($body['deadline']??''));if($deadline!==''){$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$deadline,new \DateTimeZone('Africa/Windhoek'));if(!$d||$d->format('Y-m-d\TH:i')!==$deadline)throw new \DomainException('Choose a valid deadline.');$deadline=$d->format('Y-m-d H:i:s');}
        $this->db->beginTransaction();try{
            $this->owner($actor);$previous=$this->rows('SELECT id,sender_id FROM portal_ack_instructions WHERE request_key=? FOR UPDATE',[$key]);
            if($previous){if((int)$previous[0]['sender_id']!==$actor)throw new \DomainException('Submission key belongs to another sender.');$this->db->commit();return ['instruction_id'=>(int)$previous[0]['id'],'duplicate'=>true];}
            $root=null;$version=1;
            if(!empty($body['revise_instruction'])){$old=$this->rows('SELECT * FROM portal_ack_instructions WHERE id=? FOR UPDATE',[(int)$body['revise_instruction']]);if(!$old)throw new \DomainException('Original instruction unavailable.');$root=(int)($old[0]['root_id']?:$old[0]['id']);$this->rows('SELECT id FROM portal_ack_instructions WHERE id=? FOR UPDATE',[$root]);$versions=$this->rows('SELECT MAX(version) v FROM portal_ack_instructions WHERE id=? OR root_id=?',[$root,$root]);$version=(int)$versions[0]['v']+1;}
            foreach($ids as $id)$this->actor($id);
            $this->db->prepare('INSERT INTO portal_ack_instructions(root_id,version,request_key,title,message_html,sender_id,deadline,required,notify_immediately,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$root,$version,$key,$title,$html,$actor,$deadline?:null,empty($body['required'])?0:1,empty($body['notify_immediately'])?0:1,$this->now()]);$instruction=(int)$this->db->lastInsertId();
            foreach($ids as $id){$employee=$this->actor($id);$this->db->prepare('INSERT INTO portal_ack_recipients(instruction_id,employee_id,employee_name) VALUES(?,?,?)')->execute([$instruction,$id,$employee['full_name']]);$recipient=(int)$this->db->lastInsertId();$this->event($recipient,$actor,'assigned','Instruction assigned',self::uuid());}
            $this->db->commit();return ['instruction_id'=>$instruction,'duplicate'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function uuid():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    public function respond(int $actor,array $body,string $session):void{
        $key=$this->token((string)($body['request_key']??''));$id=(int)($body['recipient_id']??0);$action=(string)($body['action']??'');$message=trim((string)($body['message']??''));
        $this->db->beginTransaction();try{
            $d=$this->detail($actor,$id,true);$prior=$this->rows('SELECT actor_id,recipient_id FROM portal_ack_events WHERE request_key=?',[$key]);
            if($prior){if((int)$prior[0]['actor_id']!==$actor||(int)$prior[0]['recipient_id']!==$id)throw new \DomainException('Invalid repeated request.');$this->db->commit();return;}
            if($d['status']==='acknowledged')throw new \DomainException('Completed acknowledgments cannot be edited. Send a revised instruction instead.');
            if(in_array($action,['acknowledge','clarify'],true)){
                if((int)$d['employee_id']!==$actor)throw new \DomainException('Only the assigned employee may respond.');
                if($action==='acknowledge'){
                    if(($body['understood']??false)!==true)throw new \DomainException('Confirm that you read and understood the instruction.');
                    $this->db->prepare("UPDATE portal_ack_recipients SET status='acknowledged',acknowledged_at=?,session_reference=? WHERE id=?")->execute([$this->now(),hash('sha256',$session),$id]);$message='Employee read and understood instruction version '.$d['version'];
                }else{
                    if($message===''||strlen($message)>6000)throw new \DomainException('Explain what you do not understand (up to 6,000 characters).');
                    $this->db->prepare("UPDATE portal_ack_recipients SET status='clarification' WHERE id=?")->execute([$id]);
                }
            }elseif(in_array($action,['reply','explained_personally'],true)){
                $this->owner($actor);if($message===''||strlen($message)>6000)throw new \DomainException('Enter the clarification or personal explanation.');
                $this->db->prepare("UPDATE portal_ack_recipients SET status='pending' WHERE id=?")->execute([$id]);
            }else throw new \DomainException('Unknown response.');
            $this->event($id,$actor,$action,$message,$key);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function popup(int $actor):?array{
        $this->actor($actor);$rows=$this->rows("SELECT r.id,i.title,i.message_html,i.deadline,i.required FROM portal_ack_recipients r JOIN portal_ack_instructions i ON i.id=r.instruction_id WHERE r.employee_id=? AND r.status<>'acknowledged' AND i.notify_immediately=1 ORDER BY i.created_at DESC,r.id DESC LIMIT 1",[$actor]);return $rows[0]??null;
    }
}
