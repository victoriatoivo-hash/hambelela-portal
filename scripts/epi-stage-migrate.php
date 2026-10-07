<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$apply=false;$backup='';
foreach(array_slice($argv,1) as $arg){
    if($arg==='--apply')$apply=true;
    elseif(strpos($arg,'--backup-reference=')===0)$backup=substr($arg,19);
    else {fwrite(STDERR,"Usage: php scripts/epi-stage-migrate.php [--apply --backup-reference=VERIFIED_BACKUP_ID]\n");exit(1);}
}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/shared/database.php';
require_once dirname(__DIR__).'/shared/epi/StageSchemaInstaller.php';
$pdo=null;$read=false;
try{
    $pdo=db();
    if($apply)$report=\Hambelela\EPI\StageSchemaInstaller::apply($pdo,dirname(__DIR__),$backup);
    else{
        $pdo->exec('START TRANSACTION READ ONLY');$read=true;
        $report=\Hambelela\EPI\StageSchemaInstaller::inspect($pdo,dirname(__DIR__));
    }
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    $exit=($report['ready']??($report['status']==='installed_disabled'))?0:2;
}catch(Throwable $error){
    // DDL auto-commits. Never pretend failure rolled back, or print connection secrets.
    fwrite(STDERR,"Stage setup stopped. If applying, partial additive schema may remain; do not retry blindly. No activation was requested.\n");$exit=1;
}finally{if($read)$pdo->exec('ROLLBACK');}
exit($exit);
