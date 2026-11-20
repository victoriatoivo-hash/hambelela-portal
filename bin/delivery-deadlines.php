<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/shared/delivery/DeliveryDeadline.php';
require_once BASE_PATH.'/shared/delivery/DeliveryCodFollowup.php';
if(!DELIVERY_ENABLED)exit;
$db=db();
if(!(int)$db->query("SELECT GET_LOCK('delivery-deadline-alerts',0)")->fetchColumn())exit;
try{
 $cursor=0;
 do{
  $s=$db->prepare("SELECT id FROM delivery_jobs WHERE id>? AND status IN ('ready','out_for_delivery','failed') ORDER BY id LIMIT 200");$s->execute([$cursor]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
  foreach($ids as $id){\Hambelela\Delivery\DeliveryDeadline::notifyLate($db,(int)$id);$cursor=(int)$id;}
 }while(count($ids)===200);
 if(DELIVERY_COD_FOLLOWUP_ENABLED){
  $cursor=0;do{$s=$db->prepare("SELECT DISTINCT j.id FROM delivery_jobs j JOIN delivery_events e ON e.delivery_id=j.id WHERE j.id>? AND j.source='hambelela' AND e.event_type IN ('parcel_collected','started') AND JSON_EXTRACT(e.metadata_json,'$.front_cod_due_utc') IS NOT NULL ORDER BY j.id LIMIT 200");$s->execute([$cursor]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);foreach($ids as $id){\Hambelela\Delivery\DeliveryCodFollowup::check($db,(int)$id);$cursor=(int)$id;}}while(count($ids)===200);
 }
 error_log('Delivery deadline scan completed at '.gmdate('c').' COD='. (DELIVERY_COD_FOLLOWUP_ENABLED?'enabled':'disabled'));
 \Hambelela\Delivery\NotificationOutbox::attempt($db);
}finally{$db->query("SELECT RELEASE_LOCK('delivery-deadline-alerts')");}
