<?php
// Synthetic TEMPORARY shadow tables only; never writes real records.
require __DIR__.'/test-cash-phase11.php';
require_once BASE_PATH.'/shared/delivery/DriverRecordedHandover.php';
use Hambelela\Delivery\DriverRecordedHandover;
$sql=file_get_contents(BASE_PATH.'/database/delivery/013-driver-recorded-handovers.sql');
$sql=preg_replace('/^--.*$/m','',$sql);$sql=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$sql);$sql=preg_replace('/^\s*FOREIGN KEY[^\n]+\n/m','',$sql);$db->exec($sql);
foreach(['delivery_cash_audit','delivery_cash_allocations','delivery_cash_handovers','delivery_receipts','delivery_events'] as $t)$db->exec('DELETE FROM '.$t);
$service=new DriverRecordedHandover($db);$start=$count;
$db->exec("UPDATE delivery_jobs SET status='awaiting_reconciliation',driver_employee_id=3 WHERE id=$job");
$db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,'delivered',3,?,UTC_TIMESTAMP())")->execute([$job,json_encode(['declaration_review'=>true,'declared_cents'=>17000,'expected_cents'=>15900,'difference_cents'=>1100,'declared_method'=>'cash'])]);
$s=$service->summary(3);ok11($s['handover_due_minor']===17000,'full 170 declaration available including 11 retained for customer');
function simple13($amount,$s){return ['action'=>'driver_confirm','key'=>key11(),'currency'=>'NAD','amount_minor'=>$amount,'note'=>'Test cash handed to Front Desk','attested'=>true,'version'=>$s['handover_version']];}
foreach([0,-1,1.5,'17000',10000000000] as $bad)no11(fn()=>$service->confirm(3,simple13($bad,$s)),'invalid amount refused '.json_encode($bad));
no11(fn()=>$service->confirm(2,simple13(17000,$s)),'front cannot impersonate driver');
no11(fn()=>$service->confirm(3,array_replace(simple13(17000,$s),['attested'=>false])),'driver declaration required');
no11(fn()=>$service->confirm(4,simple13(17000,$s)),'cross-driver snapshot refused');
$p=simple13(10000,$s);$r=$service->confirm(3,$p);ok11($r['state']==='driver_recorded'&&$r['declared_minor']===10000&&strlen($r['created_at'])===19,'single step completed with server timestamp');
ok11($service->summary(3)['handover_due_minor']===7000,'less handed over leaves exact 70 balance');
ok11($service->confirm(3,$p)===$r,'lost response retry returns same record');
no11(fn()=>$service->confirm(3,array_replace($p,['amount_minor'=>11000])),'same key different amount refused');
no11(fn()=>$service->confirm(3,simple13(17000,$s)),'second tab stale balance cannot duplicate');
$s=$service->summary(3);$r2=$service->confirm(3,simple13(8000,$s));
ok11($r2['unallocated_minor']===1000,'extra 10 recorded separately');
ok11($service->summary(3)['handover_due_minor']===0&&$service->summary(3)['handover_variance_minor']===1000,'completed handover no admin gate and extra remains visible');
ok11(count($service->bookkeeping())===2,'both handovers visible in bookkeeping list');
ok11(count($service->summary(4)['handovers'])===0,'other driver cannot see handover history');
// A later 159 receipt must not erase the earlier physical 170 declaration or move its variance to another job.
$db->prepare("INSERT INTO delivery_receipts(delivery_id,collection_uuid,payment_method,order_goods_cents,collected_by_employee_id,collected_at) VALUES(?,?,'cash',15900,3,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 SECOND))")->execute([$job,key11()]);
$s=$service->summary(3);ok11($s['handover_due_minor']===0&&$s['handover_variance_minor']===2100,'later lower payment receipt leaves 11 difference plus 10 extra visible');
$j2=(int)$db->query('SELECT id FROM delivery_jobs WHERE id<>'.$job.' LIMIT 1')->fetchColumn();$db->exec("UPDATE delivery_jobs SET driver_employee_id=3,status='out_for_delivery' WHERE id=$j2");
$db->prepare("INSERT INTO delivery_receipts(delivery_id,collection_uuid,payment_method,order_goods_cents,collected_by_employee_id,collected_at) VALUES(?,?,'cash',5000,3,UTC_TIMESTAMP())")->execute([$j2,key11()]);
ok11($service->summary(3)['handover_due_minor']===5000,'overage never hides cash for a different order');
$s=$service->summary(3);$beforeRows=$db->query('SELECT * FROM delivery_driver_handovers')->fetchAll();
$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");no11(fn()=>$service->confirm(3,simple13(5000,$s)),'revoked driver cannot confirm');$db->exec("UPDATE ops_employees SET status='active' WHERE id=3");
ok11($beforeRows===$db->query('SELECT * FROM delivery_driver_handovers')->fetchAll(),'failed command no handover mutation');
$paymentBefore=$db->query('SELECT * FROM order_payment_allocations')->fetchAll();$ledgerBefore=$db->query('SELECT * FROM delivery_ledger')->fetchAll();
$service->confirm(3,simple13(5000,$s));ok11($paymentBefore===$db->query('SELECT * FROM order_payment_allocations')->fetchAll()&&$ledgerBefore===$db->query('SELECT * FROM delivery_ledger')->fetchAll(),'handover never posts sales or payment allocations');
$who=3;[$status,$body]=http11(['cash'=>1,'feed'=>1]);ok11($status===200&&json_decode($body,true)['data']['handover_due_minor']===0,'real feed returns new recorded balance');
[$status,$body]=http11(['cash'=>1],'POST',['csrf'=>'bad','payload'=>'{}']);ok11($status===403,'new flow retains CSRF protection');
$clean=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);foreach($before as $t=>$rows)if($rows!==$clean->query('SELECT * FROM '.$t)->fetchAll())throw new RuntimeException('Original table changed: '.$t);
echo 'TOTAL '.($count-$start)." driver-only handover checks passed; original tables unchanged.\n";
