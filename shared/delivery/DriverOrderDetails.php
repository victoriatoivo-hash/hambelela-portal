<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');
$feed=($_GET['view']??'')==='order-details-data';$driverId=(int)(current_user()['id']??0);$assignmentId=0;$data=null;$error=null;
require_once __DIR__.'/DriverOrderDetailsData.php';require_once __DIR__.'/DriverInbox.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);throw new LogicException('Read-only screen.');}
    if(current_role_key()!=='delivery_driver')throw new DomainException();
    if(!is_string($_GET['delivery']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['delivery']))throw new OutOfBoundsException();
    $assignmentId=(int)$_GET['delivery'];
    $data=\Hambelela\Delivery\DriverOrderDetailsData::read(db(),$driverId,$assignmentId);
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
require __DIR__.'/order-details/Helpers.php';
if($feed){
    ob_start();require __DIR__.'/order-details/Content.php';$html=ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['dataStatus'=>$error?'error':'live','driverId'=>$driverId,'assignmentId'=>$assignmentId,'html'=>$html],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return;
}
require __DIR__.'/order-details/View.php';
