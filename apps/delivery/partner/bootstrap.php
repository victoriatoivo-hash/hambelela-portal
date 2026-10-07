<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/config.php';require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Partner Delivery is not enabled.');}
require_once BASE_PATH.'/shared/database.php';require_once BASE_PATH.'/shared/delivery/PartnerAuth.php';require_once BASE_PATH.'/shared/delivery/PartnerService.php';
try{\Hambelela\Delivery\PartnerAuth::open();}catch(Throwable $e){http_response_code(503);exit('Secure Partner access unavailable.');}
$partnerAuth=new \Hambelela\Delivery\PartnerAuth(db());
