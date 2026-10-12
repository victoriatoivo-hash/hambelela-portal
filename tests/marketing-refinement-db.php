<?php
declare(strict_types=1);
$dsn=getenv('MARKETING_TEST_DSN')?:'mysql:host=127.0.0.1;port=33316';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Loopback only');
$pdo=new PDO($dsn,'root',getenv('MARKETING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database='marketing_refinement_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$actor=2;$owner=false;
function db(){global $pdo;return $pdo;}
function marketing_is_owner(){global $owner;return $owner;}
function marketing_employee_id(){global $actor;return $actor;}
function current_user(){return ['id'=>marketing_employee_id(),'name'=>'Synthetic tester'];}
function marketing_statuses(){return ['brief','in_progress','approved','published','ready_for_review'];}
function marketing_notify(...$args){}
function notifications_current_employee_id(){return marketing_employee_id();}
function notifications_schema_ready(){return true;}
function ops_rows($sql,$params){$q=db()->prepare($sql);$q->execute($params);return $q->fetchAll();}
function marketing_column_exists($table,$column){$q=db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();}
function ok($value,$message){if(!$value)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function rejected($fn,$message){try{$fn();}catch(RuntimeException $e){ok(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
require __DIR__.'/../shared/marketing-execution.php';
// Exercise the actual notification functions without bootstrapping production config.
$source=file_get_contents(__DIR__.'/../shared/notifications.php');
foreach(['notifications_claim_task_delivery','notifications_marketing_popups','notifications_marketing_remind'] as $name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);eval(substr($source,$start,$end===false?null:$end-$start));}
try{
db()->exec("CREATE TABLE marketing_work_items(id INT PRIMARY KEY,title VARCHAR(190),assigned_employee_id INT,cancelled_at DATETIME NULL,status VARCHAR(30),approval_required INT,caption TEXT,published_at DATETIME NULL,content_type VARCHAR(30),platform VARCHAR(80),published_url VARCHAR(500),due_at DATETIME,priority VARCHAR(30));
INSERT INTO marketing_work_items VALUES(1,'Synthetic content',2,NULL,'approved',1,'Test',NULL,'social_post','',NULL,'2026-10-14 15:00:00','normal'),(2,'Other employee',3,NULL,'approved',1,'Other',NULL,'social_post','',NULL,NULL,'normal');
CREATE TABLE marketing_item_history(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,old_status VARCHAR(30),new_status VARCHAR(30),note TEXT,actor_id INT,actor_name VARCHAR(100));
CREATE TABLE marketing_item_versions(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT);
CREATE TABLE notifications(id INT PRIMARY KEY,title VARCHAR(190),message TEXT,created_at DATETIME,related_id INT,related_type VARCHAR(80),action_link VARCHAR(255),sound_key VARCHAR(30));
CREATE TABLE notification_recipients(notification_id INT,employee_id INT,delivered_at DATETIME NULL,read_at DATETIME NULL,cleared_at DATETIME NULL,next_reminder_at DATETIME NULL,PRIMARY KEY(notification_id,employee_id));
INSERT INTO notifications VALUES(1,'Assigned','Work',NOW(),1,'marketing_assignment','/apps/marketing/execution.php?id=1','assigned'),(2,'Other','Work',NOW(),2,'marketing_assignment','/apps/marketing/execution.php?id=2','assigned');
INSERT INTO notification_recipients(notification_id,employee_id) VALUES(1,2),(2,3);");
marketing_execution_schema_ready();$owner=true;
$keys=['instagram','facebook','tiktok','whatsapp'];
marketing_execution_requirements(1,$keys,array_combine($keys,['IG','FB','TT','WA']),['tiktok'=>'2026-10-14T14:00']);
$rows=marketing_execution_rows(marketing_execution_item(1));
ok(count($rows)===4&&array_column($rows,'instructions','channel_key')['tiktok']==='TT','four independent channel instructions including TikTok');
ok(array_column($rows,'scheduled_at','channel_key')['tiktok']==='2026-10-14 14:00:00','optional channel schedule retained in Namibia local time');
rejected(function(){marketing_execution_requirements(1,['tiktok'],[],['tiktok'=>'2026-02-31T14:00']);},'invalid calendar date rejected atomically');
ok(count(marketing_execution_rows(marketing_execution_item(1)))===4,'failed requirements save rolls back');
$owner=false;
marketing_execution_save(['id'=>1,'channels'=>['tiktok'=>['prepared'=>1,'completed'=>1,'proof_url'=>'https://example.test/proof','note'=>'Done']],'execution_status'=>'in_progress']);
ok(marketing_execution_progress(marketing_execution_rows(marketing_execution_item(1)))['complete']===1,'independent channel completion saved');
rejected(function(){marketing_execution_save(['id'=>1,'execution_status'=>'published']);},'employee cannot complete unfinished channels');
rejected(function(){marketing_execution_item(2);},'other employee work is inaccessible');
$owner=true;rejected(function(){marketing_execution_save(['id'=>1,'execution_status'=>'published']);},'Owner completion also requires all channels');
marketing_execution_requirements(1,['instagram'],['instagram'=>'IG']);
marketing_execution_requirements(1,$keys,array_combine($keys,['IG','FB','TT','WA']));
ok(array_column(marketing_execution_rows(marketing_execution_item(1)),'completed','channel_key')['tiktok']==1,'deselect and reselect preserves channel evidence');
$owner=false;
ok(count(notifications_marketing_popups())===1,'popup is scoped to assigned employee');
ok(!notifications_claim_task_delivery(2),'cannot claim another employee notification');
ok(notifications_claim_task_delivery(1)&&!notifications_claim_task_delivery(1),'notification claim prevents duplicate popups and sounds across tabs');
ok(notifications_marketing_remind(1)&&count(notifications_marketing_popups())===0,'Remind Me Later snoozes the existing recipient record');
db()->exec('UPDATE notification_recipients SET next_reminder_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE notification_id=1');
ok(count(notifications_marketing_popups())===1,'snoozed assignment returns without new notification rows');
db()->exec('UPDATE marketing_work_items SET assigned_employee_id=3 WHERE id=1');
ok(count(notifications_marketing_popups())===0&&!notifications_claim_task_delivery(1),'reassignment revokes former employee popup eligibility');
ok((int)db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,'no duplicate notification records');
}finally{db()->exec('DROP DATABASE '.$database);}
