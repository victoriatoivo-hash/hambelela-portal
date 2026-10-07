<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));}catch(DomainException $e){http_response_code(403);exit('Delivery access denied.');}
header('Cache-Control: no-store');$driverPage=false;$endpoint='front-api.php';
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin();
require BASE_PATH.'/shared/delivery/CoreView.php';
