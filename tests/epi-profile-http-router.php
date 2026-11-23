<?php
declare(strict_types=1);
// Developer-only loopback harness: renders the ACTUAL employee profile and data route.
if(PHP_SAPI!=='cli-server'||getenv('EPI_LOCAL_UI')!=='1'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){
    http_response_code(404);exit;
}
$database=(string)getenv('HAMBELELA_DB_NAME');
if(!preg_match('/^epi_p0_audit_[a-f0-9]{12}$/D',$database)||getenv('HAMBELELA_DB_HOST')!=='127.0.0.1;port=33317'){
    http_response_code(500);exit('Synthetic database required.');
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($path,'/assets/')===0 && strpos($path,'..')===false)return false;
if(!in_array($path,['/apps/operations/kpi-employee.php','/apps/operations/kpi-employee-data.php','/apps/operations/reports.php','/apps/operations/reports-performance-reports-data.php'],true)){
    http_response_code(404);exit('Local test route not enabled.');
}
require_once dirname(__DIR__).'/config.php';
if(DB_HOST!=='127.0.0.1;port=33317'||DB_NAME!==$database)throw new RuntimeException('Unsafe preview database.');
require_once dirname(__DIR__).'/shared/auth.php';
$_SESSION['user']=['id'=>999,'name'=>'Synthetic Owner','role_key'=>'owner_admin'];
$_SESSION['authenticated_at']=date(DATE_ATOM);$_SESSION['absolute_expires_at']=time()+3600;
$_SESSION['last_activity_at']=date(DATE_ATOM);$_SESSION['login_date']=date('Y-m-d');
$_SESSION['session_user_id']=999;$_SESSION['session_identifier']=hash('sha256',session_id());
// Enforce read-only route acceptance at the database, including shared includes.
require_once dirname(__DIR__).'/shared/database.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')db()->exec('START TRANSACTION READ ONLY');
elseif($path!=='/apps/operations/kpi-employee-data.php'||($_GET['action']??'')!=='performance_incident_review'){
    http_response_code(405);exit('Local write route not enabled.');
}
$failureFile=dirname(__DIR__).'/.run-artifacts/epi-ui-force-failure';
if(substr($path,-9)==='-data.php' && is_file($failureFile) && trim((string)file_get_contents($failureFile))==='enabled'){
    http_response_code(503);header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'message'=>'Synthetic acceptance-test service failure.']);exit;
}
require dirname(__DIR__).$path;
