<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
$driverPage=current_role_key()==='delivery_driver';
require_once BASE_PATH.'/shared/delivery/EmployeeDriverSession.php';
try{$deliveryActor=$driverPage?\Hambelela\Delivery\EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0)):\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));}catch(DomainException $e){http_response_code(403);exit('Delivery access denied.');}
header('Cache-Control: no-store');$endpoint=BASE_URL.'/apps/delivery/'.($driverPage?'driver/api.php':'front-api.php');
if(isset($_GET['profile'])){require BASE_PATH.'/shared/delivery/DriverProfile.php';exit;}
if(isset($_GET['history'])){require BASE_PATH.'/shared/delivery/DriverDeliveryHistory.php';exit;}
if(isset($_GET['cash'])){require BASE_PATH.'/shared/delivery/DriverCashHandover.php';exit;}
if(isset($_GET['dispatch'])){require BASE_PATH.'/shared/delivery/DriverDispatchMessages.php';exit;}
if(isset($_GET['proof'])){require BASE_PATH.'/shared/delivery/DriverProofOfDelivery.php';exit;}
if(isset($_GET['issue'])){require BASE_PATH.'/shared/delivery/DriverDeliveryIssue.php';exit;}
if(isset($_GET['errands'])){require BASE_PATH.'/shared/delivery/ErrandView.php';exit;}
if($driverPage){require BASE_PATH.'/shared/delivery/DriverDeliveries.php';exit;}
require_once BASE_PATH.'/shared/delivery/ErrandLanding.php';
\Hambelela\Delivery\ErrandLanding::enforce($deliveryActor);
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin(true, $driverPage);
require BASE_PATH.'/shared/delivery/'.($driverPage?'CoreView.php':'WorkspaceView.php');
