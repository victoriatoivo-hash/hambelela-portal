<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/OrderTotalGuard.php';
use Hambelela\Delivery\OrderTotalGuard;
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TEMPORARY TABLE ops_orders(id INT PRIMARY KEY,payment_source VARCHAR(30),total_amount DECIMAL(12,2)) ENGINE=InnoDB");
$db->exec("INSERT INTO ops_orders VALUES(1,'delivery',510),(2,'pos',100)");
$write=static function()use($db){return $db->exec('UPDATE ops_orders SET total_amount=50 WHERE id IN (1,2)');};
try{OrderTotalGuard::update($db,[1,2],$write);throw new LogicException('Expected rejection');}catch(DomainException $e){}
if((float)$db->query('SELECT total_amount FROM ops_orders WHERE id=2')->fetchColumn()!==100.0||$db->inTransaction())throw new RuntimeException('Partial update or transaction leak');
echo "PASS: protected bulk total change is rejected before any write\n";
OrderTotalGuard::update($db,[2],static function()use($db){return $db->exec('UPDATE ops_orders SET total_amount=75 WHERE id=2');});
if((float)$db->query('SELECT total_amount FROM ops_orders WHERE id=2')->fetchColumn()!==75.0)throw new RuntimeException('Ordinary total update failed');
echo "PASS: ordinary Order total remains editable\n";
$db->beginTransaction();OrderTotalGuard::update($db,[2],static function(){return 0;});if(!$db->inTransaction())throw new RuntimeException('Caller transaction closed');$db->rollBack();
echo "PASS: caller transaction preserved\n";
