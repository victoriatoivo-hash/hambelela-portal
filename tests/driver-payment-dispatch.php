<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$dsn=getenv('DELIVERY_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Synthetic loopback database only');
$db=new PDO($dsn,'root',getenv('DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='payment_dispatch_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
require_once __DIR__.'/../shared/delivery/DriverOrderDetailsData.php';
require_once __DIR__.'/../shared/delivery/DeliveryService.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function uid(){return bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));}
try{
 $db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60));INSERT INTO ops_roles VALUES(1,'delivery_driver');
 CREATE TABLE ops_employees(id INT PRIMARY KEY,status VARCHAR(20),full_name VARCHAR(160),role_id INT);
 INSERT INTO ops_employees VALUES(1,'active','Synthetic Front',NULL),(2,'active','Synthetic Driver',1);
 CREATE TABLE ops_permissions(id INT PRIMARY KEY,permission_key VARCHAR(100));
 CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number VARCHAR(60),status VARCHAR(30),payment_status VARCHAR(30),payment_method VARCHAR(30),total_amount DECIMAL(12,2),deleted_at DATETIME NULL,portal_paid_confirmed TINYINT NULL);
 INSERT INTO ops_orders VALUES(1,'SYNTHETIC','completed','unpaid','EFT',40,NULL,0);
 CREATE TABLE ops_order_items(id INT PRIMARY KEY,order_id INT,product_name VARCHAR(100),quantity INT);
 CREATE TABLE order_payment_allocations(id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,amount_cents INT,payment_method VARCHAR(30),source VARCHAR(30));");
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/001-delivery-proposed.sql'));
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/002-driver-auth-proposed.sql'));
 $db->exec("INSERT INTO delivery_driver_profiles VALUES(2,1,1,UTC_TIMESTAMP());
 INSERT INTO delivery_jobs(id,public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_payer,order_due_snapshot_cents,scheduled_date,driver_employee_id,arranged_by_employee_id,status) VALUES(1,'TEST-PAYMENT','hambelela',1,'Synthetic customer','0810000000','Test street 12','Test area',4000,'customer',4000,CURRENT_DATE(),2,1,'ready');
 INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(1,'arranged',1,'{\"tracking_version\":1,\"payment_arrangement\":\"cod\",\"order_dispatch\":{\"payment_policy\":\"portal_cash_confirmation\",\"total_cents\":4000}}');");
 $read=fn()=>Hambelela\Delivery\DriverOrderDetailsData::read($db,2,1);
 $r=$read();check($r['delivery']['stage']==='to_collect'&&$r['action']==='accept','Released unpaid completed order allows driver acceptance');
 check($r['payment']['codAmountDueNad']===40&& !$r['payment']['verified'],'Dispatch COD instruction overrides older EFT method without inventing payment');
 $db->exec("UPDATE ops_orders SET payment_status='paid',portal_paid_confirmed=1 WHERE id=1");
 $r=$read();check($r['payment']['verified']&&$r['delivery']['stage']==='to_collect','Approved portal Paid tick is honoured without duplicate allocation requirement');
 $db->exec("UPDATE ops_orders SET portal_paid_confirmed=0 WHERE id=1");
 $r=$read();check(!$r['payment']['verified']&&$r['action']==='accept','Unticked legacy paid label does not invent settlement or block acceptance');
 $db->exec("UPDATE ops_orders SET payment_status='partial',portal_paid_confirmed=0 WHERE id=1");
 $r=$read();check(!$r['payment']['verified']&&$r['payment']['codAmountDueNad']===null&&$r['action']==='accept','Unknown balance remains unknown but does not block dispatch');
 $front=['kind'=>'employee','id'=>1,'role'=>'front_desk_admin_employee','active'=>true];
 $driver=['kind'=>'employee','id'=>2,'role'=>'delivery_driver','active'=>true,'driver_verified'=>true];
 $svc=new Hambelela\Delivery\DeliveryService($db,['cash'],static fn()=>['order_goods'=>0,'delivery_fee'=>4000,'partner_cod'=>0]);
 $svc->accept($driver,1,uid());$svc->pickup($front,1,uid(),2,['confirmed'=>true]);$svc->confirmPickup($driver,1,uid());
 $blockedBalance=new Hambelela\Delivery\DeliveryService($db,['cash'],static function(){throw new DomainException('Unresolved financial allocation');});
 $blockedBalance->start($driver,1,uid());
 check($db->query('SELECT status FROM delivery_jobs WHERE id=1')->fetchColumn()==='out_for_delivery','Start delivery is independent of settlement review');
 check((int)$db->query('SELECT COUNT(*) FROM delivery_receipts')->fetchColumn()===0&&$db->query('SELECT payment_status FROM ops_orders WHERE id=1')->fetchColumn()==='partial','Transport does not create receipts or mark order paid');
 $db->exec("UPDATE ops_orders SET status='cancelled' WHERE id=1");
 check($read()['delivery']['stage']==='exception'&&$read()['action']===null,'Cancelled source safeguard retained');
 echo "PASS driver payment dispatch regression\n";
}finally{$db->exec('DROP DATABASE '.$name);}
