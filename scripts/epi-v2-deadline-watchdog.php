<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/shared/database.php';
require_once dirname(__DIR__).'/shared/epi/bootstrap.php';
date_default_timezone_set('Africa/Windhoek');
try{
    $db=db();$db->exec("SET time_zone='+02:00'");
    if(in_array('--health',$argv,true))$result=(new \Hambelela\EPI\V2PerformanceQuery($db))->watchdogHealth();
    else $result=\Hambelela\EPI\V2Watchdog::run($db);
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(($result['status']??'')==='failed'||!empty($result['unhealthy'])?1:0);
}catch(Throwable $error){fwrite(STDERR,'EPI V2 watchdog failed: '.$error->getMessage().PHP_EOL);exit(1);}
