<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/ProofContext.php';require_once __DIR__.'/ProofStorage.php';require_once __DIR__.'/DeliveryService.php';require_once __DIR__.'/FrontSession.php';
use Hambelela\Delivery\{ProofContext,ProofStorage,ProofSubmission,ProofPolicy,EmployeeDriverSession,FrontSession,DeliveryPolicy,DeliveryService,OrderBalanceAdapter,NotificationOutbox,WooShippingSource};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');$ctx=null;$error=null;$assignmentId=0;$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$files=[];$storage=null;$kept=[];
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){http_response_code(405);throw new DomainException('Method not allowed.');}
    if(!is_string($_GET['proof']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['proof']))throw new DomainException('Delivery unavailable.');
    $assignmentId=(int)$_GET['proof'];$driver=current_role_key()==='delivery_driver';
    $actor=$driver?EmployeeDriverSession::actor(db(),(int)current_user()['id']):FrontSession::actor(db(),(int)current_user()['id']);
    $job=ProofContext::row(db(),'SELECT * FROM delivery_jobs WHERE id=?',[$assignmentId]);
    if(!$job||!DeliveryPolicy::canView($actor,$job))throw new DomainException('Delivery unavailable or access removed.');
    if(isset($_GET['evidence'])){
        if($isPost)throw new DomainException('Evidence is read-only.');
        $receipt=ProofContext::receipt(db(),$assignmentId);$found=null;
        foreach($receipt['evidence']??[] as $file)if(is_string($_GET['evidence'])&&hash_equals($file['id'],$_GET['evidence']))$found=$file;
        if(!$found)throw new DomainException('Evidence unavailable.');
        $path=ProofStorage::configured()->path($found['id']);
        if(!is_file($path)||!hash_equals($found['stored_sha256'],hash_file('sha256',$path)))throw new DomainException('Evidence unavailable. Contact dispatch.');
        header('Content-Type: image/png');header('Content-Disposition: inline; filename="delivery-proof.png"');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($path);return;
    }
    if(!$driver){
        if($isPost)throw new DomainException('Only the assigned driver can submit proof.');
        $ctx=['job'=>$job,'reference'=>$job['public_reference'],'name'=>$job['customer_name'],'receipt'=>ProofContext::receipt(db(),$assignmentId),'reason'=>'Evidence review only.','version'=>(int)$job['version'],'arrival'=>null,'payment_verified'=>false,'payment_label'=>'See Order payment records','due'=>null,'policy'=>ProofPolicy::configured()];
    }elseif($isPost){
        EmployeeDriverSession::csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
        foreach($_POST as $value)if(!is_string($value))throw new DomainException('Invalid form value.');
        $uuid=$_POST['request_id']??'';$version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);
        if($version===false||$version===null)throw new DomainException('Refresh the delivery details.');
        $shipping=null;
        if(!$local){require_once BASE_PATH.'/shared/woocommerce.php';require_once __DIR__.'/WooShippingSource.php';$shipping=WooShippingSource::forJob(db(),$actor,$assignmentId,'complete',static fn($path)=>wc_get($path,[],8));}
        $service=new DeliveryService(db(),[],static function($db,$j)use($shipping){if($shipping)WooShippingSource::assertLockedJob($db,$j,$shipping);return (new OrderBalanceAdapter())->read($db,$j);});
        if(($_POST['action']??'')==='arrive'){
            $service->arrive($actor,$assignmentId,$uuid,$version);$result=['arrival_recorded'=>true,'context'=>ProofContext::read(db(),$actor['id'],$assignmentId)];
            unset($result['context']['job']);
        }elseif(($_POST['action']??'')==='complete'){
            if(!ProofPolicy::enabled())throw new DomainException('Owner approval of the evidence policy is pending. Completion is not enabled.');
            $storage=ProofStorage::configured();
            foreach(['photo','signature'] as $kind)if(isset($_FILES[$kind])&&($_FILES[$kind]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$files[$kind]=$storage->prepare($_FILES[$kind],$kind);
            $proof=new ProofSubmission($_POST,$files,$version);
            $result=$service->complete($actor,$assignmentId,$uuid,null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid','note'=>''],$proof);
            $kept=array_column($result['proof']['evidence'],'id');
            // Commit succeeded. Queue status is truthful even if asynchronous delivery needs retry.
            NotificationOutbox::attempt(db());
        }else throw new DomainException('Unknown proof action.');
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);return;
    }else $ctx=ProofContext::read(db(),$actor['id'],$assignmentId);
}catch(DomainException $e){http_response_code(409);$error=$e->getMessage();}
catch(Throwable $e){error_log('Proof of delivery failed: '.get_class($e));http_response_code(503);$error='Proof could not be confirmed. Your form is retained; retry safely or contact dispatch.';}
finally{if($storage)$storage->discard(array_filter($files,static fn($file)=>!in_array($file['id'],$kept,true)));}
if($isPost){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error]);return;}
require __DIR__.'/proof/Helpers.php';require __DIR__.'/proof/View.php';
