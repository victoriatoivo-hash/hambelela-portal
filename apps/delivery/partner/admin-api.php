<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';require_once BASE_PATH.'/shared/delivery/PartnerAdminService.php';header('Content-Type: application/json');
try{
 if(empty($_SESSION['partner']))throw new DomainException('Please sign in.');$actor=$partnerAuth->actor($_SESSION['partner']);$service=new \Hambelela\Delivery\PartnerAdminService(db());
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $view=(string)($_GET['view']??'');
  if($view==='accounting')$result=$service->accounting($actor);
  elseif($view==='driver'){$q=$_GET;$q['view']=$_GET['state']??'active';$result=$service->driver($actor,$q);}
  else throw new DomainException('Unsupported view.');
 }elseif($_SERVER['REQUEST_METHOD']==='POST'){
  \Hambelela\Delivery\PartnerAuth::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));$b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($b)||($b['action']??'')!=='edit')throw new DomainException('Unsupported action.');$result=$service->edit($actor,$b);
 }else{http_response_code(405);exit;}
 echo json_encode($result+['csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(503);echo '{"error":"Partner workspace temporarily unavailable."}';}
