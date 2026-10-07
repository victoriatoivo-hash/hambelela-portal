<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$testDb=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function db(){global $testDb;return $testDb;}
function ops_row($sql,$args=[]){$s=db()->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC);}
function ops_rows($sql,$args=[]){$s=db()->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
function ops_payment_label($m){return $m;}
function ops_ensure_order_payment_schema(){if(db()->inTransaction())throw new RuntimeException('DDL preflight inside transaction');return true;}
// Evaluate only the two exact shared functions, without production bootstrap/config.
$source=file_get_contents(__DIR__.'/../apps/operations/operations.php');
$start=strpos($source,'function ops_sync_order_payment_allocations(');$end=strpos($source,'function ops_normalize_fulfilment_mode(',$start);
if($start===false||$end===false)throw new RuntimeException('Shared function boundaries changed');eval(substr($source,$start,$end-$start));
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
$id=random_int(100000000,900000000);db()->prepare("INSERT INTO ops_orders(id,total_amount,payment_source,payment_version) VALUES(?,450,'delivery','receipt-test')")->execute([$id]);
db()->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source,source_version) VALUES(?,'cash',45000,'delivery','receipt-test')")->execute([$id]);
foreach(['woocommerce','pos','order_list'] as $source){ops_sync_order_payment_allocations($id,[['method'=>'cash','amount_cents'=>100]],$source,'stale');ok((int)ops_row('SELECT amount_cents FROM order_payment_allocations WHERE order_id=?',[$id])['amount_cents']===45000,"$source snapshot preserves Driver payment");ok(!db()->inTransaction(),'sync closes owned transaction');}
db()->beginTransaction();
try{ops_replace_order_payment_allocations($id,[['method'=>'cash','amount_cents'=>100]],'order_list','manual');throw new LogicException('Expected guard');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'Driver payments'))throw $e;}finally{db()->rollBack();}
ok(true,'manual replacement of Driver receipt rejected');
$id++;db()->prepare("INSERT INTO ops_orders(id,total_amount,payment_source,payment_version) VALUES(?,450,'woocommerce','old')")->execute([$id]);
ops_sync_order_payment_allocations($id,[['method'=>'cash','amount_cents'=>45000]],'pos','new');
ok(ops_row('SELECT payment_status FROM ops_orders WHERE id=?',[$id])['payment_status']==='paid','ordinary POS sync still updates payment');
db()->beginTransaction();ops_sync_order_payment_allocations($id,[['method'=>'cash','amount_cents'=>200]],'woocommerce','stale');ok(db()->inTransaction(),'shared sync preserves caller transaction');db()->rollBack();
echo "Shared production-function regression tests passed against isolated local fixtures.\n";
