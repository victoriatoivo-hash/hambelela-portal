<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/config.php';require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Partner Delivery is not enabled.');}
require_once BASE_PATH.'/shared/database.php';require_once BASE_PATH.'/shared/delivery/PartnerAuth.php';require_once BASE_PATH.'/shared/delivery/PartnerService.php';
try{\Hambelela\Delivery\PartnerAuth::open();}catch(Throwable $e){http_response_code(503);exit('Secure Partner access unavailable.');}
$partnerAuth=new \Hambelela\Delivery\PartnerAuth(db());

// A temporary login can only reach password setup or sign out, never data routes.
if(!empty($_SESSION['partner'])&&!in_array(basename($_SERVER['SCRIPT_NAME']??''),['login.php','password.php','logout.php'],true)){
 try{$pending=$partnerAuth->actor($_SESSION['partner'],true);if(!empty($pending['must_change_password'])){header('Location: password.php',true,303);exit;}}catch(\DomainException $e){unset($_SESSION['partner']);}
}
