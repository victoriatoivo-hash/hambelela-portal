<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;$base=rtrim(BASE_URL,'/');
$feed=($_GET['view']??'')==='route-data';$driverId=(int)(current_user()['id']??0);$data=null;$error=null;$mapEnabled=false;$isPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
require_once __DIR__.'/DriverLiveRouteData.php';
try{
    if(current_role_key()!=='delivery_driver')throw new DomainException();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);throw new LogicException();}
    // Date is current business day; neither query-string driver IDs nor route IDs select a driver.
    $today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
    if(isset($_GET['date'])&&(!is_string($_GET['date'])||$_GET['date']!==$today)){http_response_code(422);throw new InvalidArgumentException();}
    $data=\Hambelela\Delivery\DriverLiveRouteData::read(db(),$driverId);
    require_once __DIR__.'/TrackingConfiguration.php';
    $maps=$local?['enabled'=>false,'browser_key'=>'','server_key'=>'']:(new \Hambelela\Delivery\TrackingConfiguration())->read();
    $mapEnabled=!empty($maps['enabled'])&&!empty($maps['browser_key'])&&!empty($maps['server_key']);
    $data['map']['state']=$mapEnabled?'available':'unavailable';$data['capabilities']['map']=$mapEnabled;$data['capabilities']['locate']=$mapEnabled;
    if($isPost){
        header('Content-Type: application/json; charset=utf-8');
        \Hambelela\Delivery\EmployeeDriverSession::csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
        try{
            if((string)($_POST['actor']??'')!==(string)$driverId)throw new DomainException('Account changed. Reload the route.');
            if(!$mapEnabled)throw new DomainException('Google Maps is unavailable. Open a delivery for external directions or contact dispatch.');
            $action=$_POST['action']??'';
            if($action==='maps_bootstrap')$result=['browser_key'=>$maps['browser_key']];
            elseif($action==='estimate'){
                if(($_POST['location_consent']??'')!=='1')throw new DomainException('Confirm location sharing for this delivery.');
                require_once __DIR__.'/DriverRouteMaps.php';
                $point=json_decode(is_string($_POST['position']??null)?$_POST['position']:'',true,8,JSON_THROW_ON_ERROR);
                if(!is_array($point)||!is_string($_POST['revision']??null))throw new DomainException('Invalid location request.');
                $result=(new \Hambelela\Delivery\DriverRouteMaps(db(),$maps['server_key']))->route($driverId,(int)($_POST['delivery']??0),$point,$_POST['revision']);
            }else throw new DomainException('Route reordering is not enabled.');
            echo json_encode(['ok'=>true,'driver_id'=>$driverId,'result'=>$result],JSON_THROW_ON_ERROR);return;
        }catch(DomainException $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);return;}
    }
    if(isset($_GET['action'])){http_response_code(409);throw new UnexpectedValueException();}
}catch(DomainException $e){http_response_code(403);$error='Your active driver access could not be confirmed. Please sign in again.';}
catch(InvalidArgumentException $e){$error='This screen shows the current Windhoek business day. Return to today’s route.';}
catch(UnexpectedValueException $e){$error='No dispatcher-approved next stop is recorded. Open Deliveries or contact dispatch.';}
catch(LogicException $e){$error='Route changes are not enabled. No assignment or stop order was changed.';}
catch(Throwable $e){error_log('Driver route read failed: '.get_class($e));http_response_code(503);$error='Your assigned stops are temporarily unavailable. Please retry.';}
if($error)$data=null;
if($isPost){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error],JSON_THROW_ON_ERROR);return;}
require __DIR__.'/live-route/Helpers.php';
if($feed){
    ob_start();require __DIR__.'/live-route/Content.php';$html=ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data_status'=>$error?'error':$data['data_status'],'driver_id'=>$driverId,'csrf'=>$_SESSION['csrf']??'','route'=>$data,'html'=>$html],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return;
}
require __DIR__.'/live-route/View.php';
