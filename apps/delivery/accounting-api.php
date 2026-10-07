<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);echo '{"error":"Delivery is not enabled."}';exit;}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
require_once BASE_PATH.'/shared/delivery/AccountingService.php';
try{
 $actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));
 $service=new \Hambelela\Delivery\AccountingService(db());
 $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $view=(string)($_GET['view']??'report');
  if($view==='zones')$result=['zones'=>$service->zones($actor)];
  elseif($view==='report')$result=$service->report($actor,(string)($_GET['from']??date('Y-m-01')),(string)($_GET['to']??date('Y-m-d')));
  else throw new DomainException('Unknown report.');
  echo json_encode($result+['csrf'=>$_SESSION['delivery_front_csrf']],JSON_THROW_ON_ERROR);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
 $b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b))throw new DomainException('Invalid request.');
 $uuid=(string)($b['uuid']??'');
 switch($b['action']??''){
  case 'expense':$result=$service->expense($actor,$uuid,$b);break;
  case 'void_expense':$result=$service->voidExpense($actor,$uuid,(int)($b['id']??0),(string)($b['reason']??''));break;
  case 'zone':$result=$service->saveZone($actor,$uuid,$b);break;
  case 'partner_settlement':$result=$service->partnerSettlement($actor,$uuid,(int)($b['partner_id']??0),(string)($b['type']??''),(string)($b['amount']??''),(string)($b['reference']??''),(string)($b['reason']??''));break;
  default:throw new DomainException('Unknown accounting action.');
 }
 echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Delivery accounting failed: '.get_class($e));http_response_code(503);echo '{"error":"Accounting is temporarily unavailable. Your request can be retried safely."}';}
