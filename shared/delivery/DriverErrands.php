<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
final class DriverErrands
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    public function install():void
    {
        try{$this->db->query('SELECT id FROM delivery_errands LIMIT 0');$this->db->query('SELECT errand_id FROM delivery_errand_details LIMIT 0');$this->db->query('SELECT id FROM delivery_errand_events LIMIT 0');return;}
        catch(\PDOException $e){if($e->getCode()!=='42S02')throw $e;}
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_errands (
          id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, created_by INT NOT NULL, driver_id INT NOT NULL,
          request_key CHAR(32) NOT NULL, place VARCHAR(255) NOT NULL, contact_name VARCHAR(190) NOT NULL,
          contents VARCHAR(1500) NOT NULL, payment_state ENUM('paid','unpaid') NOT NULL,
          created_at DATETIME NOT NULL, seen_at DATETIME NULL, collected_at DATETIME NULL,
          UNIQUE KEY request_once(created_by,request_key), KEY driver_queue(driver_id,collected_at,seen_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Additive sidecars: original assignments and timestamps are not rewritten by migration.
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_errand_details (
          errand_id BIGINT UNSIGNED PRIMARY KEY, revision INT NOT NULL DEFAULT 1,
          state VARCHAR(20) NOT NULL DEFAULT 'open', amount DECIMAL(12,2) NULL,
          payer VARCHAR(190) NOT NULL DEFAULT '', note VARCHAR(1500) NOT NULL DEFAULT '',
          updated_at DATETIME NULL, claim_key CHAR(32) NULL, claim_until DATETIME NULL,
          snooze_until DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_errand_events (
          id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, errand_id BIGINT UNSIGNED NOT NULL,
          actor_id INT NOT NULL, action VARCHAR(30) NOT NULL, before_json MEDIUMTEXT NULL,
          after_json MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL,
          KEY errand_history(errand_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function front(array $a):void{if(!DeliveryPolicy::can($a,'delivery_arrange'))throw new \DomainException('Delivery arrangement access required.');}
    private function driver(array $a):void{if(($a['role']??'')!=='delivery_driver'||!DeliveryPolicy::can($a,'delivery_drive'))throw new \DomainException('Assigned driver access required.');}
    private const DRIVERS="SELECT e.id,e.full_name FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE p.active=1 AND e.status='active' AND r.role_key='delivery_driver'";
    public function drivers(array $a):array{$this->front($a);return $this->db->query(self::DRIVERS.' ORDER BY e.full_name')->fetchAll(\PDO::FETCH_ASSOC);}
    public function send(array $a,array $v):int
    {
        $this->front($a);
        foreach(['place'=>255,'contact_name'=>190,'contents'=>1500] as $field=>$max){
            $v[$field]=trim((string)($v[$field]??''));if($v[$field]===''||mb_strlen($v[$field])>$max)throw new \DomainException('Enter the pickup place, contact person and items to collect within the field limits.');
        }
        if(!in_array($v['payment_state']??'',['paid','unpaid'],true))throw new \DomainException('Choose Paid or Not paid.');
        if(!preg_match('/^[a-f0-9]{32}$/D',(string)($v['request_key']??'')))throw new \DomainException('Reload the form and try again.');
        [$amount,$payer]=$this->payment($v);
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare(self::DRIVERS.' AND e.id=? FOR UPDATE');$s->execute([(int)($v['driver_id']??0)]);if(!$s->fetch())throw new \DomainException('Choose an active driver.');
            $s=$this->db->prepare('INSERT INTO delivery_errands(created_by,driver_id,request_key,place,contact_name,contents,payment_state,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            $s->execute([$a['id'],$v['driver_id'],$v['request_key'],$v['place'],$v['contact_name'],$v['contents'],$v['payment_state']]);$id=(int)$this->db->lastInsertId();
            $s=$this->db->prepare('SELECT driver_id,place,contact_name,contents,payment_state FROM delivery_errands WHERE id=?');$s->execute([$id]);$saved=$s->fetch(\PDO::FETCH_ASSOC);
            foreach($saved as $k=>$val)if((string)$v[$k]!== (string)$val)throw new \DomainException('This form was already sent with different details. Open a new form for a new errand.');
            $s=$this->db->prepare('SELECT errand_id,amount,payer FROM delivery_errand_details WHERE errand_id=?');$s->execute([$id]);$details=$s->fetch(\PDO::FETCH_ASSOC);
            if($details){if($details['amount']!==$amount||$details['payer']!==$payer)throw new \DomainException('This form was already sent with different payment instructions.');}
            else{$s=$this->db->prepare('INSERT INTO delivery_errand_details(errand_id,amount,payer,updated_at) VALUES(?,?,?,UTC_TIMESTAMP())');$s->execute([$id,$amount,$payer]);$this->audit($a,$id,'sent',null,$this->get($id));}
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function next(array $a):?array{
        $this->driver($a);$s=$this->db->prepare(self::SELECT_SQL." WHERE j.driver_id=? AND j.seen_at IS NULL AND j.collected_at IS NULL AND COALESCE(d.state,'open')<>'cancelled' AND (d.snooze_until IS NULL OR d.snooze_until<=UTC_TIMESTAMP()) ORDER BY j.id LIMIT 1");$s->execute([$a['id']]);return $s->fetch(\PDO::FETCH_ASSOC)?:null;
    }
    public function mark(array $a,int $id,bool $collected):void{
        if($collected)throw new \DomainException('Confirm collection with a completion note.');
        $j=$this->get($id);$this->change($a,$id,(int)($j['revision']??0),'seen',[]);
    }
    public function entries(array $a,bool $done,int $page,bool $cancelled=false):array{
        $driver=($a['role']??'')==='delivery_driver';if($driver)$this->driver($a);else $this->front($a);
        $sql=self::SELECT_SQL.' WHERE '.($cancelled?"COALESCE(d.state,'open')='cancelled'":"COALESCE(d.state,'open')<>'cancelled' AND j.collected_at IS ".($done?'NOT NULL':'NULL')).($driver?' AND j.driver_id=?':'').' ORDER BY j.id DESC LIMIT 21 OFFSET '.(20*max(0,min(100000,$page)));
        $s=$this->db->prepare($sql);$s->execute($driver?[$a['id']]:[]);return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    private const SELECT_SQL="SELECT j.*,e.full_name driver_name,c.full_name creator_name,COALESCE(d.revision,1) revision,COALESCE(d.state,'open') state,d.amount,COALESCE(d.payer,'') payer,COALESCE(d.note,'') note,d.updated_at FROM delivery_errands j JOIN ops_employees e ON e.id=j.driver_id LEFT JOIN ops_employees c ON c.id=j.created_by LEFT JOIN delivery_errand_details d ON d.errand_id=j.id";
    private function get(int $id):?array{$s=$this->db->prepare(self::SELECT_SQL.' WHERE j.id=?');$s->execute([$id]);return $s->fetch(\PDO::FETCH_ASSOC)?:null;}
    private function payment(array $v):array{
        $raw=trim((string)($v['amount']??''));$payer=trim((string)($v['payer']??''));
        if(($raw!==''&&!preg_match('/^\d{1,9}(\.\d{1,2})?$/D',$raw))||mb_strlen($payer)>190)throw new \DomainException('Use a valid non-negative NAD amount and payment instructions up to 190 characters.');
        return [$raw===''?null:number_format((float)$raw,2,'.',''),$payer];
    }
    private function audit(array $a,int $id,string $action,?array $before,array $after):void{
        $s=$this->db->prepare('INSERT INTO delivery_errand_events(errand_id,actor_id,action,before_json,after_json,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
        $s->execute([$id,$a['id'],$action,$before?json_encode($before,JSON_THROW_ON_ERROR):null,json_encode($after,JSON_THROW_ON_ERROR)]);
    }
    public function countPending(array $a):int{
        $this->driver($a);$s=$this->db->prepare("SELECT COUNT(*) FROM delivery_errands j LEFT JOIN delivery_errand_details d ON d.errand_id=j.id WHERE j.driver_id=? AND j.collected_at IS NULL AND COALESCE(d.state,'open')<>'cancelled'");$s->execute([$a['id']]);return (int)$s->fetchColumn();
    }
    public function history(array $a,int $id):array{
        $j=$this->get($id);if(!$j)throw new \DomainException('Errand unavailable.');
        if(($a['role']??'')==='delivery_driver'){$this->driver($a);if((int)$j['driver_id']!==(int)$a['id'])throw new \DomainException('Errand unavailable.');}else $this->front($a);
        $s=$this->db->prepare('SELECT h.action,h.created_at,e.full_name actor_name,h.after_json FROM delivery_errand_events h LEFT JOIN ops_employees e ON e.id=h.actor_id WHERE h.errand_id=? ORDER BY h.id DESC LIMIT 100');$s->execute([$id]);return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function change(array $a,int $id,int $revision,string $action,array $v):array{
        $isDriver=($a['role']??'')==='delivery_driver';if($isDriver)$this->driver($a);else $this->front($a);
        if(!in_array($action,$isDriver?['seen','claim','later','collected','blocked']:['edit','cancel','reopen'],true))throw new \DomainException('Action not permitted.');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT id FROM delivery_errands WHERE id=? FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new \DomainException('Errand unavailable.');
            $j=$this->get($id);
            if($isDriver&&(int)$j['driver_id']!==(int)$a['id'])throw new \DomainException('Errand unavailable.');
            if((int)$j['revision']!==$revision)throw new \DomainException('This errand changed on another screen. Close this form and review the latest details.');
            if($j['state']==='cancelled'||($j['collected_at']&&$action!=='reopen'))throw new \DomainException('This errand is already closed.');
            $s=$this->db->prepare('INSERT IGNORE INTO delivery_errand_details(errand_id) VALUES(?)');$s->execute([$id]);
            if($action==='claim'){
                $claim=(string)($v['claim_key']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$claim))throw new \DomainException('Invalid notice claim.');
                if($j['seen_at'])throw new \DomainException('Already acknowledged.');
                $s=$this->db->prepare('UPDATE delivery_errand_details SET claim_key=?,claim_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND) WHERE errand_id=? AND (snooze_until IS NULL OR snooze_until<=UTC_TIMESTAMP()) AND (claim_until IS NULL OR claim_until<UTC_TIMESTAMP() OR claim_key=?)');$s->execute([$claim,$id,$claim]);
                $check=$this->db->prepare('SELECT claim_key,claim_until>UTC_TIMESTAMP() active FROM delivery_errand_details WHERE errand_id=?');$check->execute([$id]);$lease=$check->fetch(\PDO::FETCH_ASSOC);
                $this->db->commit();return ['claimed'=>$lease['claim_key']===$claim&&(bool)$lease['active']];
            }
            if($action==='later'){
                $s=$this->db->prepare('UPDATE delivery_errand_details SET snooze_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),claim_key=NULL,claim_until=NULL WHERE errand_id=?');$s->execute([$id]);
            }elseif($action==='seen'){
                $s=$this->db->prepare('UPDATE delivery_errands SET seen_at=COALESCE(seen_at,UTC_TIMESTAMP()) WHERE id=?');$s->execute([$id]);
            }else{
                $note=trim((string)($v['note']??''));if($note===''||mb_strlen($note)>1500)throw new \DomainException('Add a note explaining this update (up to 1,500 characters).');
                $state=$j['state'];
                if($action==='edit'){
                    if(!in_array($v['payment_state']??'',['paid','unpaid'],true))throw new \DomainException('Choose Paid or Not paid.');
                    foreach(['place'=>255,'contact_name'=>190,'contents'=>1500] as $field=>$max){$v[$field]=trim((string)($v[$field]??''));if($v[$field]===''||mb_strlen($v[$field])>$max)throw new \DomainException('Fill in the pickup, contact and collection details.');}
                    $s=$this->db->prepare(self::DRIVERS.' AND e.id=? FOR UPDATE');$s->execute([(int)($v['driver_id']??0)]);if(!$s->fetch())throw new \DomainException('Choose an active driver.');
                    [$amount,$payer]=$this->payment($v);
                    $s=$this->db->prepare('UPDATE delivery_errands SET place=?,contact_name=?,contents=?,driver_id=?,payment_state=?,seen_at=NULL WHERE id=?');$s->execute([$v['place'],$v['contact_name'],$v['contents'],$v['driver_id'],$v['payment_state'],$id]);
                    $s=$this->db->prepare('UPDATE delivery_errand_details SET amount=?,payer=? WHERE errand_id=?');$s->execute([$amount,$payer,$id]);
                }elseif($action==='collected'){
                    if(($v['confirmed']??'')!=='1')throw new \DomainException('Confirm that the items were collected.');
                    $s=$this->db->prepare('UPDATE delivery_errands SET collected_at=UTC_TIMESTAMP(),seen_at=COALESCE(seen_at,UTC_TIMESTAMP()) WHERE id=?');$s->execute([$id]);$state='open';
                }elseif($action==='blocked'){$state='blocked';$s=$this->db->prepare('UPDATE delivery_errands SET seen_at=COALESCE(seen_at,UTC_TIMESTAMP()) WHERE id=?');$s->execute([$id]);}
                elseif($action==='cancel'){$state='cancelled';}
                elseif($action==='reopen'){
                    if(!$j['collected_at']&&$j['state']!=='blocked')throw new \DomainException('Only collected or unable-to-collect errands can be reopened.');
                    $s=$this->db->prepare('UPDATE delivery_errands SET collected_at=NULL,seen_at=NULL WHERE id=?');$s->execute([$id]);$state='open';
                }
                $s=$this->db->prepare('UPDATE delivery_errand_details SET state=?,note=?,revision=revision+1,updated_at=UTC_TIMESTAMP(),claim_key=NULL,claim_until=NULL,snooze_until=NULL WHERE errand_id=?');$s->execute([$state,$note,$id]);
            }
            $after=$this->get($id);$this->audit($a,$id,$action,$j,$after);$this->db->commit();return $after;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
