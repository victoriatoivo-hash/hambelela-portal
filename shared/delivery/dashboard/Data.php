<?php
declare(strict_types=1);
namespace Hambelela\Delivery\Dashboard;
require_once BASE_PATH.'/shared/delivery/EmployeeDriverSession.php';
require_once BASE_PATH.'/shared/delivery/DriverInbox.php';
require_once BASE_PATH.'/shared/delivery/DriverErrands.php';
require_once BASE_PATH.'/shared/delivery/DeliveryDeadline.php';
require_once BASE_PATH.'/shared/delivery/OrderBalanceAdapter.php';
require_once BASE_PATH.'/shared/delivery/DriverAssignments.php';
final class Data {
 public static function read(\PDO $db,int $id):array {
  $actor=\Hambelela\Delivery\EmployeeDriverSession::actor($db,$id);
  $date=new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek'));
  $s=$db->prepare('SELECT full_name FROM ops_employees WHERE id=?');$s->execute([$id]);$name=trim((string)$s->fetchColumn());
  $assigned=\Hambelela\Delivery\DriverAssignments::read($db,$id,$date);
  $p=['assigned'=>$assigned['counts']['all'],'delivered'=>$assigned['counts']['done'],'remaining'=>$assigned['counts']['all']-$assigned['counts']['done']];
  $j=null;foreach($assigned['deliveries'] as $candidate){if(in_array($candidate['stage'],['to_collect','on_route'],true)){$j=$candidate;break;}}$next=null;
  if($j){
   $s=$db->prepare('SELECT address FROM delivery_jobs WHERE id=? AND driver_employee_id=?');$s->execute([$j['assignmentId'],$id]);$address=trim((string)$s->fetchColumn());
   $nav=mb_strlen($address)>=5&&!preg_match('/[\x00-\x1f]/',$address)?'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address):null;
   $next=['id'=>$j['assignmentId'],'orderNumber'=>$j['orderNumber'],'statusLabel'=>$j['displayStatus'],'destinationArea'=>$j['deliveryArea'],'address'=>$address,'customerDisplayName'=>$j['customerDisplayName'],'itemCount'=>$j['itemCount'],'paymentLabel'=>$j['paymentLabel'],'deliveryWindow'=>$j['scheduledTimeLocal']??'No specific time','distanceKm'=>null,'navigationUrl'=>$nav,'isNextStop'=>$j['isNextStop']];
  }
  $s=$db->prepare("SELECT COALESCE(SUM(r.order_goods_cents+r.delivery_fee_cents+r.partner_cod_cents),0) FROM delivery_receipts r WHERE r.collected_by_employee_id=? AND LOWER(r.payment_method)='cash' AND r.status='collected' AND NOT EXISTS(SELECT 1 FROM delivery_events e WHERE e.delivery_id=r.delivery_id AND e.event_type='cash_handover' AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.receipt_id'))=CAST(r.id AS CHAR))");
  $s->execute([$id]);$cash=(int)$s->fetchColumn();
  $inbox=\Hambelela\Delivery\DriverInbox::feed($db,$actor);
  return ['driver'=>['id'=>$id,'firstName'=>preg_split('/\s+/u',$name)[0]?:'Driver','initials'=>mb_strtoupper(mb_substr($name,0,1)),'dutyState'=>'unknown'],'businessDate'=>$date->format('Y-m-d'),'dateLabel'=>$date->format('l, j F'),'greeting'=>(int)$date->format('G')<12?'Good morning':((int)$date->format('G')<18?'Good afternoon':'Good evening'),'cityLabel'=>'Windhoek','progress'=>$p,'nextDelivery'=>$next,'quickActions'=>['deliveriesToday'=>$p['assigned'],'openErrands'=>(new \Hambelela\Delivery\DriverErrands($db))->countPending($actor),'cashDueNad'=>$cash/100,'unreadDispatch'=>null],'unreadNotifications'=>(int)$inbox['counts']['all'],'dataStatus'=>$p['assigned']||$next?'live':'empty'];
 }
}
