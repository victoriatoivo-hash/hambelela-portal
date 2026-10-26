<?php
declare(strict_types=1);
// Disposable CI database only. Exercise the deployed SQL and existing note transaction.
$dsn=getenv('CW_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=cw_supplier_test(?:;charset=utf8mb4)?$/D',$dsn))throw new RuntimeException('Disposable loopback database required');
$pdo=new PDO($dsn,getenv('CW_TEST_USER')?:'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database='marketing_notes_test_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$fixture=sys_get_temp_dir().'/'.$database;mkdir($fixture.'/shared/epi',0700,true);
file_put_contents($fixture.'/shared/notifications.php','<?php // Test loads the real functions below.');
file_put_contents($fixture.'/shared/epi/bootstrap.php','<?php // No performance side effects in synthetic tests.');
define('BASE_PATH',$fixture);define('BASE_URL','');$actor=2;
function db(){return $GLOBALS['pdo'];}
function notifications_current_employee_id(){return $GLOBALS['actor'];}
function notifications_schema_ready(){return true;}
function notifications_recipient_accepts($id,$module){return false;} // Required notes bypass preferences.
function marketing_employee_id(){return $GLOBALS['actor'];}
function marketing_is_owner(){return in_array($GLOBALS['actor'],[99,98],true);}
function current_user(){return ['name'=>marketing_is_owner()?'Synthetic Owner':'Synthetic Employee'];}
function marketing_now(){return '2026-10-26 16:00:00';}
function marketing_work_evidence(...$args){}
function ops_rows($sql,$params=[]){$q=db()->prepare($sql);$q->execute($params);return $q->fetchAll();}
function marketing_execution_item($id,$lock=false){$rows=ops_rows('SELECT * FROM marketing_work_items WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);$row=$rows[0]??null;if(!$row||(!marketing_is_owner()&&(int)$row['assigned_employee_id']!==marketing_employee_id()))throw new RuntimeException('Forbidden');return $row;}
function load_functions($file,$names){$source=file_get_contents($file);foreach($names as $name){$start=strpos($source,'function '.$name.'(');if($start===false)throw new RuntimeException('Missing '.$name);$end=strpos($source,"\nfunction ",$start+1);$code=substr($source,$start,$end===false?null:$end-$start);eval(str_replace('__DIR__',var_export(BASE_PATH.'/shared',true),$code));}}
function ok($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
function rejected($fn,$message){try{$fn();}catch(Throwable $e){ok(true,$message);return;}throw new RuntimeException('Unexpected success: '.$message);}
function notice_id($note){return(int)ops_rows('SELECT id FROM notifications WHERE deduplication_key=?',['marketing-note:'.$note])[0]['id'];}
function popup_ids(){return array_map('intval',array_column(notifications_marketing_popups(),'id'));}
$root=dirname(__DIR__);
load_functions($root.'/shared/notifications.php',['notifications_role_recipients','notifications_create','notifications_marketing_popup_scope','notifications_marketing_popups','notifications_marketing_seen','notifications_claim_task_delivery']);
load_functions($root.'/shared/marketing-workspace.php',['marketing_activity','marketing_note_send']);
try{
 $pdo->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(50));INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'marketing_sales');
 CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100),role_id INT,status VARCHAR(30));INSERT INTO ops_employees VALUES(99,'Owner',1,'active'),(98,'Inactive Owner',1,'inactive'),(2,'Employee',2,'active'),(3,'Other employee',2,'active');
 CREATE TABLE marketing_work_items(id INT PRIMARY KEY,title VARCHAR(190),assigned_employee_id INT,status VARCHAR(40),cancelled_at DATETIME NULL,due_at DATETIME,priority VARCHAR(30),review_submitted_at DATETIME NULL,caption TEXT);
 INSERT INTO marketing_work_items VALUES(10,'Synthetic assignment',2,'in_progress',NULL,'2026-10-30 17:00:00','normal',NULL,'');
 CREATE TABLE marketing_channel_execution(item_id INT,channel_key VARCHAR(50),prepared TINYINT,is_required TINYINT);
 CREATE TABLE marketing_notes(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,actor_id INT,actor_name VARCHAR(190),is_owner TINYINT,body TEXT,request_key VARCHAR(64),created_at DATETIME,UNIQUE(item_id,actor_id,request_key));
 CREATE TABLE marketing_item_history(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,old_status VARCHAR(40),new_status VARCHAR(40),note TEXT,actor_id INT,actor_name VARCHAR(190));
 CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190),message TEXT,module VARCHAR(50),related_id INT,related_type VARCHAR(60),priority VARCHAR(30),deadline_state VARCHAR(30),sound_key VARCHAR(30),scheduled_at DATETIME NULL,deduplication_key VARCHAR(190) UNIQUE,action_link VARCHAR(500),created_by INT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE notification_recipients(notification_id INT,employee_id INT,read_at DATETIME NULL,cleared_at DATETIME NULL,popup_seen_at DATETIME NULL,delivered_at DATETIME NULL,next_reminder_at DATETIME NULL,PRIMARY KEY(notification_id,employee_id));");
 $original=marketing_execution_item(10);
 $note=marketing_note_send(10,'[Assistance request] Please check this draft',str_repeat('a',32));$id=notice_id($note);
 ok(marketing_note_send(10,'[Assistance request] Please check this draft',str_repeat('a',32))===0,'Repeated submit creates no duplicate note');
 ok(count(ops_rows('SELECT * FROM notifications'))===1,'Repeated submit creates no duplicate notification');
 ok(array_column(ops_rows('SELECT employee_id FROM notification_recipients'),'employee_id')===[99],'Assistance goes only to active Owner');
 $actor=99;$popups=notifications_marketing_popups();ok(count($popups)===1&&$popups[0]['note_body']==='[Assistance request] Please check this draft'&&$popups[0]['note_actor_name']==='Synthetic Employee','Owner receives actual assistance body and sender');
 ok(notifications_claim_task_delivery($id)&&!notifications_claim_task_delivery($id),'Delivery claim prevents concurrent-tab duplicate popup');
 ok(notifications_marketing_seen($id)&&notifications_marketing_seen($id),'Got It/dismiss persists and retries idempotently');
 ok(popup_ids()===[],'Refresh does not reopen acknowledged message');
 $actor=0;ok(popup_ids()===[],'Signed-out session has no popups');$actor=99;ok(popup_ids()===[],'New login stays dismissed using server state');
 $recipient=ops_rows('SELECT * FROM notification_recipients WHERE notification_id=?',[$id])[0];ok($recipient['read_at']===null&&$recipient['cleared_at']===null,'Popup dismissal preserves separate notification history/read state');
 $reply=marketing_note_send(10,'Please update the draft before scheduling.',str_repeat('b',32));$replyId=notice_id($reply);
 ok(popup_ids()===[],'Owner does not receive own reply');$actor=3;ok(popup_ids()===[]&&!notifications_marketing_seen($replyId)&&!notifications_claim_task_delivery($replyId),'Other employee cannot read, claim or dismiss reply');
 $actor=2;$popups=notifications_marketing_popups();ok(count($popups)===1&&(int)$popups[0]['note_is_owner']===1&&$popups[0]['action_link']==='/apps/marketing/execution.php?id=10#notes','Assigned employee receives reply and exact conversation link');
 ok(notifications_marketing_seen($replyId)&&popup_ids()===[],'Open Conversation acknowledges reply on server');
 $second=marketing_note_send(10,'[Assistance request] One more question',str_repeat('c',32));$secondId=notice_id($second);$actor=99;ok(popup_ids()===[$secondId],'Genuinely new message still appears after previous dismissal');
 $pdo->exec("UPDATE marketing_work_items SET status='completed' WHERE id=10");ok(popup_ids()===[$secondId],'Completed assignment conversation can still notify');
 $pdo->exec("UPDATE marketing_work_items SET status='published' WHERE id=10");ok(popup_ids()===[$secondId],'Published assignment conversation can still notify without reopening work');
 $pdo->exec("UPDATE marketing_work_items SET assigned_employee_id=3 WHERE id=10");ok(popup_ids()===[]&&!notifications_claim_task_delivery($secondId),'Stale employee assistance excluded after reassignment');
 $pdo->exec("UPDATE marketing_work_items SET assigned_employee_id=2,status='cancelled' WHERE id=10");ok(popup_ids()===[]&&!notifications_marketing_seen($secondId),'Cancelled assignment excluded consistently');
 $pdo->exec("UPDATE marketing_work_items SET status='in_progress' WHERE id=10");$actor=2;ok(marketing_execution_item(10)===$original,'Notifications never change assignment status or deadline');
 $before=count(ops_rows('SELECT * FROM marketing_notes'));$pdo->exec("CREATE TRIGGER fail_notice BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'");rejected(fn()=>marketing_note_send(10,'Retry me',str_repeat('d',32)),'Failed notification aborts the note transaction');$pdo->exec('DROP TRIGGER fail_notice');ok(count(ops_rows('SELECT * FROM marketing_notes'))===$before,'No partial note survives notification failure');
 $actor=3;rejected(fn()=>marketing_note_send(10,'Forbidden',str_repeat('e',32)),'Another employee cannot send on assignment');
 // Existing assignment/review popups remain unchanged.
 $actor=99;$review=notifications_create(['related_type'=>'marketing_review','related_id'=>10,'required_delivery'=>1],[99]);$pdo->exec("UPDATE marketing_work_items SET status='ready_for_review' WHERE id=10");ok(in_array($review,popup_ids(),true),'Owner review popup retained');ok(notifications_marketing_seen($review),'Review acknowledgement retained');
 $assignment=notifications_create(['related_type'=>'marketing_assignment','related_id'=>10,'required_delivery'=>1],[2]);$actor=2;ok(in_array($assignment,popup_ids(),true),'Employee assignment popup retained');
 echo "Marketing assistance popup database suite complete\n";
}finally{$pdo->exec('DROP DATABASE '.$database);unlink($fixture.'/shared/notifications.php');unlink($fixture.'/shared/epi/bootstrap.php');rmdir($fixture.'/shared/epi');rmdir($fixture.'/shared');rmdir($fixture);}
