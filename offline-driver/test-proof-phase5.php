<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');define('DRIVER_OFFLINE_LAB',true);
require BASE_PATH.'/shared/delivery/DeliveryService.php';
use Hambelela\Delivery\{DeliveryService,ProofContext,ProofPolicy,ProofStorage,ProofSubmission,OrderBalanceAdapter,EmployeeDriverSession,NotificationOutbox,DeliveryPolicy};
$dsn='mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4';
$db=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$testActor=3;$testRole='delivery_driver';function db(){global $db;return $db;}function current_user(){global $testActor;return ['id'=>$testActor];}function current_role_key(){global $testRole;return $testRole;}
function proofRequest(array $get,string $method='GET',array $post=[]){$_GET=$get;$_POST=$post;$_FILES=[];$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/DriverProofOfDelivery.php';return [http_response_code(),ob_get_clean()];}
$original=$db->query('SELECT id,status,version FROM delivery_jobs ORDER BY id')->fetchAll();
// Connection-local tables shadow the practice database. Service commits cannot touch original data.
foreach(['ops_roles','ops_employees','delivery_driver_profiles','delivery_jobs','ops_orders','ops_order_items','delivery_events','delivery_requests','delivery_receipts','delivery_ledger','delivery_outbox','delivery_employee_grants','ops_permissions','order_payment_allocations','notifications','notification_recipients'] as $table){
    $rows=$db->query('SELECT * FROM '.$table)->fetchAll();$ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);
    foreach($rows as $row){$cols=array_keys($row);$db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));}
}
$checks=0;function verify($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejects(callable $run,string $label){try{$run();}catch(LogicException $e){verify(true,$label);return;}throw new RuntimeException('NOT BLOCKED: '.$label);}
function uuid5(){return sprintf('%s-%s-4%s-a%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2))[0].substr(bin2hex(random_bytes(2)),0,2),substr(bin2hex(random_bytes(2)),0,3),bin2hex(random_bytes(6)));}
$actor=EmployeeDriverSession::actor($db,3);$job=$db->query("SELECT * FROM delivery_jobs WHERE driver_employee_id=3 AND status='ready' ORDER BY id LIMIT 1")->fetch();$id=(int)$job['id'];$order=(int)$job['order_id'];
$event=function($type,$meta)use($db,$id){$db->prepare('INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,?,3,?)')->execute([$id,$type,json_encode($meta)]);};
$db->exec("DELETE FROM delivery_events WHERE delivery_id=$id AND event_type NOT IN ('arranged')");
$db->exec("DELETE FROM delivery_receipts WHERE delivery_id=$id");$db->exec("DELETE FROM delivery_requests");$db->exec("DELETE FROM delivery_ledger WHERE delivery_id=$id");$db->exec("DELETE FROM delivery_outbox");
$db->exec("UPDATE ops_orders SET payment_status='paid',payment_method='EFT',status='in_progress',deleted_at=NULL WHERE id=$order");
$db->exec("DELETE FROM order_payment_allocations WHERE order_id=$order");$db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES($order,'EFT',12000,'pos')");
$today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$db->prepare("UPDATE delivery_jobs SET scheduled_date=?,status='out_for_delivery',delivered_at=NULL,completed_at=NULL,version=10 WHERE id=?")->execute([$today,$id]);
$service=new DeliveryService($db,['Cash','EFT'],static fn($d,$j)=>(new OrderBalanceAdapter())->read($d,$j));
$policy=['evidence'=>'one','representative'=>'relationship'];$fields=['recipient_name'=>'Synthetic Test Recipient','recipient_type'=>'customer','relationship'=>'','acknowledged'=>'1'];
$root=dirname(BASE_PATH).'/driver-proof-phase5-test';if(!is_dir($root))mkdir($root,0700);$storage=new ProofStorage($root);$created=[];$fixtures=[];
putenv('HO_DRIVER_PROOF_DIR='.$root);
try{
    $photo=$root.'/test-pattern.png';$fixtures[]=$photo;$im=imagecreatetruecolor(240,100);imagefill($im,0,0,imagecolorallocate($im,255,255,255));$ink=imagecolorallocate($im,30,60,40);imagerectangle($im,20,20,210,80,$ink);imagestring($im,4,40,40,'SYNTHETIC TEST',$ink);imagepng($im,$photo);imagedestroy($im);
    $upload=fn($path)=>['tmp_name'=>$path,'error'=>UPLOAD_ERR_OK];$file=$storage->prepare($upload($photo),'photo',true);$created[]=$file;
    verify(is_file($storage->path($file['id']))&&$file['mime']==='image/png','private re-encoded photo');
    rejects(fn()=>$storage->path('../outside'),'path traversal blocked');rejects(fn()=>new ProofStorage(BASE_PATH),'public root storage blocked');
    $blank=$root.'/test-blank.png';$fixtures[]=$blank;$im=imagecreatetruecolor(240,100);imagefill($im,0,0,imagecolorallocate($im,255,255,255));imagepng($im,$blank);imagedestroy($im);
    rejects(fn()=>$storage->prepare($upload($blank),'signature',true),'blank signature blocked');
    $signature=$storage->prepare($upload($photo),'signature',true);$created[]=$signature;verify($signature['kind']==='signature','synthetic ink validation accepted (not a person signature)');
    $corrupt=$root.'/test-corrupt.png';$fixtures[]=$corrupt;file_put_contents($corrupt,str_repeat('not an image',20));rejects(fn()=>$storage->prepare($upload($corrupt),'photo',true),'corrupt/wrong MIME blocked');
    $huge=$root.'/test-large.png';$fixtures[]=$huge;file_put_contents($huge,str_repeat('x',2000001));rejects(fn()=>$storage->prepare($upload($huge),'photo',true),'oversized upload blocked');
    rejects(fn()=>$storage->prepare(['tmp_name'=>$photo,'error'=>UPLOAD_ERR_PARTIAL],'photo',true),'partial upload blocked');
    rejects(fn()=>$storage->prepare($upload($photo),'photo'),'non-HTTP upload blocked');
    rejects(fn()=>new ProofSubmission($fields,[],10,$policy),'no evidence blocked');
    rejects(fn()=>new ProofSubmission(array_replace($fields,['recipient_name'=>' ']),['photo'=>$file],10,$policy),'whitespace recipient blocked');
    rejects(fn()=>new ProofSubmission(array_replace($fields,['acknowledged'=>'0']),['photo'=>$file],10,$policy),'unchecked handover blocked');
    rejects(fn()=>new ProofSubmission($fields,['photo'=>$file],10,['evidence'=>'both','representative'=>'relationship']),'stricter both policy blocks one');
    new ProofSubmission($fields,['photo'=>$file,'signature'=>$signature],10,['evidence'=>'both','representative'=>'relationship']);verify(true,'both proofs accepted');
    $rep=array_replace($fields,['recipient_type'=>'representative']);rejects(fn()=>new ProofSubmission($rep,['photo'=>$file],10,$policy),'representative requires relationship');
    $rep['relationship']='Recipient-authorized colleague';new ProofSubmission($rep,['photo'=>$file],10,$policy);verify(true,'representative relationship retained');
    rejects(fn()=>new ProofSubmission($rep,['photo'=>$file],10,['evidence'=>'one','representative'=>'pending']),'unapproved representative policy blocked');
    rejects(fn()=>new ProofSubmission($fields,['photo'=>$file],10,['evidence'=>'pending']),'unapproved evidence policy blocked');
    rejects(fn()=>$service->arrive($actor,$id,uuid5(),10),'pickup required before arrival');
    $event('parcel_collected',['driver_id'=>3]);$before=ProofContext::read($db,3,$id);verify(!$before['reason']&&!$before['arrival'],'eligible paid parcel is not automatically arrived');
    $submit=fn($version=10)=>new ProofSubmission($fields,['photo'=>$file],$version,$policy);
    $complete=fn($uuid,$proof,$who=null)=>$service->complete($who??$actor,$id,$uuid,null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid','note'=>''],$proof);
    rejects(fn()=>$complete(uuid5(),$submit()),'arrival required before completion');
    $arrivalKey=uuid5();$service->arrive($actor,$id,$arrivalKey,10);$service->arrive($actor,$id,$arrivalKey,10);verify((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$id AND event_type='arrived'")->fetchColumn()===1,'arrival retry records one event');
    $ctx=ProofContext::read($db,3,$id);verify($ctx['version']===11&&$ctx['arrival']!==null,'arrival timestamp and version are recorded');
    rejects(fn()=>$complete(uuid5(),$submit(10)),'stale version rejected');
    rejects(fn()=>$complete(uuid5(),$submit(11),EmployeeDriverSession::actor($db,4)),'other driver rejected');
    $db->exec("UPDATE delivery_jobs SET status='cancelled' WHERE id=$id");rejects(fn()=>$complete(uuid5(),$submit(11)),'cancelled delivery blocked');$db->exec("UPDATE delivery_jobs SET status='out_for_delivery' WHERE id=$id");
    $db->exec("UPDATE order_payment_allocations SET amount_cents=10000 WHERE order_id=$order");rejects(fn()=>$complete(uuid5(),$submit(11)),'partial/unpaid balance blocked');$db->exec("UPDATE order_payment_allocations SET amount_cents=12000 WHERE order_id=$order");
    $db->exec("UPDATE ops_orders SET status='refunded' WHERE id=$order");rejects(fn()=>$complete(uuid5(),$submit(11)),'refunded source blocked');$db->exec("UPDATE ops_orders SET status='in_progress' WHERE id=$order");
    $db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");rejects(fn()=>$complete(uuid5(),$submit(11)),'inactive employee blocked');$db->exec("UPDATE ops_employees SET status='active' WHERE id=3");
    putenv('HO_DRIVER_PROOF_POLICY=one');rejects(fn()=>$service->complete($actor,$id,uuid5(),null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid']),'legacy driver completion cannot bypass configured proof');putenv('HO_DRIVER_PROOF_POLICY');
    $db->prepare("INSERT INTO delivery_receipts(delivery_id,collection_uuid,payment_method,order_goods_cents,collected_by_employee_id,status) VALUES(?,?,'Cash',1000,3,'issue')")->execute([$id,uuid5()]);rejects(fn()=>$complete(uuid5(),$submit(11)),'unreconciled collection issue blocks completion');$db->exec("DELETE FROM delivery_receipts WHERE delivery_id=$id");
    $broken=new DeliveryService($db,[],static function(){throw new DomainException('Simulated verification failure after proof validation.');});
    rejects(fn()=>$broken->complete($actor,$id,uuid5(),null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid'],$submit(11)),'transaction rolls back on late payment verification failure');
    verify($db->query("SELECT status FROM delivery_jobs WHERE id=$id")->fetchColumn()==='out_for_delivery'&&!ProofContext::receipt($db,$id),'failed transaction saves no completion or proof event');
    $moneyBefore=$db->query("SELECT amount_cents,source FROM order_payment_allocations WHERE order_id=$order")->fetchAll();
    $key=uuid5();$proof=$submit(11);$result=$complete($key,$proof);verify($result['status']==='completed'&&isset($result['proof']['receipt']),'actual service transaction completes with proof receipt');
    $again=$complete($key,$proof);verify($again===$result,'identical retry returns original receipt');
    rejects(fn()=>$complete($key,new ProofSubmission(array_replace($fields,['recipient_name'=>'Different person']),['photo'=>$file],11,$policy)),'same key different payload rejected');
    rejects(fn()=>$complete(uuid5(),$proof),'second completion with new key rejected');
    verify((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$id AND event_type='delivered'")->fetchColumn()===1,'exactly one completed audit event');
    verify(ProofContext::receipt($db,$id)===$result['proof'],'receipt persisted in immutable event');
    [$code,$html]=proofRequest(['proof'=>(string)$id]);verify($code===200&&str_contains($html,'Evidence receipt '.$result['proof']['receipt']),'actual controller displays saved receipt after refresh');
    [$code,$png]=proofRequest(['proof'=>(string)$id,'evidence'=>$file['id']]);verify($code===200&&hash('sha256',$png)===$file['stored_sha256'],'authorized controller streams re-encoded evidence');
    [$code,$html]=proofRequest(['proof'=>(string)$id,'evidence'=>'../outside']);verify($code!==200&&!str_starts_with($html,"\x89PNG"),'controller rejects arbitrary file requests');
    $_SESSION['csrf']='test-only-csrf';[$code,$json]=proofRequest(['proof'=>(string)$id],'POST',['csrf'=>'wrong','version'=>'11','action'=>'complete']);verify($code!==200&&json_decode($json,true)['ok']===false,'controller rejects bad CSRF with JSON error');
    $testActor=1;$testRole='owner_admin';[$code,$html]=proofRequest(['proof'=>(string)$id]);verify($code===200&&str_contains($html,'View photo'),'Owner can review authorized evidence');
    $testActor=3;$testRole='delivery_driver';
    verify($moneyBefore===$db->query("SELECT amount_cents,source FROM order_payment_allocations WHERE order_id=$order")->fetchAll(),'order money allocations unchanged');
    verify((int)$db->query("SELECT COUNT(*) FROM delivery_receipts WHERE delivery_id=$id")->fetchColumn()===0,'no duplicate collected money');
    verify((int)$db->query("SELECT COUNT(*) FROM delivery_outbox WHERE event_key LIKE 'proof:%'")->fetchColumn()>0,'dispatch queued atomically');
    NotificationOutbox::drain($db);verify((int)$db->query("SELECT COUNT(*) FROM delivery_outbox WHERE event_key LIKE 'proof:%' AND delivered_at IS NOT NULL")->fetchColumn()>0,'committed dispatch notifications drain successfully');
    $db->exec("UPDATE delivery_jobs SET driver_employee_id=4 WHERE id=$id");rejects(fn()=>$complete($key,$proof),'replay authorization rechecked after reassignment');
    verify(!DeliveryPolicy::canView($actor,$db->query("SELECT * FROM delivery_jobs WHERE id=$id")->fetch()),'former driver loses evidence access');
    [$code,$html]=proofRequest(['proof'=>(string)$id,'evidence'=>$file['id']]);verify($code!==200&&!str_starts_with($html,"\x89PNG"),'former driver cannot retrieve evidence from controller');
    $clean=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);verify($original===$clean->query('SELECT id,status,version FROM delivery_jobs ORDER BY id')->fetchAll(),'practice records untouched by all test commits');
    echo "TOTAL $checks proof checks passed\n";ob_end_flush();
}finally{$storage->discard($created);foreach($fixtures as $path)if(is_file($path)&&!(in_array('--keep-photo',$argv,true)&&$path===$photo))unlink($path);putenv('HO_DRIVER_PROOF_POLICY');putenv('HO_DRIVER_PROOF_DIR');}
