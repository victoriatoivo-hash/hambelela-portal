<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/DispatchChat.php';
use Hambelela\Delivery\{DispatchChat,DispatchFiles};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$method=$_SERVER['REQUEST_METHOD']??'GET';$json=$method==='POST'||isset($_GET['feed'])||isset($_GET['inbox']);$error=null;$phone=null;$authorized=false;
try {
    $actor=DispatchChat::actor(db(),(int)(current_user()['id']??0));$authorized=true;$chat=new DispatchChat(db());
    $requested=filter_var($_GET['driver']??0,FILTER_VALIDATE_INT);if($requested===false||$requested<0)throw new DomainException('Invalid driver.');$driver=$actor['role']==='delivery_driver';
    if($driver)$chat->driver($actor['id'],$requested); // Even HTML queries must reject impersonation.
    if(!in_array($method,['GET','POST'],true))throw new DomainException('Method not allowed.',405);
    $_SESSION['dispatch_chat_csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['dispatch_chat_csrf'],$_POST['csrf']))throw new DomainException('Session verification failed. Reload and try again.',403);
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>18000)throw new DomainException('Invalid message request.');$p=json_decode($_POST['payload'],true,24,JSON_THROW_ON_ERROR);
        if(!is_array($p)||(int)($p['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload this screen.',403);$result=[];
        switch($p['action']??''){
            case 'send':$f=isset($_FILES['attachment'])&&($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE?$_FILES['attachment']:null;
                if($f){$attempts=array_values(array_filter($_SESSION['dispatch_upload_attempts']??[],static fn($t)=>is_int($t)&&$t>time()-60));if(count($attempts)>=5)throw new DomainException('Please wait a minute before another attachment attempt.');$attempts[]=time();$_SESSION['dispatch_upload_attempts']=$attempts;}
                $result=$chat->send($actor['id'],$requested,$p,$f);break;
            case 'read':case 'delivered':if(!is_array($p['ids']??null))throw new DomainException('Invalid receipt.');$chat->receipt($actor['id'],$requested,$p['ids'],$p['action']);break;
            case 'handle':if(!is_int($p['message']??null)||!is_int($p['assignee']??null))throw new DomainException('Invalid handling request.');$chat->handle($actor['id'],$requested,$p['message'],$p['assignee']);break;
            default:throw new DomainException('Unknown chat action.');
        }header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'result'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if(isset($_GET['attachment'])){
        $id=filter_var($_GET['attachment'],FILTER_VALIDATE_INT);if(!$id||$id<1)throw new DomainException('Invalid attachment.');$f=$chat->attachment($actor['id'],$requested,$id);$path=DispatchFiles::path($f);
        header('Content-Type: '.$f['mime']);header('Content-Disposition: attachment; filename="'.$f['name'].'"');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.filesize($path));readfile($path);return;
    }
    if(isset($_GET['inbox'])){header('Content-Type: application/json');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'threads'=>$chat->inbox($actor['id'])],JSON_THROW_ON_ERROR);return;}
    if(isset($_GET['feed'])){
        foreach(['before','after'] as $k)if(isset($_GET[$k])&&(!ctype_digit((string)$_GET[$k])||strlen((string)$_GET[$k])>15))throw new DomainException('Invalid page reference.');
        $visible=explode(',',(string)($_GET['visible']??''));header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$chat->feed($actor['id'],$requested,(int)($_GET['after']??0),(int)($_GET['before']??0),$visible),JSON_THROW_ON_ERROR);return;
    }
    $phone=DispatchFiles::phone();
}catch(DomainException $e){http_response_code($e->getCode()===405?405:(!$authorized||$method==='GET'||$e->getCode()===403?403:409));$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Dispatch chat unavailable: '.get_class($e));$error='Dispatch chat is unavailable. Keep your draft and retry; no confirmation was received.';}
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error],JSON_THROW_ON_ERROR);return;}
if(!isset($actor)){echo htmlspecialchars($error??'Sign in again.',ENT_QUOTES,'UTF-8');return;}
require __DIR__.'/dispatch/View.php';
