<?php
declare(strict_types=1);
// Two real PDO connections/processes, one disposable synthetic fixture, no live configuration.
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/shared/delivery/DeliveryService.php';
use Hambelela\Delivery\{DeliveryService,DeliveryIssueState,EmployeeDriverSession,FrontSession};
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$uuid=static fn()=>bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));
if(($argv[1]??'')==='--worker'){
 $id=(int)$argv[2];$version=(int)$argv[3];$action=$argv[4];$key=$argv[5];
 $q=$db->prepare("SELECT public_reference FROM delivery_jobs WHERE id=? AND public_reference LIKE 'LAB-PHASE06-RACE-%'");$q->execute([$id]);if(!$q->fetchColumn())exit(2);
 $service=new DeliveryService($db,[],static function(){throw new LogicException('Race test cannot access money.');});
 try{
  if($action==='report')$r=$service->reportIssue(EmployeeDriverSession::actor($db,3),$id,$key,$version,['reason'=>'other','notes'=>'Synthetic concurrent report for automated testing only.','contact_attempted'=>'0']);
  else $r=$service->decideIssue(FrontSession::actor($db,2),$id,$key,$version,['incident_id'=>$argv[6],'decision'=>'retry_today','instruction'=>'Synthetic concurrency test: retry today is authorized.']);
  echo json_encode(['ok'=>true,'result'=>$r]);
 }catch(DomainException $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
 exit;
}
$ref='LAB-PHASE06-RACE-'.bin2hex(random_bytes(8));$job=0;$order=0;$keys=[];$processes=[];
$require=static function($condition,$message){if(!$condition)throw new RuntimeException($message);echo 'PASS '.$message."\n";};
try{
 $db->beginTransaction();$order=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM ops_orders FOR UPDATE')->fetchColumn();
 $db->prepare("INSERT INTO ops_orders(id,order_number,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status) VALUES(?,?,120,'paid','EFT','Synthetic race test','','Disposable local test','delivery','in_progress')")->execute([$order,$ref]);
 $db->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES(?,'EFT',12000,'pos')")->execute([$order]);
 $date=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
 $db->prepare("INSERT INTO delivery_jobs(public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_payer,scheduled_date,status,driver_employee_id,arranged_by_employee_id,notes) VALUES(?,'hambelela',?,'Synthetic race test','','Training warehouse, Windhoek','Local race test',0,'already_paid',?,'ready',3,2,'Disposable synthetic race fixture')")->execute([$ref,$order,$date]);$job=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,'arranged',2,?,UTC_TIMESTAMP())")->execute([$job,json_encode(['local_fixture'=>'phase6-race','payment_arrangement'=>'already_paid','order_dispatch'=>['total_cents'=>12000]])]);$db->commit();
 $parallel=function(string $action,bool $same)use($db,$job,$uuid,&$keys,&$processes){
  $q=$db->prepare('SELECT version FROM delivery_jobs WHERE id=?');$q->execute([$job]);$v=(int)$q->fetchColumn();$incident=DeliveryIssueState::latest($db,$job)['incident_id']??'';$one=$uuid();$two=$same?$one:$uuid();$keys[]=$one;$keys[]=$two;
  // Force both workers to wait on the same authoritative job row before releasing it.
  $db->beginTransaction();$q=$db->prepare('SELECT id FROM delivery_jobs WHERE id=? FOR UPDATE');$q->execute([$job]);$workers=[];
  foreach([$one,$two] as $key){$cmd=[PHP_BINARY,'-n','-d','extension_dir='.dirname(PHP_BINARY).'/ext','-d','extension=pdo_mysql','-d','extension=mbstring',__FILE__,'--worker',(string)$job,(string)$v,$action,$key,$incident];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($p))throw new RuntimeException('Could not launch race worker.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$p,'pipes'=>$pipes,'out'=>'','error'=>''];$processes[]=$p;}
  usleep(500000);$db->commit();$deadline=microtime(true)+10;
  do{$running=false;foreach($workers as &$w){$w['out'].=stream_get_contents($w['pipes'][1]);$w['error'].=stream_get_contents($w['pipes'][2]);$running=proc_get_status($w['process'])['running']||$running;}unset($w);if($running)usleep(20000);}while($running&&microtime(true)<$deadline);
  $result=[];foreach($workers as &$w){if(proc_get_status($w['process'])['running']){proc_terminate($w['process']);throw new RuntimeException('Race worker timed out.');}$w['out'].=stream_get_contents($w['pipes'][1]);$w['error'].=stream_get_contents($w['pipes'][2]);fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['process']);if($w['error'])throw new RuntimeException('Race worker error: '.$w['error']);$result[]=json_decode($w['out'],true,512,JSON_THROW_ON_ERROR);}unset($w);return $result;
 };
 $r=$parallel('report',true);$require($r[0]['ok']&&$r[1]['ok']&&$r[0]['result']===$r[1]['result'],'simultaneous identical report retries return one canonical receipt');
 $q=$db->prepare("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=? AND event_type='delivery_issue_reported'");$q->execute([$job]);$require((int)$q->fetchColumn()===1,'simultaneous retries store exactly one incident');
 $r=$parallel('decide',false);$require(count(array_filter($r,static fn($x)=>$x['ok']))===1,'competing dispatch decisions have exactly one winner');
 $q=$db->prepare("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=? AND event_type='dispatch_resolution_recorded'");$q->execute([$job]);$require((int)$q->fetchColumn()===1,'one dispatch decision and no silent overwrite');
 $r=$parallel('report',false);$require(count(array_filter($r,static fn($x)=>$x['ok']))===1,'different concurrent report keys cannot create duplicate open incidents');
 $q=$db->prepare("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=? AND event_type='delivery_issue_reported'");$q->execute([$job]);$require((int)$q->fetchColumn()===2,'one new incident after explicit retry, not two');
 $q=$db->prepare('SELECT total_amount,payment_status,status FROM ops_orders WHERE id=?');$q->execute([$order]);$o=$q->fetch();$require($o['total_amount']==='120.00'&&$o['payment_status']==='paid'&&$o['status']==='in_progress','parallel actions preserve source order and payment');
 $q=$db->prepare('SELECT COUNT(*) FROM delivery_ledger WHERE delivery_id=?');$q->execute([$job]);$require((int)$q->fetchColumn()===0,'parallel issue actions create no money ledger entries');
 echo "TOTAL 8 two-process race checks passed\n";
}finally{
 if($db->inTransaction())$db->rollBack();foreach($processes as $p)if(is_resource($p))proc_terminate($p);
 if($job){
  $db->beginTransaction();$q=$db->prepare('SELECT order_id FROM delivery_jobs WHERE id=? AND public_reference=? FOR UPDATE');$q->execute([$job,$ref]);
  if((int)$q->fetchColumn()!==$order){$db->rollBack();throw new RuntimeException('Fixture identity mismatch; cleanup refused.');}
  foreach(array_unique($keys) as $key)$db->prepare("DELETE FROM delivery_requests WHERE request_uuid=? AND actor_key IN ('employee:2','employee:3')")->execute([$key]);
  foreach(['delivery_outbox','delivery_events'] as $table)$db->prepare("DELETE FROM $table WHERE delivery_id=?")->execute([$job]);
  $db->prepare('DELETE FROM delivery_jobs WHERE id=? AND public_reference=?')->execute([$job,$ref]);$db->prepare('DELETE FROM order_payment_allocations WHERE order_id=?')->execute([$order]);$db->prepare('DELETE FROM ops_orders WHERE id=? AND order_number=?')->execute([$order,$ref]);$db->commit();echo "Disposable race fixture removed; existing practice records preserved.\n";
 }
}
