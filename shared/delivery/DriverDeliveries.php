<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
if(current_role_key()!=='delivery_driver'){http_response_code(403);exit('Driver access required.');}
if(isset($_GET['delivery'])){require __DIR__.'/DriverOrderDetails.php';return;}
require_once __DIR__.'/DriverAssignments.php';
require_once __DIR__.'/DriverInbox.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');
$feed=($_GET['view']??'')==='deliveries-data';$selected=null;$unavailable=false;
$driverId=(int)(current_user()['id']??0);
try{
    $id=$driverId;
    $data=\Hambelela\Delivery\DriverAssignments::read(db(),$id);
    $actor=\Hambelela\Delivery\EmployeeDriverSession::actor(db(),$id);
    $data['unreadNotifications']=\Hambelela\Delivery\DriverInbox::feed(db(),$actor)['counts']['all'];
    if(isset($_GET['delivery'])){
        if(!is_scalar($_GET['delivery'])||!ctype_digit((string)$_GET['delivery']))throw new OutOfBoundsException();
        try{$selected=\Hambelela\Delivery\DriverAssignments::find($data,(int)$_GET['delivery']);}catch(DomainException $e){throw new OutOfBoundsException();}
    }
}catch(OutOfBoundsException $e){http_response_code(404);$unavailable=true;}
catch(DomainException $e){http_response_code(403);$data=['dataStatus'=>'error','error'=>'Active driver access required.'];}
catch(Throwable $e){error_log('Driver deliveries read failed: '.get_class($e));http_response_code(503);$data=['dataStatus'=>'error','error'=>'Deliveries are temporarily unavailable.'];}
if($feed){header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return;}
require __DIR__.'/deliveries/View.php';
