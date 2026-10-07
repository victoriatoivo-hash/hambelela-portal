<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/PaymentReviewService.php';
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function check($value,$message){if(!$value)throw new RuntimeException($message);echo "PASS: $message\n";}
function denied(callable $f,string $message){try{$f();throw new RuntimeException('Unexpected success: '.$message);}catch(DomainException $e){check(true,$message);}}
$id=random_int(100000000,900000000);
$db->prepare("INSERT INTO ops_employees(id,full_name,status) VALUES(?,'Synthetic Review Owner','active')")->execute([$id]);
$db->prepare("INSERT INTO ops_orders(id,order_number,total_amount,payment_source,payment_status) VALUES(?,'REVIEW-TEST',450,'delivery','paid')")->execute([$id]);
$db->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source,source_version) VALUES(?,'cash',45000,'delivery','test')")->execute([$id]);
$snapshot=json_encode(['total_cents'=>45000,'allocations'=>['cash'=>0],'source'=>'pos']);
$db->prepare('INSERT INTO delivery_order_sync_reviews(order_id,snapshot_hash,snapshot_json) VALUES(?,?,?)')->execute([$id,hash('sha256',$snapshot),$snapshot]);$reviewId=(int)$db->lastInsertId();
$owner=['id'=>$id,'kind'=>'employee','role'=>'owner_admin','active'=>true];$front=$owner;$front['role']='front_desk_admin';$driver=$owner;$driver['role']='delivery_driver';$driver['driver_verified']=true;
$service=new \Hambelela\Delivery\PaymentReviewService($db);
$queue=$service->pending($front);check(!$queue['can_resolve'],'Front can inspect but cannot resolve');
$review=array_values(array_filter($queue['reviews'],fn($r)=>$r['id']===$reviewId))[0];
check($review['current']['allocations']['cash']===45000&&$review['incoming']['allocations']['cash']===0,'review compares protected and external amounts');
denied(fn()=>$service->pending($driver),'Driver cannot read financial review queue');
denied(fn()=>$service->keepVerifiedPortalPayment($front,$reviewId,$review['version'],'Verified receipt evidence.'),'Front cannot close Owner review');
denied(fn()=>$service->keepVerifiedPortalPayment($owner,$reviewId,$review['version'],'short'),'meaningful verification reason required');
$db->prepare('UPDATE delivery_order_sync_reviews SET last_seen_at=DATE_ADD(last_seen_at,INTERVAL 1 SECOND) WHERE id=?')->execute([$reviewId]);
denied(fn()=>$service->keepVerifiedPortalPayment($owner,$reviewId,$review['version'],'Verified receipt evidence.'),'new external snapshot blocks stale review');
$queue=$service->pending($owner);$review=array_values(array_filter($queue['reviews'],fn($r)=>$r['id']===$reviewId))[0];
$db->prepare('UPDATE order_payment_allocations SET amount_cents=44000 WHERE order_id=?')->execute([$id]);
denied(fn()=>$service->keepVerifiedPortalPayment($owner,$reviewId,$review['version'],'Verified receipt evidence.'),'changed portal payment blocks stale review');
$db->prepare('UPDATE order_payment_allocations SET amount_cents=45000 WHERE order_id=?')->execute([$id]);
$queue=$service->pending($owner);$review=array_values(array_filter($queue['reviews'],fn($r)=>$r['id']===$reviewId))[0];
$service->keepVerifiedPortalPayment($owner,$reviewId,$review['version'],'Synthetic verification against signed receipt.');
$row=$db->query("SELECT * FROM delivery_order_sync_reviews WHERE id=$reviewId")->fetch(PDO::FETCH_ASSOC);
check($row['state']==='resolved'&&(int)$row['resolved_by_employee_id']===$id&&!empty($row['resolved_at'])&&str_contains($row['resolution_note'],'Synthetic verification'),'Owner decision retains actor, time and reason');
check((int)$db->query("SELECT amount_cents FROM order_payment_allocations WHERE order_id=$id")->fetchColumn()===45000,'review closure does not mutate protected payment');
denied(fn()=>$service->keepVerifiedPortalPayment($owner,$reviewId,$review['version'],'Duplicate attempt reason.'),'closed review cannot be overwritten');
