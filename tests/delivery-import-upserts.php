<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Connection-scoped temporary table shadows, but never changes, existing test orders.
$db->exec("CREATE TEMPORARY TABLE ops_orders(id INT AUTO_INCREMENT PRIMARY KEY,woo_order_id INT UNIQUE,order_number VARCHAR(60),customer_name VARCHAR(160),customer_contact VARCHAR(190),payment_method VARCHAR(190),total_amount DECIMAL(12,2),product_total DECIMAL(12,2),tax_total DECIMAL(12,2),shipping_total DECIMAL(12,2),shipping_tax_total DECIMAL(12,2),discount_total DECIMAL(12,2),refund_total DECIMAL(12,2),payment_status VARCHAR(30),order_type VARCHAR(30),priority VARCHAR(30),complexity INT,assigned_packer_id INT,status VARCHAR(30),notes TEXT,workload_score INT,created_at DATETIME,updated_at DATETIME,payment_source VARCHAR(30) DEFAULT 'woocommerce')");
foreach(['orders-board-action.php','sync-orders.php'] as $file){
 foreach([[true,true],[true,false],[false,false]] as [$hasTotalAmount,$hasReportBreakdown]){
  $text=file_get_contents(__DIR__.'/../apps/operations/'.$file);
  $anchor=strpos($text,'$pdo->beginTransaction();');
  $start=strpos($text,$file==='orders-board-action.php'?'if ($hasTotalAmount) {':'$amountColumns =',$anchor);
  $end=strpos($text,'$itemStmt =',$start);if($start===false||$end===false)throw new RuntimeException('Importer boundaries changed');
  $displayDateTimeInsertColumn='';$displayDateTimeInsertValue='';$displayDateTimeUpdate='created_at = VALUES(created_at)';
  $pdo=new class{public function prepare($sql){return $sql;}};
  eval(substr($text,$start,$end-$start)); // Actual import SQL construction only; no application/bootstrap.
  preg_match('/INSERT INTO ops_orders\s*\((.*?)\)\s*VALUES/s',$orderStmt,$m);$cols=array_map('trim',explode(',',$m[1]));
  $woo=random_int(10000000,99999999);
  $values=array_map(fn($c)=>match($c){'woo_order_id'=>$woo,'payment_method'=>'cash','total_amount'=>510,'product_total'=>450,'shipping_total'=>60,'tax_total','shipping_tax_total','discount_total','refund_total'=>0,'complexity','assigned_packer_id','workload_score'=>1,'created_at'=>'2026-10-06 12:00:00',default=>'test'},$cols);
  $stmt=$db->prepare($orderStmt);$stmt->execute($values);$db->exec("UPDATE ops_orders SET payment_source='delivery' WHERE woo_order_id=$woo");
  $changed=$values;foreach($cols as $i=>$c)if($c==='payment_method')$changed[$i]='eft';elseif(in_array($c,['total_amount','product_total','tax_total','shipping_total','shipping_tax_total','discount_total','refund_total'],true))$changed[$i]=20;
  $stmt->execute($changed);$row=$db->query("SELECT * FROM ops_orders WHERE woo_order_id=$woo")->fetch(PDO::FETCH_ASSOC);
  if($row['payment_method']!=='cash'||($hasTotalAmount&&(float)$row['total_amount']!==510.0)||($hasReportBreakdown&&(float)$row['shipping_total']!==60.0))throw new RuntimeException('Delivery financial overwrite');
  $db->exec("UPDATE ops_orders SET payment_source='woocommerce' WHERE woo_order_id=$woo");$stmt->execute($changed);$row=$db->query("SELECT * FROM ops_orders WHERE woo_order_id=$woo")->fetch(PDO::FETCH_ASSOC);
  if($row['payment_method']!=='eft'||($hasTotalAmount&&(float)$row['total_amount']!==20.0))throw new RuntimeException('Ordinary sync changed');
  echo "PASS: $file total=".(int)$hasTotalAmount.' breakdown='.(int)$hasReportBreakdown." protects Delivery; ordinary sync works.\n";
 }
}
