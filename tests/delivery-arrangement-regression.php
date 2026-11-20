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
// MySQL binary JSON may return object keys in storage order; object member order is not part of the API.
function sameResponse(array $a,array $b):bool{ksort($a);ksort($b);return $a===$b;}
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
 require_once __DIR__.'/../shared/delivery/DriverErrands.php';
 $errands=new \Hambelela\Delivery\DriverErrands($db);$errands->install();$errands->install();
 $errand=['place'=>'Test supplier','contact_name'=>'Test contact','contents'=>'Two cartons','payment_state'=>'unpaid','driver_id'=>3,'request_key'=>bin2hex(random_bytes(16))];
 denied(fn()=>$errands->send($driver,$errand),'Driver cannot log front-desk errands');
 denied(fn()=>$errands->send($front,array_merge($errand,['payment_state'=>''])),'Payment status required');
 denied(fn()=>$errands->send($front,array_merge($errand,['driver_id'=>5])),'Non-driver cannot be assigned');
 $eid=$errands->send($front,$errand);ok($eid>0&&$errands->send($front,$errand)===$eid,'Double submission creates one errand');
 ok(count($errands->previewForDriver($owner,3))===1&&$errands->previewForDriver($owner,(int)$other['id'])===[],'Owner preview reads only the selected driver errands');
 denied(fn()=>$errands->previewForDriver($front,3),'Front cannot access owner errand preview');
 $startedId=$errands->send($front,array_merge($errand,['request_key'=>bin2hex(random_bytes(16))]));
 denied(fn()=>$errands->change($other,$startedId,1,'started',[]),'Other driver cannot start errand');
 $errands->change($driver,$startedId,1,'started',[]);$startedAgain=$errands->change($driver,$startedId,1,'started',[]);
 ok($startedAgain['state']==='started'&&$startedAgain['started_at']!==null,'Errand start is timed and repeat safe');
 ok((int)$db->query("SELECT COUNT(*) FROM delivery_errand_events WHERE errand_id=$startedId AND action='started'")->fetchColumn()===1,'Repeated start creates one audit event');
 $errands->change($front,$startedId,2,'cancel',['note'=>'Synthetic test completed']);
 denied(fn()=>$errands->send($front,array_merge($errand,['contents'=>'different'])),'Changed payload never reuses an earlier send');
 ok($errands->next($driver)['payment_state']==='unpaid'&&$errands->next($other)===null,'Popup only reaches assigned driver with payment instructions');
 denied(fn()=>$errands->mark($other,$eid,true),'Other driver cannot mark collected');
 $errands->mark($driver,$eid,false);ok(!$errands->next($driver)&&count($errands->entries($driver,false,0))===1,'Acknowledging removes popup, not workload');
 denied(fn()=>$errands->change($driver,$eid,1,'collected',['confirmed'=>'1']),'Collection requires a note');
 denied(fn()=>$errands->change($driver,$eid,1,'collected',['note'=>'Collected test items']),'Collection requires explicit confirmation');
 $errands->change($driver,$eid,1,'collected',['note'=>'Collected test items','confirmed'=>'1']);ok(count($errands->entries($front,true,0))===1&&count($errands->entries($other,true,0))===0,'Front sees collected errand; other driver never sees it');
 denied(fn()=>$errands->change($driver,$eid,1,'collected',['note'=>'Duplicate','confirmed'=>'1']),'Stale completion cannot duplicate history');
 denied(fn()=>$errands->change($driver,$eid,2,'reopen',['note'=>'Undo']),'Only front desk can correct completion');
 $errands->change($front,$eid,2,'reopen',['note'=>'Completion recorded in error']);
 ok(count($errands->entries($driver,false,0))===1,'Reopening restores pending work');
 $claim=['claim_key'=>str_repeat('a',32)];$claim2=['claim_key'=>str_repeat('b',32)];
 ok($errands->change($driver,$eid,3,'claim',$claim)['claimed'],'First tab claims popup');
 ok(!$errands->change($driver,$eid,3,'claim',$claim2)['claimed'],'Second tab cannot duplicate active popup');
 $errands->change($driver,$eid,3,'later',[]);ok($errands->next($driver)===null&&$errands->countPending($driver)===1,'View later snoozes popup but preserves pending badge');
 $edited=array_merge($errand,['driver_id'=>4,'amount'=>'150.5','payer'=>'Front desk pays supplier','note'=>'Reassigned after review']);
 $errands->change($front,$eid,3,'edit',$edited);
 ok(count($errands->entries($driver,false,0))===0&&$errands->countPending($other)===1,'Reassignment removes former driver access');
 denied(fn()=>$errands->history($driver,$eid),'Former driver cannot read reassigned history');
 denied(fn()=>$errands->change($driver,$eid,4,'seen',[]),'Former driver cannot acknowledge');
 denied(fn()=>$errands->change($front,$eid,3,'edit',$edited),'Optimistic revision prevents stale overwrite');
 ok($errands->next($other)['amount']==='150.50','Amount and payer reach newly assigned driver');
 $errands->change($other,$eid,4,'blocked',['note'=>'Supplier closed']);
 ok($errands->entries($front,false,0)[0]['state']==='blocked','Unable-to-collect remains visible to front');
 $errands->change($front,$eid,5,'cancel',['note'=>'No longer needed']);
 ok(!$errands->next($other)&&$errands->countPending($other)===0&&count($errands->entries($front,false,0,true))===2,'Cancelled errand retained but removed from driver workload');
 denied(fn()=>$errands->change($other,$eid,6,'collected',['note'=>'Late action','confirmed'=>'1']),'Cancelled errand cannot be collected');
 $history=$errands->history($owner,$eid);ok(count($history)===8&&$history[0]['action']==='cancel','Audit preserves sent, seen, collected, reopened, deferred, reassigned, blocked and cancelled');
 $saved=json_decode($history[0]['after_json'],true);ok($saved['amount']==='150.50'&&$saved['payer']==='Front desk pays supplier','Payment details preserved in audit');
 $errand['request_key']=bin2hex(random_bytes(16));$another=$errands->send($front,$errand);
 denied(fn()=>$errands->change($front,$another,1,'edit',array_merge($errand,['amount'=>'-1','note'=>'Invalid payment'])),'Negative amount rejected transactionally');
 ok($errands->entries($front,false,0)[0]['revision']===1||((int)$errands->entries($front,false,0)[0]['revision'])===1,'Invalid edit does not advance revision');
 ok((int)$db->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn()===0,'Errand does not create a delivery sale');
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
 $db->exec("CREATE TABLE ops_order_items(id INT PRIMARY KEY,order_id INT,product_name VARCHAR(190),quantity INT);INSERT INTO ops_order_items VALUES(1,100,'Test bottle',2)");
 $body['board_terms']='cod';
 $job=$core->command($front,$body);$jobId=(int)$job['id'];
 ok(sameResponse($core->command($front,$body),$job),'retry replays same delivery');
 $duplicate=$body;$duplicate['uuid']=requestId();denied(fn()=>$core->command($front,$duplicate),'second request cannot create duplicate arrangement');
 ok((int)$db->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn()===1,'one order has one delivery record');
 $saved=$db->query('SELECT * FROM delivery_jobs')->fetch();
 ok(strpos($saved['notes'],'2 × Test bottle')!==false&&strpos($saved['notes'],'Cash on delivery')!==false,'assigned driver receives order quantities and payment instructions');
 $pickup=['action'=>'parcel_collected','id'=>$jobId,'driver'=>3,'uuid'=>requestId()];
 denied(fn()=>$core->command($driver,$pickup),'driver cannot record front desk pickup');
 $picked=$core->command($front,$pickup);ok(isset($picked['front_cod_due_utc'])&&sameResponse($core->command($front,$pickup),$picked),'pickup starts one front desk COD clock');
 $otherPickup=$pickup;$otherPickup['uuid']=requestId();denied(fn()=>$core->command($owner,$otherPickup),'other employee cannot record duplicate pickup');
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

 denied(fn()=>$core->command($driver,['action'=>'complete','id'=>$jobId,'uuid'=>requestId(),'confirmation'=>['confirmed'=>false,'note'=>'Delivered']]),'Explicit completion confirmation required');
 denied(fn()=>$core->command($driver,['action'=>'complete','id'=>$jobId,'uuid'=>requestId(),'confirmation'=>['confirmed'=>true,'note'=>'  ']]),'Method and actual amount mandatory even with confirmation');
 $variant=getenv('DRIVER_DECLARATION_CASE');$amount='120.00';$method='cash';$expected=12000;
 if(in_array($variant,['prepaid-extra','prepaid-zero'],true)){
   $db->exec("UPDATE ops_orders SET payment_status='paid' WHERE id=100");
   // The authoritative adapter verifies actual allocations; seed the same ledger used by Front.
   $db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents) VALUES(100,'cash',12000)");
   $expected=0;
 }
 if($variant==='short')$amount='100.00';if($variant==='extra')$amount='130.00';if($variant==='prepaid-extra')$amount='10.00';
 if($variant==='zero'||$variant==='prepaid-zero'){$amount='0.00';$method=$expected?'no_payment':'already_paid';}
 if($variant==='mobile')$method='mobile';
 $finish=['action'=>'complete','id'=>$jobId,'uuid'=>requestId(),'confirmation'=>['confirmed'=>true,'amount'=>$amount,'method'=>$method,'note'=>'']];
 $finished=$core->command($driver,$finish);ok(sameResponse($finished,$core->command($driver,$finish)),'Completion retry is idempotent');
 ok($finished['note']===''&&$finished['delivery_payment_confirmed']===true,'Completion note and confirmation retained in event');
 ok($finished['expected_cents']===$expected&&$finished['declared_cents']===\Hambelela\Delivery\DeliveryPolicy::cents($amount),'Saved actual and expected integer cents');
 if($variant){
   $review=$variant!=='prepaid-zero';
   ok($finished['declaration_review']===$review,'Discrepant or unspecified-channel money is held for review');
   ok((int)$db->query('SELECT COUNT(*) FROM delivery_receipts')->fetchColumn()===0,'no automatic or duplicate allocated receipt');
   ok($db->query('SELECT delivered_at FROM delivery_jobs')->fetchColumn()!==null,'actual completion time recorded');
   $read=$core->board($driver,['state'=>'driver_completed']);ok($read['jobs'][0]['declaration']['declared_cents']===$finished['declared_cents'],'delivered job exposes saved declaration');
   return;
 }
 $receipt=(int)$db->query('SELECT id FROM delivery_receipts')->fetchColumn();
 ok($db->query("SELECT payment_status FROM ops_orders WHERE id=100")->fetchColumn()==='unpaid','dispatch, customer receipt and delivery do not mark order paid');
 ok($db->query("SELECT status FROM delivery_jobs")->fetchColumn()==='awaiting_reconciliation','delivered independently from cash reconciliation');
 denied(fn()=>$core->command($front,['action'=>'reconcile','id'=>$jobId,'uuid'=>requestId(),'receipt'=>$receipt]),'cash cannot be posted before handover');
 $count=['action'=>'cash_handover','id'=>$jobId,'receipt'=>$receipt,'amount'=>'120.00','uuid'=>requestId()];
 denied(fn()=>$core->command($driver,$count),'driver cannot reconcile own handover');
 if(getenv('COLLECTION_SHORT_TEST')==='1'){
   $count['amount']='115.00';$result=$core->command($owner,$count);
   ok($result['difference_cents']===-500&&$db->query('SELECT status FROM delivery_receipts')->fetchColumn()==='issue','short handover is held for review');
   denied(fn()=>$core->command($front,['action'=>'reconcile','id'=>$jobId,'uuid'=>requestId(),'receipt'=>$receipt]),'short handover cannot be posted');
   ok((int)$db->query('SELECT COUNT(*) FROM order_payment_allocations')->fetchColumn()===0,'no allocation posted for disputed cash');
   return;
 }
 $counted=$core->command($owner,$count);ok($counted['difference_cents']===0&&sameResponse($counted,$core->command($owner,$count)),'owner can count cash without double booking');
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
