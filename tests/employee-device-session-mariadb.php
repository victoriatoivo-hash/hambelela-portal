<?php
declare(strict_types=1);
// Disposable CI MariaDB only; never load the portal database configuration.
require_once dirname(__DIR__).'/shared/employee-device-session.php';
if (getenv('CI')!=='true') throw new RuntimeException('Disposable CI fixture only');
function db(): PDO {return $GLOBALS['fixture'];}
function portal_request_is_https(): bool {return true;}
function portal_initialize_authenticated_session(array $user): void {$_SESSION['absolute_expires_at']=time()+36000;}
function expect_fixture($value): void {if (!$value) throw new RuntimeException('Lifecycle regression');}
$GLOBALS['fixture']=$db=new PDO('mysql:host=127.0.0.1;port=33319;dbname=sessions_fixture','root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name TEXT,email TEXT,password_hash TEXT,status VARCHAR(20),role_id INT,must_change_access_code INT,locked_until DATETIME);
CREATE TABLE ops_roles(id INT PRIMARY KEY,name TEXT,role_key TEXT);
CREATE TABLE ops_security_events(id INT PRIMARY KEY,employee_id INT,event_type TEXT,metadata_json TEXT);
INSERT INTO ops_roles VALUES(1,'Fixture','owner_admin');
INSERT INTO ops_employees VALUES(8,'Fixture','fixture@example.invalid','test-hash','active',1,0,NULL)");
expect_fixture(session_start());
portal_device_issue(8,'test-hash');
$d=$db->query('SELECT * FROM portal_employee_devices')->fetch(PDO::FETCH_ASSOC);
expect_fixture($d && (int)$d['expires_at']-(int)$d['issued_at']===90*86400);
// Inject a known browser secret only in the fixture; exercise real rotation and logout SQL.
$token=$d['device_id'].'.'.str_repeat('b',64);
$db->prepare('UPDATE portal_employee_devices SET token_hash=?,last_contact=? WHERE device_id=?')->execute([hash('sha256',$token),time()-2000,$d['device_id']]);
$_COOKIE[PORTAL_DEVICE_COOKIE]=$token;
expect_fixture(portal_device_restore() && $_SESSION['portal_dashboard_pending'] && portal_restored_device_valid(8,time()));
expect_fixture(!portal_device_restore());
portal_device_forget();
expect_fixture((int)$db->query('SELECT COUNT(*) FROM portal_employee_devices')->fetchColumn()===0);
echo "MariaDB device registration, absolute lifetime, rotation, return and logout passed.\n";
