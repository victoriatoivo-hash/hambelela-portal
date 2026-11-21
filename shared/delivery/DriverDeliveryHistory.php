<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/DeliveryHistory.php';
use Hambelela\Delivery\{DeliveryHistory,EmployeeDriverSession};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$historyError=null;$authorized=false;$method=$_SERVER['REQUEST_METHOD']??'GET';$json=$method==='POST';
try{
    $actor=EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));$authorized=true;$history=new DeliveryHistory(db());
    if(isset($_GET['driver'])||isset($_POST['driver'])||isset($_GET['search']))throw new DomainException('Use the signed-in driver and private search form.',403);
    $_SESSION['driver_history_csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['driver_history_csrf'],$_POST['csrf']))throw new DomainException('Session verification failed. Reload.',403);
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>4000)throw new DomainException('Invalid history request.');
        $p=json_decode($_POST['payload'],true,16,JSON_THROW_ON_ERROR);if(!is_array($p)||isset($p['driver'])||(int)($p['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload.',403);
        if(($p['action']??'')==='list')$result=$history->page($actor['id'],$p);
        elseif(($p['action']??'')==='detail'&&is_int($p['event_id']??null)&&$p['event_id']>0)$result=$history->detail($actor['id'],$p['event_id']);
        else throw new DomainException('History is read-only. Invalid action.',405);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'data'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if($method!=='GET')throw new DomainException('History is read-only.',405);
    if(isset($_GET['evidence'])){
        if(!is_string($_GET['evidence'])||!ctype_digit($_GET['evidence'])||strlen($_GET['evidence'])>15||!in_array($_GET['part']??'', ['0','1'],true))throw new DomainException('Evidence unavailable.',404);
        $path=$history->evidence($actor['id'],(int)$_GET['evidence'],(int)$_GET['part']);header('Content-Type: image/png');header('Content-Disposition: inline; filename="history-evidence.png"');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($path);return;
    }
}catch(DomainException $e){http_response_code(in_array($e->getCode(),[403,404,405])?$e->getCode():($authorized?409:403));$historyError=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Driver history unavailable: '.get_class($e));$historyError='History is unavailable. Retry without changing any records.';}
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$historyError],JSON_THROW_ON_ERROR);return;}
if(!$authorized||$historyError){echo htmlspecialchars($historyError??'Sign in again.',ENT_QUOTES,'UTF-8');return;}
require __DIR__.'/history/View.php';
