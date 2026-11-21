<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/DeliveryService.php';require_once __DIR__.'/ProofStorage.php';require_once __DIR__.'/IssueContext.php';
use Hambelela\Delivery\{DeliveryService,DeliveryIssueState,IssueContext,ProofStorage,EmployeeDriverSession,FrontSession,DeliveryPolicy,NotificationOutbox};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$reference=false;$base=rtrim(BASE_URL,'/');$c=null;$error=null;$assignmentId=0;$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$photo=null;$storage=null;$kept=[];
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))throw new DomainException('Method not allowed.');
    if(!is_string($_GET['issue']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['issue']))throw new DomainException('Delivery unavailable.');
    $assignmentId=(int)$_GET['issue'];$driver=current_role_key()==='delivery_driver';
    $actor=$driver?EmployeeDriverSession::actor(db(),(int)current_user()['id']):FrontSession::actor(db(),(int)current_user()['id']);
    $job=DeliveryIssueState::row(db(),'SELECT * FROM delivery_jobs WHERE id=?',[$assignmentId]);
    if(!$job||!DeliveryPolicy::canView($actor,$job))throw new DomainException('Delivery unavailable or access removed.');
    if(isset($_GET['evidence'])){
        if($isPost||!is_string($_GET['evidence']))throw new DomainException('Evidence is read-only.');
        $q=db()->prepare("SELECT metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='delivery_issue_reported'");$q->execute([$assignmentId]);$found=null;
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json)foreach(json_decode($json,true)['evidence']??[] as $file)if(hash_equals($file['id'],$_GET['evidence']))$found=$file;
        if(!$found)throw new DomainException('Supporting photo unavailable.');
        $path=ProofStorage::configured()->path($found['id']);if(!is_file($path)||!hash_equals($found['stored_sha256'],hash_file('sha256',$path)))throw new DomainException('Supporting photo unavailable.');
        header('Content-Type: image/png');header('Content-Disposition: inline; filename="delivery-issue.png"');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($path);return;
    }
    if($isPost){
        foreach($_POST as $v)if(!is_string($v))throw new DomainException('Invalid form value.');
        if($driver)EmployeeDriverSession::csrf($_POST['csrf']??'');else FrontSession::csrf($_POST['csrf']??'');
        $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);if($version===false||$version===null||$version<0)throw new DomainException('Refresh the delivery details.');
        $service=new DeliveryService(db(),[],static function(){throw new LogicException('Issue reporting never reads or changes money.');});
        if(($_POST['action']??'')==='report'&&$driver){
            DeliveryIssueState::report($_POST);
            if(isset($_FILES['photo'])&&($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$storage=ProofStorage::configured();$photo=$storage->prepare($_FILES['photo'],'photo');}
            $result=$service->reportIssue($actor,$assignmentId,$_POST['request_id']??'',$version,$_POST,$photo);$kept=array_column($result['evidence'],'id');
        }elseif(($_POST['action']??'')==='decide'&&!$driver)$result=$service->decideIssue($actor,$assignmentId,$_POST['request_id']??'',$version,$_POST);
        else throw new DomainException('This action is not permitted.');
        NotificationOutbox::attempt(db());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if(empty($_SESSION['delivery_front_csrf']))$_SESSION['delivery_front_csrf']=bin2hex(random_bytes(32));
    $c=IssueContext::read(db(),$actor,$job);
    if($driver&&($_GET['new']??'')==='1'&&empty($c['blocked'])&&empty($c['incident']['blocking']))$c['incident']=null;
}catch(DomainException $e){http_response_code(409);$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('Delivery issue action failed: '.get_class($e));$error='No confirmation was received. Keep this screen open and retry, or contact dispatch.';}
finally{if($storage&&$photo&&!in_array($photo['id'],$kept,true))$storage->discard([$photo]);}
if($isPost){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error]);return;}
require __DIR__.'/issues/Helpers.php';require __DIR__.'/issues/View.php';
