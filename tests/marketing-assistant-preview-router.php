<?php
declare(strict_types=1);
// Local fixtures only. This router blocks all application routes except those
// explicitly rendered below; it never includes config.php or a live DB adapter.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/assets/') && preg_match('/\.(css|js|svg|png|jpg|woff2?|mp3|wav)$/i',$path)) {
 $asset=realpath(dirname(__DIR__).$path);$root=realpath(dirname(__DIR__).'/assets').DIRECTORY_SEPARATOR;
 if($asset && str_starts_with($asset,$root))return false;
}
require __DIR__.'/marketing-assistant-fixture.php';
$fixtureRole=($_GET['role']??'')==='owner'?'owner_admin':'marketing_sales';
if (str_contains($path,'notifications') || str_contains($path,'portal-presence')) {
 header('Content-Type: application/json');echo json_encode(['success'=>true,'unread_count'=>0,'latest'=>[],'notifications'=>[],'online'=>[],'preferences'=>['sound_enabled'=>1,'desktop_enabled'=>0,'sound_volume'=>65]]);exit;
}
function fixture_source(string $file): string {
 $source=file_get_contents($file);
 $source=preg_replace('/declare\(strict_types\s*=\s*1\);/','',$source);
 // Replace dependencies only in memory; production source files are untouched.
 $source=preg_replace("~require_once\s+(?:dirname\(__DIR__,2\)\.'/config.php'|BASE_PATH\.'/shared/marketing.php'|__DIR__\s*\.\s*'/auth.php'|__DIR__\s*\.\s*'/notifications.php');~",'',$source);
 $source=str_replace("include BASE_PATH.'/shared/header.php';", "eval('?>'.fixture_source(BASE_PATH.'/shared/header.php'));",$source);
 $source=str_replace("__DIR__.'/employee-calendar.php'", "BASE_PATH.'/apps/marketing/employee-calendar.php'",$source);
 $source=str_replace("__DIR__.'/app-shell.php'", "BASE_PATH.'/apps/marketing/app-shell.php'",$source);
 return $source;
}
if ($path==='/guard') {
 $route=(string)($_GET['route']??'index.php');
 if(!in_array($route,['index.php','analytics-data.php','export.php','metric-file.php','track-link.php'],true)){http_response_code(404);exit;}
 $source=fixture_source(BASE_PATH.'/apps/marketing/'.$route);
 if($route==='index.php')$source=substr($source,0,strpos($source,'try{'));
 else $source=substr($source,0,strpos($source,'marketing_require_owner();')+strlen('marketing_require_owner();'));
 $source=preg_replace('~require_once[^;]+;~','',$source);
 eval('?>'.$source);echo 'Guard passed';exit;
}
if(!in_array($path,['/','/apps/marketing/index.php','/apps/marketing/execution.php','/apps/marketing/product-work.php'],true)){http_response_code(404);exit('Fixture route only.');}
$owner=marketing_is_owner();$message='Local fixture — no customer data. Changes here do not affect the live portal.';
if($path==='/apps/marketing/execution.php') { $_GET['id']=$_GET['id']??1;eval('?>'.fixture_source(BASE_PATH.'/apps/marketing/execution.php'));exit; }
if($path==='/apps/marketing/product-work.php') { $_GET['id']=$_GET['id']??1;eval('?>'.fixture_source(BASE_PATH.'/apps/marketing/product-work.php'));exit; }
if(!marketing_employee_view_allowed((string)($_GET['view']??'dashboard')))marketing_execution_deny();
if($owner){header('Location: /apps/marketing/execution.php?id=1&role=owner');exit;}
eval('?>'.fixture_source(BASE_PATH.'/apps/marketing/employee-workspace.php'));
