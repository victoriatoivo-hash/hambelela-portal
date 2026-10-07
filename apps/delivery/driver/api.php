<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(DELIVERY_ENABLED!==true){http_response_code(503);header('Content-Type: application/json');echo '{"error":"Sign-in test only. Delivery actions are not enabled."}';exit;}
require_once BASE_PATH.'/shared/woocommerce.php';
require_once BASE_PATH.'/shared/delivery/DriverNotices.php';
header('Content-Type: application/json');
try{$actor=\Hambelela\Delivery\DriverSession::actor($driverAuth);}catch(Throwable $e){http_response_code(401);echo '{"error":"Please sign in again."}';exit;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'&&($_GET['view']??'')==='notifications'){echo json_encode(['notifications'=>\Hambelela\Delivery\DriverNotices::list(db(),$actor)],JSON_THROW_ON_ERROR);exit;}
 $core=new \Hambelela\Delivery\CoreApi(db(),ops_payment_method_map(),static function(string $path):array{return wc_get($path,[],8);});
if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode($core->board($actor,$_GET)+['csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 \Hambelela\Delivery\DriverSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
 $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
 if(is_array($body)&&($body['action']??'')==='read_notification'){\Hambelela\Delivery\DriverNotices::read(db(),$actor,(int)($body['notification']??0));echo '{"ok":true}';exit;}
 if(!is_array($body)||!in_array($body['action']??'',['start','collect','complete','note','fail'],true))throw new DomainException('Driver action not allowed.');
 $result=$core->command($actor,$body);\Hambelela\Delivery\NotificationOutbox::attempt(db());echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Driver command failed: '.$e->getMessage());http_response_code(503);echo '{"error":"Action not confirmed. Retry safely."}';}
