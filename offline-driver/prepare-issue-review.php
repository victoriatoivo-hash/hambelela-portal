<?php
// CLI-only, repeat-safe synthetic review data. Never uses the portal configuration.
if(PHP_SAPI!=='cli')exit(1);
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();
try{
 $q=$db->query("SELECT id FROM delivery_jobs WHERE public_reference='LAB-PHASE06-ISSUE' FOR UPDATE");$existing=$q->fetchColumn();
 if($existing){$db->commit();echo "Existing local review delivery: $existing\n";exit;}
 $id=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM ops_orders FOR UPDATE')->fetchColumn();
 $db->prepare("INSERT INTO ops_orders(id,order_number,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status) VALUES(?,'LAB-PHASE06-ISSUE',120,'paid','EFT','Local test recipient','','Synthetic Phase 06 review only','delivery','in_progress')")->execute([$id]);
 $db->prepare("INSERT INTO ops_order_items(order_id,product_name,quantity) VALUES(?,'Synthetic test parcel',1)")->execute([$id]);
 $db->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES(?,'EFT',12000,'pos')")->execute([$id]);
 $day=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
 $db->prepare("INSERT INTO delivery_jobs(public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_payer,scheduled_date,status,driver_employee_id,arranged_by_employee_id,notes) VALUES('LAB-PHASE06-ISSUE','hambelela',?,'Local test recipient','','Training warehouse, Windhoek','Local review',0,'already_paid',?,'ready',3,2,'Synthetic fixture; never a live customer')")->execute([$id,$day]);$job=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,'arranged',2,?,UTC_TIMESTAMP())")->execute([$job,json_encode(['local_fixture'=>'phase6','payment_arrangement'=>'already_paid','order_dispatch'=>['total_cents'=>12000]])]);
 $db->commit();echo "Created local review delivery: $job\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
