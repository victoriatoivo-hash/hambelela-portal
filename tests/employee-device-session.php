<?php
declare(strict_types=1);
// Isolated, disposable fixtures; no portal configuration or production database.
require_once dirname(__DIR__).'/shared/employee-device-session.php';
function db(): PDO { return $GLOBALS['fixtureDb']; }
function portal_request_is_https(): bool { return $GLOBALS['https']; }
function portal_initialize_authenticated_session(array $user): void { $_SESSION['absolute_expires_at']=time()+36000; }
function check($condition,string $label): void { if (!$condition) throw new RuntimeException($label); }
$GLOBALS['https']=true;
$GLOBALS['fixtureDb']=$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
$db->exec("CREATE TABLE ops_employees(id INTEGER PRIMARY KEY,full_name TEXT,email TEXT,password_hash TEXT,status TEXT,role_id INT,must_change_access_code INT,locked_until TEXT);
CREATE TABLE ops_roles(id INTEGER PRIMARY KEY,name TEXT,role_key TEXT);
CREATE TABLE ops_security_events(id INTEGER PRIMARY KEY, employee_id INT,event_type TEXT,metadata_json TEXT);
CREATE TABLE portal_employee_devices(device_id TEXT PRIMARY KEY,token_hash TEXT UNIQUE,employee_id INT,credential_version TEXT,issued_at INT,expires_at INT,last_contact INT);
INSERT INTO ops_roles VALUES(1,'Owner','owner_admin');
INSERT INTO ops_employees VALUES(8,'Test','x@example.invalid','fixture','active',1,0,NULL)");
set_error_handler(static function(int $level,string $message): bool {throw new RuntimeException($message);});
check(session_start(),'test session failed');
$now=time();
$id=str_repeat('a',32);
$cookie=$id.'.'.str_repeat('b',64);
$fixture=function(int $expires,int $seen) use($db,$now,$id,$cookie): void {
    $db->exec('DELETE FROM portal_employee_devices');
    $db->prepare('INSERT INTO portal_employee_devices VALUES(?,?,?,?,?,?,?)')->execute([$id,hash('sha256',$cookie),8,hash('sha256','fixture'),$now-86400,$expires,$seen]);
    $_COOKIE[PORTAL_DEVICE_COOKIE]=$cookie;
};
$fixture($now+600,$now-3600);
check(portal_device_restore(),'restore failed');
check($_SESSION['portal_dashboard_pending'] && $_SESSION['user']['id']===8,'wrong return destination/user');
check($_SESSION['absolute_expires_at']===$now+600,'hard expiry extended');
check(!portal_device_restore(),'used secret restored twice');
portal_device_forget();
check(!$db->query('SELECT count(*) FROM portal_employee_devices')->fetchColumn(),'logout did not revoke rotated token');
$fixture($now+600,$now-60);
check(portal_device_restore() && !$_SESSION['portal_dashboard_pending'],'short disconnect lost current page');
$fixture($now-1,$now-10);
check(!portal_device_restore(),'expired device accepted');
$fixture($now+600,$now-10);
foreach (["status='inactive'","must_change_access_code=1","password_hash='replaced'","locked_until='2999-01-01'"] as $mutation) {
    $db->beginTransaction();$db->exec("UPDATE ops_employees SET ".$mutation);check(!portal_device_restore(),$mutation);$db->rollBack();
}
$db->prepare('INSERT INTO ops_security_events VALUES(1,8,?,?)')->execute(['employee_sessions_revoked',json_encode(['revoked_before'=>$now])]);
check(!portal_device_restore(),'revoked device resumed');
$GLOBALS['https']=false;check(!portal_device_restore(),'insecure transport accepted');
$_COOKIE[PORTAL_DEVICE_COOKIE]='invalid';check(portal_device_key()===null,'invalid cookie accepted');
$_SESSION['portal_last_contact']=$now;
check(!portal_presence_dashboard_due($now+1799) && portal_presence_dashboard_due($now+1800),'dashboard gap');
check(PORTAL_DEVICE_LIFETIME===7776000 && PORTAL_OFFLINE_SECONDS===300,'policy intervals');
$db->exec('CREATE TABLE portal_employee_presence_intervals(id INTEGER PRIMARY KEY,employee_id INT,returned_at INT,last_seen INT)');
foreach ([1000,1000,1299,1599,1600] as $sample) portal_record_browser_contact($db,8,$sample);
portal_record_browser_contact($db,9,1300);
check($db->query('SELECT returned_at,last_seen FROM portal_employee_presence_intervals WHERE employee_id=8 ORDER BY id')->fetchAll(PDO::FETCH_NUM) === [[1000,1299],[1599,1600]],'offline gap or duplicate intervals');
check(portal_presence_local_time(0)===(new DateTimeImmutable('@0'))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s'),'local time');
echo "Automatic employee return: restore, rotation, logout, expiry, role identity, reset, revocation and resume boundaries passed.\n";
