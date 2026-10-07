<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
if(($argv[1]??'')==='--worker'){
    require __DIR__.'/../shared/delivery/CoreApi.php';
    $input=json_decode(base64_decode($argv[2],true),true,32,JSON_THROW_ON_ERROR);
    $db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    try{$result=(new \Hambelela\Delivery\CoreApi($db,['cash'=>'Cash']))->command($input['actor'],$input['body']);echo json_encode(['ok'=>true,'result'=>$result]);}
    catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>get_class($e)]);}
    exit;
}
require __DIR__.'/delivery-production-core.php';
function raceDelivery(array $commands):array{
    $processes=[];$results=[];
    try{
        foreach($commands as $input){$pipes=[];$process=proc_open([PHP_BINARY,'-d','extension=pdo_mysql',__FILE__,'--worker',base64_encode(json_encode($input))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start concurrency worker');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$processes[]=[$process,$pipes,'',''];}
        $deadline=microtime(true)+30;
        do{$running=false;foreach($processes as &$p){$p[2].=stream_get_contents($p[1][1]);$p[3].=stream_get_contents($p[1][2]);if(proc_get_status($p[0])['running'])$running=true;}unset($p);if($running)usleep(10000);}while($running&&microtime(true)<$deadline);
        if($running)throw new RuntimeException('Concurrency worker timed out');
        foreach($processes as &$p){$p[2].=stream_get_contents($p[1][1]);$decoded=json_decode($p[2],true);if(!is_array($decoded))throw new RuntimeException('Worker did not return JSON: '.$p[3]);$results[]=$decoded;}unset($p);
        return $results;
    }finally{foreach($processes as $p){if(proc_get_status($p[0])['running'])proc_terminate($p[0]);fclose($p[1][1]);fclose($p[1][2]);proc_close($p[0]);}}
}
$raceOrder=$id+4;$db->exec("INSERT INTO ops_orders(id,total_amount,notes) VALUES($raceOrder,450,'Shipping address: Synthetic race address')");
$raceBody=$body;$raceBody['order']=$raceOrder;$raceBody['uuid']=uid();
$outcomes=raceDelivery([['actor'=>$front,'body'=>$raceBody],['actor'=>$front,'body'=>$raceBody]]);
$s=$db->prepare('SELECT id FROM delivery_jobs WHERE order_id=?');$s->execute([$raceOrder]);$created=$s->fetchAll(PDO::FETCH_COLUMN);
ok(count($created)===1,'parallel arrangement creates one delivery');
ok(count(array_filter($outcomes,static function($r){return $r['ok'];}))>=1,'at least one arrangement commits');
$raceJob=(int)$created[0];
$core->command($driver,['action'=>'start','id'=>$raceJob,'uuid'=>uid()]);
$core->command($driver,['action'=>'collect','id'=>$raceJob,'uuid'=>uid(),'goods'=>'450','fee'=>'60','method'=>'cash']);
$core->command($driver,['action'=>'complete','id'=>$raceJob,'uuid'=>uid()]);
$receipt=(int)$db->query("SELECT id FROM delivery_receipts WHERE delivery_id=$raceJob")->fetchColumn();
$one=['action'=>'reconcile','id'=>$raceJob,'receipt'=>$receipt,'uuid'=>uid()];$two=$one;$two['uuid']=uid();
$outcomes=raceDelivery([['actor'=>$front,'body'=>$one],['actor'=>$front,'body'=>$two]]);
ok(count(array_filter($outcomes,static function($r){return $r['ok'];}))>=1,'parallel reconciliation completes');
ok((int)$db->query("SELECT SUM(amount_cents) FROM delivery_ledger WHERE delivery_id=$raceJob AND account='fee_received'")->fetchColumn()===6000,'parallel reconciliation receives fee only once');
ok($db->query("SELECT status FROM delivery_jobs WHERE id=$raceJob")->fetchColumn()==='completed','concurrent handover leaves delivery completed');
ok($db->query("SELECT payment_status FROM ops_orders WHERE id=$raceOrder")->fetchColumn()==='paid','concurrent handover credits linked Order');
