<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');
$feed=($_GET['view']??'')==='order-details-data';$driverId=(int)(current_user()['id']??0);$assignmentId=0;$data=null;$error=null;
require_once __DIR__.'/DriverOrderDetailsData.php';require_once __DIR__.'/DriverInbox.php';
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);throw new LogicException('Method not allowed.');}
    if(current_role_key()!=='delivery_driver')throw new DomainException();
    if(!is_string($_GET['delivery']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['delivery']))throw new OutOfBoundsException();
    $assignmentId=(int)$_GET['delivery'];
    $data=\Hambelela\Delivery\DriverOrderDetailsData::read(db(),$driverId,$assignmentId);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        header('Content-Type: application/json; charset=utf-8');
        \Hambelela\Delivery\EmployeeDriverSession::csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
        $actor=\Hambelela\Delivery\EmployeeDriverSession::actor(db(),$driverId);
        try{
            if((string)($_POST['actor']??'')!==(string)$driverId)throw new DomainException('Account changed. Reload before continuing.');
            $action=$_POST['action']??null;
            if(!is_string($action)||!in_array($action,['accept','confirm_pickup','start'],true))throw new DomainException('Unsupported delivery action.');
            if($action==='start'&&($_POST['parcels_received']??'')!=='1')throw new DomainException('Confirm that you have received the parcels and are leaving.');
            require_once __DIR__.'/CoreApi.php';
            $source=null;
            if(!$local){require_once BASE_PATH.'/shared/woocommerce.php';$source=static fn($path)=>wc_get($path,[],8);}
            $methods=function_exists('ops_payment_method_map')?ops_payment_method_map():['Cash'=>'Cash','EFT'=>'EFT'];
            $result=(new \Hambelela\Delivery\CoreApi(db(),$methods,$source))->command($actor,['id'=>$assignmentId,'action'=>$action,'parcels_received'=>$action==='start','uuid'=>is_string($_POST['request_id']??null)?$_POST['request_id']:'']);
            \Hambelela\Delivery\NotificationOutbox::attempt(db());
            echo json_encode(['ok'=>true,'driverId'=>$driverId,'assignmentId'=>$assignmentId,'result'=>$result],JSON_THROW_ON_ERROR);return;
        }catch(DomainException $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);return;}
    }
    try{$data['unreadNotifications']=\Hambelela\Delivery\DriverInbox::feed(db(),\Hambelela\Delivery\EmployeeDriverSession::actor(db(),$driverId))['counts']['all'];}
    catch(Throwable $e){/* A notification failure must not invent a zero or hide the assignment. */}
    if(isset($_GET['contact'])){
        // Recheck authorization on intentional contact/navigation, not just initial view.
        $action=$_GET['contact'];$target=null;
        if(!$local&&$action==='call'&&$data['customer']['phone'])$target='tel:'.$data['customer']['phone'];
        if(!$local&&$action==='navigate'&&$data['delivery']['navigationAvailable'])$target='https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($data['delivery']['address']['street']);
        if(!$target){http_response_code(409);$error='This contact or navigation action is unavailable. Please contact dispatch.';}
        else{header('Location: '.$target, true,302);return;}
    }
}catch(OutOfBoundsException $e){http_response_code(404);$error='This delivery is no longer in your current assignments. Return to Deliveries or contact dispatch.';}
catch(DomainException $e){http_response_code(403);$error='Your active driver access could not be confirmed. Please sign in again.';}
catch(LogicException $e){$error='This screen is read-only. Return to Deliveries.';}
catch(Throwable $e){error_log('Driver order details read failed: '.get_class($e));http_response_code(503);$error='Order details are temporarily unavailable. Please retry.';}
if($error)$data=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error],JSON_THROW_ON_ERROR);return;}
require __DIR__.'/order-details/Helpers.php';
if($feed){
    ob_start();require __DIR__.'/order-details/Content.php';$html=ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['dataStatus'=>$error?'error':'live','driverId'=>$driverId,'assignmentId'=>$assignmentId,'html'=>$html],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return;
}
require __DIR__.'/order-details/View.php';
