<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/acknowledgments/integration.php';
date_default_timezone_set('Africa/Windhoek');
$health=dirname(BASE_PATH).'/.portal-ack-scheduler-health.json';
function ack_worker_health(string $path,string $status):void{
    $tmp=$path.'.'.getmypid();
    if(file_put_contents($tmp,json_encode(['status'=>$status,'checked_at'=>date(DATE_ATOM)]),LOCK_EX)!==false){chmod($tmp,0600);rename($tmp,$path);}
}
try{
    $db=db();
    $lock=$db->query("SELECT GET_LOCK('portal_ack_scheduled_release',0)")->fetchColumn();
    if(!(int)$lock)exit(0);
    try{acknowledgments_deliver();}finally{$db->query("SELECT RELEASE_LOCK('portal_ack_scheduled_release')");}
    ack_worker_health($health,'success');
    echo "Acknowledgment scheduled release complete\n";
}catch(Throwable $e){ack_worker_health($health,'failed');error_log('Acknowledgment scheduled release failed: '.$e->getMessage());exit(1);}
