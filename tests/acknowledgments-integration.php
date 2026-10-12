<?php
declare(strict_types=1);
require __DIR__.'/acknowledgments.php';
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
function db():PDO{return $GLOBALS['db'];}
function notifications_current_employee_id():?int{return 1;}
function load_functions(string $file,string $first,string $next):void{
    $source=file_get_contents($file);$a=strpos($source,'function '.$first.'(');$b=strpos($source,'function '.$next.'(',$a+1);
    if($a===false||$b===false)throw new RuntimeException('Test source range missing');
    eval(str_replace('__DIR__',var_export(dirname($file),true),substr($source,$a,$b-$a)));
}
require BASE_PATH.'/shared/task-reminders.php';
load_functions(BASE_PATH.'/shared/notifications.php','notifications_schema_ready','notifications_current_employee_id');
load_functions(BASE_PATH.'/shared/notifications.php','notifications_role_recipients','notifications_recipient_accepts');
load_functions(BASE_PATH.'/shared/notifications.php','notifications_create','notifications_create_for_roles');
$integration=file_get_contents(BASE_PATH.'/shared/acknowledgments/integration.php');
eval(str_replace('__DIR__',var_export(BASE_PATH.'/shared/acknowledgments',true),substr($integration,strpos($integration,'function acknowledgments_ready'))));
$db->exec(file_get_contents(BASE_PATH.'/operations-epi-foundation-migration.sql'));
notifications_schema_ready();
$db->exec("DELETE r FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id WHERE n.deduplication_key LIKE 'ack-event-%'");
$db->exec("DELETE FROM notifications WHERE deduplication_key LIKE 'ack-event-%'");
$db->exec("DELETE FROM epi_employee_evidence WHERE activity_source='portal_ack_events'");
$db->exec("INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES('epi_mode','enabled') ON DUPLICATE KEY UPDATE setting_value='enabled'");
acknowledgments_deliver();
$notifications=(int)$db->query("SELECT COUNT(*) FROM notifications WHERE deduplication_key LIKE 'ack-event-%'")->fetchColumn();
check($notifications>0,'Existing notification centre receives acknowledgment events');
check((int)$db->query("SELECT COUNT(*) FROM portal_ack_events WHERE notification_delivered_at IS NULL")->fetchColumn()===0,'All pending event notifications delivered');
check((int)$db->query("SELECT COUNT(*) FROM portal_ack_events WHERE evidence_recorded_at IS NULL")->fetchColumn()===0,'Existing performance engine receives all evidence');
check((int)$db->query("SELECT COUNT(*) FROM portal_ack_events WHERE event_type='overdue'")->fetchColumn()>0,'Overdue evidence produced');
$events=(int)$db->query('SELECT COUNT(*) FROM portal_ack_events')->fetchColumn();acknowledgments_deliver();
check((int)$db->query('SELECT COUNT(*) FROM portal_ack_events')->fetchColumn()===$events,'Overdue event replay deduplicated');
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE deduplication_key LIKE 'ack-event-%'")->fetchColumn()===$notifications,'Notification replay deduplicated');
check((int)$db->query("SELECT COUNT(*) FROM epi_employee_evidence WHERE activity_source='portal_ack_events' AND score_impact IS NOT NULL")->fetchColumn()===0,'No automatic scoring deductions');
$clock=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
$scheduleService=new \Hambelela\Acknowledgments\Service($db);
$future=$scheduleService->send(1,['request_key'=>\Hambelela\Acknowledgments\Service::uuid(),'title'=>'Future synthetic release','message_html'=>'<p>Testing only</p>','recipients'=>[2],'required'=>true,'send_mode'=>'later','scheduled_at'=>$clock->modify('+1 day')->format('Y-m-d\TH:i'),'deadline'=>$clock->modify('+2 days')->format('Y-m-d\TH:i')]);
$futureId=$future['instruction_id'];$futureRecipient=(int)$db->query('SELECT id FROM portal_ack_recipients WHERE instruction_id='.$futureId)->fetchColumn();
acknowledgments_deliver();
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE related_type='acknowledgment' AND related_id=$futureRecipient")->fetchColumn()===0,'No scheduled notification before release');
check((int)$db->query("SELECT COUNT(*) FROM portal_ack_events WHERE recipient_id=$futureRecipient AND event_type='overdue'")->fetchColumn()===0,'No scheduled overdue evidence');
$db->prepare('UPDATE portal_ack_instructions SET scheduled_at=? WHERE id=?')->execute([$clock->modify('-1 minute')->format('Y-m-d H:i:s'),$futureId]);
acknowledgments_deliver();acknowledgments_deliver();
check((int)$db->query("SELECT COUNT(*) FROM notifications WHERE related_type='acknowledgment' AND related_id=$futureRecipient")->fetchColumn()===1,'Release creates one persistent notification, retries do not duplicate');
check((int)$db->query("SELECT COUNT(*) FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id WHERE n.related_type='acknowledgment' AND n.related_id=$futureRecipient AND r.employee_id=2")->fetchColumn()===1,'Released notification belongs to intended employee');
check($scheduleService->popup(2)['title']==='Future synthetic release','Popup available after release');
check($scheduleService->detail(2,$futureRecipient)['status']==='pending','Release does not acknowledge for employee');
