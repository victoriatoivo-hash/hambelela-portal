<?php
declare(strict_types=1);
// Disposable CI database only. Never uses application configuration or live credentials.
if(PHP_SAPI!=='cli'||getenv('TEDLASER_TEST_DB')!=='tedlaser_flow_test')exit(1);
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/delivery/DeliveryService.php';
require BASE_PATH.'/shared/delivery/PartnerService.php';
require BASE_PATH.'/shared/delivery/DriverOrderDetailsData.php';
use Hambelela\Delivery\{DeliveryService,PartnerService,PartnerRouting,DriverAssignments,DriverOrderDetailsData,DeliveryCustody,EmployeeDriverSession,OrderBalanceAdapter,ProofContext,ProofPolicy,ProofSubmission,ProofStorage,NotificationOutbox};
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=tedlaser_flow_test;charset=utf8mb4','root','synthetic-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SHOW TABLES')->fetchColumn())throw new RuntimeException('Requires empty disposable database');
$db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80)); CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,status VARCHAR(30),full_name VARCHAR(100)); CREATE TABLE ops_permissions(id INT PRIMARY KEY,permission_key VARCHAR(100));
CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number VARCHAR(80),status VARCHAR(30),payment_status VARCHAR(30),payment_method VARCHAR(30),total_amount DECIMAL(12,2),deleted_at DATETIME NULL,archived_at DATETIME NULL,refund_total DECIMAL(12,2) DEFAULT 0,portal_paid_confirmed INT DEFAULT 0);
CREATE TABLE ops_order_items(id INT PRIMARY KEY,order_id INT,product_name VARCHAR(100),quantity INT);
CREATE TABLE order_payment_allocations(order_id INT,amount_cents INT,payment_method VARCHAR(30),source VARCHAR(30),transaction_reference VARCHAR(100));
CREATE TABLE ops_security_events(id INT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(80),employee_id INT,metadata_json TEXT);
CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,title TEXT,message TEXT,module VARCHAR(40),related_type VARCHAR(40),related_id INT,priority VARCHAR(30),deduplication_key VARCHAR(190) UNIQUE,action_link TEXT);
CREATE TABLE notification_recipients(notification_id INT,employee_id INT,PRIMARY KEY(notification_id,employee_id));");
$db->exec(file_get_contents(BASE_PATH.'/database/delivery/001-delivery-proposed.sql'));
$db->exec("INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'delivery_driver'); INSERT INTO ops_employees VALUES(1,1,'active','Synthetic Owner'),(2,2,'active','Synthetic Driver'),(3,2,'active','Other Driver'); INSERT INTO delivery_driver_profiles VALUES(2,1,1,UTC_TIMESTAMP()),(3,1,1,UTC_TIMESTAMP()); INSERT INTO delivery_partners(id,name,code,created_by_employee_id) VALUES(1,'Synthetic Tedlaser','tedlaser',1); INSERT INTO delivery_partner_users(id,partner_id,display_name,email,password_hash,active) VALUES(1,1,'Synthetic Admin','test@example.invalid','unused',1); INSERT INTO delivery_zones(id,area,fee_cents,updated_by_employee_id) VALUES(1,'Synthetic area',4000,1);");
function ok($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function uuid(){return sprintf('%08x-aaaa-4aaa-8aaa-%012x',random_int(1,0x7fffffff),random_int(1,0x7fffffff));}
function rejects(callable $f,$label){try{$f();}catch(DomainException $e){ok(true,$label);return;}throw new RuntimeException('Not blocked: '.$label);}
$day=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
$driver=EmployeeDriverSession::actor($db,2);$other=EmployeeDriverSession::actor($db,3);
$service=new DeliveryService($db,['cash','eft'],static fn($db,$j)=>(new OrderBalanceAdapter())->read($db,$j));
PartnerRouting::save($db,1,1,2,'Synthetic approved route');
$partner=['kind'=>'partner','active'=>true,'id'=>1,'partner_id'=>1];
$input=['customer_name'=>'Synthetic Customer','mobile'=>'+264810000000','address'=>'Synthetic street address','reference'=>'TEST-ONE','date'=>$day,'zone_id'=>1,'fee_payer'=>'customer','cod'=>'100','notes'=>'Synthetic test only'];
$request=uuid();$jobs=new PartnerService($db);$created=$jobs->arrange($partner,$request,$input);$id=(int)$created['id'];
ok($jobs->arrange($partner,$request,$input)===$created,'partner submission retry creates no duplicate');
NotificationOutbox::drain($db,50);
ok((int)$db->query("SELECT COUNT(*) FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id WHERE r.employee_id=2 AND n.related_id=$id AND n.title='NEW DELIVERY ASSIGNED'")->fetchColumn()===1,'partner request produces one driver assignment notification');
$read=static fn()=>DriverAssignments::find(DriverAssignments::read($db,2,null,$id),$id);
$a=$read();ok($a['action']==='accept'&&$a['stage']==='to_collect'&&$a['collectedAt']===null,'partner job shows Accept, without fabricated collection');
ok((float)$a['codAmountDueNad']===140.0&&!$a['paymentVerified']&&$a['paymentMethod']==='UNKNOWN','partner COD and customer fee displayed without assuming payment');
rejects(fn()=>$service->start($driver,$id,uuid(),true),'cannot start before accepting');
rejects(fn()=>$service->accept($other,$id,uuid()),'other driver cannot accept');
$accept=uuid();$accepted=$service->accept($driver,$id,$accept);ok($service->accept($driver,$id,$accept)===$accepted,'accept retry is idempotent');
$a=$read();ok($a['action']==='start'&&$a['collectedAt']===null,'accepted request shows Start without partner approval');
rejects(fn()=>$service->start($driver,$id,uuid(),false),'start requires explicit driver collection confirmation');
$start=uuid();$started=$service->start($driver,$id,$start,true);ok($service->start($driver,$id,$start,true)===$started,'start retry is idempotent');
$a=$read();ok($a['stage']==='on_route'&&$a['collectedAt']!==null,'driver collection creates on-route state with recorded timestamp');
ok((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$id AND event_type='driver_collection_confirmed'")->fetchColumn()===1,'collection evidence recorded exactly once');
ok((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$id AND event_type='parcel_collected'")->fetchColumn()===0,'no fictitious front or partner handover approval');
ok(ProofContext::read($db,2,$id)['reason']===null,'partner completion opens with amount due and verified collection');
$details=DriverOrderDetailsData::read($db,2,$id);ok(strpos(json_encode($details),'Partner parcel collected')!==false,'details timeline identifies actual driver-confirmed partner collection');
rejects(fn()=>$service->complete($driver,$id,uuid()),'completion without proof still rejected');
$fields=['recipient_name'=>'Synthetic Recipient','recipient_type'=>'customer','acknowledged'=>'1'];
rejects(fn()=>new ProofSubmission($fields,[],2),'missing photo and signature rejected');
$dir=sys_get_temp_dir().'/tedlaser-proof-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('HO_DRIVER_PROOF_DIR='.$dir);$store=new ProofStorage($dir);
$path=$dir.'/fixture.png';$image=imagecreatetruecolor(240,100);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imagerectangle($image,20,20,200,80,imagecolorallocate($image,20,50,30));imagepng($image,$path);imagedestroy($image);
$file=$store->prepare(['tmp_name'=>$path,'error'=>UPLOAD_ERR_OK],'photo',true);$proof=new ProofSubmission($fields,['photo'=>$file],2);
rejects(fn()=>$service->complete($driver,$id,uuid(),null,['confirmed'=>false,'amount'=>'140','method'=>'cash'],$proof),'payment confirmation still required');
rejects(fn()=>$service->complete($driver,$id,uuid(),null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid'],$proof),'cannot label unpaid partner job already paid');
$complete=uuid();$payment=['confirmed'=>true,'amount'=>'140','method'=>'cash'];$done=$service->complete($driver,$id,$complete,null,$payment,$proof);
ok($done['status']==='awaiting_reconciliation'&&$read()['stage']==='done','photo proof and payment complete delivery without repeating it');
ok($service->complete($driver,$id,$complete,null,$payment,$proof)===$done,'completion retry is idempotent');
$receipt=$db->query("SELECT * FROM delivery_receipts WHERE delivery_id=$id")->fetch();ok((int)$receipt['order_goods_cents']===0&&(int)$receipt['partner_cod_cents']===10000&&(int)$receipt['delivery_fee_cents']===4000,'partner cash stays separate from Hambelela sales');
// A fresh assignment invalidates acceptance and collection, including same-driver revisions.
$input['reference']='TEST-TWO';$id2=(int)$jobs->arrange($partner,uuid(),$input)['id'];$service->accept($driver,$id2,uuid());
$db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'revised',1,'{}')")->execute([$id2]);
rejects(fn()=>$service->start($driver,$id2,uuid(),true),'rescheduled assignment requires fresh acceptance');
// Hambelela still requires front-desk custody; the partner exception cannot leak.
$db->exec("INSERT INTO ops_orders(id,order_number,status,payment_status,payment_method,total_amount) VALUES(1,'TEST-SHOP','processing','paid','eft',0)");
$db->exec("INSERT INTO order_payment_allocations(order_id,amount_cents,payment_method,source) VALUES(1,0,'eft','pos')");
$db->prepare("INSERT INTO delivery_jobs(public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_payer,scheduled_date,driver_employee_id,arranged_by_employee_id) VALUES('TEST-SHOP','hambelela',1,'Synthetic','0810000000','Synthetic road','Test',0,'already_paid',?,2,1)")->execute([$day]);$shop=(int)$db->lastInsertId();
$db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'arranged',1,'{\"tracking_version\":1}')")->execute([$shop]);$service->accept($driver,$shop,uuid());
rejects(fn()=>$service->start($driver,$shop,uuid(),true),'Hambelela front-desk handover remains mandatory');
$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=2");rejects(fn()=>$service->accept($driver,$id2,uuid()),'inactive driver cannot act');
echo "PASS partner request -> notification -> Accept -> Start -> proof/payment Complete, no partner approval, no production data\n";
