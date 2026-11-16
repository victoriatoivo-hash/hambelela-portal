<?php
declare(strict_types=1);
require __DIR__.'/../shared/portal-presence-source.php';
$db=new PDO('mysql:host=127.0.0.1;port=13318;dbname=presence_test','root','fixture',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function verify($v,$message){if(!$v)throw new Exception($message);echo "PASS $message\n";}
$db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,name VARCHAR(80),role_key VARCHAR(80));
 CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,full_name VARCHAR(80),status VARCHAR(20));
 INSERT INTO ops_roles VALUES(1,'Owner','owner_admin'),(2,'Packer','packer');
 INSERT INTO ops_employees VALUES(1,1,'A','active'),(2,2,'B','active'),(3,2,'C','active')");
$touch=fn($id,$k,$now,$login,$expiry=99999)=>portal_widget_touch($db,$id,hash('sha256',$k),$now,$login,$expiry);
$rows=fn($now,$admin=true)=>array_column(portal_widget_roster($db,$now,$admin),null,'id');
$touch(1,'admin',1000,990);$touch(2,'phone',1010,1000);$touch(2,'phone',1020,1000);$touch(2,'desk',1020,1018);
$r=$rows(1040);verify(count($r)===2&&$r[1]['session_duration_seconds']===50&&$r[2]['session_duration_seconds']===40,'Unique people, independent login ages, no addition across tabs or devices');
$r=$rows(1040,false);verify(array_keys($r)===[2]&&array_keys($r[2])===['id','name','presence'],'Employee projection contains no role, timestamps or durations and retains owner visibility policy');
portal_widget_logout($db,2,hash('sha256','phone'));verify($rows(1040)[2]['session_duration_seconds']===22,'Signing out one device leaves the remaining login visible');
$touch(2,'desk',1130,1018);verify($rows(1140)[2]['session_duration_seconds']===122,'Idle but connected heartbeat maintains continuous login');
verify(!$rows(1250),'At exactly 120 seconds without contact, disconnected profiles disappear');
$touch(2,'desk',1260,1018);verify($rows(1260)[2]['session_duration_seconds']===0,'Reconnection after expiry starts a new online interval');
$touch(3,'third',1260,1260,1267);verify(!isset($rows(1267)[3]),'Authentication expiry bounds presence');
portal_widget_logout($db,2,hash('sha256','desk'));verify(!$rows(1267),'Explicit logout of last connected session clears profile');
