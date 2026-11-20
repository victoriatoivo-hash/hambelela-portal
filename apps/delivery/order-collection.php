<?php
declare(strict_types=1);
use Hambelela\Delivery\DeliveryPolicy;
use Hambelela\Delivery\FrontSession;
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);echo '{"error":"Delivery is unavailable."}';exit;}
require_once BASE_PATH.'/apps/operations/operations.php';
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
    if(!portal_role_can_access_feature(current_role_key(),'orders'))throw new DomainException('Orders access required.');
    $db=db();$actor=FrontSession::actor($db,(int)(current_user()['id']??0));
    if(!DeliveryPolicy::can($actor,'delivery_view')&&!DeliveryPolicy::can($actor,'delivery_arrange'))throw new DomainException('Delivery access required.');
    $raw=(string)($_GET['ids']??'');
    if(!preg_match('/^[1-9][0-9]{0,10}(,[1-9][0-9]{0,10}){0,99}$/D',$raw))throw new InvalidArgumentException('Invalid order selection.');
    $ids=array_unique(explode(',',$raw));$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT j.id,j.order_id,j.driver_employee_id,j.status,j.delivered_at,j.public_reference,e.full_name driver_name,
      EXISTS(SELECT 1 FROM delivery_events ev WHERE ev.delivery_id=j.id AND ev.event_type='parcel_collected') picked_up
      FROM delivery_jobs j JOIN ops_orders o ON o.id=j.order_id LEFT JOIN ops_employees e ON e.id=j.driver_employee_id
      WHERE j.source='hambelela' AND j.status<>'cancelled' AND o.deleted_at IS NULL AND o.archived_at IS NULL AND j.order_id IN ($marks) ORDER BY j.id DESC");
    $q->execute(array_values($ids));$jobs=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $j){$jobs[$j['order_id']]=$jobs[$j['order_id']]??$j;}
    $arrange=DeliveryPolicy::can($actor,'delivery_arrange');
    $result=['jobs'=>(object)$jobs,'can_arrange'=>$arrange,'can_reconcile'=>DeliveryPolicy::can($actor,'delivery_reconcile')];
    if(($_GET['review']??'')==='1'&&count($ids)===1&&$arrange){
        $_SESSION['delivery_front_csrf']=$_SESSION['delivery_front_csrf']??bin2hex(random_bytes(32));
        $result['csrf']=$_SESSION['delivery_front_csrf'];
        $result['drivers']=$db->query("SELECT e.id,e.full_name FROM delivery_driver_profiles d JOIN ops_employees e ON e.id=d.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE d.active=1 AND e.status='active' AND r.role_key='delivery_driver' ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
        $job=reset($jobs);
        if($job){
            $limit=30;$before=max(0,(int)($_GET['before']??0));
            $h=$db->prepare('SELECT v.id,v.event_type,v.created_at,v.metadata_json,e.full_name actor FROM delivery_events v LEFT JOIN ops_employees e ON e.id=v.employee_id WHERE v.delivery_id=?'.($before?' AND v.id<?':'').' ORDER BY v.id DESC LIMIT 31');
            $h->execute($before?[$job['id'],$before]:[$job['id']]);$events=$h->fetchAll(PDO::FETCH_ASSOC);
            $result['more']=count($events)>$limit;$result['events']=[];
            foreach(array_slice($events,0,$limit) as $ev){
                $meta=json_decode((string)$ev['metadata_json'],true)?:[];unset($ev['metadata_json']);
                $ev['detail']=array_intersect_key($meta,array_flip(['reason','note','front_cod_due_utc','expected_cents','counted_cents','difference_cents','payment_arrangement']));
                $result['events'][]=$ev;
            }
            $r=$db->prepare("SELECT r.id,r.payment_method,r.status,r.order_goods_cents+r.delivery_fee_cents+r.partner_cod_cents expected_cents,
              EXISTS(SELECT 1 FROM delivery_events v WHERE v.delivery_id=r.delivery_id AND v.event_type='cash_handover' AND JSON_UNQUOTE(JSON_EXTRACT(v.metadata_json,'$.receipt_id'))=r.id) counted
              FROM delivery_receipts r WHERE r.delivery_id=? ORDER BY r.id");
            $r->execute([$job['id']]);$result['receipts']=$r->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo '{"error":"Delivery access required."}';}
catch(InvalidArgumentException $e){http_response_code(400);echo '{"error":"Invalid order selection."}';}
catch(Throwable $e){error_log('Order collection read failed: '.$e->getMessage());http_response_code(503);echo '{"error":"Saved delivery status could not be checked."}';}
