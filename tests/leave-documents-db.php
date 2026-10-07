<?php
declare(strict_types=1);
$dsn=getenv('LEAVE_DOCUMENT_TEST_DSN');
if ($dsn !== 'mysql:host=127.0.0.1;port=3339') {throw new RuntimeException('Tests require the isolated localhost fixture server on port 3339.');}
$server=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS leave_documents_test');
$db=new PDO($dsn.';dbname=leave_documents_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('DROP TABLE IF EXISTS leave_requests');$db->exec('DROP TABLE IF EXISTS users');
$db->exec('CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(20),employee_id INT,active TINYINT)');
$db->exec('CREATE TABLE leave_requests(id INT PRIMARY KEY,employee_id INT,certificate VARCHAR(255),status VARCHAR(20),leave_type VARCHAR(40),start_date DATE,end_date DATE,days DECIMAL(5,1),back_capture TINYINT,approved_by INT,approved_at DATETIME,reject_reason TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin',NULL,1),(10,'employee',1,1),(11,'employee',2,1),(12,'employee',1,0)");
$insert=$db->prepare("INSERT INTO leave_requests(id,employee_id,certificate,status,leave_type,start_date,end_date,days,back_capture) VALUES(?,?,?,'approved',?,'2026-09-18','2026-09-18',1,?)");
foreach([[1,1,'uploads/certificates/cert_1_example.pdf','Sick Leave',0],[2,2,'uploads/certificates/cert_2_example.png','Sick Leave',0],
    [3,1,null,'Sick Leave',0],[4,1,'uploads/certificates/missing.pdf','Sick Leave',0],
    [5,1,'uploads/certificates/legacy-note.pdf','Annual Leave',0],[6,1,null,'Unpaid Leave',1],
    [7,2,'uploads/certificates/cert_1_forged.pdf','Sick Leave',0],[8,1,'uploads/certificates/cert_1_spoof.pdf','Sick Leave',0]] as $row){$insert->execute($row);}
require_once __DIR__.'/../verification/http-fixture/apps/hr-portal/includes/leave-documents.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
$admin=['id'=>1,'role'=>'admin','emp_id'=>0];$one=['id'=>10,'role'=>'employee','emp_id'=>1];$two=['id'=>11,'role'=>'employee','emp_id'=>2];
check(hr_leave_document_state(null)['state']==='none','no reference means Not uploaded');
check(hr_leave_document_state('uploads/certificates/cert_1_example.pdf')['state']==='available','existing PDF is available');
check(hr_leave_document_state('uploads/certificates/cert_2_example.png')['state']==='available','existing image is available');
check(hr_leave_document_state('uploads/certificates/cert_1_example.jpg')['state']==='available','existing JPEG is available');
check(hr_leave_document_state('uploads/certificates/missing.pdf')['state']==='unavailable','missing physical file is unavailable');
check(hr_leave_document_state('uploads/certificates/cert_1_spoof.pdf')['state']==='unavailable','extension/content mismatch is unavailable');
check(strpos(hr_leave_document_markup(null),'href=')===false,'no reference never renders a broken link');
check(strpos(hr_leave_document_markup(null,true),'—')!==false,'back-captured leave without a document shows a dash');
check(strpos(hr_leave_document_markup('uploads/certificates/missing.pdf'),'href=')===false,'missing file never renders a broken link');
check(strpos(hr_leave_document_markup('uploads/certificates/legacy-note.pdf'),'rel="noopener"')!==false,'document opens securely in another tab');
foreach(['../config.php','../cert_1_example.pdf','uploads/certificates/cert_1_example.pdf','..\\cert_1_example.pdf','%2e%2e%2fconfig.pdf','a.php','a.svg','a.html',"a.pdf\r\nX: y",'a..pdf'] as $bad){check(hr_certificate_filename($bad)===null,'reject unsafe filename '.json_encode($bad));}
check(hr_certificate_authorized($db,$admin,'cert_1_example.pdf',true),'admin may view attached approved document');
check(hr_certificate_authorized($db,$one,'cert_1_example.pdf',false),'employee may view own document');
check(!hr_certificate_authorized($db,$one,'cert_2_example.png',false),'another employee document denied');
check(!hr_certificate_authorized($db,$one,'cert_1_forged.pdf',false),'filename employee prefix is never authorization');
check(hr_certificate_authorized($db,$two,'cert_1_forged.pdf',false),'actual leave record ownership is authoritative');
check(!hr_certificate_authorized($db,$admin,'unreferenced.pdf',true),'admin cannot serve arbitrary unreferenced upload');
check(!hr_certificate_authorized($db,$one,'cert_1_example.pdf',true),'employee cannot use admin viewer');
check(!hr_certificate_authorized($db,['id'=>12,'role'=>'employee','emp_id'=>1],'cert_1_example.pdf',false),'inactive user denied');
check(!hr_certificate_authorized($db,['id'=>10,'role'=>'admin','emp_id'=>1],'cert_1_example.pdf',true),'stale or forged role denied');
check(!hr_certificate_authorized($db,['id'=>10,'role'=>'employee','emp_id'=>2],'cert_2_example.png',false),'stale employee mapping denied');
$source=file_get_contents(__DIR__.'/../apps/hr-portal/leave.php');
preg_match('/\$update = \$db->prepare\("(UPDATE leave_requests SET status=\'approved\'.*?)"\);/', $source,$matches);
check(isset($matches[1]),'existing approval update located unchanged');
$db->exec("UPDATE leave_requests SET status='pending' WHERE id=1");
$before=$db->query('SELECT * FROM leave_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$db->prepare($matches[1])->execute([1,1]);
$after=$db->query('SELECT * FROM leave_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check($before['certificate']===$after['certificate'] && $after['status']==='approved','actual approval SQL retains certificate on same row');
check($before['id']===$after['id'] && $before['days']===$after['days'],'approval preserves request identity and leave days');
check(strpos(hr_leave_document_markup($after['certificate']),'View document')!==false,'approved document stays visible without re-upload');
