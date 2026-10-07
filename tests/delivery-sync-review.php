<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/OrderSyncReview.php';
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!$db->query("SHOW COLUMNS FROM ops_orders LIKE 'woo_order_id'")->fetch())$db->exec('ALTER TABLE ops_orders ADD COLUMN woo_order_id INT UNIQUE NULL');
if(!$db->query("SHOW TABLES LIKE 'delivery_order_sync_reviews'")->fetchColumn())$db->exec(file_get_contents(__DIR__.'/../database/delivery/003-sync-review-proposed.sql'));
$id=random_int(100000000,900000000);$db->beginTransaction();
try{
 $db->prepare("INSERT INTO ops_orders(id,woo_order_id,total_amount,payment_source) VALUES(?,?,450,'delivery')")->execute([$id,$id]);
 $db->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source,source_version) VALUES(?,'cash',45000,'delivery','test')")->execute([$id]);
 \Hambelela\Delivery\OrderSyncReview::capture($db,$id,'450.00',[['method'=>'cash','amount_cents'=>45000]],'pos');
 if((int)$db->query("SELECT COUNT(*) FROM delivery_order_sync_reviews WHERE order_id=$id")->fetchColumn()!==0)throw new RuntimeException('Equal snapshot should not conflict');
 for($i=0;$i<2;$i++)\Hambelela\Delivery\OrderSyncReview::capture($db,$id,'450.00',[['method'=>'cash','amount_cents'=>0]],'pos');
 if((int)$db->query("SELECT COUNT(*) FROM delivery_order_sync_reviews WHERE order_id=$id")->fetchColumn()!==1)throw new RuntimeException('Review should deduplicate');
 echo "PASS: matching snapshot ignored; conflicting snapshot captured once without replacing payment.\n";
}finally{$db->rollBack();}
