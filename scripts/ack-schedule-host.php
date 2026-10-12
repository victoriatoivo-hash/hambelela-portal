<?php
/** Included only by a short-lived, token-protected release bridge. */
declare(strict_types=1);
if(!defined('ACK_DEPLOY_ROOT')||!defined('ACK_DEPLOY_MODE'))exit;
require ACK_DEPLOY_ROOT.'/config.php';
require_once BASE_PATH.'/shared/database.php';
function ack_host_command(string $command):array{
    $out=[];$status=0;exec($command.' 2>&1',$out,$status);return [$status,implode("\n",$out)];
}
$db=db();
if(!is_callable('exec'))throw new RuntimeException('Hosting disables exec; a cPanel cron must be configured before deployment.');
[$status,$cron]=ack_host_command('crontab -l');
if($status!==0&&stripos($cron,'no crontab')===false)throw new RuntimeException('Cannot inspect hosting crontab.');
if($status!==0)$cron='';
$php=null;
foreach(['/usr/local/bin/php','/opt/cpanel/ea-php82/root/usr/bin/php','/usr/bin/php'] as $candidate){
    if(!is_executable($candidate))continue;
    [$code,$result]=ack_host_command(escapeshellarg($candidate).' -r '.escapeshellarg('echo PHP_SAPI." ".(extension_loaded("pdo_mysql")?"pdo_mysql":"missing");'));
    if($code===0&&strpos($result,'cli pdo_mysql')!==false){$php=$candidate;break;}
}
if(!$php)throw new RuntimeException('No verified PHP CLI with pdo_mysql available.');
$line='* * * * * '.escapeshellarg($php).' '.escapeshellarg(BASE_PATH.'/scripts/ack-scheduled-release.php').' >/dev/null 2>&1 # portal-ack-schedule';
$installed=in_array($line,preg_split('/\r?\n/',$cron),true);
if(ACK_DEPLOY_MODE==='inspect'){
    $health=dirname(BASE_PATH).'/.portal-ack-scheduler-health.json';
    return ['cron_available'=>true,'php_cli_verified'=>true,'worker_installed'=>$installed,'existing_cron_count'=>count(array_filter(preg_split('/\r?\n/',$cron))),'worker_health'=>is_file($health)?json_decode(file_get_contents($health),true):null];
}
if(ACK_DEPLOY_MODE==='migrate'){
    require_once BASE_PATH.'/shared/acknowledgments/migrate-scheduling.php';acknowledgments_migrate_scheduling($db);
    return ['schema'=>'additive_migration_verified'];
}
if(ACK_DEPLOY_MODE==='activate'){
    if(!$installed){
        if(strpos($cron,'portal-ack-schedule')!==false)throw new RuntimeException('An unexpected acknowledgment cron exists; refusing replacement.');
        $backup=dirname(BASE_PATH).'/ack-cron-backup-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        if(file_put_contents($backup,$cron)===false)throw new RuntimeException('Unable to preserve current crontab.');chmod($backup,0600);
        $temp=tempnam(sys_get_temp_dir(),'ack-cron-');
        try{file_put_contents($temp,rtrim($cron)."\n".$line."\n");[$code,$ignored]=ack_host_command('crontab '.escapeshellarg($temp));if($code!==0)throw new RuntimeException('Cron installation failed.');}finally{unlink($temp);}
        [$code,$verify]=ack_host_command('crontab -l');if($code!==0||!in_array($line,preg_split('/\r?\n/',$verify),true))throw new RuntimeException('Cron verification failed.');
    }
    [$code,$result]=ack_host_command(escapeshellarg($php).' '.escapeshellarg(BASE_PATH.'/scripts/ack-scheduled-release.php'));
    if($code!==0||strpos($result,'scheduled release complete')===false)throw new RuntimeException('Live scheduled release worker failed.');
    return ['worker_installed'=>true,'interval'=>'every minute','worker_execution'=>'passed'];
}
throw new RuntimeException('Unsupported release mode.');
