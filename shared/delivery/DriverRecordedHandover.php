<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/CashHandover.php';

/** Append-only driver statements. Never asserts independent receipt or posts sales. */
final class DriverRecordedHandover
{
    private \PDO $db;
    public function __construct(\PDO $db) {$this->db=$db;}
    public static function ready(\PDO $db):bool {
        try {$db->query('SELECT id FROM delivery_driver_handovers LIMIT 0');return true;}
        catch(\PDOException $e){if($e->getCode()==='42S02')return false;throw $e;}
    }
    private function rows(string $sql,array $v=[]):array {$q=$this->db->prepare($sql);$q->execute($v);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    private function history(int $driver):array {return $this->rows('SELECT * FROM delivery_driver_handovers WHERE driver_id=? ORDER BY id DESC',[$driver]);}
    public static function display(array $r):array {
        return ['id'=>(int)$r['id'],'reference'=>'DH-'.$r['id'],'state'=>'driver_recorded','declared_minor'=>(int)$r['amount_minor'],'expected_minor'=>(int)$r['expected_minor'],'unallocated_minor'=>(int)$r['unallocated_minor'],'note'=>$r['note'],'created_at'=>$r['created_at'],'driver_name'=>$r['driver_name']??null];
    }
    /** Bookkeeping caller must enforce its existing feature permission. No new access grant. */
    public function bookkeeping():array {
        return array_map([self::class,'display'],$this->rows('SELECT h.*,e.full_name driver_name FROM delivery_driver_handovers h JOIN ops_employees e ON e.id=h.driver_id ORDER BY h.id DESC LIMIT 200'));
    }
    public function summary(int $driver):array {
        EmployeeDriverSession::actor($this->db,$driver);
        $cash=new CashHandover($this->db);$s=$cash->summary($driver);
        if(!self::ready($this->db))throw new \DomainException('Cash handover update is being installed. Please try again shortly.');
        $jobs=[];
        foreach($cash->collections($driver) as $r){$id=$r['delivery_id'];$jobs[$id]=$jobs[$id]??['delivery_id'=>$id,'reference'=>$r['reference'],'cash_minor'=>0,'pending_minor'=>0,'sources'=>[]];$jobs[$id]['cash_minor']+=$r['due_minor'];$jobs[$id]['pending_minor']+=$r['pending_minor'];$jobs[$id]['sources'][]=['receipt_id'=>$r['id'],'minor'=>$r['due_minor'],'status'=>$r['status']];}
        foreach($s['declarations'] as $d){$id=$d['delivery_id'];$jobs[$id]=$jobs[$id]??['delivery_id'=>$id,'reference'=>$d['reference'],'cash_minor'=>0,'pending_minor'=>0,'sources'=>[]];$jobs[$id]['cash_minor']+=$d['declared_minor'];$jobs[$id]['sources'][]=['event_id'=>$d['event_id'],'minor'=>$d['declared_minor']];}
        $history=$this->history($driver);$reported=[];$unallocated=0;
        foreach($history as $h){$unallocated+=(int)$h['unallocated_minor'];foreach(json_decode($h['sources_json'],true,32,JSON_THROW_ON_ERROR) as $a){$id=(int)$a['delivery_id'];$reported[$id]=($reported[$id]??0)+(int)$a['handed_minor'];if(!isset($jobs[$id]))$jobs[$id]=['delivery_id'=>$id,'reference'=>$a['reference'],'cash_minor'=>0,'pending_minor'=>0,'sources'=>[]];}}
        ksort($jobs,SORT_NUMERIC);$due=0;$variance=$unallocated;
        foreach($jobs as $id=>&$j){$j['reported_minor']=$reported[$id]??0;$j['remaining_minor']=max(0,$j['cash_minor']-$j['pending_minor']-$j['reported_minor']);$j['variance_minor']=max(0,$j['reported_minor']-($j['cash_minor']-$j['pending_minor']));$due+=$j['remaining_minor'];$variance+=$j['variance_minor'];}unset($j);
        // Do not use an overage on one delivery to conceal cash collected for another.
        $s['handover_due_minor']=$due;$s['handover_variance_minor']=$variance;$s['handover_sources']=array_values($jobs);
        $s['handover_version']=hash('sha256',json_encode([$driver,$jobs,array_column($history,'id')],JSON_THROW_ON_ERROR));
        $s['handovers']=array_merge(array_map([self::class,'display'],array_slice($history,0,200)),$s['handovers']);
        usort($s['handovers'],static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return $s;
    }
    public function confirm(int $driver,array $p):array {
        EmployeeDriverSession::actor($this->db,$driver);
        if(!self::ready($this->db))throw new \DomainException('Cash handover update is being installed. Please retry shortly.');
        $key=$p['key']??'';$amount=$p['amount_minor']??null;$note=$p['note']??'';$version=$p['version']??'';
        if(!is_string($key)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$key))throw new \DomainException('Invalid request reference.');
        if(!is_int($amount)||$amount<1||$amount>9999999999||($p['currency']??'')!=='NAD')throw new \DomainException('Enter a positive NAD amount with at most two decimal places.');
        if(!is_string($note)||!mb_check_encoding($note,'UTF-8')||mb_strlen($note)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$note))throw new \DomainException('Use a note of at most 2,000 characters.');
        if(($p['attested']??false)!==true||!is_string($version)||!preg_match('/^[a-f0-9]{64}$/',$version))throw new \DomainException('Confirm the amount you handed to Front Desk.');
        $hash=hash('sha256',json_encode([$amount,trim($note),$version],JSON_THROW_ON_ERROR));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            // Same job-before-employee lock order as the existing cash workflow.
            $this->rows('SELECT id FROM delivery_jobs WHERE driver_employee_id=? OR id IN (SELECT delivery_id FROM delivery_receipts WHERE collected_by_employee_id=?) OR id IN (SELECT delivery_id FROM delivery_events WHERE employee_id=? AND event_type=\'delivered\') ORDER BY id FOR UPDATE',[$driver,$driver,$driver]);
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$driver]);EmployeeDriverSession::actor($this->db,$driver);
            $old=$this->rows('SELECT * FROM delivery_driver_handovers WHERE driver_id=? AND request_uuid=?',[$driver,$key]);
            if($old){if(!hash_equals($old[0]['request_hash'],$hash))throw new \DomainException('This request reference was already used for a different amount.');$this->db->commit();return self::display($old[0]);}
            $s=$this->summary($driver);if(!hash_equals($s['handover_version'],$version))throw new \DomainException('Cash records changed. Close and check the latest amount before confirming.');
            $left=$amount;$sources=[];
            foreach($s['handover_sources'] as $j){$allocate=min($left,$j['remaining_minor']);if($allocate>0){$sources[]=$j+['handed_minor'=>$allocate];$left-=$allocate;}}
            $q=$this->db->prepare('INSERT INTO delivery_driver_handovers(driver_id,request_uuid,request_hash,amount_minor,expected_minor,unallocated_minor,note,sources_json,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$driver,$key,$hash,$amount,$s['handover_due_minor'],$left,trim($note),json_encode($sources,JSON_THROW_ON_ERROR)]);
            $id=(int)$this->db->lastInsertId();$r=$this->rows('SELECT * FROM delivery_driver_handovers WHERE id=?',[$id])[0];$this->db->commit();return self::display($r);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
