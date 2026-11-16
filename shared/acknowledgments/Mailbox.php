<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;

final class Mailbox {
    private \PDO $db;
    private int $owner;
    public function __construct(\PDO $db, int $owner) {
        $this->db=$db;
        $this->owner=$owner;
    }
    public static function forPortal(\PDO $db):self {
        $configured=getenv('ACK_CONTACT_OWNER_ID');
        if($configured!==false && $configured!=='')return new self($db,ctype_digit($configured)?(int)$configured:0);
        // Production binding verified against the active employee and role before release.
        // Fail closed if this is another installation or the verified identity changes.
        $identity=(new Service($db))->actor(1);
        return new self($db,$identity['full_name']==='Victoria Toivo' && $identity['role_key']==='owner_admin'?1:0);
    }
    private function rows(string $sql,array $params):array {
        $q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function access(int $actor):void {
        $s=new Service($this->db);$s->actor($actor);
        if(!$this->owner || $s->actor($this->owner)['role_key']!=='owner_admin')
            throw new \DomainException('The private inbox owner has not been configured.');
    }
    private function store():\Hambelela\AnonymousIntake\Store {
        require_once dirname(__DIR__,2).'/standalone/ack-anonymous/Store.php';
        return new \Hambelela\AnonymousIntake\Store($this->db,$this->owner);
    }
    public function unnamedReport(int $actor,array $data):array {
        $this->access($actor);return $this->store()->run($data);
    }
    public function registerReports(int $actor):array {
        if($actor!==$this->owner)return [];$this->access($actor);return $this->store()->summaries();
    }
    public function assistanceRows(int $actor,bool $alert=false):array {
        if($actor!==$this->owner)return [];$this->access($actor);
        return $this->rows("SELECT root.id topic,root.employee_id,e.full_name employee_name,root.created_at,LEFT(root.body,80) title,
          m.id message_id,m.created_at latest_date,LEFT(m.body,220) latest_message,
          IF(m.sender_id=root.owner_id,'owner','employee') side,
          (m.sender_id=root.employee_id AND m.id>COALESCE(s.seen,0) AND old_delivery.read_at IS NULL) unread,
          (m.request_key LIKE 'receipt:%') receipt
          FROM portal_ack_assistance root JOIN ops_employees e ON e.id=root.employee_id
          JOIN portal_ack_assistance m ON m.id=(SELECT MAX(x.id) FROM portal_ack_assistance x WHERE x.owner_id=root.owner_id AND x.employee_id=root.employee_id AND x.topic_id=root.id)
          LEFT JOIN portal_ack_assistance_seen s ON s.owner_id=root.owner_id AND s.topic_id=root.id
          LEFT JOIN notifications old_notice ON old_notice.deduplication_key=CONCAT('ack-private-',m.id)
          LEFT JOIN notification_recipients old_delivery ON old_delivery.notification_id=old_notice.id AND old_delivery.employee_id=root.owner_id
          WHERE root.owner_id=? AND root.id=root.topic_id ".
          ($alert?"AND m.sender_id=root.employee_id AND m.request_key NOT LIKE 'receipt:%' AND old_delivery.popup_seen_at IS NULL AND old_delivery.cleared_at IS NULL AND old_delivery.read_at IS NULL AND m.id>GREATEST(COALESCE(s.seen,0),COALESCE(s.alerted,0)) ORDER BY m.id LIMIT 1":"ORDER BY m.id DESC"),[$actor]);
    }
    public function assistanceThread(int $actor,array $input):array {
        if($actor!==$this->owner)throw new \DomainException('Private conversation unavailable.');
        $this->access($actor);$topic=(int)($input['topic']??0);
        $root=$this->rows('SELECT employee_id FROM portal_ack_assistance WHERE owner_id=? AND topic_id=id AND id=?',[$actor,$topic]);
        if(!$root)throw new \DomainException('Private conversation unavailable.');
        $employee=(int)$root[0]['employee_id'];
        if(isset($input['after'])&&!isset($input['message'])&&!isset($input['dismiss'])){
            $head=$this->rows('SELECT MAX(id) head FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? AND topic_id=?',[$actor,$employee,$topic]);
            if((int)$head[0]['head']===(int)$input['after'])return ['unchanged'=>true];
        }
        if(isset($input['message']))$this->send($actor,['employee'=>$employee,'topic'=>$topic,'message'=>$input['message'],'request_key'=>$input['request_key']??'']);
        $messages=$this->rows("SELECT id,IF(sender_id=owner_id,'owner','employee') side,body,created_at FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? AND topic_id=? ORDER BY id",[$actor,$employee,$topic]);
        $last=(int)end($messages)['id'];$later=isset($input['dismiss']);$through=$later?min($last,max(0,(int)$input['dismiss'])):$last;
        $this->db->prepare('INSERT INTO portal_ack_assistance_seen(owner_id,topic_id,seen,alerted) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE seen=GREATEST(seen,VALUES(seen)),alerted=GREATEST(alerted,VALUES(alerted))')->execute([$actor,$topic,$later?0:$through,$through]);
        return $later?['ok'=>true]:['messages'=>$messages,'cursor'=>$last];
    }
    public function reportAlert(int $actor):?array {
        if($actor!==$this->owner)return null;$this->access($actor);return $this->store()->alert();
    }
    public function reportAction(int $actor,array $body):array {
        if($actor!==$this->owner)throw new \DomainException('Report unavailable.');
        $this->access($actor);$id=(string)($body['report']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \DomainException('Report unavailable.');
        if(isset($body['dismiss'])){$this->store()->dismiss($id,(int)$body['dismiss']);return ['ok'=>true];}
        return $this->store()->ownerThread($id,$body);
    }
    public function inbox(int $actor,int $selected=0):array {
        $this->access($actor);$owner=$actor===$this->owner;
        if(!$owner && $selected && $selected!==$actor)throw new \DomainException('This conversation is private.');
        $employee=$owner?$selected:$actor;
        return ['owner'=>$owner,'employee'=>$employee,
            'threads'=>$owner?$this->rows('SELECT a.employee_id,e.full_name,MAX(a.id) latest FROM portal_ack_assistance a JOIN ops_employees e ON e.id=a.employee_id WHERE a.owner_id=? GROUP BY a.employee_id,e.full_name ORDER BY latest DESC',[$this->owner]):[],
            'messages'=>$employee?$this->rows("SELECT id,topic_id,sender_id,body,created_at,(request_key LIKE 't:%') starts_topic FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? ORDER BY id",[$this->owner,$employee]):[],
            'reports'=>[]]; // Reports are managed in the unified register only.
    }
    // One prompt per conversation, for its latest incoming message only. Reading the
    // existing ledger also picks up unanswered questions sent before prompts existed.
    public function attention(int $actor):array {
        $this->access($actor);
        $owner=$actor===$this->owner;
        // Latest substantive message per thread determines whose turn it is.
        // Delivery, opening a popup, and Respond Later do not resolve a conversation.
        $sql="SELECT COUNT(*) n FROM portal_ack_assistance a JOIN
            (SELECT MAX(id) last_id FROM portal_ack_assistance WHERE owner_id=? ".
            ($owner?'':'AND employee_id=? ')."AND request_key NOT LIKE 'receipt:%' GROUP BY employee_id,topic_id) t ON t.last_id=a.id
            WHERE a.sender_id=".($owner?'a.employee_id':'a.owner_id')." AND NOT EXISTS
            (SELECT 1 FROM portal_ack_assistance r WHERE r.employee_id=a.employee_id AND r.owner_id=a.owner_id
             AND r.sender_id=a.employee_id AND r.request_key=CONCAT('receipt:',a.id))";
        $chats=(int)$this->rows($sql,$owner?[$this->owner]:[$this->owner,$actor])[0]['n'];
        $reports=$owner?(int)$this->rows("SELECT COUNT(*) n FROM portal_ack_anonymous r JOIN portal_ack_report_messages m ON m.id=(SELECT MAX(t.id) FROM portal_ack_report_messages t WHERE t.report_id=r.report_id) WHERE r.owner_id=? AND m.side='reporter'",[$actor])[0]['n']:0;
        return ['private'=>$chats,'reports'=>$reports];
    }
    public function pendingPrompt(int $actor):?array {
        $this->access($actor);
        $scope=$actor===$this->owner?'a.sender_id=a.employee_id':'a.employee_id=? AND a.sender_id=a.owner_id';
        $args=[$actor,$this->owner];if($actor!==$this->owner)$args[]=$actor;
        $r=$this->rows("SELECT a.id,a.employee_id FROM portal_ack_assistance a
            LEFT JOIN notifications n ON n.deduplication_key=CONCAT('ack-private-',a.id)
            LEFT JOIN notification_recipients r ON r.notification_id=n.id AND r.employee_id=?
            WHERE a.owner_id=? AND $scope AND a.owner_id<>a.employee_id AND a.request_key NOT LIKE 'receipt:%'
              AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance newer WHERE newer.owner_id=a.owner_id AND newer.employee_id=a.employee_id AND newer.topic_id=a.topic_id AND newer.id>a.id AND (newer.request_key NOT LIKE 'receipt:%' OR newer.request_key=CONCAT('receipt:',a.id)))
              AND r.popup_seen_at IS NULL AND r.read_at IS NULL AND r.cleared_at IS NULL
              AND (r.next_reminder_at IS NULL OR r.next_reminder_at<=NOW())
            ORDER BY a.id LIMIT 1",$args);
        return $r?['id'=>(int)$r[0]['id'],'thread'=>(int)$r[0]['employee_id'],'owner'=>$actor===$this->owner]:null;
    }
    public function claimPrompt(int $actor,int $message,callable $notify,string $base):bool {
        $p=$this->pendingPrompt($actor);if(!$p||$p['id']!==$message)return false;
        $n=$notify(['module'=>'system','related_type'=>'ack_private_message','related_id'=>$message,
            'created_by'=>$p['owner']?$p['thread']:$this->owner,'required_delivery'=>true,
            'title'=>$p['owner']?'Private assistance request':'Private reply from the Owner',
            'message'=>'Open your private conversation to respond.',
            'deduplication_key'=>'ack-private-'.$message,'action_link'=>$base.($p['owner']?'/apps/acknowledgments/index.php':'/apps/acknowledgments/messages.php?thread='.$p['thread'].'#private')],[$actor]);
        if(!$n)throw new \RuntimeException('Private notification not ready.');
        $q=$this->db->prepare('UPDATE notification_recipients SET next_reminder_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE employee_id=? AND notification_id=? AND popup_seen_at IS NULL AND cleared_at IS NULL AND read_at IS NULL AND (next_reminder_at IS NULL OR next_reminder_at<=NOW())');
        $q->execute([$actor,$n]);return $q->rowCount()===1;
    }
    public function dismissPrompt(int $actor,int $message):void {
        $this->access($actor);
        $q=$this->db->prepare("UPDATE notification_recipients r JOIN notifications n ON n.id=r.notification_id SET r.popup_seen_at=COALESCE(r.popup_seen_at,NOW()),r.next_reminder_at=NULL WHERE r.employee_id=? AND n.related_type='ack_private_message' AND n.deduplication_key=?");
        $q->execute([$actor,'ack-private-'.$message]);
    }
    public function awaitingReply(int $actor):array {
        $this->access($actor);
        if($actor!==$this->owner)return [];
        return $this->rows("SELECT a.id,e.full_name,a.created_at FROM portal_ack_assistance a
            JOIN ops_employees e ON e.id=a.employee_id WHERE a.owner_id=? AND a.sender_id=a.employee_id
            AND a.request_key NOT LIKE 'receipt:%' AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance b
            WHERE b.owner_id=a.owner_id AND b.employee_id=a.employee_id AND b.id>a.id AND b.request_key NOT LIKE 'receipt:%') ORDER BY a.id",[$actor]);
    }
    public function incoming(int $actor,int $id):array {
        $this->access($actor);$owner=$actor===$this->owner;
        $row=$this->rows("SELECT a.id,a.topic_id,a.employee_id,a.body,a.created_at,e.full_name FROM portal_ack_assistance a
            JOIN ops_employees e ON e.id=a.sender_id WHERE a.id=? AND a.owner_id=? AND a.request_key NOT LIKE 'receipt:%' AND ".
            ($owner?'a.sender_id=a.employee_id':'a.sender_id=a.owner_id AND a.employee_id=?'),
            $owner?[$id,$this->owner]:[$id,$this->owner,$actor]);
        if(!$row)throw new \DomainException('This message is not available to you.');
        return $row[0]+['owner'=>$owner];
    }
    public function promptAction(int $actor,int $id,string $action,array $input):void {
        $msg=$this->incoming($actor,$id);
        $this->db->beginTransaction();
        try{
            if($action==='reply'){
                $this->send($actor,['employee'=>(int)$msg['employee_id'],'topic'=>(int)$msg['topic_id'],'request_key'=>$input['request_key']??'','message'=>$input['message']??'']);
            }elseif($action==='later'){
                // Hide this prompt only. Either recipient still has an outstanding conversation.
            }elseif($action==='understood'&&!$msg['owner']){
                // Reserved key cannot be supplied by a normal send. One explicit receipt per reply.
                $q=$this->db->prepare("INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,body,request_key,topic_id,created_at)
                    VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id");
                $q->execute([$actor,$this->owner,$actor,'I have read and understood your response.','receipt:'.$id,$msg['topic_id'],
                    (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s')]);
            }else throw new \DomainException('This action is not available.');
            $this->dismissPrompt($actor,$id);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function send(int $actor,array $input):void {
        $this->access($actor);$text=trim((string)($input['message']??''));
        if($text===''||strlen($text)>6000)throw new \DomainException('Enter a message of up to 6,000 bytes.');
        if(isset($input['report'])){$this->reportAction($actor,$input);return;}
        $employee=(int)($input['employee']??$actor);$key=(string)($input['request_key']??'');
        if($employee===$this->owner||($actor!==$this->owner&&$employee!==$actor)||!preg_match('/^[a-f0-9-]{36}$/D',$key))throw new \DomainException('Invalid conversation.');
        if($actor===$this->owner&&!$this->rows('SELECT id FROM portal_ack_assistance WHERE employee_id=? AND owner_id=? LIMIT 1',[$employee,$actor]))throw new \DomainException('Choose an existing conversation.');
        if(($input['new_topic']??false)===true)$key='t:'.str_replace('-','',$key);
        $tx=!$this->db->inTransaction();if($tx)$this->db->beginTransaction();
        try{
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$employee]);
            $topic=(int)($input['topic']??0);$new=($input['new_topic']??false)===true;
            $prev=$this->rows('SELECT employee_id,body,owner_id,topic_id FROM portal_ack_assistance WHERE sender_id=? AND request_key=?',[$actor,$key]);
            if($prev){$r=$prev[0];if((int)$r['employee_id']!==$employee||(int)$r['owner_id']!==$this->owner||$r['body']!==$text||(!$new&&$topic&&(int)$r['topic_id']!==$topic))throw new \DomainException('Invalid message retry.');}
            else{
                if($new){if($actor!==$employee)throw new \DomainException('Only an employee may start a request.');$topic=0;}
                elseif($topic){if(!$this->rows('SELECT id FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? AND id=topic_id AND id=?',[$this->owner,$employee,$topic]))throw new \DomainException('Private conversation unavailable.');}
                else{$last=$this->rows('SELECT topic_id FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? ORDER BY id DESC LIMIT 1',[$this->owner,$employee]);$topic=(int)($last[0]['topic_id']??0);}
                $this->db->prepare('INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,body,request_key,created_at,topic_id) VALUES(?,?,?,?,?,?,?)')
                  ->execute([$employee,$this->owner,$actor,$text,$key,(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s'),$topic?:null]);
                if(!$topic)$this->db->prepare('UPDATE portal_ack_assistance SET topic_id=id WHERE id=?')->execute([(int)$this->db->lastInsertId()]);
            }
            if($tx)$this->db->commit();
        }catch(\Throwable $e){if($tx)$this->db->rollBack();throw $e;}
    }
}
