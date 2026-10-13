<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';require_once BASE_PATH.'/shared/database.php';require_once BASE_PATH.'/shared/delivery/TrackingService.php';
$lock=fopen(sys_get_temp_dir().'/hambelela-gps-retention-'.hash('sha256',BASE_PATH).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{(new \Hambelela\Delivery\TrackingService(db()))->prune();}
finally{flock($lock,LOCK_UN);fclose($lock);}
