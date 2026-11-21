<?php
declare(strict_types=1);
if(!defined('BASE_PATH'))exit;
require_once __DIR__.'/DriverNotificationsData.php';
use Hambelela\Delivery\{DriverNotificationsData,EmployeeDriverSession};
header('Cache-Control: private, no-store, no-cache, max-age=0');header('Vary: Cookie');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'");
$method=$_SERVER['REQUEST_METHOD']??'GET';$noticeError=null;$authorized=false;
try {
    $actor=EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));$authorized=true;
    if(array_diff(array_keys($_GET),['notices']))throw new DomainException('Unsupported request.',403);
    $_SESSION['driver_notifications_key']??=bin2hex(random_bytes(32));$_SESSION['csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        try{EmployeeDriverSession::csrf((string)($_POST['csrf']??''));}catch(DomainException $e){throw new DomainException('Session verification failed. Reload.',403);}
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>2000)throw new DomainException('Invalid request.');
        $p=json_decode($_POST['payload'],true,8,JSON_THROW_ON_ERROR);
        if(!is_array($p)||(int)($p['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload.',403);
        $action=$p['action']??'';$allowed=match($action){'list'=>['filter','cursor'],'open'=>['id'],'read_all'=>['token'],'preferences'=>[],'sound'=>['enabled'],default=>throw new DomainException('Method not allowed.',405)};
        if(array_diff(array_keys($p),array_merge(['actor','action'],$allowed)))throw new DomainException('Unsupported fields.',403);
        $svc=new DriverNotificationsData(db(),$_SESSION['driver_notifications_key']);$id=$actor['id'];
        if($action==='list')$result=$svc->page($id,(string)($p['filter']??'all'),(string)($p['cursor']??''));
        elseif($action==='open'){if(!is_int($p['id']??null))throw new DomainException('Invalid notification.');$result=$svc->open($id,$p['id']);}
        elseif($action==='read_all'){$svc->markAll($id,(string)($p['token']??''));$result=[];}
        elseif($action==='sound'){if(!is_bool($p['enabled']??null))throw new DomainException('Invalid preference.');$result=$svc->sound($id,$p['enabled']);}
        else $result=$svc->preferences($id);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$id,'data'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if($method!=='GET')throw new DomainException('Method not allowed.',405);
}catch(DomainException $e){http_response_code(in_array($e->getCode(),[403,404,405])?$e->getCode():($authorized?409:403));$noticeError=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Driver notifications unavailable: '.get_class($e));$noticeError='Notifications unavailable. No action was confirmed. Retry.';}
if($noticeError){if($method!=='GET'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$noticeError]);}else echo htmlspecialchars($noticeError,ENT_QUOTES,'UTF-8');return;}
require __DIR__.'/notifications/View.php';
