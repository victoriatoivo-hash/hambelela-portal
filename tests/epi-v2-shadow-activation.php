<?php
// Included by the disposable database harness. Never connects to production.
require_once dirname(__DIR__).'/shared/epi/ShadowActivation.php';
$shadowName='epi_p0_audit_'.bin2hex(random_bytes(6));$shadow=connectAudit();$shadow->exec("CREATE DATABASE `$shadowName`");$shadow->exec("USE `$shadowName`");
$shadow->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80));INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'front_desk_admin');
CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100),role_id INT,status VARCHAR(20));INSERT INTO ops_employees VALUES(1,'Owner',1,'active'),(2,'Front',2,'active');
CREATE TABLE epi_employee_performance_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(30),description TEXT,updated_by INT);
INSERT INTO epi_employee_performance_settings VALUES('weekday_open','08:00','time','',NULL),('weekday_close','17:00','time','',NULL),('saturday_open','09:00','time','',NULL),('saturday_close','13:00','time','',NULL),('task_response_minutes','{\"urgent\":30,\"important\":90,\"normal\":240}','json','',NULL);
CREATE TABLE epi_employee_business_calendar(business_date DATE PRIMARY KEY,is_working_day INT,opens_at TIME,closes_at TIME);
CREATE TABLE official_score(id INT PRIMARY KEY,value INT);INSERT INTO official_score VALUES(1,87);
CREATE TABLE ops_checklist_tasks(id INT PRIMARY KEY,assigned_employee_id INT,created_by INT,priority VARCHAR(20),created_at DATETIME,date_assigned DATETIME,deadline DATETIME,released_at DATETIME,scheduled_at DATETIME);");
$plan=\Hambelela\EPI\ShadowActivation::inspect($shadow);
check('S01 saved priority deadlines preserved',['urgent'=>30,'important'=>90,'normal'=>240],$plan['policy']['task_start_by_priority']);
check('S02 undefined order SLA disabled',[],$plan['policy']['orders']);
$shadow->exec("INSERT INTO ops_employees VALUES(3,'Other Front',2,'active')");
check('S03 ambiguous Front Desk fails closed',false,attempt(function()use($shadow){\Hambelela\EPI\ShadowActivation::inspect($shadow);}));
$shadow->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");
\Hambelela\EPI\ShadowActivation::migrateAndActivate($shadow,file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql'),'synthetic-release');
check('S04 official score unchanged',87,(int)$shadow->query('SELECT value FROM official_score')->fetchColumn());
check('S05 activation shadow only','shadow',\Hambelela\EPI\V2Store::activation($shadow)['mode']);
check('S06 no fabricated employee duty',0,(int)$shadow->query('SELECT COUNT(*) FROM epi_v2_duty_periods')->fetchColumn());
$now=\Hambelela\EPI\Support::timestamp();$at=$now->format('Y-m-d H:i:s');
$shadow->prepare("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,priority,created_at,date_assigned) VALUES(1,2,1,'important',?,?)")->execute([$at,$at]);
\Hambelela\EPI\V2OperationalBridge::record($shadow,'checklist_task','task_created',1,['occurred_at'=>$at,'employee_id'=>1]);
$due=$shadow->query('SELECT due_at FROM epi_v2_operational_deadlines')->fetchColumn();
check('S07 important task uses ninety business minutes',(new \Hambelela\EPI\BusinessTimeEngine($shadow))->addWorkingMinutes($now,90)->format('Y-m-d H:i:s'),$due);
check('S08 healthy first watchdog','success',\Hambelela\EPI\V2Watchdog::run($shadow)['status']);
check('S09 immutable activation cannot be replayed',false,attempt(function()use($shadow){\Hambelela\EPI\ShadowActivation::migrateAndActivate($shadow,file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql'),'replay');}));
