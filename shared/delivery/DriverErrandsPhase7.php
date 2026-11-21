<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/ErrandWorkflow.php';
use Hambelela\Delivery\{ErrandData,ErrandWorkflow,ProofStorage};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$base=rtrim(BASE_URL,'/');$actor=null;$error=null;$method=$_SERVER['REQUEST_METHOD']??'GET';$json=$method==='POST'||isset($_GET['feed']);$storage=null;$files=[];$kept=[];
try{
    $actor=ErrandData::actor(db(),(int)(current_user()['id']??0));
}catch(Throwable $e){http_response_code(403);if($json){header('Content-Type: application/json');echo '{"ok":false,"error":"Your account no longer has access. Sign in again."}';}else echo 'Errand access unavailable.';return;}
try{
    if(!in_array($method,['GET','POST'],true)){http_response_code(405);throw new DomainException('Method not allowed.');}
    if(isset($_GET['evidence'])){
        if($method!=='GET'||!is_string($_GET['evidence']))throw new DomainException('Evidence is read-only.');
        $id=filter_var($_GET['errand']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)throw new DomainException('Errand unavailable.');ErrandData::authorized(db(),$actor['id'],$id);
        $found=null;foreach(ErrandData::events(db(),$id) as $event)foreach($event['data']['evidence']??[] as $f)if(hash_equals($f['id'],$_GET['evidence']))$found=$f;
        if(!$found)throw new DomainException('Evidence unavailable.');$path=ProofStorage::configured()->path($found['id']);if(!is_file($path)||!hash_equals($found['stored_sha256'],hash_file('sha256',$path)))throw new DomainException('Evidence unavailable.');
        header('Content-Type: image/png');header('Content-Disposition: inline; filename="errand-evidence.png"');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($path);return;
    }
    $_SESSION['delivery_errand_csrf']??=bin2hex(random_bytes(32));
    if($method==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['delivery_errand_csrf'],$_POST['csrf']))throw new DomainException('Session verification failed. Reload before trying again.');
        if(!is_string($_POST['payload']??null)||strlen($_POST['payload'])>60000)throw new DomainException('Invalid form.');
        $p=json_decode($_POST['payload'],true,32,JSON_THROW_ON_ERROR);if(!is_array($p)||!is_array($p['values']??null)||!is_string($p['action']??null)||!is_string($p['uuid']??null)||!is_int($p['id']??null)||!is_int($p['version']??null)||$p['id']<0||$p['version']<0)throw new DomainException('Invalid form values.');
        if((int)($p['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload this screen.');
        foreach(['photo','signature'] as $kind)if(isset($_FILES[$kind])&&($_FILES[$kind]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if(!in_array($p['action'],['complete','issue'],true))throw new DomainException('Evidence upload is not available for this action.');$storage??=ProofStorage::configured();$files[]=$storage->prepare($_FILES[$kind],$kind);}
        $result=(new ErrandWorkflow(db()))->command($actor['id'],$p['uuid'],$p['action'],$p['id'],$p['version'],$p['values'],$files);$kept=array_column($result['evidence'],'id');
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'actor'=>$actor['id'],'result'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if(isset($_GET['feed'])){
        $data=ErrandData::board(db(),$actor['id']);
        // Notification/history links may refer to records outside the current week's board.
        if(isset($_GET['errand'])){$id=filter_var($_GET['errand'],FILTER_VALIDATE_INT);if(!$id||$id<1)throw new DomainException('Errand unavailable.');$data['focus']=ErrandData::detail(db(),$actor['id'],$id);}
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$data,JSON_THROW_ON_ERROR);return;
    }
}catch(DomainException $e){http_response_code($method==='GET'?403:409);$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Phase07 errands unavailable: '.get_class($e));$error='Errands are unavailable. No confirmation was received. Keep your draft and retry, or contact dispatch.';}
finally{if($storage)$storage->discard(array_values(array_filter($files,static fn($f)=>!in_array($f['id'],$kept,true))));}
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error]);return;}
require __DIR__.'/errands/View.php';
