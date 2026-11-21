<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$dsn=getenv('DELIVERY_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Synthetic loopback database only');
$db=new PDO($dsn,'root',getenv('DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='handover_test_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
require_once __DIR__.'/../shared/delivery/DeliveryService.php';
require_once __DIR__.'/../shared/delivery/HandoverReminders.php';
use Hambelela\Delivery\HandoverReminders;
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function no(callable $f,$label){try{$f();}catch(DomainException $e){check(true,$label);return;}throw new RuntimeException($label);}
function uid(){return bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));}
try{
 $db->exec("CREATE TABLE ops_employees(id INT PRIMARY KEY,status VARCHAR(20),full_name VARCHAR(160));INSERT INTO ops_employees VALUES(1,'active','Synthetic Front'),(2,'active','Synthetic Driver'),(3,'active','Synthetic Other'),(4,'active','Synthetic Marketing');");
 $db->exec('CREATE TABLE ops_permissions(id INT PRIMARY KEY,permission_key VARCHAR(100));CREATE TABLE ops_orders(id INT PRIMARY KEY);');
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/001-delivery-proposed.sql'));
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/002-driver-auth-proposed.sql'));
 $db->exec("INSERT INTO delivery_driver_profiles VALUES(2,1,1,UTC_TIMESTAMP());
 INSERT INTO ops_orders VALUES(1);
 INSERT INTO delivery_jobs(id,public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_payer,order_due_snapshot_cents,scheduled_date,driver_employee_id,arranged_by_employee_id,status) VALUES(1,'TEST-HANDOVER','hambelela',1,'Synthetic customer','0810000000','Test street','Test area',0,'already_paid',0,CURRENT_DATE(),2,1,'ready');
 INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(1,'arranged',1,'{\"tracking_version\":1,\"payment_arrangement\":\"verified\"}');");
 $front=['kind'=>'employee','id'=>1,'role'=>'front_desk_admin_employee','active'=>true];
 $other=['kind'=>'employee','id'=>3,'role'=>'owner_admin','active'=>true];
 $driver=['kind'=>'employee','id'=>2,'role'=>'delivery_driver','active'=>true,'driver_verified'=>true];
 $marketing=['kind'=>'employee','id'=>4,'role'=>'marketing_sales','active'=>true];
 $svc=new Hambelela\Delivery\DeliveryService($db,['cash'],static function(){return ['order_goods'=>0,'delivery_fee'=>0,'partner_cod'=>0];});
 check(!HandoverReminders::pending($db,$front),'No reminder before driver acceptance');
 $accept=uid();$svc->accept($driver,1,$accept);$svc->accept($driver,1,$accept);
 $pending=HandoverReminders::pending($db,$front);check(count($pending)===1,'Acceptance creates one durable pending reminder');
 check(!HandoverReminders::pending($db,$other),'Not broadcast to other Front/Admin employees');
 no(fn()=>HandoverReminders::pending($db,$driver),'Driver cannot read arranger reminders');
 $before=$db->query('SELECT COUNT(*) FROM delivery_events')->fetchColumn();
 check($pending===HandoverReminders::pending($db,$front)&&$before===$db->query('SELECT COUNT(*) FROM delivery_events')->fetchColumn(),'Reading reminder neither consumes nor writes evidence');
 no(fn()=>$svc->pickup($front,1,uid(),2),'Explicit confirmation required without quantity');
 no(fn()=>$svc->pickup($front,1,uid(),2,['confirmed'=>true,'acceptance_event_id'=>999]),'Reject stale assignment');
 no(fn()=>$svc->pickup($driver,1,uid(),2,['confirmed'=>true]),'Driver cannot confirm as Front');
 $uuid=uid();$details=['confirmed'=>true,'acceptance_event_id'=>$pending[0]['acceptance_event_id']];
 $result=$svc->pickup($front,1,$uuid,2,$details);$repeat=$svc->pickup($front,1,$uuid,2,$details);
 check($result==$repeat&&$result['parcel_count']===null&&$result['recorded_by']===1,'Single Confirm records actor/time without inventing count; safe retry');
 no(fn()=>$svc->pickup($front,1,uid(),2,$details),'Duplicate confirmation rejected');
 check(!HandoverReminders::pending($db,$front),'Confirmed handover no longer prompts');
 $svc->confirmPickup($driver,1,uid());check((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE event_type='driver_collection_confirmed'")->fetchColumn()===1,'Driver confirms collection normally without quantity');
 check((int)$db->query('SELECT COUNT(*) FROM delivery_receipts')->fetchColumn()===0,'No payment receipt created by handover');
 // New assignment invalidates custody and reminders, rather than trusting stale acceptance.
 $db->exec("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(1,'revised',1,'{}')");
 check(!HandoverReminders::pending($db,$front),'Reassignment resets previous acceptance');
 $svc->accept($driver,1,uid());$new=HandoverReminders::pending($db,$front);check(count($new)===1&&$new[0]['acceptance_event_id']!==$details['acceptance_event_id'],'New acceptance has independent reminder identity');
 no(fn()=>$svc->pickup($front,1,uid(),2,$details),'Old popup cannot confirm new attempt');
 $db->exec("UPDATE delivery_jobs SET arranged_by_employee_id=4 WHERE id=1");
 check(count(HandoverReminders::pending($db,$marketing))===1&&!HandoverReminders::pending($db,$front),'Marketing arranger receives own reminder');
 $db->exec("UPDATE delivery_jobs SET status='cancelled' WHERE id=1");check(!HandoverReminders::pending($db,$marketing),'Cancelled delivery does not prompt');
 $db->exec("UPDATE delivery_jobs SET status='completed' WHERE id=1");check(!HandoverReminders::pending($db,$marketing),'Completed delivery does not prompt');
 $db->exec("UPDATE delivery_jobs SET status='ready' WHERE id=1");
 $svc->pickupException($marketing,1,uid(),'Synthetic approved direct collection exception');check(!HandoverReminders::pending($db,$marketing),'Approved exception suppresses reminder');
 $db->exec("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(1,'revised',1,'{}')");
 $svc->pickup($front,1,uid(),2,['parcel_count'=>7]);
 check(json_decode($db->query("SELECT metadata_json FROM delivery_events WHERE event_type='parcel_collected' ORDER BY id DESC LIMIT 1")->fetchColumn(),true)['parcel_count']===7,'Older clients and historical recorded quantities remain compatible');
 echo "PASS focused handover database integration\n";
}finally{$db->exec('DROP DATABASE '.$name);}
