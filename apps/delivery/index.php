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
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin(true, $driverPage);
require BASE_PATH.'/shared/delivery/'.($driverPage?'CoreView.php':'WorkspaceView.php');
