<?php
declare(strict_types=1);
$server=new PDO('mysql:host=127.0.0.1;port=3339','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['hr_policy_test','hr_policy_portal_test'] as $name){$server->exec('DROP DATABASE IF EXISTS '.$name);$server->exec('CREATE DATABASE '.$name);}
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function hrColumnExists(PDO $db,string $table,string $column):bool{$s=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return(bool)$s->fetchColumn();}
require __DIR__.'/../apps/hr-portal/includes/policy-system.php';
hrPolicyEnsureSchema($db);
$db->exec("CREATE TABLE employees(id INT PRIMARY KEY,emp_number VARCHAR(20),first_name VARCHAR(50),last_name VARCHAR(50),email VARCHAR(100),status VARCHAR(20))");
$db->exec("CREATE TABLE users(id INT PRIMARY KEY,name VARCHAR(100),email VARCHAR(100),role VARCHAR(20),employee_id INT,active TINYINT)");
$db->exec("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,title VARCHAR(190),message TEXT,type VARCHAR(20),action_url VARCHAR(190))");
$db->exec("INSERT INTO employees VALUES(1,'TEST-1','Existing','Employee','old@example.test','active'),(2,'TEST-2','New','Employee','new@example.test','active'),(3,'TEST-3','Inactive','Employee','inactive@example.test','inactive'),(4,'TEST-4','Disabled','Account','disabled@example.test','active')");
$db->exec("INSERT INTO users VALUES(1,'Test Admin','admin@example.test','admin',NULL,1),(11,'Existing Employee','old@example.test','employee',1,1),(12,'New Employee','new@example.test','employee',2,1),(13,'Inactive Employee','inactive@example.test','employee',3,1),(14,'Disabled Account','disabled@example.test','employee',4,0)");
$db->exec("INSERT INTO hr_policies(id,title,policy_type,current_version_id,status,created_by) VALUES(1,'Existing Handbook','mandatory_policy',1,'published',1)");
$sql="INSERT INTO hr_policy_versions(id,policy_id,version_number,title,created_date,effective_date,acknowledgement_deadline,file_path,original_filename,mime_type,file_size,document_hash,digital_html,digital_hash,acknowledgement_required,status,created_by,published_at) VALUES(?,1,?,'Existing Handbook','2026-08-11','2026-09-01','2026-08-31','fixture.docx','fixture.docx','application/zip',1,?,?,?,1,?,1,NOW())";
$s=$db->prepare($sql);
foreach([[1,'1.0','published'],[2,'0.9','superseded'],[3,'2.0','draft']] as $v){$s->execute([$v[0],$v[1],str_repeat('a',64),'<h2 id="policy-section-1">Existing policy</h2><p>Read the existing policy carefully.</p>',str_repeat('b',64),$v[2]]);}
$db->exec("INSERT INTO hr_policy_acknowledgements(policy_id,version_id,employee_id,user_id,legal_name,opened_at,reached_end_at,signed_at,acknowledged_at,signature_data,document_hash,acknowledgement_reference,status) VALUES(1,1,1,11,'Existing Employee',NOW(),NOW(),NOW(),NOW(),'Existing Employee',REPEAT('a',64),'EXISTING-RECEIPT','signed')");
$db->exec("INSERT INTO hr_policy_assignments(policy_id,version_id,employee_id,user_id,status) VALUES(1,1,1,11,'acknowledged')");
$before=$db->query('SELECT * FROM hr_policy_acknowledgements')->fetchAll();$versions=$db->query('SELECT * FROM hr_policy_versions')->fetchAll();
file_put_contents(__DIR__.'/../verification/synthetic-before.json',json_encode(['signed'=>$before,'versions'=>$versions]));
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
$result=hrPolicyAssignCurrent($db);
check($result===['assigned'=>1,'notified'=>1],'new active employee gets one current assignment and notification');
check(hrPolicyAssignCurrent($db)===['assigned'=>0,'notified'=>0],'repeat assignment makes no duplicates');
check((int)$db->query('SELECT COUNT(*) FROM hr_policy_notifications')->fetchColumn()===1,'exactly one policy notification');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'exactly one HR inbox notification');
check($before===$db->query('SELECT * FROM hr_policy_acknowledgements')->fetchAll(),'existing signatures and receipts unchanged');
check($versions===$db->query('SELECT * FROM hr_policy_versions')->fetchAll(),'no policy or version changes');
check((int)$db->query('SELECT COUNT(*) FROM hr_policy_assignments WHERE version_id<>1')->fetchColumn()===0,'draft and superseded policies not assigned');
check((int)$db->query('SELECT COUNT(*) FROM hr_policy_assignments WHERE employee_id IN (3,4)')->fetchColumn()===0,'inactive profiles and accounts excluded');
check(hrPolicyPopupForUser($db,12)['version_id']===1,'existing HR popup sees newly assigned current policy');
check(hrPolicyPopupForUser($db,11)===null,'signed employee gets no mandatory popup');
check(hrPolicyBridgeReturn('policy-view.php?id=1')==='policy-view.php?id=1','exact policy deep link accepted');
foreach(['https://evil.test','//evil.test','policy-view.php?id=1&preview=employee','policy-view.php?id=0','../policy-view.php?id=1','policy-view.php?id=1%0d%0aX:y'] as $url){check(hrPolicyBridgeReturn($url)==='','unsafe bridge return rejected');}
$portal=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_portal_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$portal->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(30));INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'marketing_sales');CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100),email VARCHAR(100),status VARCHAR(20),role_id INT);INSERT INTO ops_employees VALUES(1,'Test Admin','admin@example.test','active',1),(2,'New Employee','new@example.test','active',2);CREATE TABLE employee_user_links(portal_user_id INT PRIMARY KEY,hr_employee_id INT,active TINYINT);INSERT INTO employee_user_links VALUES(2,2,1)");
echo 'Prepared isolated HTTP fixture accounts and current existing policy' . "\n";
