<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/DriverProvisioning.php';
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Connection-scoped disposable tables shadow the fixtures; no live or persistent accounts.
foreach([
 'ops_roles'=>'id INT AUTO_INCREMENT PRIMARY KEY,role_key VARCHAR(60) UNIQUE,name VARCHAR(120),description VARCHAR(255)',
 'ops_employees'=>'id INT AUTO_INCREMENT PRIMARY KEY,role_id INT,full_name VARCHAR(160),phone VARCHAR(60),email VARCHAR(190),password_hash VARCHAR(255),status VARCHAR(20),packing_assignable INT,packing_auto_assignable INT',
 'ops_role_permissions'=>'role_id INT,permission_id INT',
 'delivery_driver_profiles'=>'employee_id INT PRIMARY KEY,active INT,verified_by_employee_id INT,verified_at DATETIME',
 'delivery_driver_credentials'=>'employee_id INT PRIMARY KEY,mobile VARCHAR(20) UNIQUE,secret_hash VARCHAR(255),active INT,session_version INT',
 'ops_security_events'=>'id BIGINT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(60),employee_id INT,metadata_json TEXT'
] as $table=>$columns)$db->exec("CREATE TEMPORARY TABLE $table ($columns) ENGINE=InnoDB");
$db->exec("INSERT INTO ops_roles(id,role_key,name) VALUES(1,'owner_admin','Owner'),(2,'front_desk_admin','Front')");
$db->exec("INSERT INTO ops_employees(id,role_id,full_name,status) VALUES(1,1,'Synthetic Owner','active'),(2,2,'Synthetic Front','active')");
$service=new \Hambelela\Delivery\DriverProvisioning($db);$secret='Synthetic-Only-Secret-123';
function provisionOk($v,$m){if(!$v)throw new RuntimeException($m);echo 'PASS: '.$m."\n";}
try{$service->save(2,'Synthetic Driver','+264810000008',$secret);throw new RuntimeException('Front allowed');}catch(DomainException $e){provisionOk(true,'Front cannot provision Driver');}
$id=$service->save(1,'Synthetic Driver','+264810000008',$secret);
$row=$db->query("SELECT * FROM delivery_driver_credentials WHERE employee_id=$id")->fetch(PDO::FETCH_ASSOC);
provisionOk(password_verify($secret,$row['secret_hash'])&&$row['secret_hash']!==$secret,'Driver secret is hashed');
$employee=$db->query("SELECT * FROM ops_employees WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
provisionOk($employee['email']===null&&$employee['password_hash']===null&&!$employee['packing_assignable'],'no fake email, staff password or packing assignment');
try{$service->save(1,'Synthetic Driver','+264810000008',$secret);throw new RuntimeException('Duplicate allowed');}catch(DomainException $e){provisionOk(true,'duplicate employee blocked');}
$service->save(1,'Synthetic Driver','+264810000008','Synthetic-Replacement-456',$id);
provisionOk((int)$db->query("SELECT session_version FROM delivery_driver_credentials WHERE employee_id=$id")->fetchColumn()===2,'reset invalidates existing sessions');
try{$service->save(1,'Wrong Person','+264810000008',$secret,$id);throw new RuntimeException('Wrong identity allowed');}catch(DomainException $e){provisionOk(true,'existing identity mismatch blocked');}
$audit=$db->query('SELECT metadata_json FROM ops_security_events')->fetchAll(PDO::FETCH_COLUMN);
provisionOk(count($audit)===2&&strpos(implode('',$audit),'Secret')===false&&strpos(implode('',$audit),'+264')===false,'audit retains actor and target without secret or mobile');
$role=(int)$employee['role_id'];$db->exec("INSERT INTO ops_role_permissions VALUES($role,99)");
try{$service->save(1,'Synthetic Driver','+264810000008',$secret,$id);throw new RuntimeException('Broad role allowed');}catch(DomainException $e){provisionOk(true,'unexpected staff permissions block provisioning');}
