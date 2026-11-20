<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');define('DRIVER_OFFLINE_LAB',true);
require BASE_PATH.'/shared/delivery/DriverOrderDetailsData.php';
use Hambelela\Delivery\DriverOrderDetailsData as D;
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$checks=0;function verify($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$actorId=3;$actorRole='delivery_driver';function db(){global $db;return $db;}function current_user(){global $actorId;return ['id'=>$actorId];}function current_role_key(){global $actorRole;return $actorRole;}
$job=$db->query("SELECT * FROM delivery_jobs WHERE driver_employee_id=3 AND source='hambelela' AND status='ready' ORDER BY id LIMIT 1")->fetch();$id=(int)$job['id'];$oid=(int)$job['order_id'];
$now=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
$original=D::read($db,3,$id,$now);
$read=fn()=>D::read($db,3,$id,$now);
$denied=function($driver,$assignment)use($db,$now){try{D::read($db,$driver,$assignment,$now);return false;}catch(DomainException|OutOfBoundsException $e){return true;}};
$event=function($type,$meta,$when=null)use($db,$id){$db->prepare('INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,?,3,?,?)')->execute([$id,$type,json_encode($meta),$when??gmdate('Y-m-d H:i:s')]);return (int)$db->lastInsertId();};
verify($denied(4,$id),'another driver cannot read assignment');
foreach([0,1,2,5,999999] as $driver)verify($denied($driver,$id),'unauthorized identity denied '.$driver);
verify($denied(3,999999),'unknown assignment is not found');
verify(D::phone(' +264 81 123 4567 ')==='+264811234567','validated phone formatting');
foreach(['','javascript:alert(1)','+264811234567;ext=1','+264811234567?body=hello','office@example.com','123'] as $phone)verify(D::phone($phone)===null,'invalid contact rejected');
verify(D::timestamp('2026-02-30 10:00:00')===null&&D::timestamp('invalid')===null,'invalid timestamps do not become Now');
verify(D::timestamp('2026-11-20 08:15:00')==='2026-11-20T10:15:00+02:00','events convert UTC to Windhoek');
verify($original['dispatchedQuantityTotal']===null&&$original['items'][0]['quantity']===null,'ordered units are never claimed as dispatched');
verify(!isset($original['notes'],$original['total_amount'],$original['salary']),'private fields excluded');
$db->beginTransaction();
try{
 $db->exec("UPDATE delivery_jobs SET customer_mobile='+264811234567',address='24 Test Street, Windhoek',area='Test district',additional_delivery=1 WHERE id=$id");
 $db->exec("UPDATE ops_orders SET payment_status='paid',payment_method='EFT' WHERE id=$oid");$db->exec("DELETE FROM order_payment_allocations WHERE order_id=$oid");
 $r=$read();verify(!$r['payment']['verified'],'EFT selected and paid flag alone do not verify money');
 verify($r['customer']['phone']==='+264811234567'&&$r['customer']['messagingContact']===null,'valid assignment phone only; no invented messaging route');
 verify($r['delivery']['address']['street']==='24 Test Street, Windhoek'&&!$r['delivery']['navigationAvailable'],'saved dispatch address shown, payment exception blocks navigation');
 $db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES($oid,'EFT',6000,'pos'),($oid,'Cash',6000,'pos')");
 $r=$read();verify($r['payment']['status']==='split'&&$r['payment']['label']==='Split payment · verified','split paid methods explicitly identified');
 verify($r['delivery']['navigationAvailable'],'approved address navigation allowed after verified payment');
 $db->exec("UPDATE ops_orders SET status='refunded' WHERE id=$oid");$r=$read();verify(!$r['payment']['verified']&&$r['payment']['label']==='Payment needs review','refunded source cannot retain a green paid pill');$db->exec("UPDATE ops_orders SET status='in_progress' WHERE id=$oid");$r=$read();
 verify($r['dispatchedQuantityTotal']===null&&str_contains($r['itemsNotice'],'partial handover'),'additional trip does not inherit full order as its parcel');
 $db->exec("UPDATE delivery_jobs SET address='KH, NA',customer_mobile='email@example.com' WHERE id=$id");$r=$read();verify($r['delivery']['address']['street']===null&&!$r['delivery']['navigationAvailable']&&$r['customer']['phone']===null,'missing or incomplete address/phone disable actions');
 $db->exec("UPDATE delivery_jobs SET address=area WHERE id=$id");verify(!$read()['delivery']['navigationAvailable'],'area-only location not treated as exact destination');
 $db->exec("UPDATE delivery_jobs SET status='out_for_delivery',started_at=UTC_TIMESTAMP() WHERE id=$id");$started=$event('started',[]);
 verify($read()['delivery']['stage']==='exception','start alone cannot claim physical pickup');
 $pickup=$event('parcel_collected',['driver_id'=>3]);$r=$read();verify($r['delivery']['stage']==='on_route'&&$r['delivery']['lastStageChangedAt']!==null,'real pickup has real on-route timestamp');
 $old=$event('note_added',['note'=>'INTERNAL-SENTINEL payroll'],'2026-11-19 08:00:00');verify(!str_contains(json_encode($read()),'INTERNAL-SENTINEL'),'internal notes never leak into timeline');
 $revised=$event('revised',['driver'=>3]);$r=$read();verify($r['delivery']['stage']==='exception'&&!in_array('parcel_collected',array_column($r['timeline'],'eventType')),'old-driver/past-revision pickup not shown as current');
 $pickup2=$event('parcel_collected',['driver_id'=>3],'2026-11-19 22:30:00');$r=$read();verify(in_array('00:30',array_column($r['timeline'],'timeLabel')),'midnight event uses business timezone');
 $failed=$event('failed',[],'2026-11-19 08:00:00');$db->exec("UPDATE delivery_jobs SET status='failed' WHERE id=$id");$r=$read();verify(in_array('19 Nov 2026 · 10:00',array_column($r['timeline'],'timeLabel')),'cross-day events include a date');
 $db->exec("UPDATE delivery_jobs SET driver_employee_id=4 WHERE id=$id");verify($denied(3,$id),'stale reassignment immediately denied');$db->exec("UPDATE delivery_jobs SET driver_employee_id=3,status='cancelled' WHERE id=$id");verify($denied(3,$id),'cancelled assignment immediately denied');
 $db->exec("UPDATE delivery_jobs SET status='ready' WHERE id=$id");$db->exec('UPDATE delivery_driver_profiles SET active=0 WHERE employee_id=3');verify($denied(3,$id),'inactive driver rejected');$db->exec('UPDATE delivery_driver_profiles SET active=1 WHERE employee_id=3');
 $db->exec("DELETE FROM ops_order_items WHERE order_id=$oid");verify($read()['items']===[],'empty items do not create fictional products');
 for($i=0;$i<25;$i++)$db->prepare('INSERT INTO ops_order_items(order_id,product_name,quantity) VALUES(?,?,?)')->execute([$oid,str_repeat('Long variant 250 ml ',8).$i,3]);
 $r=$read();verify(count($r['items'])===25&&$r['dispatchedQuantityTotal']===null,'25 product lines retained without fake parcel sum');
 $data=$r;$data['customer']['displayName']='<script>alert(1)</script>';$data['items'][0]['name']='<img src=x onerror=alert(1)>';$error=null;$local=true;$base='';$driverId=3;$assignmentId=$id;
 require BASE_PATH.'/shared/delivery/order-details/Helpers.php';ob_start();require BASE_PATH.'/shared/delivery/order-details/View.php';$html=ob_get_clean();
 verify(!str_contains($html,'<img src=x')&&!str_contains($html,'<script>alert')&&str_contains($html,'&lt;img'),'all customer and item strings escaped');
 verify(substr_count($html,'aria-current="page"')===1&&str_contains($html,'data-order-back'),'one shared navigation and list back button');
 verify(!str_contains($html,'Complete delivery')&&!str_contains($html,'method="post"')&&!str_contains($html,'tel:'),'read-only local page cannot call, complete or charge');
}finally{$db->rollBack();}
verify($read()===$original,'all transactional changes rolled back; original entries intact');
function requestDetails($params,$role='delivery_driver',$driver=3,$method='GET'){
 global $actorId,$actorRole;$actorId=$driver;$actorRole=$role;$_GET=$params;$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);
 ob_start();require BASE_PATH.'/shared/delivery/DriverOrderDetails.php';$html=ob_get_clean();return [http_response_code(),$html];
}
[$code,$html]=requestDetails(['delivery'=>(string)$id,'view'=>'order-details-data']);verify($code===200&&json_decode($html,true)['dataStatus']==='live','authenticated JSON endpoint succeeds');
foreach([['delivery'=>['1']],['delivery'=>'1 OR 1=1'],['delivery'=>'0'],['delivery'=>'999999']] as $params){[$code,$html]=requestDetails($params);verify($code===404&&!str_contains($html,$original['customer']['displayName']),'tampered IDs return safe 404');}
[$code,$html]=requestDetails(['delivery'=>(string)$id],'front',2);verify($code===403&&!str_contains($html,$original['customer']['displayName']),'non-driver controller returns 403 without details');
[$code,$html]=requestDetails(['delivery'=>(string)$id],'delivery_driver',4);verify($code===404,'controller rejects cross-driver assignment');
[$code,$html]=requestDetails(['delivery'=>(string)$id],'delivery_driver',3,'POST');verify($code===405,'POST cannot mutate detail view');
[$code,$html]=requestDetails(['delivery'=>(string)$id,'contact'=>'navigate']);verify($code===409&&!str_contains($html,'https://www.google.com'),'local navigation cannot open practice customer destination');
echo "TOTAL $checks checks passed\n";ob_end_flush();
