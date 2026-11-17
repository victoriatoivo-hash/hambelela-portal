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
    public function isDesignatedOwner(int $actor):bool {
        return $actor===$this->owner && $actor>0 && (new Service($this->db))->actor($actor)['role_key']==='owner_admin';
    }
    public function removeForOwner(int $actor,string $kind,int $topic,int $head,int $expected):void {
        if(!$this->isDesignatedOwner($actor))throw new \DomainException('Only the designated Owner can delete a chat from their inbox.');
        if($head!==$expected||$head<=0)throw new \DomainException('A newer message arrived. Read it before deleting.');
        $this->db->prepare('INSERT INTO portal_ack_owner_chat_deletions(owner_id,kind,thread_id,through_id,removed_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE through_id=GREATEST(through_id,VALUES(through_id)),removed_at=UTC_TIMESTAMP()')->execute([$actor,$kind,$topic,$head]);
    }
    private const VISIBLE="NOT EXISTS (SELECT 1 FROM portal_ack_owner_chat_deletions h WHERE h.owner_id=a.owner_id AND h.kind='assistance' AND h.thread_id=a.topic_id AND h.through_id>=a.id)";
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
          (m.sender_id=root.employee_id AND m.request_key NOT LIKE 'later:%' AND m.id>COALESCE(s.seen,0) AND old_delivery.read_at IS NULL) unread,
          (m.request_key LIKE 'receipt:%') receipt,CASE WHEN m.request_key LIKE 'end:%' THEN 'Closed by Management' WHEN m.request_key LIKE 'ack:%' THEN 'Closed – Acknowledged' WHEN m.request_key LIKE 'later:%' THEN 'Respond Later' WHEN m.request_key LIKE 'receipt:%' THEN 'Reply received' WHEN m.sender_id=root.owner_id THEN 'Awaiting Employee Response' ELSE 'Awaiting Admin Response' END status
          FROM portal_ack_assistance root JOIN ops_employees e ON e.id=root.employee_id
          JOIN portal_ack_assistance m ON m.id=(SELECT MAX(x.id) FROM portal_ack_assistance x WHERE x.owner_id=root.owner_id AND x.employee_id=root.employee_id AND x.topic_id=root.id AND x.request_key NOT LIKE 'later:%')
          LEFT JOIN portal_ack_assistance_seen s ON s.owner_id=root.owner_id AND s.topic_id=root.id
          LEFT JOIN notifications old_notice ON old_notice.deduplication_key=CONCAT('ack-private-',m.id)
          LEFT JOIN notification_recipients old_delivery ON old_delivery.notification_id=old_notice.id AND old_delivery.employee_id=root.owner_id
          WHERE root.owner_id=? AND root.id=root.topic_id AND NOT EXISTS (SELECT 1 FROM portal_ack_owner_chat_deletions h WHERE h.owner_id=root.owner_id AND h.kind='assistance' AND h.thread_id=root.id AND h.through_id>=m.id) ".
          ($alert?"AND m.sender_id=root.employee_id AND m.request_key NOT LIKE 'later:%' AND m.request_key NOT LIKE 'receipt:%' AND old_delivery.popup_seen_at IS NULL AND old_delivery.cleared_at IS NULL AND old_delivery.read_at IS NULL AND m.id>GREATEST(COALESCE(s.seen,0),COALESCE(s.alerted,0)) ORDER BY m.id LIMIT 1":"ORDER BY m.id DESC"),[$actor]);
    }
    // Both participants use one topic-bound dialog. All writers take the same employee lock as send().
    public function dialog(int $actor,array $p):array {
        $this->access($actor);$topic=(int)($p['id']??0);
        $root=$this->rows('SELECT employee_id FROM portal_ack_assistance WHERE owner_id=? AND id=? AND topic_id=id',[$this->owner,$topic]);
        if(!$root||($actor!==$this->owner&&$actor!==(int)$root[0]['employee_id']))throw new \DomainException('Private conversation unavailable.');
        $emp=(int)$root[0]['employee_id'];$owner=$actor===$this->owner;
        $this->db->beginTransaction();
        try{
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$emp]);
            $head=function()use($topic){return $this->rows('SELECT id,sender_id,request_key,body,created_at FROM portal_ack_assistance WHERE topic_id=? AND owner_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$topic,$this->owner])[0];};
            $h=$head();$closed=static function($h){return strpos($h['request_key'],'ack:')===0||strpos($h['request_key'],'end:')===0;};$op=$p['op']??'open';
            if($op==='delete_for_me'){
                $this->removeForOwner($actor,'assistance',$topic,(int)$h['id'],(int)($p['expected']??0));
                $this->db->prepare("UPDATE notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN portal_ack_assistance a ON n.deduplication_key=CONCAT('ack-private-',a.id) SET r.cleared_at=COALESCE(r.cleared_at,NOW()),r.next_reminder_at=NULL WHERE r.employee_id=? AND n.related_type='ack_private_message' AND a.owner_id=? AND a.topic_id=? AND a.id<=?")->execute([$actor,$actor,$topic,$h['id']]);
                $this->db->commit();return ['removed'=>true];
            }
            if($op==='reply')$this->send($actor,['employee'=>$emp,'topic'=>$topic,'message'=>$p['message']??'','request_key'=>$p['request_key']??'']);
            elseif(in_array($op,['ack','end','later'],true)){
                if(($op==='end'&&!$owner)||($op==='ack'&&$owner))throw new \DomainException('This action is not available.');
                if(!$closed($h)){
                    if((int)($p['expected']??0)!==(int)$h['id'])throw new \DomainException('A newer message arrived. Read it before continuing.');
                    $incoming=$this->rows("SELECT sender_id FROM portal_ack_assistance WHERE topic_id=? AND request_key NOT LIKE 'later:%' ORDER BY id DESC LIMIT 1",[$topic]);
                    if($op==='ack'&&(int)$incoming[0]['sender_id']===$actor)throw new \DomainException('No incoming message to acknowledge.');
                    if($op!=='later'){
                        $text=['ack'=>'Closed – Acknowledged','end'=>'Closed by Management','later'=>'Respond Later'][$op];
                        $this->db->prepare('INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,topic_id,request_key,body,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$emp,$this->owner,$actor,$topic,$op.':'.$h['id'],$text,(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s')]);
                    }
                }
            }elseif(!in_array($op,['open','poll'],true))throw new \DomainException('Unknown conversation action.');
            $h=$head();$last=(int)$h['id'];$done=$closed($h);
            if($op==='poll'&&(int)($p['after']??0)===$last)$out=['unchanged'=>true];
            else{
                $this->db->prepare('INSERT INTO portal_ack_assistance_seen(owner_id,topic_id,seen,alerted) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE seen=GREATEST(seen,VALUES(seen)),alerted=GREATEST(alerted,VALUES(alerted))')->execute([$actor,$topic,$last,$last]);
                $messages=$this->rows('SELECT m.id,m.body,m.created_at,e.full_name sender,(m.sender_id=?) mine FROM portal_ack_assistance m JOIN ops_employees e ON e.id=m.sender_id WHERE m.owner_id=? AND m.topic_id=? ORDER BY m.id',[$actor,$this->owner,$topic]);
                $incoming=$this->rows("SELECT sender_id FROM portal_ack_assistance WHERE topic_id=? AND request_key NOT LIKE 'later:%' ORDER BY id DESC LIMIT 1",[$topic]);
                $out=['messages'=>$messages,'last'=>$last,'closed'=>$done,'awaiting_owner'=>(int)$incoming[0]['sender_id']===$emp,'owner'=>$owner,'can_remove'=>$owner,'can_ack'=>!$owner&&!$done&&(int)$incoming[0]['sender_id']!==$actor,'closure'=>$done?$h['body']:null,'closed_at'=>$done?$h['created_at']:null,'closed_by'=>$done?end($messages)['sender']:null];
            }
            $this->db->commit();return $out;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
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
            'threads'=>$owner?$this->rows('SELECT a.employee_id,e.full_name,MAX(a.id) latest FROM portal_ack_assistance a JOIN ops_employees e ON e.id=a.employee_id WHERE a.owner_id=? AND '.self::VISIBLE.' GROUP BY a.employee_id,e.full_name ORDER BY latest DESC',[$this->owner]):[],
            'messages'=>$employee?$this->rows("SELECT id,topic_id,sender_id,body,created_at,(request_key LIKE 't:%') starts_topic FROM portal_ack_assistance a WHERE owner_id=? AND employee_id=? ".($owner?"AND EXISTS(SELECT 1 FROM portal_ack_assistance b WHERE b.topic_id=a.topic_id AND b.owner_id=a.owner_id AND ".str_replace('a.','b.',self::VISIBLE).") ":'')."ORDER BY id",[$this->owner,$employee]):[],
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
            ($owner?'':'AND employee_id=? ')."AND request_key NOT LIKE 'receipt:%' AND request_key NOT LIKE 'later:%' GROUP BY employee_id,topic_id) t ON t.last_id=a.id
            WHERE a.request_key NOT LIKE 'ack:%' AND a.request_key NOT LIKE 'end:%' AND a.sender_id=".($owner?'a.employee_id':'a.owner_id')." AND NOT EXISTS
            (SELECT 1 FROM portal_ack_assistance r WHERE r.employee_id=a.employee_id AND r.owner_id=a.owner_id
             AND r.sender_id=a.employee_id AND r.request_key=CONCAT('receipt:',a.id))";
        if($owner)$sql.=' AND '.self::VISIBLE;
        $chats=(int)$this->rows($sql,$owner?[$this->owner]:[$this->owner,$actor])[0]['n'];
        $reports=$owner?(int)$this->rows("SELECT COUNT(*) n FROM portal_ack_anonymous r JOIN portal_ack_report_messages m ON m.id=(SELECT MAX(t.id) FROM portal_ack_report_messages t WHERE t.report_id=r.report_id) WHERE r.owner_id=? AND m.side='reporter' AND m.message_key<>'close:ack'",[$actor])[0]['n']:0;
        return ['private'=>$chats,'reports'=>$reports];
    }
    public function pendingPrompt(int $actor):?array {
        $this->access($actor);
        $scope=$actor===$this->owner?'a.sender_id=a.employee_id':'a.employee_id=? AND a.sender_id=a.owner_id';
        if($actor===$this->owner)$scope.=' AND '.self::VISIBLE;
        $args=[$actor,$this->owner];if($actor!==$this->owner)$args[]=$actor;
        $r=$this->rows("SELECT a.id,a.employee_id,a.topic_id FROM portal_ack_assistance a
            LEFT JOIN notifications n ON n.deduplication_key=CONCAT('ack-private-',a.id)
            LEFT JOIN notification_recipients r ON r.notification_id=n.id AND r.employee_id=?
            WHERE a.owner_id=? AND $scope AND a.owner_id<>a.employee_id AND a.request_key NOT LIKE 'receipt:%' AND a.request_key NOT LIKE 'later:%'
              AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance_seen v WHERE v.topic_id=a.topic_id AND v.owner_id=".($actor===$this->owner?'a.owner_id':'a.employee_id')." AND v.seen>=a.id)
              AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance newer WHERE newer.owner_id=a.owner_id AND newer.employee_id=a.employee_id AND newer.topic_id=a.topic_id AND newer.id>a.id AND (newer.request_key NOT LIKE 'receipt:%' OR newer.request_key=CONCAT('receipt:',a.id)))
              AND r.popup_seen_at IS NULL AND r.read_at IS NULL AND r.cleared_at IS NULL
              AND (r.next_reminder_at IS NULL OR r.next_reminder_at<=NOW())
            ORDER BY a.id LIMIT 1",$args);
        return $r?['id'=>(int)$r[0]['id'],'topic'=>(int)$r[0]['topic_id'],'thread'=>(int)$r[0]['employee_id'],'owner'=>$actor===$this->owner]:null;
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
        return $this->rows("SELECT a.id,a.topic_id,e.full_name,a.created_at FROM portal_ack_assistance a
            JOIN ops_employees e ON e.id=a.employee_id WHERE a.owner_id=? AND a.sender_id=a.employee_id AND ".self::VISIBLE."
            AND a.request_key NOT LIKE 'receipt:%' AND a.request_key NOT LIKE 'later:%' AND a.request_key NOT LIKE 'ack:%' AND a.request_key NOT LIKE 'end:%' AND NOT EXISTS(SELECT 1 FROM portal_ack_assistance b
            WHERE b.owner_id=a.owner_id AND b.topic_id=a.topic_id AND b.id>a.id AND b.request_key NOT LIKE 'later:%') ORDER BY a.id",[$actor]);
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
        if($action==='understood'){$this->dialog($actor,['id'=>$msg['topic_id'],'op'=>'ack','expected'=>$id]);$this->dismissPrompt($actor,$id);return;}
        $this->db->beginTransaction();
        try{
            if($action==='reply'){
                $this->send($actor,['employee'=>(int)$msg['employee_id'],'topic'=>(int)$msg['topic_id'],'request_key'=>$input['request_key']??'','message'=>$input['message']??'']);
            }elseif($action==='later'){
                // Hide this prompt only. Either recipient still has an outstanding conversation.
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
                if($topic&&$this->rows("SELECT id FROM portal_ack_assistance WHERE owner_id=? AND topic_id=? AND (request_key LIKE 'end:%' OR request_key LIKE 'ack:%') LIMIT 1 FOR UPDATE",[$this->owner,$topic]))throw new \DomainException('This conversation is closed.');
                $this->db->prepare('INSERT INTO portal_ack_assistance(employee_id,owner_id,sender_id,body,request_key,created_at,topic_id) VALUES(?,?,?,?,?,?,?)')
                  ->execute([$employee,$this->owner,$actor,$text,$key,(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s'),$topic?:null]);
                if(!$topic)$this->db->prepare('UPDATE portal_ack_assistance SET topic_id=id WHERE id=?')->execute([(int)$this->db->lastInsertId()]);
            }
            if($tx)$this->db->commit();
        }catch(\Throwable $e){if($tx)$this->db->rollBack();throw $e;}
    }
}
