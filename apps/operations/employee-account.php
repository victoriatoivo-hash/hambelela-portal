<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';require_login();
require_once BASE_PATH.'/shared/hr-access.php';require_once BASE_PATH.'/shared/employee-account-editor.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try{
 $owner=(int)(current_user()['id']??0);$service=new EmployeeAccountEditor(db(),ops_hr_db());$service->owner($owner);
 if($_SERVER['REQUEST_METHOD']==='GET')$result=$service->load($owner,(int)($_GET['id']??0));
 elseif($_SERVER['REQUEST_METHOD']==='POST'){
  $b=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($b)||empty($_SESSION['settings_csrf_token'])||!hash_equals($_SESSION['settings_csrf_token'],(string)($b['csrf_token']??'')))throw new DomainException('Session verification failed. Reload Settings.');
  $result=$service->save($owner,(int)($b['id']??0),$b);
 }else{http_response_code(405);exit;}
 echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Employee editor: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Employee changes could not be saved. No partial account edits were committed.']);}
