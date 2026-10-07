<?php
declare(strict_types=1);
// Execute production notification functions against an isolated MySQL database.
define('BASE_URL', '');
$currentEmployee = 2;
$pdo = new PDO(getenv('TASK_POPUP_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3337', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS task_popup_test');
$pdo->exec('USE task_popup_test');
function db(): PDO { global $pdo; return $pdo; }
function ops_rows(string $sql, array $params=[]): array { $q=db()->prepare($sql);$q->execute($params);return $q->fetchAll(); }
function ops_table_exists(string $name): bool { return true; }
function ops_column_exists(string $table,string $column): bool { return in_array($column,['scheduled_at','released_at'],true); }
function ops_activity_log(string $action,string $type,int $id,array $metadata=[]): void {db()->prepare('INSERT INTO ops_activity_logs(action,entity_type,entity_id,metadata) VALUES(?,?,?,?)')->execute([$action,$type,$id,json_encode($metadata)]);}
function notifications_schema_ready(): bool { return true; }
function notifications_current_employee_id(): int { global $currentEmployee;return $currentEmployee; }
function notifications_recipient_accepts(int $id,string $module): bool { return true; }
function notifications_task_reminder_log(...$args): void {}
foreach (['notification_recipients','notifications','ops_checklist_tasks','ops_checklist_recurring_templates','ops_activity_logs','ops_employees'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec("CREATE TABLE notifications (id INT PRIMARY KEY AUTO_INCREMENT,title VARCHAR(240),message TEXT,module VARCHAR(40),related_type VARCHAR(40),related_id INT,priority VARCHAR(20),deadline_state VARCHAR(24),sound_key VARCHAR(24),scheduled_at DATETIME,deduplication_key VARCHAR(190) UNIQUE,action_link TEXT,created_by INT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE notification_recipients (notification_id INT,employee_id INT,read_at DATETIME NULL,cleared_at DATETIME NULL,delivered_at DATETIME NULL,snoozed_until DATETIME NULL,UNIQUE KEY(notification_id,employee_id))");
$pdo->exec("CREATE TABLE ops_checklist_tasks (id INT PRIMARY KEY,assigned_employee_id INT,employee_visible INT DEFAULT 1,scheduled_at DATETIME NULL,released_at DATETIME NULL,status VARCHAR(20) DEFAULT 'new',archived_at DATETIME NULL,deleted_at DATETIME NULL,recurring_template_id INT NULL,task_name VARCHAR(200),date_assigned DATETIME DEFAULT CURRENT_TIMESTAMP,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by INT DEFAULT 1,priority VARCHAR(20) DEFAULT 'normal',deadline DATETIME NULL,instructions TEXT,task_mode VARCHAR(30) DEFAULT 'manual')");
$pdo->exec("CREATE TABLE ops_checklist_recurring_templates (id INT PRIMARY KEY,is_active INT DEFAULT 1,status VARCHAR(20) DEFAULT 'active')");
$pdo->exec("CREATE TABLE ops_activity_logs (id INT PRIMARY KEY AUTO_INCREMENT,entity_type VARCHAR(40) DEFAULT 'checklist_task',entity_id INT,action VARCHAR(40),metadata TEXT,employee_id INT DEFAULT 1)");
$pdo->exec("CREATE TABLE ops_employees (id INT PRIMARY KEY,full_name VARCHAR(100))");
$pdo->exec("INSERT INTO ops_employees VALUES (1,'Victoria'),(2,'Hope'),(3,'Secilia')");
$source=file_get_contents(__DIR__.'/../shared/notifications.php');
foreach (['notifications_create','notifications_mark_task_state','notifications_claim_task_delivery','notifications_task_event','notifications_notify_task_assigned','notifications_recover_task_assignments','notifications_task_popups'] as $name) {
    $start=strpos($source,'function '.$name.'(');
    if ($start===false) throw new RuntimeException('Missing production function '.$name);
    preg_match('/^function /m',$source,$match,PREG_OFFSET_CAPTURE,$start+1);
    $end=$match[0][1] ?? strlen($source);
    // The next function's docblock can be included safely: it is complete.
    eval(str_replace("__DIR__ . '/epi/bootstrap.php'", "__DIR__ . '/../shared/epi/bootstrap.php'", substr($source,$start,$end-$start)));
}
function check(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n"; }
function task(int $id,int $employee=2): void {db()->prepare("INSERT INTO ops_checklist_tasks (id,assigned_employee_id,task_name,instructions) VALUES (?,?,'Prepare courier waybills','Check the details.')")->execute([$id,$employee]);db()->prepare("INSERT INTO ops_activity_logs(entity_id,action) VALUES (?,'task_created')")->execute([$id]);}
task(1);
$id=notifications_notify_task_assigned(1,2,'Prepare courier waybills');
check((bool)$id,'person assignment persists without a frontend toggle');
check(notifications_notify_task_assigned(1,2,'Prepare courier waybills')===$id,'repeated save/delivery is idempotent');
check(count(notifications_task_popups())===1,'offline unseen assignment appears on login');
$currentEmployee=3;check(count(notifications_task_popups())===0,'other employee cannot receive task');
$currentEmployee=2;check(notifications_claim_task_delivery($id),'first tab atomically claims popup');
check(!notifications_claim_task_delivery($id),'second tab cannot claim same popup');
check(count(notifications_task_popups())===0,'refresh does not replay a delivered popup');
notifications_mark_task_state($id,'dismissed');
$row=ops_rows('SELECT * FROM notification_recipients WHERE notification_id=?',[$id])[0];
check(!$row['read_at']&&!$row['cleared_at'],'dismiss leaves unread notification history intact');
db()->exec("UPDATE ops_checklist_tasks SET assigned_employee_id=3 WHERE id=1");
db()->exec("INSERT INTO ops_activity_logs(entity_id,action) VALUES(1,'task_reassigned')");
$new=notifications_notify_task_assigned(1,3,'Prepare courier waybills');
check((bool)$new&&$new!==$id,'reassignment notifies new assignee');
check(count(notifications_task_popups($id))===0,'old assignee gets no stale actionable popup');
db()->exec("UPDATE ops_checklist_tasks SET assigned_employee_id=2 WHERE id=1");
db()->exec("INSERT INTO ops_activity_logs(entity_id,action) VALUES(1,'task_reassigned')");
$back=notifications_notify_task_assigned(1,2,'Prepare courier waybills');
check((bool)$back&&$back!==$id,'reassignment back to original employee creates a new event');
check(notifications_notify_task_assigned(1,2,'Prepare courier waybills')===$back,'reassignment retry deduplicates');
db()->exec("INSERT INTO ops_activity_logs(entity_id,action) VALUES(1,'task_notification_updated')");
$update=notifications_notify_task_assigned(1,2,'Prepare courier waybills',true);
check(ops_rows('SELECT title FROM notifications WHERE id=?',[$update])[0]['title']==='Task updated','meaningful update uses distinct notification');
db()->exec("INSERT INTO ops_activity_logs(entity_id,action) VALUES(1,'task_admin_updated')");
check(notifications_notify_task_assigned(1,2,'Prepare courier waybills',true)===$update,'harmless edits do not change update event');
task(2);db()->exec("UPDATE ops_checklist_tasks SET scheduled_at='2099-01-01 08:00:00',employee_visible=0 WHERE id=2");
check(notifications_notify_task_assigned(2,2,'Future task')===null,'future tasks do not notify early');
db()->exec("UPDATE ops_checklist_tasks SET released_at=NOW(),employee_visible=1 WHERE id=2");
$release=notifications_notify_task_assigned(2,2,'Future task');check((bool)$release,'scheduled release notifies');
db()->exec("INSERT INTO ops_activity_logs(entity_id,action) VALUES(2,'task_released')");
check(notifications_notify_task_assigned(2,2,'Future task')===$release,'release audit does not create a second assignment');
db()->exec("INSERT INTO ops_checklist_recurring_templates(id) VALUES(1)");
task(3);task(4);db()->exec("UPDATE ops_checklist_tasks SET recurring_template_id=1,task_mode='recurring' WHERE id IN(3,4)");
$a=notifications_notify_task_assigned(3,2,'Monday');$b=notifications_notify_task_assigned(4,2,'Tuesday');
check((bool)$a&&(bool)$b&&$a!==$b,'recurring occurrences get separate notification identities');
db()->exec("UPDATE ops_checklist_tasks SET status='complete' WHERE id=3");
check(!notifications_claim_task_delivery($a),'completed task cannot be claimed');
db()->exec("UPDATE ops_checklist_tasks SET assigned_employee_id=3 WHERE id=4");
check(!notifications_claim_task_delivery($b),'reassigned-away task cannot be claimed');
task(5);db()->exec("UPDATE ops_checklist_tasks SET status='cancelled' WHERE id=5");
check(notifications_notify_task_assigned(5,2,'Cancelled')===null,'cancelled task cannot generate actionable notification');
task(6);notifications_recover_task_assignments();
check((int)ops_rows('SELECT COUNT(*) AS n FROM notifications WHERE related_id=6')[0]['n']===1,'missing post-commit notification recovers');
notifications_recover_task_assignments();
check((int)ops_rows('SELECT COUNT(*) AS n FROM notifications WHERE related_id=6')[0]['n']===1,'recovery does not duplicate');
$missing=notifications_notify_task_assigned(6,2,'Recover');db()->prepare('DELETE FROM notification_recipients WHERE notification_id=?')->execute([$missing]);
check(notifications_notify_task_assigned(6,2,'Recover')===$missing&&count(ops_rows('SELECT * FROM notification_recipients WHERE notification_id=?',[$missing]))===1,'partial recipient insert repairs on retry');
require_once __DIR__.'/../shared/task-scheduling.php';
task(7);db()->exec("UPDATE ops_checklist_tasks SET scheduled_at='2026-01-01 08:00:00',employee_visible=0 WHERE id=7");
check(task_release_due_scheduled_tasks()===1,'production scheduled-release workflow releases only due task');
check((int)ops_rows('SELECT COUNT(*) AS n FROM notifications WHERE related_id=7')[0]['n']===1,'production release persists one assignment notification');
check(task_release_due_scheduled_tasks()===0,'production release is idempotent');
notifications_recover_task_assignments();
check((int)ops_rows('SELECT COUNT(*) AS n FROM notifications WHERE related_id=7')[0]['n']===1,'login recovery after production release does not duplicate');
echo "Database integration checks complete.\n";
