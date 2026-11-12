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
    public function inbox(int $actor,int $selected=0):array {
        $this->access($actor);$owner=$actor===$this->owner;
        if(!$owner && $selected && $selected!==$actor)throw new \DomainException('This conversation is private.');
        $employee=$owner?$selected:$actor;
        return ['owner'=>$owner,'employee'=>$employee,
            'threads'=>$owner?$this->rows('SELECT a.employee_id,e.full_name,MAX(a.id) latest FROM portal_ack_assistance a JOIN ops_employees e ON e.id=a.employee_id WHERE a.owner_id=? GROUP BY a.employee_id,e.full_name ORDER BY latest DESC',[$this->owner]):[],
            'messages'=>$employee?$this->rows('SELECT sender_id,body,created_at FROM portal_ack_assistance WHERE owner_id=? AND employee_id=? ORDER BY id',[$this->owner,$employee]):[],
            'reports'=>$owner?$this->rows('SELECT report_id,body,reply FROM portal_ack_anonymous WHERE owner_id=? ORDER BY CASE WHEN reply IS NULL THEN 0 ELSE 1 END,report_id',[$this->owner]):[]];
    }
    // One prompt per conversation, for its latest incoming message only. Reading the
    // existing ledger also picks up unanswered questions sent before prompts existed.
    public function pendingPrompt(int $actor):?array {
        $this->access($actor);
        $scope=$actor===$this->owner?'a.sender_id=a.employee_id':'a.employee_id=? AND a.sender_id=a.owner_id';
        $args=[$actor,$this->owner];if($actor!==$this->owner)$args[]=$actor;
        $r=$this->rows("SELECT a.id,a.employee_id FROM portal_ack_assistance a
            LEFT JOIN notifications n ON n.deduplication_key=CONCAT('ack-private-',a.id)
            LEFT JOIN notification_recipients r ON r.notification_id=n.id AND r.employee_id=?
            WHERE a.owner_id=? AND $scope AND a.owner_id<>a.employee_id AND a.request_key NOT LIKE 'receipt:%'
              AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance newer WHERE newer.owner_id=a.owner_id AND newer.employee_id=a.employee_id AND newer.id>a.id AND (newer.request_key NOT LIKE 'receipt:%' OR newer.request_key=CONCAT('receipt:',a.id)))
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
            'deduplication_key'=>'ack-private-'.$message,'action_link'=>$base.'/apps/acknowledgments/messages.php?thread='.$p['thread'].'#private'],[$actor]);
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
        $row=$this->rows("SELECT a.id,a.employee_id,a.body,a.created_at,e.full_name FROM portal_ack_assistance a
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
                $this->send($actor,['employee'=>(int)$msg['employee_id'],'request_key'=>$input['request_key']??'','message'=>$input['message']??'']);
            }elseif($action==='later'&&$msg['owner']){
                // The unanswered conversation stays in the Owner queue until a reply is saved.
            }elseif($action==='understood'&&!$msg['owner']){
                // Reserved key cannot be supplied by a normal send. One explicit receipt per reply.
                $q=$this->db->prepare("INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,body,request_key,created_at)
                    VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id");
                $q->execute([$actor,$this->owner,$actor,'I have read and understood your response.','receipt:'.$id,
                    (new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s')]);
            }else throw new \DomainException('This action is not available.');
            $this->dismissPrompt($actor,$id);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function send(int $actor,array $input):void {
        $this->access($actor);$text=trim((string)($input['message']??''));
        if($text===''||strlen($text)>6000)throw new \DomainException('Enter a message of up to 6,000 bytes.');
        if(isset($input['report'])){
            if($actor!==$this->owner)throw new \DomainException('Only the inbox owner can reply.');
            $id=(string)$input['report'];
            if(!$this->rows('SELECT report_id FROM portal_ack_anonymous WHERE owner_id=? AND report_id=?',[$actor,$id]))throw new \DomainException('Report unavailable.');
            $this->db->prepare('UPDATE portal_ack_anonymous SET reply=? WHERE owner_id=? AND report_id=?')->execute([$text,$actor,$id]);return;
        }
        $employee=(int)($input['employee']??$actor);$key=(string)($input['request_key']??'');
        if($employee===$this->owner||($actor!==$this->owner&&$employee!==$actor)||!preg_match('/^[a-f0-9-]{36}$/D',$key))throw new \DomainException('Invalid conversation.');
        if($actor===$this->owner&&!$this->rows('SELECT id FROM portal_ack_assistance WHERE employee_id=? AND owner_id=? LIMIT 1',[$employee,$actor]))throw new \DomainException('Choose an existing conversation.');
        try{$this->db->prepare('INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,body,request_key,created_at) VALUES(?,?,?,?,?,?)')
            ->execute([$employee,$this->owner,$actor,$text,$key,(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s')]);}
        catch(\PDOException $e){$r=$this->rows('SELECT employee_id,body,owner_id FROM portal_ack_assistance WHERE sender_id=? AND request_key=?',[$actor,$key]);
            if(!$r||(int)$r[0]['employee_id']!==$employee||(int)$r[0]['owner_id']!==$this->owner||$r[0]['body']!==$text)throw $e;}
    }
}
