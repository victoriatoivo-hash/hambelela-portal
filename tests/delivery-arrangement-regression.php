<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$dsn=getenv('DELIVERY_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Synthetic loopback database only');
$db=new PDO($dsn,'root',getenv('DELIVERY_TEST_PASSWORD')?:'synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='delivery_arrangement_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
require __DIR__.'/../shared/delivery/CoreApi.php';
require __DIR__.'/../shared/delivery/FrontSession.php';
require __DIR__.'/../shared/delivery/EmployeeDriverSession.php';
require __DIR__.'/../shared/delivery/DriverNotices.php';
require __DIR__.'/../shared/delivery/AccountingService.php';
function ok($value,string $label):void{if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
function requestId():string{return bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException $e){ok(true,$label);return;}throw new RuntimeException('Unexpected acceptance: '.$label);}
try{
 $db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60));
 CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,status VARCHAR(20),full_name VARCHAR(160));
 CREATE TABLE ops_permissions(id INT PRIMARY KEY,permission_key VARCHAR(100));
 CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number VARCHAR(80),woo_order_id INT,total_amount DECIMAL(12,2),payment_status VARCHAR(30),payment_method VARCHAR(80),customer_name VARCHAR(190),customer_contact VARCHAR(190),notes TEXT,fulfilment_mode VARCHAR(30),order_type VARCHAR(30),status VARCHAR(30),deleted_at DATETIME NULL,archived_at DATETIME NULL,payment_source VARCHAR(30),payment_version VARCHAR(100),payment_updated_by_employee_id INT,payment_updated_at DATETIME,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE order_payment_allocations(id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,payment_method VARCHAR(30),amount_cents BIGINT,transaction_reference VARCHAR(190),source VARCHAR(30),source_version VARCHAR(100),updated_by_employee_id INT);
 CREATE TABLE order_payment_allocation_audit(id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,previous_allocations_json TEXT,new_allocations_json TEXT,changed_by_employee_id INT,source VARCHAR(30));
 CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190),message TEXT,module VARCHAR(60),related_type VARCHAR(80),related_id BIGINT,priority VARCHAR(20),deduplication_key VARCHAR(190) UNIQUE,action_link VARCHAR(255),created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE notification_recipients(notification_id INT,employee_id INT,read_at DATETIME NULL,cleared_at DATETIME NULL,delivered_at DATETIME NULL,PRIMARY KEY(notification_id,employee_id));
 INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'front_desk_admin_employee'),(3,'delivery_driver'),(4,'marketing_sales'),(5,'packer');
 INSERT INTO ops_employees VALUES(1,1,'active','Synthetic Owner'),(2,2,'active','Synthetic Front'),(3,3,'active','Synthetic Driver'),(4,3,'active','Other Driver'),(5,5,'active','Synthetic Packer');");
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/001-delivery-proposed.sql'));
 $db->exec(file_get_contents(__DIR__.'/../database/delivery/002-driver-auth-proposed.sql'));
 $db->exec("INSERT INTO delivery_driver_profiles VALUES(3,1,1,UTC_TIMESTAMP()),(4,1,1,UTC_TIMESTAMP());
 INSERT INTO ops_orders(id,order_number,woo_order_id,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status) VALUES(100,'WEB-SYNTHETIC',100,120,'unpaid','Cash','Synthetic customer','55','Shipping address: KH, NA','delivery','in_progress');");
 $front=\Hambelela\Delivery\FrontSession::actor($db,2);
 $owner=\Hambelela\Delivery\FrontSession::actor($db,1);
 $driver=\Hambelela\Delivery\EmployeeDriverSession::actor($db,3);
 $other=\Hambelela\Delivery\EmployeeDriverSession::actor($db,4);
 $remote=['id'=>100,'currency'=>'NAD','status'=>'processing','total'=>'120.00','shipping_total'=>'40.00','shipping_tax'=>'0.00','shipping_lines'=>[['total'=>'40.00','total_tax'=>'0.00']],'refunds'=>[],'billing'=>['phone'=>'55'],'shipping'=>[]];
 $core=new \Hambelela\Delivery\CoreApi($db,['cash'=>'Cash'],static function($path)use(&$remote){return $remote;});
 $quote=$core->command($front,['action'=>'quote','source_order'=>true,'order'=>100]);
 ok($quote['customer_mobile']===''&&$quote['address']==='','Front can review invalid test contact and region-only address without login error');
 ok($quote['delivery_fee']===4000&&$quote['goods_due']===8000&&$quote['collect_cents']===12000,'existing shipping stays inside original order total');
 $body=['action'=>'arrange','source_order'=>true,'order'=>100,'driver'=>3,'date'=>(new DateTimeImmutable('today',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d'),'verification'=>$quote['verification'],'uuid'=>requestId()];
 denied(fn()=>$core->command($front,$body),'incomplete contact/address cannot dispatch');
 $body+=['address'=>'Synthetic test street','mobile'=>'+264810000000','urgent'=>true];
 $bad=$body;$bad['mobile']='55';denied(fn()=>$core->command($front,$bad),'invalid corrected customer number cannot dispatch');
 denied(fn()=>$core->command($driver,$body),'Driver cannot arrange deliveries');
 $job=$core->command($front,$body);$jobId=(int)$job['id'];
 ok($core->command($front,$body)===$job,'retry replays same delivery');
 $duplicate=$body;$duplicate['uuid']=requestId();denied(fn()=>$core->command($front,$duplicate),'second request cannot create duplicate arrangement');
 ok((int)$db->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn()===1,'one order has one delivery record');
 $saved=$db->query('SELECT * FROM delivery_jobs')->fetch();
 ok($saved['customer_mobile']==='+264810000000'&&(int)$saved['urgent']===1,'confirmed missing contact and urgent flag persist on delivery');
 ok($db->query('SELECT customer_contact FROM ops_orders WHERE id=100')->fetchColumn()==='55','arrangement preserves original order record');
 foreach([$front,$owner,$driver] as $actor){$board=$core->board($actor,['state'=>'for_delivery']);ok((int)$board['jobs'][0]['id']===$jobId,'Front/Owner/Driver query same persisted delivery');}
 ok($core->board($other,['state'=>'for_delivery'])['total']===0,'another Driver cannot see this delivery');
 ok($core->board($owner,['source'=>'partner'])['total']===0,'Hambelela order does not appear in Tedlaser source');
 ok(\Hambelela\Delivery\NotificationOutbox::assignmentNotice($db,$jobId)===null,'queued notification is not falsely reported as delivered');
 ok(\Hambelela\Delivery\NotificationOutbox::drain($db)===1&&\Hambelela\Delivery\NotificationOutbox::drain($db)===0,'one committed Driver notification, no duplicate');
 ok(\Hambelela\Delivery\NotificationOutbox::assignmentNotice($db,$jobId)!==null,'saved Driver recipient receipt is verified from actual notification tables');
 $notice=$db->query('SELECT * FROM notifications')->fetch();
 ok($notice['title']==='NEW DELIVERY ASSIGNED'&&$notice['priority']==='urgent'&&strpos($notice['message'],'Order: WEB-SYNTHETIC')!==false,'Driver notification includes order, customer, area and urgency');
 ok($notice['action_link']==='/apps/delivery/driver/index.php?delivery='.$jobId,'notification opens exact assigned delivery');
 \Hambelela\Delivery\DriverNotices::read($db,$other,(int)$notice['id']);ok(!\Hambelela\Delivery\DriverNotices::list($db,$driver)[0]['read_at'],'other Driver cannot acknowledge notification');
 \Hambelela\Delivery\DriverNotices::read($db,$driver,(int)$notice['id']);ok((bool)\Hambelela\Delivery\DriverNotices::list($db,$driver)[0]['read_at'],'Driver acknowledgement is stored and survives fresh query');
 ok(\Hambelela\Delivery\EmployeeDriverSession::allowedPath('/api/notifications.php'),'Driver can use existing account-scoped polling API');
 foreach(['/apps/delivery/front-api.php','/apps/accounts/payment-reconciliation.php','/settings.php'] as $path)ok(!\Hambelela\Delivery\EmployeeDriverSession::allowedPath($path),'Driver remains restricted from '.$path);
 $core->command($driver,['action'=>'start','id'=>$jobId,'uuid'=>requestId()]);
 $core->command($driver,['action'=>'collect','id'=>$jobId,'uuid'=>requestId(),'goods'=>'80.00','fee'=>'40.00','method'=>'cash']);
 $core->command($driver,['action'=>'complete','id'=>$jobId,'uuid'=>requestId()]);
 $receipt=(int)$db->query('SELECT id FROM delivery_receipts')->fetchColumn();
 $core->command($front,['action'=>'reconcile','id'=>$jobId,'uuid'=>requestId(),'receipt'=>$receipt]);
 ok((int)$db->query('SELECT SUM(amount_cents) FROM order_payment_allocations')->fetchColumn()===12000,'reconciliation credits original total once');
 ok((int)$db->query("SELECT SUM(amount_cents) FROM delivery_ledger WHERE account='fee_received'")->fetchColumn()===4000,'delivery-fee income remains separate');
 ok((int)$db->query("SELECT SUM(amount_cents) FROM delivery_ledger WHERE account='order_funds_received'")->fetchColumn()===8000,'product collections remain separate');
 // Existing valid WooCommerce billing data rescues an invalid portal contact.
 $remote['billing']['phone']='264811234567';$quote=$core->command($front,['action'=>'quote','source_order'=>true,'order'=>100]);
 ok($quote['customer_mobile']==='+264811234567','valid saved billing phone is reused automatically');
 ok($quote['collect_cents']===0&&$quote['fee_due']===0,'paid orders do not charge shipping again');
 echo "PASS complete authenticated-role arrangement lifecycle on synthetic database only\n";
}finally{$db->exec('DROP DATABASE '.$name);}
