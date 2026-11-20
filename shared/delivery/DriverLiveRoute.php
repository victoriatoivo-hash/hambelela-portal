<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');
$feed=($_GET['view']??'')==='route-data';$driverId=(int)(current_user()['id']??0);$data=null;$error=null;
require_once __DIR__.'/DriverLiveRouteData.php';
try{
    if(current_role_key()!=='delivery_driver')throw new DomainException();
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);throw new LogicException();}
    // Date is current business day; neither query-string driver IDs nor route IDs select a driver.
    $today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
    if(isset($_GET['date'])&&(!is_string($_GET['date'])||$_GET['date']!==$today)){http_response_code(422);throw new InvalidArgumentException();}
    $data=\Hambelela\Delivery\DriverLiveRouteData::read(db(),$driverId);
    if(isset($_GET['action'])){http_response_code(409);throw new UnexpectedValueException();}
}catch(DomainException $e){http_response_code(403);$error='Your active driver access could not be confirmed. Please sign in again.';}
catch(InvalidArgumentException $e){$error='This screen shows the current Windhoek business day. Return to today’s route.';}
catch(UnexpectedValueException $e){$error='No dispatcher-approved next stop is recorded. Open Deliveries or contact dispatch.';}
catch(LogicException $e){$error='Route changes are not enabled. No assignment or stop order was changed.';}
catch(Throwable $e){error_log('Driver route read failed: '.get_class($e));http_response_code(503);$error='Your assigned stops are temporarily unavailable. Please retry.';}
if($error)$data=null;
require __DIR__.'/live-route/Helpers.php';
if($feed){
    ob_start();require __DIR__.'/live-route/Content.php';$html=ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data_status'=>$error?'error':$data['data_status'],'driver_id'=>$driverId,'route'=>$data,'html'=>$html],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return;
}
require __DIR__.'/live-route/View.php';
