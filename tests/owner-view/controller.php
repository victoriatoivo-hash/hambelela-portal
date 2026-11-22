<?php
if(PHP_SAPI!=='cli')exit(1);
ob_start();define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');define('OWNER_VIEW_LAB',true);
function db(){static $db;if(!$db){$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);require __DIR__.'/fixture.php';owner_view_fixture($db);}return $db;}
$who=1;function current_user(){global $who;return ['id'=>$who];}function current_role_key(){return 'owner_admin';}
$_SESSION=['user'=>['id'=>1,'role_key'=>'owner_admin']];$checks=[];
function ok($b,$n){global $checks;if(!$b)throw new RuntimeException('FAIL '.$n);$checks[]='PASS '.$n;}
function request($actor,$get,$method='GET'){global $who;$who=$actor;$_GET=$get;$_SERVER=['REQUEST_METHOD'=>$method];http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/OwnerDriverViewController.php';return ['status'=>http_response_code(),'body'=>ob_get_clean()];}
ok(request(0,['mode'=>'drivers'])['status']===401,'anonymous denied');
foreach([2,3,4,5] as $id)foreach(['drivers','open','read','phone','evidence'] as $mode)ok(request($id,['mode'=>$mode,'driver'=>3])['status']===403,'non-owner '.$id.' '.$mode);
foreach(['POST','PUT','PATCH','DELETE','OPTIONS'] as $method)ok(request(1,['mode'=>'open','driver'=>3],$method)['status']===405,'HTTP '.$method.' denied');
ok(request(1,['mode'=>'drivers'],'HEAD')['body']==='','HEAD has no body');
$r=request(1,['mode'=>'open','driver'=>'3']);$data=json_decode($r['body'],true)['data'];$cap=$data['cap'];ok($r['status']===200&&strlen($cap)===64,'purpose-limited capability');
$scope=['driver'=>3,'cap'=>$cap];
ok(request(1,['mode'=>'summary']+$scope)['status']===200,'scoped summary');
ok(request(1,['mode'=>'summary','driver'=>4,'cap'=>$cap])['status']===403,'capability cannot switch driver');
ok(request(1,['mode'=>'read','op'=>'complete','params'=>'{}']+$scope)['status']===405,'GET command denied');
ok(request(1,['mode'=>'read','op'=>'profile','params'=>'{"driver":4}']+$scope)['status']===422,'read impersonation denied');
ok(request(1,['mode'=>'phone','screen'=>'../../config']+$scope)['status']===404,'screen traversal denied');
ok(request(1,['mode'=>'evidence','id'=>16,'key'=>'../../config']+$scope)['status']===422,'evidence traversal denied');
ok(request(1,['mode'=>['open']])['status']===422,'malformed mode denied');
ok(request(1,['mode'=>'open','driver'=>['3']])['status']===422,'malformed ID denied');
ok(request(1,['mode'=>'read','op'=>'profile','params'=>'not json']+$scope)['status']===422,'malformed JSON denied');
ok(request(1,['mode'=>'phone','screen'=>'home']+$scope)['status']===200,'shared home rendered');
ok($_SESSION['user']===['id'=>1,'role_key'=>'owner_admin'],'owner identity unchanged');
$_SESSION['owner_driver_views'][$cap]['expires']=time()-1;ok(request(1,['mode'=>'summary']+$scope)['status']===403,'expired capability denied');
ob_end_clean();echo implode("\n",$checks),"\nTOTAL ",count($checks)," controller checks passed\n";
