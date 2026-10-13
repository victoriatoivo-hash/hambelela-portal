<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/TrackingSchema.php';require __DIR__.'/../shared/delivery/TrackingAlerts.php';
$db=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='delivery_gps_alert_test_'.bin2hex(random_bytes(5));$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
function assertAlert($ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message."\n";}
try{
 \Hambelela\Delivery\TrackingSchema::install($db);
 $db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60)); CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,status VARCHAR(20),full_name VARCHAR(100)); CREATE TABLE delivery_driver_profiles(employee_id INT PRIMARY KEY,active TINYINT);
 CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(200),message TEXT,module VARCHAR(50),related_type VARCHAR(60),related_id BIGINT,priority VARCHAR(20),deduplication_key VARCHAR(190) UNIQUE,action_link VARCHAR(255)) ENGINE=InnoDB;
 CREATE TABLE notification_recipients(notification_id INT,employee_id INT,UNIQUE KEY recipient(notification_id,employee_id)) ENGINE=InnoDB;
 INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'delivery_driver'),(3,'front_desk_admin');INSERT INTO ops_employees VALUES(1,1,'active','Owner'),(2,2,'active','Driver'),(3,3,'active','Front'),(4,1,'inactive','Former Owner');INSERT INTO delivery_driver_profiles VALUES(2,1)");
 $t=(new DateTimeImmutable('2026-10-12 10:00:00',new DateTimeZone('Africa/Windhoek')))->getTimestamp();$id=str_repeat('a',32);
 $settings=json_encode(\Hambelela\Delivery\TrackingPolicy::settings([]));
 $db->prepare('INSERT INTO delivery_tracking_devices(id,name,bike,employee_id,settings_json,assigned_at,created_by,token_hash) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,'Synthetic phone','Bike',2,$settings,$t-3600,1,str_repeat('b',64)]);
 $alerts=new \Hambelela\Delivery\TrackingAlerts($db);
 assertAlert($alerts->check(true,$t)===0,'not armed before first phone contact');
 $db->exec('UPDATE delivery_tracking_devices SET last_contact='.($t-301).',last_position='.($t-301).',gps_available=1');
 assertAlert($alerts->check(false,$t)===0,'global pause suppresses alerts');
 assertAlert($alerts->check(true,$t-3*3600)===0,'off-duty hours suppress alerts');
 assertAlert($alerts->check(true,$t)===1,'stale GPS creates Owner availability notice');
 assertAlert($alerts->check(true,$t+60)===0,'same outage cannot notify twice');
 assertAlert((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'only one notification stored');
 assertAlert($db->query('SELECT employee_id FROM notification_recipients')->fetchAll(PDO::FETCH_COLUMN)===[1],'only active Owner is a recipient');
 assertAlert(strpos($db->query('SELECT message FROM notifications')->fetchColumn(),'cause is not confirmed')!==false,'neutral wording does not blame Driver');
 $db->exec('UPDATE delivery_tracking_devices SET last_contact='.($t+70).',last_position='.($t+70));
 assertAlert($alerts->check(true,$t+70)===0,'recovery closes outage without new alert');
 assertAlert($alerts->check(true,$t+371)===1,'new outage after recovery alerts again');
 assertAlert($alerts->check(false,$t+400)===0,'pause closes existing alert episode');
 $db->exec('UPDATE delivery_tracking_devices SET active=0');assertAlert($alerts->check(true,$t+500)===0,'inactive device excluded');
 $db->exec("UPDATE delivery_tracking_devices SET active=1;UPDATE ops_employees SET status='inactive' WHERE id=2");assertAlert($alerts->check(true,$t+500)===0,'inactive Driver excluded');
 $db->exec("UPDATE ops_employees SET status='active' WHERE id=2;UPDATE delivery_tracking_devices SET last_contact=".($t+500).",last_position=NULL");
 $db->prepare("INSERT INTO delivery_tracking_audit(device_id,actor_id,event_type,metadata_json,recorded_at) VALUES(?,0,'tracking_started','{}',?)")->execute([$id,$t+500]);
 assertAlert($alerts->check(true,$t+550)===0,'initial GPS acquisition receives grace period');
 $db->exec('UPDATE delivery_tracking_devices SET last_contact='.($t+810));assertAlert($alerts->check(true,$t+810)===1,'missing location alerts even when heartbeats continue');
 $alerts->check(false,$t+820);
 $db->exec('UPDATE delivery_tracking_devices SET last_position='.($t+820).',last_contact='.($t+820));
 $before=(int)$db->query('SELECT COUNT(*) FROM delivery_tracking_alerts')->fetchColumn();
 $db->exec('DROP TABLE notification_recipients');
 try{$alerts->check(true,$t+1200);throw new RuntimeException('Expected recipient failure');}catch(PDOException $expected){}
 assertAlert((int)$db->query('SELECT COUNT(*) FROM delivery_tracking_alerts')->fetchColumn()===$before,'notification failure rolls back episode so retry is possible');
 echo "Synthetic alert checks complete; no live notifications sent.\n";
}finally{$db->exec('DROP DATABASE '.$name);}
