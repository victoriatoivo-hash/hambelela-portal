<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('{"error":"Delivery is not enabled."}');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';require_once BASE_PATH.'/shared/delivery/PaymentReviewService.php';
try{
    $actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));
    $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));
    $service=new \Hambelela\Delivery\PaymentReviewService(db());
    if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode($service->pending($actor)+['csrf'=>$_SESSION['delivery_front_csrf']],JSON_THROW_ON_ERROR);exit;}
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
    \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
    $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($body)||($body['action']??'')!=='keep_verified')throw new DomainException('Unsupported review action.');
    $service->keepVerifiedPortalPayment($actor,(int)($body['id']??0),(string)($body['version']??''),(string)($body['note']??''));
    echo '{"ok":true}';
}catch(DomainException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);echo '{"error":"Invalid review request."}';}
catch(Throwable $e){error_log('Delivery review unavailable: '.$e->getMessage());http_response_code(503);echo '{"error":"Review unavailable. Refresh before retrying."}';}
