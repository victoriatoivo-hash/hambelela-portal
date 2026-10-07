<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/hr-access.php';
$dsn = getenv('HR_ACCESS_TEST_DSN');
if (!$dsn) { throw new RuntimeException('Set HR_ACCESS_TEST_DSN to an isolated test server.'); }
$server = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (['hr_access_test_portal','hr_access_test_hr'] as $name) { $server->exec('CREATE DATABASE IF NOT EXISTS ' . $name); }
$portal = new PDO($dsn . ';dbname=hr_access_test_portal', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$hr = new PDO($dsn . ';dbname=hr_access_test_hr', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (['employee_user_links','ops_employees','ops_roles'] as $table) { $portal->exec('DROP TABLE IF EXISTS ' . $table); }
foreach (['users','employees'] as $table) { $hr->exec('DROP TABLE IF EXISTS ' . $table); }
$portal->exec('CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60))');
$portal->exec('CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,full_name VARCHAR(160),email VARCHAR(190),status VARCHAR(30))');
$portal->exec('CREATE TABLE employee_user_links(id INT PRIMARY KEY AUTO_INCREMENT,portal_user_id INT UNIQUE,hr_employee_id INT,role VARCHAR(120),linked_by INT,active TINYINT DEFAULT 1,linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$hr->exec('CREATE TABLE employees(id INT PRIMARY KEY,emp_number VARCHAR(30),first_name VARCHAR(80),last_name VARCHAR(80),email VARCHAR(180),status VARCHAR(30))');
$hr->exec("CREATE TABLE users(id INT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(120) NOT NULL,email VARCHAR(180) UNIQUE NOT NULL,password VARCHAR(255) NOT NULL,role ENUM('admin','employee') DEFAULT 'employee',employee_id INT,active TINYINT DEFAULT 1)");
$portal->exec("INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'marketing_sales'),(3,'packer')");
$portal->exec("INSERT INTO ops_employees VALUES(1,1,'Victoria Toivo','owner@example.test','active'),(15,2,'Hope Kahuika','hope@example.test','active'),(7,3,'Klaudia Averinus','klaudia@business.test','active')");
$portal->exec('INSERT INTO employee_user_links(portal_user_id,hr_employee_id,active) VALUES(15,4,1),(7,1,1)');
$hr->exec("INSERT INTO employees VALUES(4,'HO-004','Hope','Kahuika',' hope@example.test ','active'),(1,'HO-001','Klaudia','Averinus',' klaudia@hr.test ','active')");
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } echo 'PASS ' . $message . "\n"; }
function health(): array { global $portal,$hr; return hr_access_health($portal,$hr,15); }
function denied(string $label, callable $action): void {
    try { $action(); } catch (RuntimeException $error) { check(true,$label); return; }
    throw new RuntimeException('Unexpectedly allowed: ' . $label);
}
check(health()['state']==='account_missing','missing HR user is never ready');
check(hr_access_health($portal,null,15)['state']==='unavailable','connection failure is never missing user');
denied('employee cannot provision HR users',function()use($portal,$hr){hr_access_setup($portal,$hr,15,4,15);});
$before = $hr->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$result = hr_access_setup($portal,$hr,15,4,1);
check($result['state']==='ready' && $result['created'],'owner provisions existing Hope profile');
$user = $hr->query('SELECT * FROM users WHERE employee_id=4')->fetch(PDO::FETCH_ASSOC);
check($user['role']==='employee' && (int)$user['active']===1,'provisioned account is active employee without admin');
check($user['email']==='hope@example.test','HR email is normalized');
check(password_get_info($user['password'])['algoName']==='bcrypt' && !password_verify('password',$user['password']),'only secure hash stored, no shared default');
check($hr->query('SELECT COUNT(*) FROM employees')->fetchColumn()===$before,'no HR employee profile duplicated');
check(!hr_access_setup($portal,$hr,15,4,1)['created'] && (int)$hr->query('SELECT COUNT(*) FROM users WHERE employee_id=4')->fetchColumn()===1,'repair is idempotent');
$hr->exec('UPDATE users SET active=0 WHERE employee_id=4');
check(health()['state']==='account_inactive','inactive account distinguished from missing');
denied('inactive account never silently enabled',function()use($portal,$hr){hr_access_setup($portal,$hr,15,4,1);});
check((int)$hr->query('SELECT active FROM users WHERE employee_id=4')->fetchColumn()===0,'deactivation preserved');
$hr->exec("UPDATE users SET active=1,role='admin' WHERE employee_id=4");
check(health()['state']==='conflict','wrong HR role is conflict');
denied('wrong role cannot be provisioned around',function()use($portal,$hr){hr_access_setup($portal,$hr,15,4,1);});
$hr->exec("UPDATE users SET role='employee',employee_id=1 WHERE email='hope@example.test'");
check(health()['state']==='conflict','cross-person email/name match denied');
denied('wrong employee cannot be relinked automatically',function()use($portal,$hr){hr_access_setup($portal,$hr,15,4,1);});
$hr->exec('UPDATE users SET employee_id=4');
$hr->exec("INSERT INTO users(name,email,password,employee_id) VALUES('Hope Kahuika','other@example.test','unused',4)");
check(health()['state']==='conflict','duplicate user rows rejected');
$hr->exec("DELETE FROM users WHERE email='other@example.test'");
$portal->exec('UPDATE employee_user_links SET active=0 WHERE portal_user_id=15');
check(health()['state']==='not_linked','inactive link unusable');
check(hr_access_setup($portal,$hr,15,4,1,true)['state']==='ready','future link verifies whole chain');
$portal->exec("INSERT INTO ops_employees VALUES(16,2,'Hope Kahuika','other-hope@example.test','active')");
$portal->exec('INSERT INTO employee_user_links(portal_user_id,hr_employee_id,active) VALUES(16,4,1)');
check(health()['state']==='conflict','shared HR mapping denied');
$portal->exec('DELETE FROM employee_user_links WHERE portal_user_id=16');
$portal->exec("UPDATE ops_employees SET full_name='Someone Else' WHERE id=15");
check(health()['state']==='conflict','profile identity mismatch denied');
$portal->exec("UPDATE ops_employees SET full_name='Hope Kahuika',status='inactive' WHERE id=15");
check(health()['state']==='conflict','inactive portal employee denied');
$portal->exec("UPDATE ops_employees SET status='active' WHERE id=15");
$hr->exec("UPDATE employees SET status='terminated' WHERE id=4");
check(health()['state']==='profile_inactive','terminated HR profile denied');
$hr->exec("UPDATE employees SET status='active' WHERE id=4");
$hr->exec("INSERT INTO users(name,email,password,employee_id) VALUES('Klaudia Averinus',' klaudia@hr.test ','unused',1)");
check(hr_access_health($portal,$hr,7)['state']==='ready','separate Business and HR emails preserved');
$portal->exec('DELETE FROM employee_user_links WHERE portal_user_id=15');
$hr->exec('DELETE FROM users WHERE employee_id=4');
check(hr_access_setup($portal,$hr,15,4,1,true)['state']==='ready','new link provisions absent user and verifies ready');
check(hr_access_health($portal,$hr,15,1)['state']==='conflict','existing link cannot silently point at another profile');
check(health()['state']==='ready','Hope fixture ready for bridge HTTP test');
