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
foreach(['notifications_claim_task_delivery','notifications_marketing_popups','notifications_marketing_remind','notifications_mark_task_state','notifications_marketing_seen'] as $name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);eval(substr($source,$start,$end===false?null:$end-$start));}
try{
db()->exec("CREATE TABLE marketing_work_items(id INT PRIMARY KEY,title VARCHAR(190),assigned_employee_id INT,cancelled_at DATETIME NULL,status VARCHAR(30),approval_required INT,caption TEXT,published_at DATETIME NULL,content_type VARCHAR(30),platform VARCHAR(80),published_url VARCHAR(500),due_at DATETIME,priority VARCHAR(30));
INSERT INTO marketing_work_items VALUES(1,'Synthetic content',2,NULL,'approved',1,'Test',NULL,'social_post','',NULL,'2026-10-14 15:00:00','normal'),(2,'Other employee',3,NULL,'approved',1,'Other',NULL,'social_post','',NULL,NULL,'normal');
CREATE TABLE marketing_item_history(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,old_status VARCHAR(30),new_status VARCHAR(30),note TEXT,actor_id INT,actor_name VARCHAR(100));
CREATE TABLE marketing_item_versions(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT);
CREATE TABLE notifications(id INT PRIMARY KEY,title VARCHAR(190),message TEXT,created_at DATETIME,related_id INT,related_type VARCHAR(80),action_link VARCHAR(255),sound_key VARCHAR(30));
CREATE TABLE notification_recipients(notification_id INT,employee_id INT,delivered_at DATETIME NULL,read_at DATETIME NULL,cleared_at DATETIME NULL,popup_seen_at DATETIME NULL,next_reminder_at DATETIME NULL,PRIMARY KEY(notification_id,employee_id));
INSERT INTO notifications VALUES(1,'Assigned','Work',NOW(),1,'marketing_assignment','/apps/marketing/execution.php?id=1','assigned'),(2,'Other','Work',NOW(),2,'marketing_assignment','/apps/marketing/execution.php?id=2','assigned');
INSERT INTO notification_recipients(notification_id,employee_id) VALUES(1,2),(2,3);");
marketing_execution_schema_ready();$owner=true;
db()->exec('ALTER TABLE marketing_work_items ADD created_by INT DEFAULT 1');
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
ok(notifications_claim_task_delivery(1)&&!notifications_claim_task_delivery(1),'notification claim prevents repeated sounds across tabs');
ok(count(notifications_marketing_popups())===1,'delivered but unacknowledged assignment survives refresh');
$beforeAck=marketing_execution_item(1);
ok(notifications_marketing_seen(1),'employee acknowledges own assignment through actual notification state function');
ok(notifications_marketing_seen(1),'retry or second-tab acknowledgement succeeds idempotently');
ok(!notifications_marketing_seen(2),'cannot acknowledge another employee notification');
ok(marketing_execution_item(1)===$beforeAck,'acknowledgement preserves every assignment field');
ok(count(notifications_marketing_popups())===0,'acknowledgement stops popup without changing assignment');
ok(db()->query('SELECT read_at FROM notification_recipients WHERE notification_id=1')->fetchColumn()===null,'popup acknowledgement preserves separate unread status');
$actor=3;ok(count(notifications_marketing_popups())===1,'another employee still receives new assignment');$actor=2;
ok(count(notifications_marketing_popups())===0,'fresh employee context retains server acknowledgement');
db()->exec('UPDATE notification_recipients SET read_at=NULL,popup_seen_at=NULL WHERE notification_id=1 AND employee_id=2');
ok(notifications_marketing_remind(1)&&count(notifications_marketing_popups())===0,'Remind Me Later snoozes the existing recipient record');
db()->exec('UPDATE notification_recipients SET next_reminder_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE notification_id=1');
ok(count(notifications_marketing_popups())===1,'snoozed assignment returns without new notification rows');
db()->exec('UPDATE marketing_work_items SET assigned_employee_id=3 WHERE id=1');
ok(count(notifications_marketing_popups())===0&&!notifications_claim_task_delivery(1),'reassignment revokes former employee popup eligibility');
ok((int)db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,'no duplicate notification records');
$owner=true;$all=[];foreach($keys as$key)$all[$key]=['completed'=>1];
db()->exec("UPDATE marketing_work_items SET status='approved' WHERE id=1");
marketing_execution_save(['id'=>1,'channels'=>$all,'execution_status'=>'published']);
ok(marketing_execution_item(1)['status']==='published','completion succeeds after every required channel is complete');
marketing_execution_requirements(1,array_merge($keys,['website']),[]);
ok(marketing_execution_item(1)['status']==='in_progress','adding unfinished requirements reopens completed work');
db()->exec("ALTER TABLE notifications MODIFY id INT AUTO_INCREMENT, ADD module VARCHAR(60),ADD priority VARCHAR(20),ADD deadline_state VARCHAR(30),ADD scheduled_at DATETIME,ADD deduplication_key VARCHAR(190) UNIQUE,ADD created_by INT");
$start=strpos($source,'function notifications_create(');$end=strpos($source,"\nfunction ",$start+1);eval(substr($source,$start,$end-$start));
define('BASE_URL','');
$marketingSource=file_get_contents(__DIR__.'/../shared/marketing.php');$start=strpos($marketingSource,'function marketing_notify_assignment(');$end=strpos($marketingSource,"\nfunction ",$start+1);
$function=str_replace("require_once BASE_PATH.'/shared/notifications.php';",'/* dependencies isolated above */',substr($marketingSource,$start,$end-$start));eval($function);
marketing_notify_assignment(1,3,'Synthetic assignment');marketing_notify_assignment(1,3,'Synthetic assignment');
ok((int)db()->query("SELECT COUNT(*) FROM notifications WHERE related_type='marketing_assignment' AND deduplication_key IS NOT NULL")->fetchColumn()===1,'assignment event retries reuse one notification');


$owner=true;
$allKeys=array_keys(marketing_execution_channels());
$beforeProof=db()->query('SELECT channel_key,prepared,completed,proof_url,completion_note FROM marketing_channel_execution WHERE item_id=1 ORDER BY channel_key')->fetchAll();
marketing_execution_requirements(1,$allKeys,[],array_fill_keys($allKeys,'2026-11-01T10:00'),array_fill_keys($allKeys,'now'));
$nowRows=marketing_execution_rows(marketing_execution_item(1));
ok(count($nowRows)===8 && count(array_filter($nowRows,fn($r)=>$r['publish_timing']==='now' && $r['scheduled_at']===null))===8,'Publish now persists for all eight channels and clears stale schedules');
$afterProof=db()->query('SELECT channel_key,prepared,completed,proof_url,completion_note FROM marketing_channel_execution WHERE item_id=1 ORDER BY channel_key')->fetchAll();
foreach($beforeProof as $proof)ok(in_array($proof,$afterProof,true),'publishing intent preserves existing completion evidence for '.$proof['channel_key']);
marketing_execution_requirements(1,$allKeys,[],['instagram'=>'2026-11-01T10:00'],['instagram'=>'later']);
$byKey=array_column(marketing_execution_rows(marketing_execution_item(1)),null,'channel_key');
ok($byKey['instagram']['scheduled_at']==='2026-11-01 10:00:00' && $byKey['instagram']['publish_timing']==='later','Schedule for later persists Namibia date and time');
rejected(fn()=>marketing_execution_requirements(1,$allKeys,[],[],['instagram'=>'later']),'later requires a date on the server');
rejected(fn()=>marketing_execution_requirements(1,$allKeys,[],[],['instagram'=>'invalid']),'server rejects invalid publishing intent');
$byKey=array_column(marketing_execution_rows(marketing_execution_item(1)),null,'channel_key');
ok($byKey['instagram']['scheduled_at']==='2026-11-01 10:00:00','invalid changes roll back without losing schedule');
marketing_execution_requirements(1,$allKeys,[],[],array_fill_keys($allKeys,'now'));
db()->exec("UPDATE marketing_work_items SET status='brief',approval_required=1 WHERE id=1");
$owner=false;$actor=3;
rejected(fn()=>marketing_execution_save(['id'=>1,'channels'=>['instagram'=>['completed'=>1]]]),'Publish now does not bypass Owner approval');
ok(marketing_execution_item(1)['status']==='brief','Publish now does not falsely mark content published');

}finally{db()->exec('DROP DATABASE '.$database);}
