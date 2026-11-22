<?php
// Narrow production-smoke regression; synthetic DB with connection-local job shadow only.
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');
require BASE_PATH.'/shared/delivery/OwnerDriverView.php';
use Hambelela\Delivery\{OwnerDriverView,DriverAssignments};
function current_role_key(){return 'owner_admin';}
$_SESSION=['user'=>['id'=>1,'role_key'=>'owner_admin'],'csrf'=>'OWNER_TEST_SECRET'];$session=$_SESSION;
$connect=static fn()=>new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=$connect();$before=$db->query('SELECT * FROM delivery_jobs ORDER BY id')->fetchAll();
require __DIR__.'/fixture.php';owner_view_fixture($db);
$day=(new DateTimeImmutable('yesterday',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
$db->prepare('UPDATE delivery_jobs SET scheduled_date=? WHERE id=16 AND driver_employee_id=3')->execute([$day]);
$n=0;function check($v,$s){global $n;if(!$v)throw new RuntimeException('FAIL '.$s);++$n;echo 'PASS '.$s,"\n";}
$current=DriverAssignments::read($db,3);check(!in_array(16,array_column($current['deliveries'],'assignmentId'),true),'driver current-day queue remains unchanged');
$view=new OwnerDriverView($db,1);$data=$view->read(3,'details',['id'=>16]);
check($data['assignmentId']===16&&$data['driverId']===3,'owner historical link uses the exact assigned record');
$html=$view->render(3,'details',16);check(strpos($html,'ho-driver-dashboard')!==false,'historical shared details screen renders');
check(strpos($html,'OWNER_TEST_SECRET')===false,'historical screen has no owner CSRF');
try{$view->read(4,'details',['id'=>16]);throw new LogicException('Cross-driver access allowed');}catch(RuntimeException $e){check($e->getCode()===404,'cross-driver historical access denied');}
check($_SESSION===$session,'owner identity unchanged');
check($connect()->query('SELECT * FROM delivery_jobs ORDER BY id')->fetchAll()===$before,'original assignment dates and records unchanged');
echo "TOTAL $n historical-details checks passed\n";
