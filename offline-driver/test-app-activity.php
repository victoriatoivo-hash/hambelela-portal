<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);ob_start();define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
require BASE_PATH.'/shared/delivery/DriverAppActivity.php';
require BASE_PATH.'/shared/delivery/OrderReference.php';
use Hambelela\Delivery\DriverAppActivity;
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ddl=$db->query('SHOW CREATE TABLE delivery_requests')->fetch(PDO::FETCH_NUM)[1];
$db->exec(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl));
$count=0;function checkActivity($v,$s){global $count;if(!$v)throw new RuntimeException('FAIL '.$s);$count++;echo "PASS $s\n";}
$one=DriverAppActivity::record($db,3,new DateTimeImmutable('2026-11-21T05:10:00Z'));
checkActivity($one['day']==='2026-11-21'&&$one['label']==='App activity · 07:10','Namibia first opening, not duty');
checkActivity(DriverAppActivity::record($db,3,new DateTimeImmutable('2026-11-21T18:00:00Z'))===$one,'repeat opening preserves first timestamp');
checkActivity(DriverAppActivity::record($db,3,new DateTimeImmutable('2026-11-21T21:59:59Z'))===$one,'same Namibia day ends at UTC 22:00');
$next=DriverAppActivity::record($db,3,new DateTimeImmutable('2026-11-21T22:00:00Z'));
checkActivity($next['day']==='2026-11-22'&&$next['label']==='App activity · 00:00','new Namibia calendar day creates a new opening');
checkActivity((int)$db->query('SELECT COUNT(*) FROM delivery_requests')->fetchColumn()===2,'exactly one record per driver/day');
DriverAppActivity::record($db,4,new DateTimeImmutable('2026-11-21T08:00:00Z'));
checkActivity((int)$db->query('SELECT COUNT(*) FROM delivery_requests')->fetchColumn()===3,'drivers have independent daily records');
try{DriverAppActivity::record($db,1);throw new RuntimeException('Non-driver accepted');}catch(DomainException $e){checkActivity(true,'non-driver refused');}
function db(){return $GLOBALS['db'];}function current_user(){return ['id'=>3];}
$_SESSION=['csrf'=>'test','driver_profile_csrf'=>'test-profile'];$_GET=['profile'=>1];$_SERVER['REQUEST_METHOD']='POST';
function activityHttp($payload,$csrf='test-profile'){$_POST=['csrf'=>$csrf,'payload'=>json_encode($payload)];http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/DriverProfile.php';return [http_response_code(),json_decode(ob_get_clean(),true)];}
[$status,$body]=activityHttp(['action'=>'app_open','actor'=>3]);checkActivity($status===200&&$body['ok']&&$body['actor']===3,'actual authenticated endpoint records opening');
[$status]=activityHttp(['action'=>'app_open','actor'=>4]);checkActivity($status===403,'cross-driver actor spoof denied');
[$status]=activityHttp(['action'=>'app_open','actor'=>3],'bad');checkActivity($status===403,'CSRF required');
[$status]=activityHttp(['action'=>'app_open','actor'=>3,'date'=>'2000-01-01']);checkActivity($status===403,'client cannot backdate activity');
foreach(['WEB-39050','INV-39050','#39050','39050'] as $ref)checkActivity(\Hambelela\Delivery\OrderReference::display($ref)==='Order 39050','display reference '.$ref);
checkActivity(\Hambelela\Delivery\OrderReference::display('ERR-100')==='ERR-100','errand references preserved');
checkActivity(strpos(file_get_contents(BASE_PATH.'/shared/delivery/DriverAppActivity.php'),'UPDATE')===false,'opening never rewrites existing first event');
echo "TOTAL $count app-activity checks passed\n";
