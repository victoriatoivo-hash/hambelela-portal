<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/shared/database.php';
require_once dirname(__DIR__).'/shared/epi/bootstrap.php';
require_once dirname(__DIR__).'/shared/epi/StageReadiness.php';
date_default_timezone_set('Africa/Windhoek');
$portal=null;$hr=null;$portalRead=false;$hrRead=false;
try {
    $portal=db();$hr=\Hambelela\EPI\HrConnection::connect();
    $portal->exec('START TRANSACTION READ ONLY');$portalRead=true;
    if($hr && $hr!==$portal){$hr->exec('START TRANSACTION READ ONLY');$hrRead=true;}
    $report=\Hambelela\EPI\StageReadiness::inspect($portal,$hr);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    $exit=$report['technical_prerequisites_ready']?0:2;
} catch(Throwable $error) {
    // Never print credentials, raw SQL errors or HR records.
    fwrite(STDERR,"Read-only EPI readiness check could not complete; check server logs.\n");$exit=1;
} finally {
    if($hrRead)$hr->exec('ROLLBACK');
    if($portalRead)$portal->exec('ROLLBACK');
}
exit($exit);
