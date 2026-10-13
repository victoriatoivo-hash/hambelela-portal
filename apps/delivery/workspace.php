<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{$deliveryActor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if(!\Hambelela\Delivery\DeliveryPolicy::can($deliveryActor,'delivery_view'))throw new DomainException();}catch(DomainException $e){http_response_code(403);exit('Front Operations access denied.');}
header('Cache-Control: no-store');$driverPage=false;$endpoint='front-api.php';
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin(true, true);
ob_start();
require BASE_PATH.'/shared/delivery/CoreView.php';
$deliveryMarkup=(string)ob_get_clean();
if(($deliveryActor['role']??'')==='owner_admin')$deliveryMarkup=str_replace('<button type="button" class="primary" id="open-order-search">','<a class="secondary" href="back-capture.php">Back-Capture Deliveries</a><button type="button" class="primary" id="open-order-search">',$deliveryMarkup);
echo $deliveryMarkup;
