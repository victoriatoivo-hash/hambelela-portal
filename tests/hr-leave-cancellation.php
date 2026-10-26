<?php
require_once __DIR__.'/../apps/hr-portal/includes/leave-cancellation.php';
$db=new PDO(getenv('CW_TEST_DSN'),getenv('CW_TEST_USER')?:'root',getenv('CW_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// Temporary tables shadow any installed tables; nothing can touch live HR records.
$schemas=[
 'settings'=>'setting_key VARCHAR(100) PRIMARY KEY,setting_val TEXT',
 'leave_requests'=>"id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(50),start_date DATE,end_date DATE,days DECIMAL(5,1),status ENUM('pending','approved','rejected') DEFAULT 'pending',approved_by INT,approved_at DATETIME,certificate VARCHAR(255)",
 'leave_balances'=>'employee_id INT,leave_type VARCHAR(50),year INT,used_days DECIMAL(5,1),total_days DECIMAL(5,1)',
 'payroll_runs'=>'id INT PRIMARY KEY,period_year INT,period_month INT,status VARCHAR(20)',
 'audit_log'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,action VARCHAR(50),description TEXT,ip_address VARCHAR(50)',
 'users'=>'id INT PRIMARY KEY,employee_id INT,active INT,role VARCHAR(20)',
 'notifications'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,title TEXT,message TEXT,type VARCHAR(20),action_url TEXT'
];
foreach($schemas as $table=>$schema)$db->exec("CREATE TEMPORARY TABLE $table ($schema) ENGINE=InnoDB");
hrEnsureLeaveCancellationSchema($db);hrEnsureLeaveCancellationSchema($db);
$db->exec("INSERT INTO users VALUES(2,1,1,'employee')");
$db->exec("INSERT INTO leave_balances VALUES(1,'Unpaid Leave',2026,3,0),(1,'Annual Leave',2026,10,16)");
$q=$db->prepare('INSERT INTO leave_requests(id,employee_id,leave_type,start_date,end_date,days,status,approved_by,approved_at,certificate) VALUES(?,1,?,?,?,1,?,7,?,?)');
foreach([1=>'2026-10-12',2=>'2026-10-16',3=>'2026-11-02'] as $id=>$date){$q->execute([$id,'Unpaid Leave',$date,$date,'approved','2026-10-01 09:00:00','keep.pdf']);$q->execute([$id+3,'Annual Leave',$date,$date,'pending',null,null]);}
$q->execute([7,'Annual Leave','2026-11-04','2026-11-04','pending',null,null]);
$owner=['id'=>7,'role'=>'admin','portal_role_key'=>'owner_admin','portal_user_id'=>99];
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function denied(callable $action,$label){try{$action();}catch(DomainException $e){return;}throw new RuntimeException('Expected refusal: '.$label);}
denied(function()use($db,$owner){hrCancelApprovedLeave($db,array_merge($owner,['portal_role_key'=>'front']),1,'reason');},'non-owner');
denied(function()use($db,$owner){hrCancelApprovedLeave($db,$owner,1,' ');},'blank reason');
$db->exec("INSERT INTO settings VALUES('hr_leave_recovery_lock','1')");
denied(function()use($db,$owner){hrCancelApprovedLeave($db,$owner,1,'reason');},'recovery lock');$db->exec("UPDATE settings SET setting_val='0'");
foreach(['draft','finalised'] as $state){$db->exec("INSERT INTO payroll_runs VALUES(1,2026,10,'$state')");denied(function()use($db,$owner){hrCancelApprovedLeave($db,$owner,1,'reason');},'existing payroll');$db->exec('DELETE FROM payroll_runs');}
foreach([1,2,3] as $id)hrCancelApprovedLeave($db,$owner,$id,'Replaced by annual-leave request; payroll not run.');
check((int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='cancelled'")->fetchColumn()===3,'Only original approvals cancelled');
check((int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn()===4,'Replacements and 4 November untouched');
check((int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE approved_by=7 AND approved_at='2026-10-01 09:00:00' AND certificate='keep.pdf'")->fetchColumn()===3,'Original approval and evidence retained');
check((float)$db->query("SELECT used_days FROM leave_balances WHERE leave_type='Unpaid Leave'")->fetchColumn()===0.0,'Unpaid usage cleared');
check((float)$db->query("SELECT used_days FROM leave_balances WHERE leave_type='Annual Leave'")->fetchColumn()===10.0,'Annual balance untouched');
denied(function()use($db,$owner){hrCancelApprovedLeave($db,$owner,1,'retry');},'repeated cancellation');
denied(function()use($db,$owner){hrCancelApprovedLeave($db,$owner,4,'pending');},'pending request');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'One employee notification per cancellation');
check((int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===3,'One audit per cancellation');
$q->execute([8,'Unpaid Leave','2026-12-01','2026-12-01','approved','2026-10-01 09:00:00','keep.pdf']);
denied(function()use($db,$owner){hrDeleteCancelledLeave($db,array_merge($owner,['portal_role_key'=>'front']),1);},'non-owner deletion');
denied(function()use($db,$owner){hrDeleteCancelledLeave($db,$owner,4);},'pending deletion');
denied(function()use($db,$owner){hrDeleteCancelledLeave($db,$owner,8);},'approved deletion');
$db->exec("INSERT INTO payroll_runs VALUES(1,2026,10,'draft')");
denied(function()use($db,$owner){hrDeleteCancelledLeave($db,$owner,1);},'payroll deletion guard');
$db->exec('DELETE FROM payroll_runs');
foreach([1,2] as $id) hrDeleteCancelledLeave($db,$owner,$id);
check((int)$db->query('SELECT COUNT(*) FROM leave_requests WHERE id IN (1,2)')->fetchColumn()===0,'Selected cancelled records removed');
check((int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn()===4,'Deletion preserves all replacements');
check((float)$db->query("SELECT used_days FROM leave_balances WHERE leave_type='Annual Leave'")->fetchColumn()===10.0,'Deletion preserves annual balance');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='leave_cancelled'")->fetchColumn()===3,'Cancellation audits retained');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='cancelled_leave_deleted'")->fetchColumn()===2,'Deletion snapshots recorded');
denied(function()use($db,$owner){hrDeleteCancelledLeave($db,$owner,1);},'repeat deletion');
$db->exec('DROP TEMPORARY TABLE audit_log');
try{hrDeleteCancelledLeave($db,$owner,3);throw new RuntimeException('Expected delete audit failure');}catch(PDOException $e){}
check($db->query('SELECT status FROM leave_requests WHERE id=3')->fetchColumn()==='cancelled','Deletion rolls back if audit fails');
try{hrCancelApprovedLeave($db,$owner,8,'Audit failure test');throw new RuntimeException('Expected database failure');}catch(PDOException $e){}
check($db->query('SELECT status FROM leave_requests WHERE id=8')->fetchColumn()==='approved','Failure rolls back approval');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'Failure cannot notify');
echo "Leave cancellation: owner permissions, payroll guard, recovery lock, exact records, preserved evidence, unchanged replacements/balance, duplicate prevention and rollback PASS\n";
