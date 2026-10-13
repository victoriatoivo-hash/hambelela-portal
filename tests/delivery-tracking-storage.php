<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/TrackingSchema.php';
require __DIR__.'/../shared/delivery/TrackingService.php';
use Hambelela\Delivery\TrackingSchema;
use Hambelela\Delivery\TrackingService;
// This test never connects to a portal schema or uses production credentials.
$db=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='delivery_gps_test_'.bin2hex(random_bytes(5));
$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
function check($value,string $message):void{if(!$value)throw new RuntimeException($message);echo 'PASS: '.$message."\n";}
function denied(callable $fn,string $message):void{try{$fn();}catch(DomainException $e){check(true,$message);return;}throw new RuntimeException($message);}
try{
 TrackingSchema::install($db);TrackingSchema::install($db);
 $db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60)); CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,status VARCHAR(20),full_name VARCHAR(160)); CREATE TABLE delivery_driver_profiles(employee_id INT PRIMARY KEY,active TINYINT);
 CREATE TABLE delivery_jobs(id INT PRIMARY KEY,driver_employee_id INT,started_at DATETIME,delivered_at DATETIME,status VARCHAR(40),public_reference VARCHAR(40),source VARCHAR(40),customer_name VARCHAR(100),address VARCHAR(200),urgent TINYINT);
 INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'delivery_driver'); INSERT INTO ops_employees VALUES(1,1,'active','Synthetic Owner'),(2,2,'active','Synthetic Driver'),(3,2,'active','Other Driver'); INSERT INTO delivery_driver_profiles VALUES(2,1),(3,1)");
 $owner=['kind'=>'employee','id'=>1,'role'=>'owner_admin','active'=>true];
 $driver=['kind'=>'employee','id'=>2,'role'=>'delivery_driver','active'=>true];
 $service=new TrackingService($db);$now=time();$tz=new DateTimeZone('Africa/Windhoek');
 $settings=['work_start'=>(new DateTimeImmutable('@'.($now-3600)))->setTimezone($tz)->format('H:i'),'work_end'=>(new DateTimeImmutable('@'.($now+3600)))->setTimezone($tz)->format('H:i'),'work_days'=>[1,2,3,4,5,6,7]];
 $input=['employee_id'=>2,'name'=>'Test phone','bike'=>'Test bike','notice_confirmed'=>true,'settings'=>$settings];
 denied(function()use($service,$driver,$input){$service->register($driver,$input);},'Driver cannot register devices');
 $device=$service->register($owner,$input);$id=$device['id'];
 check(strlen($device['pairing_code'])===48,'one-time pairing issued');
 $paired=$service->pair($id,$device['pairing_code']);$token=$paired['token'];
 denied(function()use($service,$device,$id){$service->pair($id,$device['pairing_code']);},'pairing cannot replay');
 check($db->query("SELECT token_hash FROM delivery_tracking_devices WHERE id='$id'")->fetchColumn()!==$token,'device token stored only as hash');
 $start=gmdate('Y-m-d H:i:s',$now-120);$db->prepare("INSERT INTO delivery_jobs VALUES(10,2,?,NULL,'out_for_delivery','SYNTHETIC','hambelela','Synthetic','Synthetic address',0)")->execute([$start]);
 $db->exec("SET time_zone='+05:00'"); // Association must be immune to MySQL session timezone.
 $point=['event_id'=>'11111111-1111-4111-8111-111111111111','recorded_at'=>$now,'latitude'=>-22.56,'longitude'=>17.08,'accuracy'=>8];
 $result=$service->ingest($id,$token,['points'=>[$point],'gps_available'=>true]);
 check($result['accepted']===1,'GPS point saved');
 check((int)$db->query('SELECT delivery_id FROM delivery_tracking_points')->fetchColumn()===10,'UTC delivery association independent of database timezone');
 denied(function()use($service,$id,$token,$point){$service->ingest($id,$token,['points'=>[$point]]);},'heartbeat flood rejected');
 $db->exec("UPDATE delivery_tracking_devices SET last_contact=0 WHERE id='$id'");
 $result=$service->ingest($id,$token,['points'=>[$point],'gps_available'=>true]);
 check($result['accepted']===0&&(int)$db->query('SELECT COUNT(*) FROM delivery_tracking_points')->fetchColumn()===1,'offline replay cannot duplicate point');
 $board=$service->dashboard($owner);check($board['devices'][0]['tracking_status']==='Live','fresh GPS visible as live');
 check(!isset($board['devices'][0]['token_hash'])&&!isset($board['devices'][0]['pairing_hash']),'dashboard excludes credential hashes');
 denied(function()use($service,$driver){$service->dashboard($driver);},'Driver cannot read fleet locations');
 denied(function()use($service){$service->dashboard(['kind'=>'partner','id'=>1,'active'=>true,'role'=>'owner_admin']);},'partner cannot impersonate Owner');
 denied(function()use($service,$owner,$id){$service->confirmReady($owner,$id,[]);},'cannot mark ready without actual phone attestations');
 $service->configure($owner,$id,['employee_id'=>3,'active'=>true,'settings'=>$settings]);
 denied(function()use($service,$id,$token){$service->ingest($id,$token,[]);},'reassignment revokes old device credentials');
 $board=$service->dashboard($owner);check($board['devices'][0]['latitude']===null&&$board['devices'][0]['last_contact']===null,'reassignment does not display previous employee position');
 $history=$service->history($owner,$id,$now-100,$now+100);check((int)$history['points'][0]['employee_id']===2,'historical evidence retains original driver attribution');
 $new=$service->pairing($owner,$id);$newToken=$service->pair($id,$new['pairing_code'])['token'];
 $db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");
 denied(function()use($service,$id,$newToken){$service->ingest($id,$newToken,[]);},'inactive employee cannot upload GPS');
 check((int)$db->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn()===1,'tracking does not mutate deliveries');
 echo "Synthetic GPS storage checks complete. No production database accessed.\n";
}finally{$db->exec('DROP DATABASE '.$name);}
