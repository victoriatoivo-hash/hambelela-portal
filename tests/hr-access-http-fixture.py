"""Build a localhost-only fixture using the production bridge and HR guards."""
from pathlib import Path
import shutil
root = Path(__file__).resolve().parents[1]
fixture = root / 'test-http-fixture'
(fixture / 'shared').mkdir(parents=True, exist_ok=True)
shutil.copytree(root / 'apps/hr-portal', fixture / 'apps/hr-portal', dirs_exist_ok=True)
shutil.copy2(root / 'shared/hr-access.php', fixture / 'shared/hr-access.php')
(fixture / 'assets/fonts').mkdir(parents=True, exist_ok=True)
shutil.copy2(root.parent / 'assets/fonts/jost-variable.woff2', fixture / 'assets/fonts/jost-variable.woff2')
(fixture / 'config.php').write_text("""<?php
define('BASE_PATH', __DIR__); define('BASE_URL','');
$localSecrets=['hr_db_host'=>'127.0.0.1;port=3338','hr_db_name'=>'hr_access_test_hr','hr_db_user'=>'root','hr_db_pass'=>''];
""")
(fixture / 'shared/database.php').write_text("""<?php
function db(): PDO { static $db; return $db ?? ($db=new PDO('mysql:host=127.0.0.1;port=3338;dbname=hr_access_test_portal','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC])); }
""")
(fixture / 'shared/auth.php').write_text("""<?php
session_name('business_fixture_session');session_start();
function require_login(): void { if(empty($_SESSION['user'])){http_response_code(401);exit('Test login required');} }
function current_role_key(): string { return $_SESSION['user']['role_key'] ?? ''; }
function current_user_has_capability(string $key): bool { return current_role_key()==='owner_admin'; }
""")
(fixture / 'login-test.php').write_text("""<?php
require __DIR__.'/config.php';require __DIR__.'/shared/database.php';require __DIR__.'/shared/auth.php';
$rows=db()->prepare('SELECT e.id,e.full_name,e.email,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?');$rows->execute([(int)($_GET['id']??15)]);$u=$rows->fetch();
$_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['full_name'],'email'=>trim($u['email']),'role_key'=>$u['role_key']];
echo json_encode($_SESSION['user']);
""")
(fixture / 'business-test.php').write_text("""<?php
require __DIR__.'/config.php';require __DIR__.'/shared/auth.php';require_login();echo json_encode($_SESSION['user']);
""")
(fixture / 'state-test.php').write_text("""<?php
$hr=new PDO('mysql:host=127.0.0.1;port=3338;dbname=hr_access_test_hr','root','');
$state=$_GET['state']??'ready';
if($state==='inactive')$hr->exec('UPDATE users SET active=0 WHERE employee_id=4');
if($state==='wrong_role')$hr->exec("UPDATE users SET role='admin' WHERE employee_id=4");
if($state==='ready')$hr->exec("UPDATE users SET active=1,role='employee' WHERE name='Hope Kahuika'");
echo 'Fixture updated';
""")
(fixture / 'apps/hr-portal/self-service.php').write_text("""<?php
require __DIR__.'/config.php';requireLogin();$user=currentUser();
if($user['role']!=='employee'){header('Location: dashboard.php');exit;}
header('Content-Type: application/json');echo json_encode($user);
""")
print('Local HTTP fixture ready; production bridge and HR authorization guards copied.')
