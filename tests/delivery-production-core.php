<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/CoreApi.php';require __DIR__.'/../shared/delivery/FrontSession.php';
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Upgrade ONLY isolated synthetic stubs to the inspected production-column contract.
$db->exec('CREATE TABLE IF NOT EXISTS ops_roles(id INT PRIMARY KEY,role_key VARCHAR(60)) ENGINE=InnoDB');
$permissionColumn=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ops_permissions' AND column_name='permission_key'")->fetchColumn();if(!$permissionColumn)$db->exec('ALTER TABLE ops_permissions ADD COLUMN permission_key VARCHAR(100) NULL');
foreach(['ops_employees'=>['role_id'=>'INT NULL','status'=>"VARCHAR(20) DEFAULT 'active'",'full_name'=>"VARCHAR(160) DEFAULT 'Synthetic'"],
'ops_orders'=>['deleted_at'=>'DATETIME NULL','archived_at'=>'DATETIME NULL','notes'=>'TEXT NULL','customer_name'=>"VARCHAR(190) DEFAULT 'Synthetic Customer'",'customer_contact'=>"VARCHAR(190) DEFAULT '+264810000009'",'shipping_total'=>'DECIMAL(12,2) DEFAULT 0','shipping_tax_total'=>'DECIMAL(12,2) DEFAULT 0']] as $table=>$fields){foreach($fields as $name=>$def){$s=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$name]);if(!$s->fetchColumn())$db->exec("ALTER TABLE $table ADD COLUMN $name $def");}}
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
function uid(){return sprintf('%08x-aaaa-4aaa-8aaa-%012x',random_int(1,0x7fffffff),random_int(1,0x7fffffff));}
$id=random_int(10000000,99000000);$driverId=$id+1;
$db->exec("INSERT INTO ops_roles VALUES($id,'front_desk_admin'),($driverId,'delivery_driver')");
$db->exec("INSERT INTO ops_employees(id,role_id) VALUES($id,$id),($driverId,$driverId)");
$db->exec("INSERT INTO delivery_driver_profiles VALUES($driverId,1,$id,UTC_TIMESTAMP())");
$db->exec("INSERT INTO ops_orders(id,total_amount,notes) VALUES($id,450,'Shipping address: Saved test address')");
$front=\Hambelela\Delivery\FrontSession::actor($db,$id);$driver=['kind'=>'employee','id'=>$driverId,'role'=>'delivery_driver','active'=>true,'driver_verified'=>true];
$core=new \Hambelela\Delivery\CoreApi($db,['cash'=>'Cash']);
$body=['action'=>'arrange','order'=>$id,'driver'=>$driverId,'date'=>(new DateTimeImmutable('tomorrow',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d'),'uuid'=>uid(),'area'=>'Test suburb','manual_fee'=>'60.00','manual_reason'=>'Synthetic authorized quote','urgent'=>true,'notes'=>'Test only'];
$job=$core->command($front,$body);$jobId=$job['id'];ok($core->command($front,$body)===$job,'production arrangement retry is idempotent');
$quote=$core->command($front,array_merge($body,['action'=>'quote']));ok($quote['goods_due']===45000&&$quote['fee_due']===6000,'pre-save quote keeps product amount and fee separate');
ok(!$db->inTransaction(),'quote releases its transaction without recording a payment');
try{$core->command($driver,array_merge($body,['action'=>'quote']));throw new RuntimeException('Driver read Front quote');}catch(DomainException $e){ok(true,'Driver cannot use Front quote');}
$row=$db->query("SELECT * FROM delivery_jobs WHERE id=$jobId")->fetch(PDO::FETCH_ASSOC);
ok($row['address']==='Saved test address'&&$row['customer_mobile']==='+264810000009','saved Order address and mobile reused');
ok($row['manual_rate_reason']==='Synthetic authorized quote'&&(int)$row['urgent']===1,'manual rate evidence and urgency preserved');
$changed=$body;$changed['address']='Changed address';try{$core->command($front,$changed);throw new RuntimeException('Changed payload accepted');}catch(DomainException $e){ok(true,'changed arrangement payload cannot reuse request ID');}
$core->command($driver,['action'=>'start','id'=>$jobId,'uuid'=>uid()]);
$core->command($driver,['action'=>'collect','id'=>$jobId,'uuid'=>uid(),'goods'=>'450.00','fee'=>'60.00','method'=>'cash']);
$core->command($driver,['action'=>'complete','id'=>$jobId,'uuid'=>uid()]);
$receipt=(int)$db->query("SELECT id FROM delivery_receipts WHERE delivery_id=$jobId")->fetchColumn();
$core->command($front,['action'=>'reconcile','id'=>$jobId,'uuid'=>uid(),'receipt'=>$receipt]);
ok($db->query("SELECT payment_status FROM ops_orders WHERE id=$id")->fetchColumn()==='paid','production core flow updates linked Order');
$board=$core->board($driver);ok(count($board['jobs'])===1&&$board['jobs'][0]['remaining']['order_goods']===0,'Driver board remains assigned-only and reads current balance');
ok($board['metrics']['completed_today']===1&&$board['metrics']['today']===0,'Driver totals use assigned records and Windhoek dates, independent of tomorrow scheduling');
ok($board['metrics']['awaiting_reconciliation']===0&&$board['metrics']['urgent']===0,'completed deliveries leave active and handover totals');
ok($board['jobs'][0]['driver_name']==='Synthetic','board attributes assigned Driver from employee record');
ok($core->board($driver,['state'=>'active'])['total']===0,'completed jobs are excluded by server-side active filter');
ok($core->board($driver,['state'=>'completed','q'=>$row['public_reference']])['total']===1,'history search finds assigned completed delivery');
ok(count($core->board($driver,['state'=>'completed','page'=>2])['jobs'])===0,'history pagination preserves assigned-only boundaries');
ok(!$board['can_reconcile']&&!$board['can_arrange'],'Driver has no Front actions');
$owner=['kind'=>'employee','id'=>$id,'role'=>'owner_admin','active'=>true];
ok($core->board($owner,['driver_id'=>$driverId])['total']===1,'Owner oversight filters by selected Driver');
ok($core->board($owner,['driver_id'=>$driverId+1])['total']===0,'Owner oversight does not show other Drivers in selected view');
ok($core->board($driver,['driver_id'=>$driverId+1])['total']===1,'Driver cannot switch identity using oversight filter');
$repeat=$body;$repeat['uuid']=uid();
try{$core->command($front,$repeat);throw new RuntimeException('Unapproved repeat delivery accepted');}catch(DomainException $e){ok(str_contains($e->getMessage(),'completed delivery'),'completed Order cannot be silently booked again');}
ok($core->command($front,$body)===$job,'original assignment replay still works after completion');
echo "Production core classes passed local synthetic end-to-end test. No live endpoint enabled.\n";
