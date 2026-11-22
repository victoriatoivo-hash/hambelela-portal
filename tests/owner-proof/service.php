<?php
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__,2));require BASE_PATH.'/shared/delivery/OwnerProofViewer.php';
use Hambelela\Delivery\{OwnerProofViewer,ProofStorage};
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$count=0;function check($b,$name){global $count;if(!$b)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name,"\n";$count++;}
function denied($fn,$code,$name){try{$fn();check(false,$name);}catch(RuntimeException $e){check($e->getCode()===$code,$name);}}
$before=[];foreach(['delivery_jobs','delivery_events','ops_orders','order_payment_allocations','delivery_receipts','delivery_owner_proof_actions','delivery_outbox','notifications','notification_recipients'] as $t)$before[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();
// Shadow only the review/outbox stores. Permanent fixture records cannot be modified by these actions.
foreach(['delivery_owner_proof_actions','delivery_outbox'] as $t){$ddl=$db->query('SHOW CREATE TABLE '.$t)->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);}
$v=new OwnerProofViewer($db,1);$data=$v->read(16);check((int)$data['job']['id']===16,'canonical delivery id');check((int)$data['order']['id']===103,'source order is distinct');check(count($data['declarations'])>0,'actual handover payment projection');
foreach([2,3,4,5,999] as $actor)denied(static fn()=>new OwnerProofViewer($db,$actor),403,'deny non-owner '.$actor);
denied(static fn()=>$v->read(999999),404,'missing delivery');
denied(static fn()=>$v->file(16,str_repeat('f',48)),404,'foreign or guessed evidence denied');
$job=['id'=>1,'order_id'=>2];$e=['id'=>4,'employee_id'=>3,'event_type'=>'delivered','created_at'=>'2026-11-21 10:00:00','metadata_json'=>json_encode(['proof'=>['receipt'=>str_repeat('a',32),'delivery_id'=>99,'order_id'=>2,'driver_id'=>3,'evidence'=>[['id'=>str_repeat('a',48),'kind'=>'signature','stored_sha256'=>str_repeat('a',64),'mime'=>'image/png']]]])];
check(!OwnerProofViewer::evidence($job,[$e])['files'],'mismatched delivery evidence rejected');$m=json_decode($e['metadata_json'],true);$m['proof']['delivery_id']=1;$m['proof']['driver_id']=99;$e['metadata_json']=json_encode($m);check(!OwnerProofViewer::evidence($job,[$e])['files'],'mismatched evidence actor rejected');
check(OwnerProofViewer::time('2026-11-21 10:00:00')==='Sat, 21 Nov 2026 · 12:00','UTC to Namibia display');check(OwnerProofViewer::time(null)==='Time not recorded','missing time not invented');
$uuid=static fn()=>bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));
$input=['action'=>'note','body'=>'Synthetic owner note <script>alert(1)</script>','request_id'=>$uuid(),'version'=>$data['version']];$first=$v->action(16,$input);$again=$v->action(16,$input);check($first['id']===$again['id']&&$again['repeated'],'exact retry idempotent');$input['body']='Different content';denied(static fn()=>$v->action(16,$input),409,'UUID cannot be reused with different payload');
$input['request_id']=$uuid();$input['version']=str_repeat('0',64);denied(static fn()=>$v->action(16,$input),409,'stale evidence version rejected');$input['version']=$data['version'];$input['body']='';denied(static fn()=>$v->action(16,$input),422,'empty note rejected');
$input=['action'=>'review','body'=>'Documented test exception: no sample proof claimed.','request_id'=>$uuid(),'version'=>$data['version']];$a=$v->action(16,$input);$input['request_id']=$uuid();$b=$v->action(16,$input);check($a['id']===$b['id'],'review duplicate click idempotent');
$input=['action'=>'discrepancy','body'=>'Synthetic payment mismatch review only','reason'=>'payment','request_id'=>$uuid(),'version'=>$data['version']];$d=$v->action(16,$input);check(count($v->read(16)['open'])===1,'discrepancy open');check((int)$db->query('SELECT COUNT(*) FROM delivery_outbox')->fetchColumn()>0,'dispatch notice queued transactionally');
$input=['action'=>'resolve','body'=>'Compared synthetic receipts and documented the resolution.','related_id'=>$d['id'],'request_id'=>$uuid(),'version'=>$data['version']];$v->action(16,$input);check(!$v->read(16)['open'],'explicit resolution append-only');$input['request_id']=$uuid();denied(static fn()=>$v->action(16,$input),409,'second resolution rejected');
check(count($v->read(16)['actions'])===4,'exact audit count');
$altered=$data['job'];$altered['version']=(int)$altered['version']+1;check(OwnerProofViewer::version($altered,$data['events'])!==$data['version'],'changed delivery invalidates review version');
$alteredEvents=$data['events'];$alteredEvents[]=['id'=>999,'event_type'=>'delivered','metadata_json'=>'new proof'];check(OwnerProofViewer::version($data['job'],$alteredEvents)!==$data['version'],'new evidence invalidates review version');
foreach(range(1,16) as $n)$v->action(16,['action'=>'note','body'=>'Rate limit test '.$n,'request_id'=>$uuid(),'version'=>$data['version']]);
denied(static fn()=>$v->action(16,['action'=>'note','body'=>'Rate limit test overflow','request_id'=>$uuid(),'version'=>$data['version']]),429,'owner write rate limit');
$fresh=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);foreach($before as $t=>$rows)check($rows===$fresh->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(),'unchanged permanent '.$t);
echo "TOTAL $count checks passed\n";
