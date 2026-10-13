<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/delivery/TrackingService.php';
require_once BASE_PATH.'/shared/delivery/TrackingConfiguration.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
// Deployment must explicitly enable collection after migration and validation.
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>131072){http_response_code(413);exit;}
try{
 $raw=file_get_contents('php://input',false,null,0,131073);if(strlen($raw)>131072){http_response_code(413);exit;}
 $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($body))throw new DomainException('Invalid request.');
 if(!(new \Hambelela\Delivery\TrackingConfiguration())->read()['enabled']){
  if(($body['action']??'')==='ingest'){echo json_encode(['paused'=>true,'server_time'=>time(),'authorized_window'=>null]);exit;}
  http_response_code(422);echo '{"error":"Owner must enable tracking before pairing."}';exit;
 }
 $service=new \Hambelela\Delivery\TrackingService(db());$id=(string)($body['device_id']??'');
 if(($body['action']??'')==='pair')$result=$service->pair($id,(string)($body['pairing_code']??''));
 elseif(($body['action']??'')==='ingest'){
  $authorization=isset($_SERVER['HTTP_X_TRACKING_TOKEN'])?'Bearer '.(string)$_SERVER['HTTP_X_TRACKING_TOKEN']:(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
  if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match))throw new DomainException('Device authorization failed.');
  $result=$service->ingest($id,$match[1],$body);
 }else throw new DomainException('Invalid action.');
 echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){
 // Never log device credentials or coordinates. Flood/retry responses must not
 // revoke a correctly paired phone's local credentials.
 $message=$e->getMessage();$auth=in_array($message,['Pairing unavailable.','Device authorization failed.','Choose an existing active Driver employee.'],true);
 http_response_code($auth?403:(strpos($message,'five seconds')!==false?429:422));echo json_encode(['error'=>$message]);
}catch(Throwable $e){http_response_code(503);echo '{"error":"Tracking is temporarily unavailable. Keep queued points and retry."}';}
