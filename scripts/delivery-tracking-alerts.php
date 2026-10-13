<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/delivery/TrackingAlerts.php';require_once BASE_PATH.'/shared/delivery/TrackingConfiguration.php';
$lock=fopen(sys_get_temp_dir().'/hambelela-gps-alerts-'.hash('sha256',BASE_PATH).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{(new \Hambelela\Delivery\TrackingAlerts(db()))->check((new \Hambelela\Delivery\TrackingConfiguration())->read()['enabled']);}
finally{flock($lock,LOCK_UN);fclose($lock);}
