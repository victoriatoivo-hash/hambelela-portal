<?php
// Isolated synthetic review; never deployed. No production configuration or arbitrary PHP routing.
if(PHP_SAPI!=='cli-server'||getenv('OFFLINE_BUILD')!=='owner-operations-v1'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:51840'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');define('OWNER_OPERATIONS_LAB',true);define('OWNER_PROOF_LAB',true);date_default_timezone_set('Africa/Windhoek');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
session_name('HO_DRIVER_LAB');session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Strict']);session_start();
if(empty($_SESSION['tester'])){http_response_code(401);echo 'Private local staging. Open the existing Driver review launcher in this browser first.';return;}
function db(){static $db;return $db??=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function current_user(){return ['id'=>1,'full_name'=>'Synthetic Owner','role_key'=>'owner_admin'];}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$allowed=['/assets/css/driver-admin-operations.css'=>'text/css','/assets/js/driver-admin-operations.js'=>'application/javascript','/assets/css/driver-admin-proof-viewer.css'=>'text/css','/assets/css/ess-dashboard.css'=>'text/css','/assets/fonts/jost-variable.woff2'=>'font/woff2','/assets/js/driver-admin-proof-viewer.js'=>'application/javascript'];
if(isset($allowed[$path])){header('Content-Type: '.$allowed[$path]);readfile(BASE_PATH.$path);return;}
if(in_array($path,['/review/approved-reference.png','/review/operations-1440.jpg'],true)){header(substr($path,-4)==='.png'?'Content-Type: image/png':'Content-Type: image/jpeg');readfile(__DIR__.'/screens/'.basename($path));return;}
if($path==='/review/comparison'){require __DIR__.'/comparison.php';return;}
if($path==='/apps/operations/driver-delivery-details.php'){require BASE_PATH.'/shared/delivery/OwnerProofController.php';return;}
if($path==='/'||$path==='/apps/operations/driver-operations-dashboard.php'){require BASE_PATH.'/shared/delivery/OwnerOperationsController.php';return;}
http_response_code(404);echo 'This existing portal workflow is not connected in the isolated synthetic preview. No live business action has been sent.';
