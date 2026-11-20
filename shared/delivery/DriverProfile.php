<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/DriverProfileData.php';
use Hambelela\Delivery\{DriverProfileData,EmployeeDriverSession};
header('Cache-Control: private, no-store, no-cache, max-age=0');header('Pragma: no-cache');header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
$profileError=null;$authorized=false;$method=$_SERVER['REQUEST_METHOD']??'GET';
try{
    $actor=EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));$authorized=true;
    foreach(['driver','employee','employee_id','user_id'] as $key)if(isset($_GET[$key])||isset($_POST[$key]))throw new DomainException('Only your signed-in profile is available.',403);
    $_SESSION['driver_profile_csrf']??=bin2hex(random_bytes(32));$_SESSION['csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['driver_profile_csrf'],$_POST['csrf']))throw new DomainException('Session verification failed. Reload.',403);
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>1000)throw new DomainException('Invalid profile request.');
        $payload=json_decode($_POST['payload'],true,8,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||array_diff(array_keys($payload),['action','actor'])||(int)($payload['actor']??0)!==$actor['id'])throw new DomainException('Account changed or unsupported fields. Reload.',403);
        if(($payload['action']??'')!=='read')throw new DomainException('Profile changes are not enabled. Contact dispatch.',405);
        $result=(new DriverProfileData(db()))->read($actor['id']);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'data'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if($method!=='GET')throw new DomainException('Method not allowed.',405);
}catch(DomainException $e){http_response_code(in_array($e->getCode(),[403,404,405])?$e->getCode():($authorized?409:403));$profileError=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Driver profile unavailable: '.get_class($e));$profileError='Profile unavailable. Retry without changing any records.';}
if($method!=='GET'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$profileError],JSON_THROW_ON_ERROR);return;}
if(!$authorized||$profileError){echo htmlspecialchars($profileError??'Sign in again.',ENT_QUOTES,'UTF-8');return;}
require __DIR__.'/profile/View.php';
