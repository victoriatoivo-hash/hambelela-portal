<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';header('Content-Type: application/json');
try{if(empty($_SESSION['partner']))throw new DomainException('Please sign in.');$actor=$partnerAuth->actor($_SESSION['partner']);}catch(DomainException $e){http_response_code(401);echo '{"error":"Please sign in."}';exit;}
try{$service=new \Hambelela\Delivery\PartnerService(db());
 if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode($service->board($actor)+['csrf'=>$_SESSION['csrf'],'permissions'=>['create'=>\Hambelela\Delivery\PartnerAccess::can($actor,'create'),'edit'=>\Hambelela\Delivery\PartnerAccess::can($actor,'edit')]],JSON_THROW_ON_ERROR);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 \Hambelela\Delivery\PartnerAuth::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));$b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($b)||($b['action']??'')!=='arrange')throw new DomainException('Unsupported Partner action.');$result=$service->arrange($actor,(string)($b['uuid']??''),$b);\Hambelela\Delivery\NotificationOutbox::attempt(db());echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Partner Delivery failed: '.get_class($e));http_response_code(503);echo '{"error":"Delivery is temporarily unavailable. Retry safely."}';}
