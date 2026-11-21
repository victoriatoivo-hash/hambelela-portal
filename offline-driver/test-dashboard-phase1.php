<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
require BASE_PATH.'/shared/delivery/dashboard/Data.php';
use Hambelela\Delivery\Dashboard\Data;
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check($value,$label){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
$original=Data::read($db,3);
check($original['progress']['assigned']===$original['progress']['delivered']+$original['progress']['remaining'],'tally reconciliation');
$other=Data::read($db,4);check($other['progress']['assigned']===0&&$other['nextDelivery']===null&&$other['quickActions']['cashDueNad']===0,'second driver isolation and empty state');
foreach([1,2,5,99999] as $id){$denied=false;try{Data::read($db,$id);}catch(DomainException $e){$denied=true;}check($denied,'reject non-driver '.$id);}
$db->beginTransaction();
try{
 $job=(int)$db->query('SELECT id FROM delivery_jobs WHERE driver_employee_id=3 ORDER BY id LIMIT 1')->fetchColumn();
 $db->exec("UPDATE delivery_jobs SET scheduled_date='".date('Y-m-d')."',status='ready',delivered_at=NULL WHERE driver_employee_id=3");
 $ready=Data::read($db,3);check($ready['progress']['delivered']===0,'ready work is not delivered');
 $db->exec("UPDATE delivery_jobs SET status='completed',delivered_at=NULL WHERE driver_employee_id=3");
 check(Data::read($db,3)['progress']['delivered']===0,'completion status without handover is not delivered');
 $db->exec("UPDATE delivery_jobs SET status='cancelled' WHERE driver_employee_id=3");
 check(Data::read($db,3)['progress']['assigned']===0,'cancelled work excluded');
 $db->exec("UPDATE delivery_jobs SET status='ready' WHERE id=$job");
 $db->prepare("INSERT INTO delivery_receipts(delivery_id,collection_uuid,payment_method,transaction_reference,order_goods_cents,delivery_fee_cents,partner_cod_cents,collected_by_employee_id) VALUES(?,?,'cash','',2500,0,0,3)")->execute([$job,bin2hex(random_bytes(16))]);
 $receipt=(int)$db->lastInsertId();$held=Data::read($db,3)['quickActions']['cashDueNad'];
 $db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'cash_handover',2,?)")->execute([$job,json_encode(['receipt_id'=>$receipt,'expected_cents'=>2500,'counted_cents'=>2500,'difference_cents'=>0])]);
 check(abs(Data::read($db,3)['quickActions']['cashDueNad']-($held-25))<0.001,'cash handover excluded before reconciliation');
 $db->exec("UPDATE delivery_driver_profiles SET active=0 WHERE employee_id=3");
 $denied=false;try{Data::read($db,3);}catch(DomainException $e){$denied=true;}check($denied,'inactive driver denied');
}finally{$db->rollBack();}
check(Data::read($db,3)===$original,'all synthetic test mutations rolled back');
$local=true;$base='';$dashboard=$original;
$dashboard['driver']['firstName']='<script>bad()</script>';$dashboard['progress']=['assigned'=>1234,'delivered'=>1,'remaining'=>1233];
ob_start();require BASE_PATH.'/shared/delivery/dashboard/View.php';$html=ob_get_clean();
check(strpos($html,'<script>bad()')===false&&strpos($html,'&lt;script&gt;')!==false,'escaped account text');
check(strpos($html,'1234')!==false,'large counts not truncated');
check(strpos($html,'href="#"')===false,'no dummy navigation links');
check(strpos($html,'hambelela-logo.jpg')!==false&&strpos($html,'id="hd-white-logo"')!==false,'original business logo rendered in white');
check(strpos($html,'Local preview · practice data only')===false&&strpos($html,' · Windhoek')===false,'removed preview subtitle and location text');
check(strpos($html,'data-hd-date')!==false&&strpos($html,'data-hd-time')!==false&&strpos($html,'data-hd-date')<strpos($html,'aria-label="Notifications'),'live clock placed before notification icon');
check(strpos($html,'Duty status not recorded')<strpos($html,'class="ho-driver-dashboard__head-actions"'),'honest duty indicator above header icons');
$dashboard=['dataStatus'=>'error'];ob_start();require BASE_PATH.'/shared/delivery/dashboard/View.php';$html=ob_get_clean();
check(strpos($html,'Unable to load deliveries')!==false&&strpos($html,'ho-driver-dashboard__metric"')===false,'error state does not fabricate zero totals');
$dashboard=$other;ob_start();require BASE_PATH.'/shared/delivery/dashboard/View.php';$html=ob_get_clean();
check(strpos($html,'No deliveries assigned yet')!==false,'empty schedule rendered distinctly');
