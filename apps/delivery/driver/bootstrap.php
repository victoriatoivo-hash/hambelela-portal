<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/delivery/DriverSession.php';
require_once BASE_PATH.'/shared/delivery/CoreApi.php';
require_once BASE_PATH.'/shared/order-payment-methods.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
// Setup permits authentication checks only; API and index gate operational access separately.
try{\Hambelela\Delivery\DriverSession::open(DELIVERY_ENABLED===true||DELIVERY_SETUP_ENABLED===true);}
catch(Throwable $e){http_response_code(503);exit('Driver Delivery is not available yet.');}
$driverAuth=\Hambelela\Delivery\DriverSession::auth(db());
