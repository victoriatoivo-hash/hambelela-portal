<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/shared/delivery/ErrandWorkflow.php';
use Hambelela\Delivery\{ErrandWorkflow,ErrandData};
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')throw new RuntimeException('Wrong database');
$db->exec(file_get_contents(BASE_PATH.'/database/delivery/007-errand-requests-proposed.sql'));
$uuid=static fn()=>bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));$service=new ErrandWorkflow($db);
$today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
$titles=['Collect packaging supplies','Deliver supplier documents','Drop off reusable containers','Collect invoice copies','Return packaging samples','Collect printing samples','Drop off sample documents'];
foreach($titles as $i=>$title){
 $contents='LOCAL PHASE07 REVIEW #'.($i+1).' — '.($i===0?'Ask for the training order and confirm quantities before leaving.':'Synthetic instructions for local review only.');
 $s=$db->prepare('SELECT id FROM delivery_errands WHERE contents=?');$s->execute([$contents]);if($s->fetchColumn())continue;
 $v=['driver_id'=>'3','title'=>$title,'place'=>$i===0?'Southern Industrial Area · Windhoek':'Central Windhoek','contact'=>'Training counter','instructions'=>$contents,'type'=>$i===1?'handover':'collection','proof'=>$i===0?'photo':'checklist','priority'=>$i===0?'1':'0','phone'=>'','due'=>$today.'T'.($i===0?'13:00':'16:00'),'items'=>[['name'=>$i===1?'Sealed documents':'Supplies','quantity'=>$i===0?'2':'1','unit'=>$i===1?'envelope':'boxes']],'checklist'=>['Confirm quantities and condition','Confirm handover with the named contact'],'payment_state'=>'paid','amount'=>'','payer'=>'Training only — no expense or payment'];
 $r=$service->command(2,$uuid(),'create',0,0,$v);$id=$r['id'];
 if($i>=2){$j=ErrandData::detail($db,3,$id);$service->command(3,$uuid(),'start',$id,$j['revision'],[]);}
 if($i>=3){$j=ErrandData::detail($db,3,$id);$service->command(3,$uuid(),'complete',$id,$j['revision'],['notes'=>'Synthetic handover verified for local review only.','recipient'=>'Training recipient','actual'=>['1'],'checks'=>['0','1']]);}
 echo 'Created synthetic errand '.$id.' ('.$title.")\n";
}
$board=ErrandData::board($db,3);echo json_encode(['counts'=>$board['counts'],'practice_items'=>array_map(static fn($i)=>['id'=>$i['id'],'status'=>$i['status'],'title'=>$i['title']],$board['items'])],JSON_PRETTY_PRINT)."\n";
