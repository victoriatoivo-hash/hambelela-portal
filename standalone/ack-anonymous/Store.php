<?php
declare(strict_types=1);
namespace Hambelela\AnonymousIntake;
final class Store {
    public function __construct(private \PDO $db,private int $owner){}
    public function run(array $data):array {
        $code=strtoupper(str_replace('-','',trim((string)($data['code']??''))));
        if(!preg_match('/^[A-F0-9]{32}$/D',$code)||$this->owner<1)throw new \DomainException('Check the private access code.');
        $hash=hash('sha256',$code);$action=$data['action']??'';
        if($action==='check'){
            $s=$this->db->prepare('SELECT reply FROM portal_ack_anonymous WHERE lookup_hash=? AND owner_id=?');$s->execute([$hash,$this->owner]);$r=$s->fetch(\PDO::FETCH_ASSOC);
            if(!$r)throw new \DomainException('No report was found for that code.');
            return ['reply'=>$r['reply']];
        }
        if($action!=='submit')throw new \DomainException('Choose an action.');
        $body=trim((string)($data['body']??''));if($body===''||strlen($body)>6000)throw new \DomainException('Enter a report of up to 6,000 bytes.');
        try{$this->db->prepare('INSERT INTO portal_ack_anonymous(report_id,lookup_hash,owner_id,body) VALUES(?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)),$hash,$this->owner,$body]);}
        catch(\PDOException $e){$s=$this->db->prepare('SELECT body FROM portal_ack_anonymous WHERE lookup_hash=? AND owner_id=?');$s->execute([$hash,$this->owner]);if($s->fetchColumn()!==$body)throw $e;}
        return ['saved'=>true];
    }
}
