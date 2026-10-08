<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
// Disabled until migration, live schema and end-to-end release checks pass.
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);header('Content-Type: application/json');echo '{"error":"Delivery is not enabled."}';exit;}
require_once BASE_PATH.'/apps/operations/operations.php';
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
require_once BASE_PATH.'/shared/delivery/CoreApi.php';
require_once BASE_PATH.'/shared/woocommerce.php';
require_login();
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 $actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));
 $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));
 $core=new \Hambelela\Delivery\CoreApi(db(),ops_payment_method_map(),static function(string $path):array{return wc_get($path,[],8);});
 if($_SERVER['REQUEST_METHOD']==='POST'){
  \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
  $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new DomainException('Invalid request.');
  if(in_array($body['action']??'',['quote','arrange'],true))$body['source_order']=true;
  $result=$core->command($actor,$body);\Hambelela\Delivery\NotificationOutbox::attempt(db());echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
if(($_GET['view']??'')==='board'){echo json_encode($core->board($actor,$_GET)+['csrf'=>$_SESSION['delivery_front_csrf']],JSON_THROW_ON_ERROR);exit;}
 if(!\Hambelela\Delivery\DeliveryPolicy::can($actor,'delivery_arrange'))throw new DomainException('Order search requires arrangement permission.');
 $suggest=($_GET['view']??'')==='suggestions';
 $query=trim((string)($_GET['q']??''));
 if(strlen($query)>80)throw new DomainException('Search text is too long.');
 // Keep the existing-delivery navigation available after completion; do not offer a silent repeat booking.
 require_once BASE_PATH.'/shared/delivery/OrderSuggestions.php';
 $orders=\Hambelela\Delivery\OrderSuggestions::search(db(),$query,$suggest);
 foreach($orders as &$o){$o['shipping_address']=\Hambelela\Delivery\PortalOrderRepository::shippingAddress((string)$o['notes']);$o['customer_instructions']=mb_substr((string)$o['notes'],0,4000);unset($o['notes']);}unset($o);
 $drivers=db()->query("SELECT e.id,e.full_name FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE p.active=1 AND e.status='active' AND r.role_key='delivery_driver' ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
 $zones=db()->query('SELECT id,area,fee_cents FROM delivery_zones WHERE active=1 ORDER BY area')->fetchAll(PDO::FETCH_ASSOC);
 $reviewCount=null;if(\Hambelela\Delivery\DeliveryPolicy::can($actor,'delivery_reconcile'))$reviewCount=(int)db()->query("SELECT COUNT(*) FROM delivery_order_sync_reviews WHERE state='pending'")->fetchColumn();
 echo json_encode(['suggestions'=>$suggest,'csrf'=>$_SESSION['delivery_front_csrf'],'orders'=>$orders,'drivers'=>$drivers,'zones'=>$zones,'pending_payment_reviews'=>$reviewCount],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Delivery Front read failed: '.$e->getMessage());http_response_code(503);echo '{"error":"Delivery data is temporarily unavailable."}';}
