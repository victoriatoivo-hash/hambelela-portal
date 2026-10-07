<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/delivery/EmployeeDriverSession.php';
require_once BASE_PATH.'/shared/delivery/CoreApi.php';
require_once BASE_PATH.'/shared/order-payment-methods.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if(DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
if(basename((string)($_SERVER['SCRIPT_NAME']??''))==='api.php'&&!portal_validate_authenticated_session()){
    http_response_code(401);header('Content-Type: application/json');echo '{"error":"Please sign in through the employee login."}';exit;
}
require_login();
$_SESSION['csrf']??=bin2hex(random_bytes(32));
