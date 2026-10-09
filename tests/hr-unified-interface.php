<?php
// Exercise the production dashboard against disposable data, without migrations.
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir().'/hr-interface-'.bin2hex(random_bytes(6));
mkdir($fixture.'/includes',0700,true);
copy($root.'/apps/hr-portal/dashboard.php',$fixture.'/dashboard.php');
copy($root.'/apps/hr-portal/includes/sidebar.php',$fixture.'/includes/sidebar.php');
copy($root.'/apps/hr-portal/includes/styles.css',$fixture.'/includes/styles.css');
copy($root.'/apps/hr-portal/includes/hr-responsive.js',$fixture.'/includes/hr-responsive.js');
file_put_contents($fixture.'/config.php','<?php function requireLogin(){} function currentUser(){return ["id"=>1,"name"=>"Test Owner","role"=>"admin","emp_id"=>0];} function db(){return $GLOBALS["testDb"];}');
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$testDb->exec("CREATE TABLE employees(id INTEGER,status TEXT);
CREATE TABLE leave_requests(status TEXT); CREATE TABLE overtime(status TEXT);
CREATE TABLE hr_policy_versions(id INTEGER,status TEXT,acknowledgement_required INTEGER);
CREATE TABLE hr_policy_assignments(employee_id INTEGER,version_id INTEGER);
CREATE TABLE hr_policy_acknowledgements(employee_id INTEGER,version_id INTEGER,signed_at TEXT);
INSERT INTO employees VALUES(1,'active'),(2,'active'),(3,'inactive');
INSERT INTO leave_requests VALUES('pending'),('pending'),('approved');
INSERT INTO overtime VALUES('pending'),('approved');
INSERT INTO hr_policy_versions VALUES(1,'published',1),(2,'superseded',1),(3,'published',0);
INSERT INTO hr_policy_assignments VALUES(1,1),(2,1),(3,1),(1,2),(1,3);
INSERT INTO hr_policy_acknowledgements VALUES(2,1,'2026-10-01');");
$before=$testDb->query('SELECT * FROM hr_policy_acknowledgements')->fetchAll();
$_SERVER['PHP_SELF']='/apps/hr-portal/dashboard.php';
$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF'];
ob_start(); require $fixture.'/dashboard.php'; $html=ob_get_clean();
function check($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
check(strpos($html,'HR Portal is Live!')===false && strpos($html,'Stage 2')===false,'development message removed');
check(strpos($html,'2 pending leave requests')!==false && strpos($html,'1 pending overtime requests')!==false,'real request totals');
check(strpos($html,'1 outstanding policy acknowledgements')!==false,'only unsigned active mandatory current assignments counted');
check($before===$testDb->query('SELECT * FROM hr_policy_acknowledgements')->fetchAll(),'signed acknowledgement unchanged');
preg_match_all('/class=\'nav-item[^\']*\'.*?<\/a>/',$html,$matches);
$expected=['Back to Portal','Dashboard','Employees','Leave Management','Leave Calendar','Overtime','Payroll &amp; Payslips','Medical Aid','Documents','Company Policies','Loans','Settings'];
check(count($matches[0])===count($expected),'original admin navigation count');
foreach($expected as $i=>$label)check(strpos($matches[0][$i],$label)!==false,'navigation: '.html_entity_decode($label));
check(strpos($html,'Build ')===false,'old build footer removed');
foreach(glob($fixture.'/includes/*') as $path)unlink($path);rmdir($fixture.'/includes');unlink($fixture.'/dashboard.php');unlink($fixture.'/config.php');rmdir($fixture);
