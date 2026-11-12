<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;

final class Mailbox {
    public function __construct(private \PDO $db, private int $owner) {}
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
