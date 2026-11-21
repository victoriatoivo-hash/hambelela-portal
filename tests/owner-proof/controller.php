<?php
if(PHP_SAPI!=='cli')exit(1);
ob_start();define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','http://127.0.0.1:51839');define('OWNER_PROOF_LAB',true);
function db(){static $db;return $db??=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
$who=1;function current_user(){global $who;return ['id'=>$who];}
$_SESSION=['delivery_front_csrf'=>'synthetic-csrf'];$checks=[];
function request($actor,$get,$post=null,$origin=null){global $who;$who=$actor;$_GET=$get;$_POST=$post??[];$_FILES=[];$_SERVER=['REQUEST_METHOD'=>$post===null?'GET':'POST'];if($origin)$_SERVER['HTTP_ORIGIN']=$origin;http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/OwnerProofController.php';$body=ob_get_clean();return ['status'=>http_response_code(),'body'=>$body];}
function ok($condition,$name){global $checks;if(!$condition)throw new RuntimeException('FAIL '.$name);$checks[]='PASS '.$name;}
$r=request(0,['delivery_id'=>'16','data'=>'1']);ok($r['status']===401,'expired session 401');
foreach([2,3,4,5] as $actor){$r=request($actor,['delivery_id'=>'16','data'=>'1']);ok($r['status']===403&&!strpos($r['body'],'Synthetic UI'),'non-owner JSON denied '.$actor);$r=request($actor,['delivery_id'=>'16','download'=>'1']);ok($r['status']===403,'non-owner export denied '.$actor);$r=request($actor,['delivery_id'=>'16','evidence'=>str_repeat('a',48)]);ok($r['status']===403,'non-owner image denied '.$actor);}
$r=request(1,['delivery_id'=>'16','data'=>'1']);ok($r['status']===200&&json_decode($r['body'],true)['ok'],'owner JSON');
$r=request(1,['delivery_id'=>'16 OR 1=1','data'=>'1']);ok($r['status']===404,'SQL-like ID rejected');
$r=request(1,['delivery_id'=>['16'],'data'=>'1']);ok($r['status']===404,'array ID rejected');
$r=request(1,['delivery_id'=>'16'],['action'=>'note']);ok($r['status']===403,'missing CSRF rejected');
$r=request(1,['delivery_id'=>'16'],['csrf'=>'wrong']);ok($r['status']===403,'wrong CSRF rejected');
$r=request(1,['delivery_id'=>'16'],['csrf'=>'synthetic-csrf'],'https://attacker.example');ok($r['status']===403,'cross origin rejected');
$r=request(1,['delivery_id'=>'16','evidence'=>'../../config.php']);ok($r['status']===404,'path traversal rejected');
$v=new \Hambelela\Delivery\OwnerProofViewer(db(),1);$data=$v->read(16);$file=$data['proof']['files'][0];
$r=request(1,['delivery_id'=>'16','evidence'=>$file['id']]);ok($r['status']===200&&substr($r['body'],0,8)==="\x89PNG\r\n\x1a\n",'authorized PNG streaming');
$r=request(1,['delivery_id'=>'17','evidence'=>$file['id']]);ok($r['status']===404,'cross-delivery evidence rejected');
$r=request(1,['delivery_id'=>'16','evidence'=>$file['id'],'thumbnail'=>'1']);ok($r['status']===200&&getimagesizefromstring($r['body'])[0]<=800,'bounded thumbnail');
$r=request(1,['delivery_id'=>'16','download'=>'1']);ok($r['status']===200&&substr($r['body'],0,2)==='PK','ZIP export succeeds');
$temp=tempnam(sys_get_temp_dir(),'hp-test-');file_put_contents($temp,$r['body']);$zip=new ZipArchive();$zip->open($temp);$manifest=json_decode($zip->getFromName('manifest.json'),true);ok($manifest['delivery_id']===16&&!isset($manifest['actions']),'export scoped and excludes private notes');$zip->close();unlink($temp);
$r=request(1,['delivery_id'=>'16']);ok($r['status']===200&&strpos($r['body'],'Customer handover recorded')!==false,'HTML renders payment declaration');ok(strpos($r['body'],'data-owner-proof')!==false,'HTML has scoped component');
ob_end_clean();echo implode("\n",$checks),"\nTOTAL ",count($checks)," controller checks passed\n";
