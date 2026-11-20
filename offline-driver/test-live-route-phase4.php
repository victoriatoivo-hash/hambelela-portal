<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');define('DRIVER_OFFLINE_LAB',true);
require BASE_PATH.'/shared/delivery/DriverLiveRouteData.php';
use Hambelela\Delivery\DriverLiveRouteData as R;
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$checks=0;function verify($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$actorId=3;$actorRole='delivery_driver';function db(){global $db;return $db;}function current_user(){global $actorId;return ['id'=>$actorId];}function current_role_key(){global $actorRole;return $actorRole;}
$now=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
$read=fn($driver=3)=>R::read($db,$driver,$now);
$initial=$read();$again=$read();verify($initial['revision_token']===$again['revision_token'],'repeat reads keep stable revision');
verify(is_int($initial['version'])&&$initial['version']<=4503599627370495,'numeric version is JavaScript-safe');
verify($initial['date']===$now->format('Y-m-d')&&$initial['timezone']==='Africa/Windhoek','business date is Windhoek');
verify($initial['next_stop_id']===null&&$initial['sequence_status']==='unconfirmed','no invented next stop or visit order');
verify(!in_array(true,$initial['capabilities'],true),'provider, GPS and writes are gated');
verify($initial['estimates']['distance_metres']===null&&$initial['estimates']['duration_seconds']===null,'no invented road distance or ETA');
verify($read(4)['data_status']==='no_route'&&$read(4)['stops']===[],'other driver does not inherit current driver stops');
foreach([0,1,2,5,999999] as $driver){$denied=false;try{$read($driver);}catch(DomainException $e){$denied=true;}verify($denied,'non-driver/inactive identity rejected '.$driver);}
$job=$db->query("SELECT * FROM delivery_jobs WHERE driver_employee_id=3 AND status='ready' ORDER BY id LIMIT 1")->fetch();$id=(int)$job['id'];$oid=(int)$job['order_id'];
$event=function($type,$meta)use($db,$id){$db->prepare('INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,?,3,?,UTC_TIMESTAMP())')->execute([$id,$type,json_encode($meta)]);};
$db->beginTransaction();
try{
 $db->exec("UPDATE delivery_jobs SET address='24 Synthetic Test Street, Windhoek',area='Layout test area' WHERE id=$id");
 $db->exec("UPDATE ops_orders SET payment_status='paid',payment_method='EFT',status='in_progress' WHERE id=$oid");
 $db->exec("DELETE FROM order_payment_allocations WHERE order_id=$oid");
 $db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES($oid,'EFT',12000,'pos')");
 $r=$read();verify($db->inTransaction(),'reader preserves caller-owned transaction');
 verify($r['remaining_stops']===1&&count($r['stops'])===1,'count and eligible route set agree');
 verify($r['held_stops']===0,'cleared payment removes dispatch-review hold');
 verify($r['revision_token']!==$initial['revision_token'],'source payment change changes route revision');
 verify($r['route_id']===$initial['route_id'],'route identity stable through same-day changes');
 verify($r['stops'][0]['address_display']==='24 Synthetic Test Street, Windhoek','own saved dispatch address retained');
 verify($r['stops'][0]['coordinates']===null,'unverified coordinate columns are not mapped');
 verify(!isset($r['stops'][0]['customer_mobile'],$r['stops'][0]['notes']),'route excludes contacts and internal notes');
 $old=$r['revision_token'];$db->exec("UPDATE delivery_jobs SET address='25 Synthetic Test Street, Windhoek' WHERE id=$id");verify($read()['revision_token']!==$old,'address change invalidates snapshot even without job version bump');
 $db->exec("UPDATE delivery_jobs SET address=area WHERE id=$id");$r=$read();verify($r['remaining_stops']===1&&$r['stops'][0]['address_display']===null,'unverified address is flagged without dropping stop');
 $db->exec("UPDATE delivery_jobs SET address='javascript://example.invalid' WHERE id=$id");verify($read()['stops'][0]['address_display']===null,'URI-shaped location is not a street destination');
 $event('parcel_collected',['driver_id'=>3]);$db->exec("UPDATE delivery_jobs SET status='out_for_delivery' WHERE id=$id");$r=$read();verify($r['stops'][0]['status']==='on_route'&&$r['stops'][0]['pickup_confirmed_at']!==null,'recorded pickup retained without fabricated shop stop');
 foreach(['delivered','awaiting_reconciliation','completed','cancelled'] as $state){$db->exec("UPDATE delivery_jobs SET status='$state' WHERE id=$id");$r=$read();verify($r['remaining_stops']===0&&$r['stops']===[]&&$r['needs_review']===[],'physical handover/completion/cancellation excluded '.$state);}
 $db->exec("UPDATE delivery_jobs SET status='failed' WHERE id=$id");$r=$read();verify($r['remaining_stops']===0&&$r['held_stops']===1,'failed assignment is held outside remaining route');
 $db->exec("UPDATE delivery_jobs SET status='ready',driver_employee_id=4 WHERE id=$id");$r=$read();verify($r['stops']===[]&&$r['needs_review']===[],'reassignment immediately removes old driver access');verify($read(4)['remaining_stops']===1,'assignment appears only for new driver');
 $db->exec("UPDATE delivery_jobs SET driver_employee_id=3,scheduled_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=$id");verify($read()['remaining_stops']===0,'previous day excluded');
 $db->exec("UPDATE delivery_jobs SET scheduled_date=DATE_ADD(CURDATE(),INTERVAL 1 DAY) WHERE id=$id");verify($read()['remaining_stops']===0,'future day excluded');
 $db->prepare('UPDATE delivery_jobs SET scheduled_date=? WHERE id=?')->execute([$now->format('Y-m-d'),$id]);
 $data=$read();$data['stops'][0]['area']='<script>alert(1)</script>';$data['stops'][0]['customer_display_name']='<img src=x onerror=alert(1)>';
 $driverId=3;$local=true;$base='';$error=null;require BASE_PATH.'/shared/delivery/live-route/Helpers.php';
 ob_start();require BASE_PATH.'/shared/delivery/live-route/View.php';$html=ob_get_clean();
 verify(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<img src=x'),'customer and address output escaped');
 verify(substr_count($html,'aria-current="page"')===1&&str_contains($html,'href="/apps/delivery/index.php?route=1" aria-current="page"'),'shared nav selects Map only');
 verify(!str_contains($html,'sample-route-map')&&!str_contains($html,'maps.googleapis.com'),'real preview never displays illustration or loads billed SDK');
 $db->exec('UPDATE delivery_driver_profiles SET active=0 WHERE employee_id=3');$denied=false;try{$read();}catch(DomainException $e){$denied=true;}verify($denied,'inactive current driver loses route');
}finally{$db->rollBack();}
verify($read()['revision_token']===$initial['revision_token'],'all fixtures restored by rollback');
function requestRoute($params=[],$role='delivery_driver',$driver=3,$method='GET'){
 global $actorId,$actorRole;$actorId=$driver;$actorRole=$role;$_GET=['route'=>'1']+$params;$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);
 ob_start();require BASE_PATH.'/shared/delivery/DriverLiveRoute.php';return [http_response_code(),ob_get_clean()];
}
[$code,$body]=requestRoute(['view'=>'route-data']);$payload=json_decode($body,true);verify($code===200&&$payload['route']['driver_id']===3,'authenticated current-driver feed works');
[$code,$body]=requestRoute(['view'=>'route-data','driver_id'=>'4','employee_id'=>'4','route_id'=>'other']);verify($code===200&&json_decode($body,true)['route']['driver_id']===3,'query parameters cannot select someone else');
[$code,$body]=requestRoute(['view'=>'route-data'],'front',2);verify($code===403&&json_decode($body,true)['route']===null,'front/admin role cannot use driver endpoint');
[$code,$body]=requestRoute(['view'=>'route-data'],'delivery_driver',4);verify($code===200&&json_decode($body,true)['route']['needs_review']===[],'other driver receives no private rows');
[$code,$body]=requestRoute(['view'=>'route-data'],'delivery_driver',3,'POST');verify($code===405&&json_decode($body,true)['route']===null,'POST cannot reorder or optimise');
foreach(['1900-01-01',['2026-11-20'],'invalid'] as $date){[$code,$body]=requestRoute(['date'=>$date]);verify($code===422,'unsupported or tampered date rejected');}
[$code,$body]=requestRoute(['view'=>'route-data','action'=>'navigate']);verify($code===409&&json_decode($body,true)['route']===null,'no direct navigation without approved next stop');
echo "TOTAL $checks route checks passed\n";ob_end_flush();
