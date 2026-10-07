<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/FrontSession.php';
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TEMPORARY TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60))');
$db->exec('CREATE TEMPORARY TABLE ops_employees(id INT PRIMARY KEY,role_id INT,status VARCHAR(20))');
$db->exec("INSERT INTO ops_roles VALUES(1,'front_desk_admin'),(2,'delivery_driver'),(3,'owner_admin'),(4,'marketing_sales')");
$db->exec("INSERT INTO ops_employees VALUES(1,1,'active'),(2,2,'active'),(3,3,'inactive'),(4,4,'active')");
foreach([1,4] as $id){$actor=\Hambelela\Delivery\FrontSession::actor($db,$id);if($actor['id']!==$id)throw new RuntimeException('Wrong identity');}
foreach([2,3,999] as $id){try{\Hambelela\Delivery\FrontSession::actor($db,$id);throw new RuntimeException('Unauthorized identity accepted');}catch(DomainException $e){}}
$_SESSION=['delivery_front_csrf'=>'server-token'];
try{\Hambelela\Delivery\FrontSession::csrf('wrong');throw new RuntimeException('CSRF accepted');}catch(DomainException $e){}
\Hambelela\Delivery\FrontSession::csrf('server-token');
echo "PASS: Front/Marketing identity resolved from active database roles; Driver/inactive/missing denied; CSRF checked.\n";
