<?php
if(!defined('BASE_PATH'))exit;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/DriverInbox.php';
require_once __DIR__.'/ReleaseFlags.php';
if(!DELIVERY_ENABLED){http_response_code(503);exit('Delivery updates unavailable.');}
if(!isset($_GET['driver_feed'])){require __DIR__.'/DriverNotifications.php';return;}
header('Cache-Control: private, no-store');
try{$actor=\Hambelela\Delivery\EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));}catch(Throwable $e){http_response_code(403);exit('Active driver required.');}
if(isset($_GET['driver_feed'])){
 header('Content-Type: application/json; charset=utf-8');
 try{
  if($_SERVER['REQUEST_METHOD']==='POST'){
    \Hambelela\Delivery\EmployeeDriverSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
    if((int)($_POST['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload.');
    $action=$_POST['action']??'';if(!in_array($action,['read','read_all'],true))throw new DomainException('Unknown action.');
    \Hambelela\Delivery\DriverInbox::mark(db(),$actor,(int)($_POST['id']??0),$action==='read_all');echo '{"ok":true}';
  }elseif($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(\Hambelela\Delivery\DriverInbox::feed(db(),$actor,(int)($_GET['before']??0),(string)($_GET['tab']??'all')),JSON_THROW_ON_ERROR);}
  else{http_response_code(405);echo '{"error":"Method not allowed"}';}
 }catch(DomainException $e){http_response_code(403);echo '{"error":"Not authorised. Reload and retry."}';}catch(Throwable $e){error_log('Driver inbox unavailable: '.get_class($e));http_response_code(503);echo '{"error":"Updates unavailable. Please retry."}';}return;
}
