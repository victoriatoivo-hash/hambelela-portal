<?php
// CLI-only synthetic screen fixture; never loads a live portal configuration.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')throw new RuntimeException('Local database only.');
$indexes=[];foreach($db->query('SHOW INDEX FROM delivery_events') as $r)$indexes[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];
$equivalent=false;foreach($indexes as $columns){ksort($columns);if(array_slice(array_values($columns),0,3)===['employee_id','event_type','created_at'])$equivalent=true;}
if(!$equivalent)$db->exec(file_get_contents(BASE_PATH.'/database/delivery/010-history-indexes-proposed.sql'));
echo "Local history index ready.\n";
if(($argv[1]??'')!=='fixtures')exit;
require_once BASE_PATH.'/shared/delivery/CoreApi.php';
require_once BASE_PATH.'/shared/delivery/EmployeeDriverSession.php';
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
function uuid_test(){return bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));}
function arrange_test(PDO $db,int $id):void {
 $source=static function(string $path):array {
  if(!preg_match('~orders/(\d+)~',$path,$m))throw new DomainException('No external connections in offline review.');
  return ['id'=>(int)$m[1],'currency'=>'NAD','status'=>'processing','total'=>'120.00','shipping_total'=>'40.00','shipping_tax'=>'0.00','shipping_lines'=>[['total'=>'40.00','total_tax'=>'0.00']],'refunds'=>[],'billing'=>['phone'=>''],'shipping'=>[]];
 };
 $core=new \Hambelela\Delivery\CoreApi($db,['cash'=>'Cash','eft'=>'EFT'],$source);$front=\Hambelela\Delivery\FrontSession::actor($db,2);
 $quote=$core->command($front,['action'=>'quote','source_order'=>true,'order'=>$id]);
 $core->command($front,['action'=>'arrange','source_order'=>true,'order'=>$id,'driver'=>3,'date'=>date('Y-m-d'),'verification'=>$quote['verification'],'uuid'=>uuid_test(),'address'=>'Training warehouse, Windhoek','mobile'=>'+264810000000','urgent'=>false]);
}
use Hambelela\Delivery\{DeliveryService,OrderBalanceAdapter,EmployeeDriverSession,FrontSession,ProofContext,ProofSubmission,ProofStorage};
date_default_timezone_set('Africa/Windhoek');
$ref='LAB-HISTORY10-POD';$q=$db->prepare('SELECT id,notes FROM ops_orders WHERE order_number=?');$q->execute([$ref]);$row=$q->fetch();
if($row&&!str_contains($row['notes'],'Synthetic Phase10'))throw new RuntimeException('Reference already used by a different fixture.');
if(!$row){$id=(int)$db->query('SELECT MAX(id)+1 FROM ops_orders')->fetchColumn();$db->beginTransaction();try{
 $db->prepare("INSERT INTO ops_orders(id,order_number,woo_order_id,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status) VALUES(?,?,?,120,'paid','EFT','History Test Recipient','','Synthetic Phase10 review only','delivery','in_progress')")->execute([$id,$ref,$id]);
 $db->prepare("INSERT INTO ops_order_items(order_id,product_name,quantity) VALUES(?,'Synthetic history practice parcel',1)")->execute([$id]);
 $db->prepare("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES(?,'EFT',12000,'pos')")->execute([$id]);$db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}}else $id=(int)$row['id'];
$q=$db->prepare('SELECT * FROM delivery_jobs WHERE order_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);$job=$q->fetch();
if(!$job){arrange_test($db,$id);$q->execute([$id]);$job=$q->fetch();}
$jid=(int)$job['id'];if(ProofContext::receipt($db,$jid)){echo "Existing synthetic signed-POD fixture retained.\n";exit;}
$service=new DeliveryService($db,['cash','eft'],fn($d,$j)=>(new OrderBalanceAdapter)->read($d,$j));$driver=EmployeeDriverSession::actor($db,3);$front=FrontSession::actor($db,2);
if(!$db->query("SELECT id FROM delivery_events WHERE delivery_id=$jid AND event_type='parcel_collected'")->fetchColumn())$service->pickup($front,$jid,uuid_test(),3);
if($job['status']==='ready')$service->start($driver,$jid,uuid_test());
$ctx=ProofContext::read($db,3,$jid);if(!$ctx['arrival'])$service->arrive($driver,$jid,uuid_test(),$ctx['version']);$ctx=ProofContext::read($db,3,$jid);
putenv('HO_DRIVER_PROOF_DIR='.dirname(BASE_PATH).'/driver-proof-private-review');$store=ProofStorage::configured();
$temp=dirname(BASE_PATH).'/driver-proof-private-review/history-synthetic-pattern.png';$im=imagecreatetruecolor(260,110);imagefill($im,0,0,imagecolorallocate($im,255,255,255));$ink=imagecolorallocate($im,35,65,45);imagerectangle($im,10,10,245,95,$ink);imagestring($im,4,20,45,'SYNTHETIC TEST - NOT A SIGNATURE',$ink);imagepng($im,$temp);imagedestroy($im);
try{$evidence=$store->prepare(['tmp_name'=>$temp,'error'=>UPLOAD_ERR_OK],'signature',true);}finally{if(is_file($temp))unlink($temp);}
$proof=new ProofSubmission(['recipient_name'=>'Synthetic History Recipient','recipient_type'=>'customer','relationship'=>'','acknowledged'=>'1'],['signature'=>$evidence],$ctx['version'],['evidence'=>'signature','representative'=>'relationship']);
$service->complete($driver,$jid,uuid_test(),null,['confirmed'=>true,'amount'=>'0','method'=>'already_paid','note'=>'Synthetic Phase10 test only'],$proof);
echo "Synthetic signed POD created using the existing completion service. No real order changed.\n";
