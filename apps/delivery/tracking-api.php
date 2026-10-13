<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/apps/operations/operations.php';
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
require_once BASE_PATH.'/shared/delivery/TrackingService.php';
require_once BASE_PATH.'/shared/delivery/TrackingMaps.php';
require_once BASE_PATH.'/shared/delivery/TrackingConfiguration.php';
require_login();header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 $actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));\Hambelela\Delivery\TrackingPolicy::owner($actor);
 $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));$service=new \Hambelela\Delivery\TrackingService(db());$config=new \Hambelela\Delivery\TrackingConfiguration();
 if($_SERVER['REQUEST_METHOD']==='GET'){
  if(($_GET['view']??'')==='history')$result=$service->history($actor,(string)($_GET['device']??''),(int)($_GET['from']??0),(int)($_GET['to']??0));
  else {$result=$service->dashboard($actor)+['csrf'=>$_SESSION['delivery_front_csrf'],'configuration'=>$config->publicStatus(),'browser_key'=>$config->read()['browser_key']];if(!$result['configuration']['enabled'])foreach($result['devices'] as &$device)$device['tracking_status']='Paused';unset($device);}
 }elseif($_SERVER['REQUEST_METHOD']==='POST'){
  \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
  $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new DomainException('Request too large.');$b=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('Invalid request.');
  $device=(string)($b['device_id']??'');
  switch($b['action']??''){
   case 'register':$result=$service->register($actor,$b);break;
   case 'configure':$result=$service->configure($actor,$device,$b);break;
   case 'pairing':$result=$service->pairing($actor,$device);break;
   case 'ready':$result=$service->confirmReady($actor,$device,$b['checks']??[]);break;
   case 'keys':$result=$config->save($actor,$b);break;
   case 'route':$settings=$config->read();$result=(new \Hambelela\Delivery\TrackingMaps(db(),$settings['enabled']?$settings['server_key']:''))->route($actor,$device,(int)($b['delivery_id']??0));break;
   default:throw new DomainException('Unknown tracking action.');
  }
 }else{http_response_code(405);exit;}
 echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo '{"error":"Driver Tracking is not ready. Check the verified migration and private configuration; no delivery was changed."}';}
