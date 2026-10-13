<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{$deliveryActor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if($deliveryActor['role']!=='owner_admin')throw new DomainException();}catch(DomainException $e){http_response_code(403);exit('Owner oversight required.');}
if(!DELIVERY_ENABLED){http_response_code(503);exit('Delivery is not enabled.');}
header('Cache-Control: no-store');
// Same cards as the Driver, but the Owner remains the actor and cannot operate jobs here.
$driverPage=true;$driverOversight=true;$endpoint='front-api.php';
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin(true,true);
require BASE_PATH.'/shared/delivery/CoreView.php';
