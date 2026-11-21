<?php
declare(strict_types=1);
require_once __DIR__.'/test-php74-assertions.php';
if(PHP_SAPI!=='cli')exit(1);
ob_start();define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');define('DRIVER_OFFLINE_LAB',true);
require BASE_PATH.'/shared/delivery/DeliveryService.php';require BASE_PATH.'/shared/delivery/IssueContext.php';require BASE_PATH.'/shared/delivery/DriverLiveRouteData.php';require BASE_PATH.'/shared/delivery/DriverOrderDetailsData.php';require BASE_PATH.'/shared/delivery/DriverInbox.php';
use Hambelela\Delivery\{DeliveryService,DeliveryIssueState,IssueContext,ProofContext,ProofPolicy,ProofStorage,ProofSubmission,EmployeeDriverSession,FrontSession,NotificationOutbox,DriverAssignments,DriverLiveRouteData,DriverOrderDetailsData,DriverInbox};
$dsn='mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4';$db=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$testActor=3;$testRole='delivery_driver';function db(){global $db;return $db;}function current_user(){global $testActor;return ['id'=>$testActor];}function current_role_key(){global $testRole;return $testRole;}
function issueRequest(array $get,string $method='GET',array $post=[]){$_GET=$get;$_POST=$post;$_FILES=[];$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/DriverDeliveryIssue.php';return [http_response_code(),ob_get_clean()];}
$original=$db->query('SELECT id,status,version FROM delivery_jobs ORDER BY id')->fetchAll();
// Every service commit below targets connection-local clones, never the user's practice records.
foreach(['ops_roles','ops_employees','delivery_driver_profiles','delivery_jobs','ops_orders','ops_order_items','delivery_events','delivery_requests','delivery_receipts','delivery_ledger','delivery_outbox','delivery_employee_grants','ops_permissions','order_payment_allocations','notifications','notification_recipients'] as $table){
 $rows=$db->query('SELECT * FROM '.$table)->fetchAll();$ddl=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);
 foreach($rows as $row){$cols=array_keys($row);$db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));}
}
$checks=0;function verify6($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejects6(callable $run,string $label){try{$run();}catch(LogicException $e){verify6(true,$label);return;}throw new RuntimeException('NOT BLOCKED: '.$label);}
function uuid6(){return sprintf('%s-%s-4%s-a%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),substr(bin2hex(random_bytes(2)),0,3),substr(bin2hex(random_bytes(2)),0,3),bin2hex(random_bytes(6)));}
$actor=EmployeeDriverSession::actor($db,3);$front=FrontSession::actor($db,2);$owner=FrontSession::actor($db,1);$other=EmployeeDriverSession::actor($db,4);
$job=$db->query("SELECT * FROM delivery_jobs WHERE driver_employee_id=3 AND status='ready' ORDER BY id LIMIT 1")->fetch();$id=(int)$job['id'];$order=(int)$job['order_id'];
$db->exec("DELETE FROM delivery_events WHERE delivery_id=$id AND event_type<>'arranged'");$db->exec("DELETE FROM delivery_receipts WHERE delivery_id=$id");$db->exec('DELETE FROM delivery_requests');$db->exec('DELETE FROM delivery_outbox');
$db->exec("UPDATE ops_orders SET status='in_progress',payment_status='paid',payment_method='EFT',deleted_at=NULL,archived_at=NULL WHERE id=$order");$db->exec("DELETE FROM order_payment_allocations WHERE order_id=$order");$db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES($order,'EFT',12000,'pos')");
$today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$db->prepare("UPDATE delivery_jobs SET driver_employee_id=3,scheduled_date=?,status='ready',delivered_at=NULL,completed_at=NULL,version=10 WHERE id=?")->execute([$today,$id]);
$service=new DeliveryService($db,[],static function(){throw new LogicException('Issues must not call payment adapters.');});
$version=fn()=>(int)$db->query("SELECT version FROM delivery_jobs WHERE id=$id")->fetchColumn();
$record=fn()=>DeliveryIssueState::latest($db,$id);
$input=['reason'=>'not_answering','notes'=>'Local synthetic test: no answer after knocking.','contact_attempted'=>'1'];
$report=fn($fields=null,$key=null,$v=null,$who=null,$photo=null)=>$service->reportIssue($who??$actor,$id,$key??uuid6(),$v??$version(),$fields??$input,$photo);
$decide=fn($choice,$more=[],$key=null,$v=null,$who=null)=>$service->decideIssue($who??$front,$id,$key??uuid6(),$v??$version(),$more+['incident_id'=>$record()['incident_id'],'decision'=>$choice,'instruction'=>'Synthetic dispatch instruction: contact front desk before proceeding.']);
$moneyBefore=$db->query('SELECT * FROM order_payment_allocations ORDER BY id')->fetchAll();$ordersBefore=$db->query('SELECT * FROM ops_orders ORDER BY id')->fetchAll();$ledgerBefore=$db->query('SELECT * FROM delivery_ledger ORDER BY id')->fetchAll();
$root=dirname(BASE_PATH).'/driver-issue-phase6-test';if(!is_dir($root))mkdir($root,0700);$storage=new ProofStorage($root);$created=[];$fixtures=[];putenv('HO_DRIVER_PROOF_DIR='.$root);
try{
 verify6(ProofPolicy::configured()===['evidence'=>'signature','representative'=>'relationship'],'Owner-approved default requires recipient signature');
 $recipient=['recipient_type'=>'customer','recipient_name'=>'Synthetic recipient','acknowledged'=>'1'];
 rejects6(fn()=>ProofPolicy::validate(ProofPolicy::configured(),$recipient,['photo'=>[]]),'photo-only completion blocked');
 ProofPolicy::validate(ProofPolicy::configured(),$recipient,['signature'=>[]]);verify6(true,'signature without photo accepted by policy');
 rejects6(fn()=>ProofPolicy::validate(ProofPolicy::configured(),array_replace($recipient,['recipient_type'=>'representative']),['signature'=>[]]),'representative relationship required');
 ProofPolicy::validate(ProofPolicy::configured(),array_replace($recipient,['recipient_type'=>'representative','relationship'=>'Colleague']),['signature'=>[]]);verify6(true,'representative name and relationship accepted');
 foreach([['reason'=>''],['notes'=>' '],['notes'=>'Too short'],['notes'=>str_repeat('x',1501)],['contact_attempted'=>'0']] as $bad)rejects6(fn()=>$report(array_replace($input,$bad)),'invalid reason/details/contact rejected '.json_encode(array_keys($bad)));
 rejects6(fn()=>$report(null,null,9),'stale report rejected');rejects6(fn()=>$report(null,null,null,$other),'cross-driver report rejected');
 $photoPath=$root.'/synthetic-test.png';$fixtures[]=$photoPath;$im=imagecreatetruecolor(240,100);imagefill($im,0,0,imagecolorallocate($im,255,255,255));imagerectangle($im,20,20,200,80,imagecolorallocate($im,30,60,40));imagepng($im,$photoPath);imagedestroy($im);
 $file=$storage->prepare(['tmp_name'=>$photoPath,'error'=>0],'photo',true);$created[]=$file;
 $badPath=$root.'/invalid-test.png';$fixtures[]=$badPath;file_put_contents($badPath,str_repeat('invalid image',100));rejects6(fn()=>$storage->prepare(['tmp_name'=>$badPath,'error'=>0],'photo',true),'spoofed photo rejected');
 file_put_contents($badPath,str_repeat('x',2000001));rejects6(fn()=>$storage->prepare(['tmp_name'=>$badPath,'error'=>0],'photo',true),'oversize photo rejected');rejects6(fn()=>$storage->prepare(['tmp_name'=>$photoPath,'error'=>UPLOAD_ERR_PARTIAL],'photo',true),'partial photo upload rejected');
 $key=uuid6();$v=$version();$result=$report(null,$key,$v,null,$file);verify6($record()['incident_id']===$result['incident_id']&&$record()['blocking'],'incident stored and completion held');
 verify6($report(null,$key,$v,null,$file)===$result,'retry returns original incident');rejects6(fn()=>$report(),'new key cannot duplicate an unresolved incident');rejects6(fn()=>$report(array_replace($input,['notes'=>'Different synthetic note']),$key,$v),'key reuse with different payload rejected');
 $row=DriverAssignments::find(DriverAssignments::read($db,3),$id);verify6($row['stage']==='exception'&&test_contains($row['displayStatus'],'Awaiting dispatch'),'Deliveries show issue, not Delivered');verify6($row['paymentVerified'],'verified payment unchanged in issue projection');
 $route=DriverLiveRouteData::read($db,3);verify6(in_array($id,array_column($route['needs_review'],'assignment_id'),true)&&!in_array($id,array_column($route['stops'],'assignment_id'),true),'route holds issue stop without selecting next stop');
 $details=DriverOrderDetailsData::read($db,3,$id);verify6($details['issue']['reference']===$result['incident_id']&&in_array('delivery_issue_reported',array_column($details['timeline'],'eventType'),true),'details contain incident and audit timeline');
 rejects6(fn()=>$service->start($actor,$id,uuid6()),'start blocked while issue unresolved');
 rejects6(fn()=>$service->complete($owner,$id,uuid6(),'Manual test must not bypass issue'), 'manual completion cannot bypass issue');
 verify6(test_contains(ProofContext::read($db,3,$id)['reason'],'Issue reported'),'Proof page blocked by issue');
 rejects6(fn()=>$service->fail($actor,$id,uuid6(),'Other','Do not bypass review'),'legacy failed action cannot bypass issue');
 $yesterday=(new DateTimeImmutable('yesterday',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$db->prepare('UPDATE delivery_jobs SET scheduled_date=? WHERE id=?')->execute([$yesterday,$id]);
 verify6(DriverAssignments::find(DriverAssignments::read($db,3),$id)['issue']['blocking'],'unresolved previous-day issue remains visible');
 verify6(DriverOrderDetailsData::read($db,3,$id)['issue']['blocking'],'previous-day issue details remain accessible');
 verify6(in_array($id,array_column(DriverLiveRouteData::read($db,3)['needs_review'],'assignment_id'),true),'previous-day issue retained in route hold');
 rejects6(fn()=>$decide('retry_today'),'yesterday issue requires approved reschedule, not implicit retry');$db->prepare('UPDATE delivery_jobs SET scheduled_date=? WHERE id=?')->execute([$today,$id]);
 $_SESSION['csrf']='phase6-test-csrf';$_SESSION['delivery_front_csrf']='phase6-front-csrf';
 [$code,$html]=issueRequest(['issue'=>(string)$id]);verify6($code===200&&test_contains($html,$result['incident_id'])&&test_contains($html,'Awaiting dispatch'),'controller persists receipt across reload');
 [$code,$png]=issueRequest(['issue'=>(string)$id,'evidence'=>$file['id']]);verify6($code===200&&hash('sha256',$png)===$file['stored_sha256'],'authorized photo is private re-encoded bytes');
 [$code,$body]=issueRequest(['issue'=>(string)$id,'evidence'=>'../outside']);verify6($code!==200&&!test_starts_with($body,"\x89PNG"),'photo path traversal blocked');
 [$code,$json]=issueRequest(['issue'=>(string)$id],'POST',['action'=>'report','csrf'=>'wrong']);verify6($code!==200&&json_decode($json,true)['ok']===false,'bad CSRF returns JSON error');
 $testActor=4;[$code,$html]=issueRequest(['issue'=>(string)$id]);verify6($code!==200&&!test_contains($html,$result['incident_id']),'other driver cannot read issue');$testActor=3;
 $testActor=1;$testRole='owner_admin';[$code,$html]=issueRequest(['issue'=>(string)$id]);verify6($code===200&&test_contains($html,'Save decision and notify driver'),'Owner has dispatch form');$testActor=3;$testRole='delivery_driver';
 verify6((int)$db->query("SELECT COUNT(*) FROM delivery_outbox WHERE event_key LIKE 'issue:%'")->fetchColumn()>0,'incident and dispatch outbox committed together');
 NotificationOutbox::drain($db,50);verify6((int)$db->query("SELECT COUNT(*) FROM notifications WHERE action_link='/apps/delivery/index.php?issue=$id'")->fetchColumn()>0,'existing notification inbox gets actionable issue link');
 rejects6(fn()=>$decide('retry_today',[],null,null,$actor),'driver cannot decide own issue');
 $decide('request_address');verify6($record()['blocking']&&$record()['acknowledged_at']!==null,'address request acknowledges but keeps hold');
 $decide('return_shop');verify6($record()['blocking'],'return to shop does not complete customer delivery');
 $decide('escalate');verify6($record()['blocking'],'escalation retains hold');
 $v=$version();$key=uuid6();$decision=$decide('retry_today',[],$key,$v);verify6(!$record()['blocking'],'explicit retry releases hold');
 verify6($decide('retry_today',[],$key,$v)===$decision,'dispatch retry returns original decision');rejects6(fn()=>$decide('return_shop',[],null,$v),'competing stale dispatch decision rejected');
 NotificationOutbox::drain($db,50);$feed=DriverInbox::feed($db,$actor);verify6(in_array('issue_decision',array_column($feed['items'],'kind'),true),'driver receives actual dispatch response in existing inbox');
 foreach(['wrong_address','unavailable','other'] as $reason){$fields=array_replace($input,['reason'=>$reason,'contact_attempted'=>$reason==='unavailable'?'1':'0','urgent'=>$reason==='other'?'1':'0']);$r=$report($fields);verify6($r['reason']===$reason,'reason accepted: '.$reason);if($reason==='other')verify6(!$r['contact_attempted']&&!$r['evidence']&&$r['urgent'],'safety report needs neither call, photo nor GPS');$decide('retry_today');}
 $report();rejects6(fn()=>$decide('reschedule'),'reschedule requires customer confirmation');
 rejects6(fn()=>$decide('reschedule',['customer_confirmed'=>'1','preference_date'=>'2020-01-01','preference_time'=>'09:00']),'past reschedule rejected');
 $future=(new DateTimeImmutable('tomorrow',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$decide('reschedule',['customer_confirmed'=>'1','preference_date'=>$future,'preference_time'=>'10:30']);
 verify6(!$record()['blocking']&&$db->query("SELECT scheduled_date FROM delivery_jobs WHERE id=$id")->fetchColumn()===$future,'only confirmed reschedule changes delivery date');
 verify6(!in_array($id,array_column(DriverAssignments::read($db,3)['deliveries'],'assignmentId'),true),'future reschedule leaves today list, not marked Done');
 [$code,$html]=issueRequest(['issue'=>(string)$id]);verify6($code===200&&test_contains($html,'10:30'),'driver can read future reschedule instruction');
 rejects6(fn()=>$report(),'cannot report from stale previous-day tab');$db->prepare('UPDATE delivery_jobs SET scheduled_date=? WHERE id=?')->execute([$today,$id]);
 foreach(['cancelled','completed','awaiting_reconciliation','failed'] as $status){$db->prepare('UPDATE delivery_jobs SET status=? WHERE id=?')->execute([$status,$id]);rejects6(fn()=>$report(),'closed/review delivery rejected: '.$status);}$db->exec("UPDATE delivery_jobs SET status='ready' WHERE id=$id");
 foreach(['deleted_at','archived_at'] as $col){$db->exec("UPDATE ops_orders SET $col=UTC_TIMESTAMP() WHERE id=$order");rejects6(fn()=>$report(),'removed source rejected: '.$col);$db->exec("UPDATE ops_orders SET $col=NULL WHERE id=$order");}
 $db->exec("UPDATE ops_orders SET status='cancelled' WHERE id=$order");rejects6(fn()=>$report(),'cancelled source rejected');$db->exec("UPDATE ops_orders SET status='in_progress' WHERE id=$order");
 $db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");rejects6(fn()=>$report(),'inactive driver rejected');$db->exec("UPDATE ops_employees SET status='active' WHERE id=3");
 $db->exec("UPDATE delivery_jobs SET driver_employee_id=4 WHERE id=$id");rejects6(fn()=>$report(),'reassignment rejected');[$code,$png]=issueRequest(['issue'=>(string)$id,'evidence'=>$file['id']]);verify6($code!==200&&!test_starts_with($png,"\x89PNG"),'former driver loses photo access');$db->exec("UPDATE delivery_jobs SET driver_employee_id=3 WHERE id=$id");
 // Simulate dispatch infrastructure unavailable: no recipient must roll back incident and version.
 $db->exec("UPDATE ops_employees SET status='inactive' WHERE id IN (1,2)");$beforeV=$version();$beforeEvent=$record()['incident_id'];rejects6(fn()=>$report(),'no dispatch recipient rejects and rolls back');verify6($version()===$beforeV&&$record()['incident_id']===$beforeEvent,'failed dispatch setup commits no orphan incident');$db->exec("UPDATE ops_employees SET status='active' WHERE id IN (1,2)");
 $r=$report(array_replace($input,['notes'=>'<script>alert(1)</script> synthetic text only.']));[$code,$html]=issueRequest(['issue'=>(string)$id]);verify6(test_contains($html,'&lt;script&gt;')&&!test_contains($html,'<script>alert(1)'),'stored notes safely escaped');
 $testActor=1;$testRole='owner_admin';[$code,$json]=issueRequest(['issue'=>(string)$id],'POST',['action'=>'decide','csrf'=>'phase6-front-csrf','version'=>(string)$version(),'request_id'=>uuid6(),'incident_id'=>$r['incident_id'],'decision'=>'retry_today','instruction'=>'Synthetic controller approval; retry the delivery.']);verify6($code===200&&json_decode($json,true)['ok']===true,'actual dispatch controller applies authorized decision');$testActor=3;$testRole='delivery_driver';
 [$code,$json]=issueRequest(['issue'=>(string)$id],'POST',$input+['action'=>'report','csrf'=>'phase6-test-csrf','version'=>(string)$version(),'request_id'=>uuid6()]);verify6($code===200&&json_decode($json,true)['ok']===true,'actual driver controller reports without optional photo');
 verify6($moneyBefore===$db->query('SELECT * FROM order_payment_allocations ORDER BY id')->fetchAll(),'payment allocations unchanged across all issue actions');verify6($ordersBefore===$db->query('SELECT * FROM ops_orders ORDER BY id')->fetchAll(),'source orders unchanged');verify6($ledgerBefore===$db->query('SELECT * FROM delivery_ledger ORDER BY id')->fetchAll(),'money ledger unchanged');
 verify6((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$id AND event_type IN ('delivered','cancelled')")->fetchColumn()===0,'no issue action completes or cancels delivery');
 $decide('retry_today');
 $db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,'parcel_collected',3,?,UTC_TIMESTAMP())")->execute([$id,json_encode(['driver_id'=>3])]);$db->exec("UPDATE delivery_jobs SET status='out_for_delivery' WHERE id=$id");
 $signature=$storage->prepare(['tmp_name'=>$photoPath,'error'=>0],'signature',true);$created[]=$signature;
 $rep=['recipient_name'=>'Synthetic representative','recipient_type'=>'representative','relationship'=>'Customer colleague','acknowledged'=>'1'];
 rejects6(fn()=>new ProofSubmission($rep,['photo'=>$file],$version()),'default submission enforces signature rather than a photo');
 $deliveryService=new DeliveryService($db,[],static fn($d,$j)=>(new \Hambelela\Delivery\OrderBalanceAdapter())->read($d,$j));
 $deliveryService->arrive($actor,$id,uuid6(),$version());
 $completion=$deliveryService->complete($actor,$id,uuid6(),null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid','note'=>''],new ProofSubmission($rep,['signature'=>$signature],$version()));
 verify6($completion['status']==='completed'&&$completion['proof']['policy']['evidence']==='signature'&&$completion['proof']['recipient']['relationship']==='Customer colleague','resolved issue resumes normal completion with representative signature and no photo');
 $clean=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);verify6($original===$clean->query('SELECT id,status,version FROM delivery_jobs ORDER BY id')->fetchAll(),'all test commits leave original practice data untouched');
 echo "TOTAL $checks issue checks passed\n";ob_end_flush();
}finally{$storage->discard($created);foreach($fixtures as $p)if(is_file($p))unlink($p);putenv('HO_DRIVER_PROOF_DIR');}
