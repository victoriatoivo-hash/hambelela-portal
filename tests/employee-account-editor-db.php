<?php
declare(strict_types=1);
$dsn=getenv('TEDLASER_TEST_DSN')?:'mysql:host=127.0.0.1;port=3318';if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Loopback only');
$db=new PDO($dsn,'root',getenv('TEDLASER_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='employee_edit_test_'.bin2hex(random_bytes(5));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
require __DIR__.'/../shared/employee-account-editor.php';require __DIR__.'/../shared/hr-access.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo 'PASS '.$m."\n";}function denied($fn,$m){try{$fn();}catch(DomainException $e){ok(true,$m);return;}throw new RuntimeException('Allowed: '.$m);}
try{
$db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80),name VARCHAR(100)); INSERT INTO ops_roles VALUES(1,'owner_admin','Owner/Admin'),(2,'marketing_sales','Marketing'),(3,'front_desk_admin','Front Desk'),(4,'delivery_driver','Driver');
CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,full_name VARCHAR(160),email VARCHAR(190) UNIQUE,phone VARCHAR(40),status VARCHAR(20),packing_assignable INT,packing_auto_assignable INT,password_hash VARCHAR(255),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
INSERT INTO ops_employees VALUES(1,1,'Owner','owner@example.test','','active',0,0,'unchanged',NOW()),(2,2,'Test Employee','employee@example.test','123456789','active',1,1,'unchanged',NOW()),(3,4,'Driver One','driver@example.test','','active',0,0,'unchanged',NOW());
CREATE TABLE employee_user_links(id INT AUTO_INCREMENT PRIMARY KEY,portal_user_id INT UNIQUE,hr_employee_id INT,role VARCHAR(120),linked_by INT,active INT,linked_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ops_security_events(id INT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(100),employee_id INT,metadata_json JSON,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE employees(id INT PRIMARY KEY,emp_number VARCHAR(20),first_name VARCHAR(80),last_name VARCHAR(80),email VARCHAR(190),job_title VARCHAR(100),department VARCHAR(100),status VARCHAR(20));
INSERT INTO employees VALUES(10,'T10','Test','Employee','hr@example.test','Sales','Marketing','active'),(11,'T11','Wrong','Person','wrong@example.test','','','active');
CREATE TABLE users(id INT PRIMARY KEY,name VARCHAR(160),email VARCHAR(190),role VARCHAR(40),employee_id INT,active INT);
INSERT INTO users VALUES(10,'Test Employee','hr@example.test','employee',10,1);
CREATE TABLE history(id INT PRIMARY KEY,employee_id INT,evidence VARCHAR(100));INSERT INTO history VALUES(1,2,'Orders tasks packing payroll acknowledgements');");
$s=new EmployeeAccountEditor($db,$db);$history=$db->query('SELECT * FROM history')->fetchAll(PDO::FETCH_ASSOC);
$load=$s->load(1,2);ok(!in_array('password_hash',$load['fields'],true)&&!isset($load['employee']['password_hash']),'credential fields never exposed');
denied(function()use($s){$s->load(2,1);},'employees cannot view management editor');
$b=['version'=>$load['version'],'role_id'=>3,'phone'=>'987654321','packing_assignable'=>0,'packing_auto_assignable'=>1];$s->save(1,2,$b);$e=$s->load(1,2)['employee'];ok((int)$e['role_id']===3&&$e['phone']==='987654321'&&(int)$e['packing_auto_assignable']===0,'role phone and coherent packing changes saved');
ok($db->query('SELECT password_hash FROM ops_employees WHERE id=2')->fetchColumn()==='unchanged'&&$db->query('SELECT * FROM history')->fetchAll(PDO::FETCH_ASSOC)===$history,'same ID credentials and history preserved');
ok(employee_account_has_edits($db,2),'manual edit protected against legacy reconciliation');
ok(employee_account_session_revoked($db,2,gmdate('c',time()-60))&&!employee_account_session_revoked($db,2,gmdate('c',time()+2)),'old sessions revoked and fresh logins allowed');
denied(function()use($s,$b){$s->save(1,2,$b);},'stale edits cannot overwrite changes');
$owner=$s->load(1,1);denied(function()use($s,$owner){$s->save(1,1,['version'=>$owner['version'],'status'=>'inactive']);},'last active Owner cannot be deactivated');
denied(function()use($s,$owner){$s->save(1,1,['version'=>$owner['version'],'role_id'=>2]);},'last active Owner cannot be demoted');
$load=$s->load(1,2);denied(function()use($s,$load){$s->save(1,2,['version'=>$load['version'],'email'=>'driver@example.test']);},'duplicate email rejected');
denied(function()use($s,$load){$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>10]);},'HR link change requires identity confirmation');
$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>10,'confirm_hr_link'=>1]);ok($s->load(1,2)['hr_health']['state']==='ready','existing HR profile linked without duplicate profile or user');
$load=$s->load(1,2);denied(function()use($s,$load){$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>11,'confirm_hr_link'=>1,'phone'=>'bad-partial']);},'wrong HR identity rejected atomically');
ok($s->load(1,2)['employee']['phone']==='987654321'&&$s->load(1,2)['employee']['hr_employee_id']===10,'failed HR correction rolls back personal and link changes');
$db->exec('UPDATE employee_user_links SET hr_employee_id=11 WHERE portal_user_id=2');$load=$s->load(1,2);$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>10,'confirm_hr_link'=>1]);ok($s->load(1,2)['hr_health']['state']==='ready','incorrect existing HR link can be corrected');
$db->exec("INSERT INTO employee_user_links(portal_user_id,hr_employee_id,active) VALUES(3,10,1)");$load=$s->load(1,2);$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>0,'confirm_hr_link'=>1]);$load=$s->load(1,2);denied(function()use($s,$load){$s->save(1,2,['version'=>$load['version'],'hr_employee_id'=>10,'confirm_hr_link'=>1]);},'HR profile cannot be assigned to two accounts');
$events=$db->query("SELECT metadata_json FROM ops_security_events WHERE event_type='employee_account_updated'")->fetchAll(PDO::FETCH_COLUMN);$audit=json_decode($events[0],true);ok(isset($audit['changes']['role_id']['previous'],$audit['changes']['role_id']['new'],$audit['performed_by'],$audit['changed_at_utc'])&&strpos(implode('',$events),'password')===false,'field-level audit has actor timestamp old/new and no credentials');
ok((int)$db->query('SELECT COUNT(*) FROM employees')->fetchColumn()===2&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'HR employee and login records unchanged');
echo "All employee editor tests passed.\n";
}finally{$db->exec('DROP DATABASE '.$name);}
