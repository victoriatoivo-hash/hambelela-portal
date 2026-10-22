<?php
declare(strict_types=1);
require __DIR__.'/../apps/hr-portal/includes/employment-letter.php';
function checkLetter($condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function emailDocumentUploaded($email,$name,$title){$GLOBALS['letter_test_emails'][]=$email;return false;}
$dsn=getenv('CW_TEST_DSN')?:'';
if(strpos($dsn,'dbname=cw_supplier_test')===false || strpos($dsn,'host=127.0.0.1')===false)throw new RuntimeException('Disposable CI database required.');
$db=new PDO(str_replace('dbname=cw_supplier_test','dbname=mysql',$dsn),getenv('CW_TEST_USER')?:'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE DATABASE IF NOT EXISTS cw_hr_letters_test CHARACTER SET utf8mb4');$db->exec('USE cw_hr_letters_test');
$db->exec("CREATE TABLE employees(id INT UNSIGNED PRIMARY KEY,emp_number VARCHAR(30),first_name VARCHAR(50),last_name VARCHAR(50),email VARCHAR(100),job_title VARCHAR(60),id_number VARCHAR(30),start_date DATE,status VARCHAR(12)) ENGINE=InnoDB");
$db->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,employee_id INT UNSIGNED,email VARCHAR(100),name VARCHAR(100)) ENGINE=InnoDB");
$db->exec("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,title VARCHAR(200),message TEXT,type VARCHAR(20)) ENGINE=InnoDB");
$db->exec("INSERT INTO employees VALUES(1,'TEST-001','Fixture','Employee','fixture@example.test','Fixture role','TEST','2026-01-01','active'),(2,'TEST-002','Inactive','Fixture','inactive@example.test','Fixture role','TEST','2026-01-01','inactive')");
$db->exec("INSERT INTO users VALUES(11,1,'fixture@example.test','Fixture Employee')");
// Reproduce the live legacy schema without responsibilities, retaining a prior letter.
$db->exec("CREATE TABLE employment_letters(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_id INT UNSIGNED NOT NULL,letter_no VARCHAR(60) UNIQUE,issued_date DATE,title VARCHAR(200),body_html MEDIUMTEXT,status ENUM('draft','published') DEFAULT 'draft',download_count TINYINT UNSIGNED DEFAULT 0,download_limit TINYINT UNSIGNED DEFAULT 2,created_by INT UNSIGNED,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,published_at TIMESTAMP NULL,FOREIGN KEY(employee_id) REFERENCES employees(id)) ENGINE=InnoDB");
$db->exec("INSERT INTO employment_letters(employee_id,letter_no,issued_date,title,body_html,status) VALUES(1,'EXISTING-LETTER','2026-01-01','Existing','Preserve this letter','published')");
ensureEmploymentLetterTable($db);ensureEmploymentLetterTable($db);
checkLetter($db->query("SELECT body_html FROM employment_letters WHERE letter_no='EXISTING-LETTER'")->fetchColumn()==='Preserve this letter','Existing letter changed');
$input=['letter_employee'=>1,'issued_date'=>'2026-10-22','letter_responsibilities'=>'Pack orders & maintain inventory'];
$draft=createEmploymentLetter($db,$input,11,str_repeat('a',64));
checkLetter(!$draft['warning'] && !$draft['duplicate'],'Draft creation failed');
checkLetter((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===0,'Draft notified employee');
$repeat=createEmploymentLetter($db,$input,11,str_repeat('a',64));checkLetter($repeat['duplicate'] && $repeat['id']===$draft['id'],'Repeated request created duplicate');
$published=publishEmploymentLetter($db,$draft['id']);checkLetter($published['warning'],'Failed mail not reported');
checkLetter((int)$db->query('SELECT user_id FROM notifications LIMIT 1')->fetchColumn()===11,'Wrong notification recipient');
publishEmploymentLetter($db,$draft['id']);checkLetter((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'Repeated publish duplicated notification');
checkLetter(count($GLOBALS['letter_test_emails'])===1,'Repeated publish duplicated email');
$immediate=createEmploymentLetter($db,$input+['publish_now'=>1],11,str_repeat('b',64));checkLetter($immediate['warning'],'Immediate creation did not survive email failure');
createEmploymentLetter($db,$input+['publish_now'=>1],11,str_repeat('b',64));
checkLetter((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,'Immediate retry duplicated notification');
$failed=createEmploymentLetter($db,$input+['publish_now'=>1],11,str_repeat('c',64),function(){throw new RuntimeException('simulated delivery failure');});checkLetter($failed['id']>0 && $failed['warning'],'Notification exception broke saved result');
foreach([['letter_employee'=>999,'issued_date'=>'2026-10-22'],['letter_employee'=>2,'issued_date'=>'2026-10-22'],['letter_employee'=>1,'issued_date'=>'2026-02-30']] as $invalid){try{createEmploymentLetter($db,$invalid,11,str_repeat('d',64));throw new RuntimeException('Invalid input accepted');}catch(DomainException $e){}}
$q=$db->query('SELECT el.*,e.first_name,e.last_name,e.job_title,e.id_number,e.start_date,e.emp_number FROM employment_letters el JOIN employees e ON e.id=el.employee_id WHERE el.id='.(int)$draft['id']);$letter=$q->fetch();
checkLetter(strpos(renderEmploymentLetterHtml($db,$letter),'Fixture Employee')!==false,'Preview generation failed');
$pdf=renderEmploymentLetterPdf($db,$letter);checkLetter(strpos($pdf,'%PDF-1.4')===0 && substr($pdf,-5)==='%%EOF','PDF generation failed');
checkLetter((int)$db->query("SELECT COUNT(*) FROM employment_letters WHERE letter_no='EXISTING-LETTER'")->fetchColumn()===1,'Existing letter lost');
echo "Employment letters: legacy migration, draft/publish, correct recipient, email failure isolation, retries, validation, preview/PDF and preservation passed.\n";
