<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/acknowledgments/integration.php';
date_default_timezone_set('Africa/Windhoek');
try{
    $db=db();
    $lock=$db->query("SELECT GET_LOCK('portal_ack_scheduled_release',0)")->fetchColumn();
    if(!(int)$lock)exit(0);
    try{acknowledgments_deliver();}finally{$db->query("SELECT RELEASE_LOCK('portal_ack_scheduled_release')");}
    echo "Acknowledgment scheduled release complete\n";
}catch(Throwable $e){error_log('Acknowledgment scheduled release failed: '.$e->getMessage());exit(1);}
