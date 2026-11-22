<?php
if(PHP_SAPI!=='cli')exit(1);
ob_start();define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');define('OWNER_OPERATIONS_LAB',true);
function db(){static $db;return $db??=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
$who=1;function current_user(){global $who;return ['id'=>$who];}
$checks=[];function ok($b,$n){global $checks;if(!$b)throw new RuntimeException('FAIL '.$n);$checks[]='PASS '.$n;}
function request($actor,$get,$method='GET'){global $who;$who=$actor;$_GET=$get;$_SERVER=['REQUEST_METHOD'=>$method];http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/OwnerOperationsController.php';return ['status'=>http_response_code(),'body'=>ob_get_clean()];}
$r=request(0,['data'=>'1']);ok($r['status']===401,'no session 401');
foreach([2,3,4,5] as $a)foreach([[],['data'=>'1'],['export'=>'1']] as $p){$r=request($a,$p);ok($r['status']===403,'non-owner page/feed/export '.$a);}
$r=request(1,['data'=>'1']);ok($r['status']===200&&json_decode($r['body'],true)['ok'],'owner feed');
$r=request(1,['data'=>'1'],'POST');ok($r['status']===405,'writes forbidden');
$r=request(1,['data'=>'1','q'=>['attack']]);ok($r['status']===422,'malformed query');
$r=request(1,['export'=>'1','q'=>'no-such-order']);ok($r['status']===200&&substr($r['body'],0,3)==="\xEF\xBB\xBF",'owner empty CSV');
$r=request(1,[]);ok($r['status']===200&&strpos($r['body'],'id="owner-operations"')!==false,'owner shared-shell page');ok(strpos($r['body'],'ho-preview-shell')===false,'no duplicate preview shell');
ob_end_clean();echo implode("\n",$checks),"\nTOTAL ",count($checks)," controller checks passed\n";
