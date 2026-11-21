<?php
declare(strict_types=1);
// Only a disposable synthetic errand in the loopback practice DB; never loads live config.
if(PHP_SAPI!=='cli')exit(1);define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/delivery/ErrandWorkflow.php';
use Hambelela\Delivery\{ErrandWorkflow,ErrandData};
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$done=['actual'=>['2'],'recipient'=>'Synthetic race recipient','notes'=>'Local race test: both training boxes counted.','checks'=>['0']];
if(($argv[1]??'')==='--worker'){
 $id=(int)$argv[2];$version=(int)$argv[3];$action=$argv[4];$key=$argv[5];
 $q=$db->prepare("SELECT id FROM delivery_errands WHERE id=? AND contents LIKE 'LOCAL-PHASE07-RACE-%'");$q->execute([$id]);if(!$q->fetchColumn())exit(2);
 try{$r=(new ErrandWorkflow($db))->command(3,$key,$action,$id,$version,$action==='complete'?$done:[]);echo json_encode(['ok'=>true,'result'=>$r]);}catch(DomainException $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}exit;
}
$uuid=static fn()=>bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));$keys=[];$jobs=[];$processes=[];$tag='LOCAL-PHASE07-RACE-'.bin2hex(random_bytes(8));$count=0;
$check=static function($condition,$message)use(&$count){if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n";};
$service=new ErrandWorkflow($db);
try{
 $create=function()use($service,$uuid,&$keys,&$jobs,$tag){$key=$uuid();$keys[]=$key;$r=$service->command(2,$key,'create',0,0,['driver_id'=>'3','title'=>'Synthetic concurrency exercise','place'=>'Local practice warehouse','contact'=>'Synthetic test contact','instructions'=>$tag,'type'=>'collection','proof'=>'checklist','items'=>[['name'=>'Training boxes','quantity'=>'2','unit'=>'boxes']],'checklist'=>['Count boxes'],'payment_state'=>'paid']);$jobs[]=$r['id'];return $r['id'];};
 $parallel=function(int $id,string $action,bool $same)use($db,$uuid,&$keys,&$processes){
  $version=ErrandData::detail($db,3,$id)['revision'];$one=$uuid();$two=$same?$one:$uuid();$keys[]=$one;$keys[]=$two;
  $db->beginTransaction();$q=$db->prepare('SELECT id FROM delivery_errands WHERE id=? FOR UPDATE');$q->execute([$id]);$workers=[];
  foreach([$one,$two] as $key){$cmd=[PHP_BINARY,'-n','-d','extension_dir='.dirname(PHP_BINARY).'/ext','-d','extension=pdo_mysql','-d','extension=mbstring',__FILE__,'--worker',(string)$id,(string)$version,$action,$key];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($p))throw new RuntimeException('Worker launch failed.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['p'=>$p,'pipes'=>$pipes,'out'=>'','err'=>''];$processes[]=$p;}
  usleep(500000);$db->commit();$deadline=microtime(true)+10;
  do{$running=false;foreach($workers as &$w){$w['out'].=stream_get_contents($w['pipes'][1]);$w['err'].=stream_get_contents($w['pipes'][2]);$running=proc_get_status($w['p'])['running']||$running;}unset($w);if($running)usleep(20000);}while($running&&microtime(true)<$deadline);
  $out=[];foreach($workers as &$w){if(proc_get_status($w['p'])['running']){proc_terminate($w['p']);throw new RuntimeException('Worker timed out.');}$w['out'].=stream_get_contents($w['pipes'][1]);$w['err'].=stream_get_contents($w['pipes'][2]);fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['p']);if($w['err'])throw new RuntimeException($w['err']);$out[]=json_decode($w['out'],true,512,JSON_THROW_ON_ERROR);}unset($w);return $out;
 };
 foreach([true,false] as $same){$id=$create();foreach(['start','complete'] as $action){$r=$parallel($id,$action,$same);$check($same?($r[0]['ok']&&$r[1]['ok']&&$r[0]['result']===$r[1]['result']):(count(array_filter($r,fn($x)=>$x['ok']))===1),($same?'Identical':'Competing')." concurrent $action returns one canonical outcome");$q=$db->prepare('SELECT COUNT(*) FROM delivery_errand_events WHERE errand_id=? AND action=?');$q->execute([$id,$action==='start'?'started':'completed']);$check((int)$q->fetchColumn()===1,"Exactly one $action audit event");}}
 echo "TOTAL $count two-process errand race checks passed\n";
}finally{
 if($db->inTransaction())$db->rollBack();foreach($processes as $p)if(is_resource($p))proc_terminate($p);
 foreach($jobs as $id){$db->beginTransaction();$q=$db->prepare('SELECT id FROM delivery_errands WHERE id=? AND contents=? FOR UPDATE');$q->execute([$id,$tag]);if(!$q->fetchColumn()){$db->rollBack();throw new RuntimeException('Synthetic fixture identity mismatch; cleanup refused.');}
  $q=$db->prepare("SELECT id FROM notifications WHERE related_type='delivery_errand' AND related_id=?");$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $notice){$db->prepare('DELETE FROM notification_recipients WHERE notification_id=?')->execute([$notice]);$db->prepare('DELETE FROM notifications WHERE id=?')->execute([$notice]);}
  foreach(['delivery_errand_events','delivery_errand_details'] as $table)$db->prepare("DELETE FROM $table WHERE errand_id=?")->execute([$id]);$db->prepare('DELETE FROM delivery_errands WHERE id=? AND contents=?')->execute([$id,$tag]);$db->commit();
 }
 foreach(array_unique($keys) as $key)$db->prepare("DELETE FROM delivery_requests WHERE request_uuid=? AND actor_key IN ('employee:2','employee:3') AND action LIKE 'errand_%'")->execute([$key]);
 echo "Removed only this run's synthetic race fixtures and test inbox events.\n";
}
