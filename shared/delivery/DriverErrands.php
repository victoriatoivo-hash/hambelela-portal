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
        try{$this->db->query('SELECT id FROM delivery_errands LIMIT 0');return;}
        catch(\PDOException $e){if($e->getCode()!=='42S02')throw $e;}
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_errands (
          id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, created_by INT NOT NULL, driver_id INT NOT NULL,
          request_key CHAR(32) NOT NULL, place VARCHAR(255) NOT NULL, contact_name VARCHAR(190) NOT NULL,
          contents VARCHAR(1500) NOT NULL, payment_state ENUM('paid','unpaid') NOT NULL,
          created_at DATETIME NOT NULL, seen_at DATETIME NULL, collected_at DATETIME NULL,
          UNIQUE KEY request_once(created_by,request_key), KEY driver_queue(driver_id,collected_at,seen_at,id)
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
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare(self::DRIVERS.' AND e.id=? FOR UPDATE');$s->execute([(int)($v['driver_id']??0)]);if(!$s->fetch())throw new \DomainException('Choose an active driver.');
            $s=$this->db->prepare('INSERT INTO delivery_errands(created_by,driver_id,request_key,place,contact_name,contents,payment_state,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            $s->execute([$a['id'],$v['driver_id'],$v['request_key'],$v['place'],$v['contact_name'],$v['contents'],$v['payment_state']]);$id=(int)$this->db->lastInsertId();
            $s=$this->db->prepare('SELECT driver_id,place,contact_name,contents,payment_state FROM delivery_errands WHERE id=?');$s->execute([$id]);$saved=$s->fetch(\PDO::FETCH_ASSOC);
            foreach($saved as $k=>$val)if((string)$v[$k]!== (string)$val)throw new \DomainException('This form was already sent with different details. Open a new form for a new errand.');
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function next(array $a):?array{
        $this->driver($a);$s=$this->db->prepare('SELECT id,place,contact_name,contents,payment_state FROM delivery_errands WHERE driver_id=? AND seen_at IS NULL AND collected_at IS NULL ORDER BY id LIMIT 1');$s->execute([$a['id']]);return $s->fetch(\PDO::FETCH_ASSOC)?:null;
    }
    public function mark(array $a,int $id,bool $collected):void{
        $this->driver($a);$s=$this->db->prepare('UPDATE delivery_errands SET seen_at=COALESCE(seen_at,UTC_TIMESTAMP())'.($collected?',collected_at=COALESCE(collected_at,UTC_TIMESTAMP())':'').' WHERE id=? AND driver_id=?');$s->execute([$id,$a['id']]);
        if(!$s->rowCount()){$s=$this->db->prepare('SELECT id FROM delivery_errands WHERE id=? AND driver_id=?');$s->execute([$id,$a['id']]);if(!$s->fetchColumn())throw new \DomainException('Errand unavailable.');}
    }
    public function entries(array $a,bool $done,int $page):array{
        $driver=($a['role']??'')==='delivery_driver';if($driver)$this->driver($a);else $this->front($a);
        $sql='SELECT j.*,e.full_name driver_name FROM delivery_errands j JOIN ops_employees e ON e.id=j.driver_id WHERE j.collected_at IS '.($done?'NOT NULL':'NULL').($driver?' AND j.driver_id=?':'').' ORDER BY j.id DESC LIMIT 21 OFFSET '.(20*max(0,min(100000,$page)));
        $s=$this->db->prepare($sql);$s->execute($driver?[$a['id']]:[]);return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
}
