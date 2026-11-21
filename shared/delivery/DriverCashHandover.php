<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/CashHandover.php';
require_once __DIR__.'/DriverInbox.php';
use Hambelela\Delivery\CashHandover;
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$error=null;$method=$_SERVER['REQUEST_METHOD']??'GET';$json=$method==='POST'||isset($_GET['feed']);$authorized=false;
try{
    $actor=CashHandover::actor(db(),(int)(current_user()['id']??0));$authorized=true;$driver=$actor['role']==='delivery_driver';$cash=new CashHandover(db());
    if(isset($_GET['driver'])||isset($_POST['driver']))throw new DomainException('Driver identity comes from the signed-in account.',403);
    $_SESSION['cash_handover_csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['cash_handover_csrf'],$_POST['csrf']))throw new DomainException('Session verification failed. Reload and retry.',403);
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>24000)throw new DomainException('Invalid cash request.');$p=json_decode($_POST['payload'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($p)||(int)($p['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload before submitting.',403);
        $result=($p['action']??'')==='review'?$cash->review($actor['id'],$p):$cash->command($actor['id'],$p);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'result'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if($method!=='GET')throw new DomainException('Method not allowed.',405);
    if(isset($_GET['feed'])){
        $data=$driver?$cash->summary($actor['id']):['handovers'=>$cash->inbox($actor['id'])];
        if($driver)$data['unread_notifications']=\Hambelela\Delivery\DriverInbox::feed(db(),$actor)['counts']['all'];
        else{$s=db()->prepare('SELECT COUNT(*) FROM notification_recipients WHERE employee_id=? AND read_at IS NULL AND cleared_at IS NULL');$s->execute([$actor['id']]);$data['unread_notifications']=(int)$s->fetchColumn();}
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'data'=>$data],JSON_THROW_ON_ERROR);return;
    }
}catch(DomainException $e){http_response_code(in_array($e->getCode(),[403,405])?$e->getCode():($authorized?409:403));$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Cash handover unavailable: '.get_class($e));$error='Cash handover is unavailable. No confirmation was received. Retry safely with the same request.';}
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error],JSON_THROW_ON_ERROR);return;}
if(!$authorized||$error){echo htmlspecialchars($error??'Sign in again.',ENT_QUOTES,'UTF-8');return;}
require __DIR__.'/cash/View.php';
