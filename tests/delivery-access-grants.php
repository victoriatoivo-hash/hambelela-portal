<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/AccessGrants.php';
use Hambelela\Delivery\AccessGrants;
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach([
 'ops_roles'=>'id INT PRIMARY KEY,role_key VARCHAR(60)',
 'ops_employees'=>'id INT PRIMARY KEY,role_id INT,status VARCHAR(20)',
 'ops_permissions'=>'id INT AUTO_INCREMENT PRIMARY KEY,permission_key VARCHAR(100) UNIQUE,description VARCHAR(190)',
 'delivery_employee_grants'=>'employee_id INT,permission_id INT,granted_by_employee_id INT,granted_at DATETIME,PRIMARY KEY(employee_id,permission_id)',
 'ops_security_events'=>'id INT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(60),employee_id INT,metadata_json TEXT'
] as $table=>$definition)$db->exec("CREATE TEMPORARY TABLE $table($definition) ENGINE=InnoDB");
$db->exec("INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'marketing_sales'),(3,'delivery_driver')");
$db->exec("INSERT INTO ops_employees VALUES(1,1,'active'),(2,2,'active'),(3,3,'active')");
function checkGrant($test,$message){if(!$test)throw new RuntimeException($message);echo "PASS: $message\n";}
$empty=AccessGrants::version([]);
foreach([[2,2,['delivery_view']],[1,3,['delivery_accounting_view']],[1,2,['delivery_drive']],[1,2,['delivery_accounting_expenses_manage']]] as $case){try{AccessGrants::save($db,$case[0],$case[1],$case[2],$empty,'Synthetic approved test');throw new RuntimeException('Unsafe grant accepted');}catch(DomainException $e){checkGrant(true,'unauthorized, Driver, unsupported or incomplete grant denied');}}
$approved=['delivery_accounting_view','delivery_accounting_expenses_manage'];
AccessGrants::save($db,1,2,$approved,$empty,'Synthetic Owner verification');
checkGrant(count(AccessGrants::current($db,2))===2,'Owner grants only approved Delivery capabilities');
try{AccessGrants::save($db,1,2,[],$empty,'Synthetic stale request');throw new RuntimeException('Stale write accepted');}catch(DomainException $e){checkGrant(true,'stale access update rejected');}
AccessGrants::save($db,1,2,[],AccessGrants::version($approved),'Synthetic access revoked');
checkGrant(AccessGrants::current($db,2)===[],'explicit grants revoked');
checkGrant((int)$db->query('SELECT role_id FROM ops_employees WHERE id=2')->fetchColumn()===2,'staff role unchanged');
checkGrant((int)$db->query('SELECT COUNT(*) FROM ops_security_events')->fetchColumn()===2,'grant and revocation both audited');
