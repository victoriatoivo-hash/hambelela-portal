<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/TrackingPolicy.php';

final class TrackingService
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $params=[]):array{$s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    private function run(string $sql,array $params=[]):void{$this->db->prepare($sql)->execute($params);}
    private function audit(int $actor,?string $device,string $event,array $data):void
    {$this->run('INSERT INTO delivery_tracking_audit(device_id,actor_id,event_type,metadata_json,recorded_at) VALUES(?,?,?,?,?)',[$device,$actor,$event,json_encode($data,JSON_THROW_ON_ERROR),time()]);}
    private function driver(int $id):void
    {
        $r=$this->rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1",[$id]);
        if(!$r)throw new \DomainException('Choose an existing active Driver employee.');
    }
    public function register(array $actor,array $input):array
    {
        TrackingPolicy::owner($actor);$employee=(int)($input['employee_id']??0);$this->driver($employee);
        $name=trim((string)($input['name']??''));$bike=trim((string)($input['bike']??''));
        if($name===''||$bike===''||strlen($name)>100||strlen($bike)>100)throw new \DomainException('Device and bike names are required, up to 100 bytes.');
        if(empty($input['notice_confirmed']))throw new \DomainException('Confirm the company phone and employee tracking notice.');
        $settings=TrackingPolicy::settings($input['settings']??[]);$id=bin2hex(random_bytes(16));$pair=bin2hex(random_bytes(24));$now=time();
        $this->db->beginTransaction();
        try{$this->run('INSERT INTO delivery_tracking_devices(id,name,bike,employee_id,pairing_hash,pairing_expires,settings_json,assigned_at,created_by) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$name,$bike,$employee,hash('sha256',$pair),$now+900,json_encode($settings),$now,$actor['id']]);
            $this->audit($actor['id'],$id,'device_registered',['employee_id'=>$employee,'notice_confirmed'=>true,'settings'=>$settings]);$this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        return ['id'=>$id,'pairing_code'=>$pair,'expires_at'=>$now+900];
    }
    public function configure(array $actor,string $id,array $input):array
    {
        TrackingPolicy::owner($actor);$settings=TrackingPolicy::settings($input['settings']??[]);
        $this->db->beginTransaction();
        try{$r=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? FOR UPDATE',[$id]);if(!$r)throw new \DomainException('Device not found.');$old=$r[0];
            $employee=(int)($input['employee_id']??$old['employee_id']);$this->driver($employee);$active=!empty($input['active']);
            $reassign=$employee!==(int)$old['employee_id'];
            $this->run('UPDATE delivery_tracking_devices SET employee_id=?,active=?,settings_json=? WHERE id=?',[$employee,(int)$active,json_encode($settings),$id]);
            if($reassign||!$active)$this->run('UPDATE delivery_tracking_devices SET token_hash=NULL,pairing_hash=NULL,ready=0,assigned_at=?,last_contact=NULL,last_position=NULL,latitude=NULL,longitude=NULL,accuracy=NULL,gps_available=0 WHERE id=?',[time(),$id]);
            $this->audit($actor['id'],$id,$reassign?'device_reassigned':'settings_changed',['previous_employee_id'=>(int)$old['employee_id'],'employee_id'=>$employee,'active'=>$active,'settings'=>$settings]);$this->db->commit();return ['ok'=>true,'requires_pairing'=>$reassign||!$active];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function pairing(array $actor,string $id):array
    {
        TrackingPolicy::owner($actor);$code=bin2hex(random_bytes(24));$expiry=time()+900;
        $this->db->beginTransaction();try{
            $r=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? AND active=1 FOR UPDATE',[$id]);if(!$r)throw new \DomainException('Active device required.');$this->driver((int)$r[0]['employee_id']);
            $this->run('UPDATE delivery_tracking_devices SET pairing_hash=?,pairing_expires=?,token_hash=NULL,ready=0 WHERE id=?',[hash('sha256',$code),$expiry,$id]);
            $this->audit($actor['id'],$id,'pairing_reset',[]);$this->db->commit();return ['id'=>$id,'pairing_code'=>$code,'expires_at'=>$expiry];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function pair(string $id,string $code):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{48}$/D',$code))throw new \DomainException('Pairing unavailable.');
        $this->db->beginTransaction();try{
            $r=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? AND active=1 FOR UPDATE',[$id]);$d=$r[0]??null;
            if(!$d||empty($d['pairing_hash'])||(int)$d['pairing_expires']<time()||!hash_equals($d['pairing_hash'],hash('sha256',$code)))throw new \DomainException('Pairing unavailable.');
            $this->driver((int)$d['employee_id']);$token=bin2hex(random_bytes(32));
            $this->run('UPDATE delivery_tracking_devices SET token_hash=?,pairing_hash=NULL,pairing_expires=NULL WHERE id=?',[hash('sha256',$token),$id]);
            $this->audit(0,$id,'device_paired',[]);$this->db->commit();return ['device_id'=>$id,'token'=>$token,'settings'=>json_decode($d['settings_json'],true),'server_time'=>time()];
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    /** Device credentials authenticate only this endpoint; they confer no portal access. */
    public function ingest(string $id,string $token,array $body):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new \DomainException('Device authorization failed.');
        $points=$body['points']??[];if(!is_array($points)||count($points)>100)throw new \DomainException('At most 100 points per batch.');
        $now=time();$this->db->beginTransaction();try{
            $rows=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? AND active=1 FOR UPDATE',[$id]);$d=$rows[0]??null;
            if(!$d||!$d['token_hash']||!hash_equals($d['token_hash'],hash('sha256',$token)))throw new \DomainException('Device authorization failed.');
            if($d['last_contact']!==null&&$now-(int)$d['last_contact']<5)throw new \DomainException('Please wait five seconds before sending another batch.');
            $this->driver((int)$d['employee_id']);$settings=TrackingPolicy::settings(json_decode($d['settings_json'],true));$accepted=0;$discarded=0;
            foreach($points as $raw){
                if(!is_array($raw)){++$discarded;continue;}
                try{$p=TrackingPolicy::point($raw,$now);}catch(\DomainException $e){++$discarded;continue;}
                if($p['recorded_at']<(int)$d['assigned_at']||TrackingPolicy::window($p['recorded_at'],$settings)===null){++$discarded;continue;}
                $existing=$this->rows('SELECT id FROM delivery_tracking_points WHERE device_id=? AND event_id=?',[$id,$p['event_id']]);if($existing)continue;
                // DeliveryService writes UTC DATETIME values. Never let the DB session
                // timezone reinterpret GPS epochs when associating historical points.
                $utc=gmdate('Y-m-d H:i:s',$p['recorded_at']);
                $jobs=$this->rows("SELECT id FROM delivery_jobs WHERE driver_employee_id=? AND started_at<=? AND (delivered_at IS NULL OR delivered_at>=?) AND status IN ('out_for_delivery','awaiting_reconciliation','completed') ORDER BY started_at DESC LIMIT 2",[$d['employee_id'],$utc,$utc]);
                // Ambiguous concurrent deliveries keep driver evidence, without inventing attribution.
                $delivery=count($jobs)===1?(int)$jobs[0]['id']:null;
                $this->run('INSERT INTO delivery_tracking_points(device_id,event_id,employee_id,delivery_id,recorded_at,received_at,latitude,longitude,accuracy) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$p['event_id'],$d['employee_id'],$delivery,$p['recorded_at'],$now,$p['latitude'],$p['longitude'],$p['accuracy']]);++$accepted;
                if($p['accuracy']<=100&&$p['recorded_at']>(int)($d['last_position']??0)){
                    $this->run('UPDATE delivery_tracking_devices SET last_position=?,latitude=?,longitude=?,accuracy=? WHERE id=?',[$p['recorded_at'],$p['latitude'],$p['longitude'],$p['accuracy'],$id]);$d['last_position']=$p['recorded_at'];
                }
            }
            $battery=isset($body['battery'])?max(0,min(100,(int)$body['battery'])):null;
            $this->run('UPDATE delivery_tracking_devices SET last_contact=?,gps_available=?,battery=?,app_version=? WHERE id=?',[$now,!empty($body['gps_available'])?1:0,$battery,substr((string)($body['app_version']??''),0,40),$id]);
            $this->db->commit();return ['accepted'=>$accepted,'discarded'=>$discarded,'server_time'=>$now,'settings'=>$settings,'authorized_window'=>TrackingPolicy::window($now,$settings)];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function dashboard(array $actor):array
    {
        TrackingPolicy::owner($actor);$devices=$this->rows('SELECT d.*,e.full_name driver_name FROM delivery_tracking_devices d JOIN ops_employees e ON e.id=d.employee_id ORDER BY d.active DESC,d.name');
        foreach($devices as &$d){unset($d['token_hash'],$d['pairing_hash'],$d['pairing_expires']);$d['settings']=TrackingPolicy::settings(json_decode($d['settings_json'],true));unset($d['settings_json']);
            $d['tracking_status']=TrackingPolicy::status(time(),isset($d['last_contact'])?(int)$d['last_contact']:null,isset($d['last_position'])?(int)$d['last_position']:null,(bool)$d['gps_available'],$d['settings'],(bool)$d['active']);
            $d['jobs']=$this->rows("SELECT id,public_reference,source,customer_name,address,started_at,status FROM delivery_jobs WHERE driver_employee_id=? AND status='out_for_delivery' ORDER BY urgent DESC,started_at",[$d['employee_id']]);
        }unset($d);
        $drivers=$this->rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name");
        return ['devices'=>$devices,'drivers'=>$drivers,'server_time'=>time(),'timezone'=>'Africa/Windhoek'];
    }
    public function history(array $actor,string $device,int $from,int $to):array
    {
        TrackingPolicy::owner($actor);if($to<$from||$to-$from>86400*7)throw new \DomainException('Choose a range up to seven days.');
        $points=$this->rows('SELECT employee_id,delivery_id,recorded_at,latitude,longitude,accuracy FROM delivery_tracking_points WHERE device_id=? AND recorded_at BETWEEN ? AND ? ORDER BY recorded_at,id LIMIT 20001',[$device,$from,$to]);
        $audit=$this->rows('SELECT actor_id,event_type,metadata_json,recorded_at FROM delivery_tracking_audit WHERE device_id=? AND recorded_at BETWEEN ? AND ? ORDER BY recorded_at,id LIMIT 1001',[$device,$from,$to]);
        return ['points'=>array_slice($points,0,20000),'audit'=>array_slice($audit,0,1000),'range_incomplete'=>count($points)>20000||count($audit)>1000];
    }
    public function confirmReady(array $actor,string $id,array $checks):array
    {
        TrackingPolicy::owner($actor);foreach(['gps','locked_screen','navigation','reconnection','off_duty','employee_notice'] as $k)if(($checks[$k]??false)!==true)throw new \DomainException('Complete every test on the actual company phone before marking ready.');
        $rows=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? AND active=1',[$id]);$d=$rows[0]??null;
        if(!$d||!$d['token_hash']||!$d['last_position']||time()-(int)$d['last_position']>120)throw new \DomainException('A recent real GPS update is required.');
        $this->run('UPDATE delivery_tracking_devices SET ready=1 WHERE id=?',[$id]);$this->audit($actor['id'],$id,'phone_tests_confirmed',$checks);return ['ok'=>true];
    }
    public function prune():void
    {
        foreach($this->rows('SELECT id,settings_json FROM delivery_tracking_devices') as $d){$s=TrackingPolicy::settings(json_decode($d['settings_json'],true));$cut=time()-$s['retention_days']*86400;
            $this->run('DELETE FROM delivery_tracking_points WHERE device_id=? AND recorded_at<?',[$d['id'],$cut]);
            $this->run('UPDATE delivery_tracking_devices SET latitude=NULL,longitude=NULL,accuracy=NULL,last_position=NULL WHERE id=? AND last_position<?',[$d['id'],$cut]);}
        // Do not retain Google coordinates or route geometry indefinitely.
        $this->run('DELETE FROM delivery_tracking_routes WHERE geocoded_at IS NOT NULL AND geocoded_at<?',[time()-86400*29]);
        $this->run('DELETE FROM delivery_tracking_api_usage WHERE usage_day<?',[gmdate('Y-m-d',time()-86400*90)]);
    }
}
