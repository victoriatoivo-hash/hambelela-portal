<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
if(current_role_key()!=='delivery_driver'){http_response_code(403);exit('Driver access required.');}
require_once __DIR__.'/dashboard/Data.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$local=defined('DRIVER_OFFLINE_LAB')&&DRIVER_OFFLINE_LAB;
$base=rtrim(BASE_URL,'/');
try{$dashboard=\Hambelela\Delivery\Dashboard\Data::read(db(),(int)(current_user()['id']??0));}
catch(DomainException $e){http_response_code(403);exit('Active driver account required.');}
catch(Throwable $e){error_log('Driver dashboard read failed: '.get_class($e));http_response_code(503);$dashboard=['dataStatus'=>'error'];}
require __DIR__.'/dashboard/View.php';
