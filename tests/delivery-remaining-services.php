<?php
declare(strict_types=1);
require __DIR__.'/delivery-production-core.php';
require __DIR__.'/../shared/delivery/AccountingService.php';
$db->exec('CREATE TEMPORARY TABLE ops_security_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(60),employee_id INT,metadata_json TEXT) ENGINE=InnoDB');
$ownerId=$id+3;$db->exec("INSERT INTO ops_roles(id,role_key) VALUES($ownerId,'owner_admin')");$db->exec("INSERT INTO ops_employees(id,role_id) VALUES($ownerId,$ownerId)");
$owner=['kind'=>'employee','id'=>$ownerId,'role'=>'owner_admin','active'=>true];
$admin=new \Hambelela\Delivery\AccountingService($db);
$expense=['date'=>date('Y-m-d'),'category'=>'Airtime / Data','amount'=>'70','description'=>'Synthetic airtime','paid'=>true];$request=uid();
$saved=$admin->expense($owner,$request,$expense);ok($saved===$admin->expense($owner,$request,$expense),'expense retry is idempotent');
$q=$db->prepare('SELECT SUM(amount_cents) FROM delivery_ledger WHERE expense_id=?');$q->execute([$saved['id']]);ok((int)$q->fetchColumn()===7000,'only paid expense enters fund');
$replacement=$admin->expense($owner,uid(),$expense+['replace_id'=>$saved['id'],'reason'=>'Correct test amount']);
$q->execute([$saved['id']]);ok((int)$q->fetchColumn()===0,'expense correction reverses original without deleting it');
$admin->voidExpense($owner,uid(),$replacement['id'],'Void synthetic expense');$q->execute([$replacement['id']]);ok((int)$q->fetchColumn()===0,'expense void is an auditable reversing entry');
try{$admin->expense($front,uid(),$expense);throw new RuntimeException('Front expense allowed');}catch(DomainException $e){ok(true,'Front cannot create expenses');}
$zone=$admin->saveZone($owner,uid(),['area'=>'Synthetic area '.uid(),'fee'=>'60','active'=>true]);
ok($zone['fee_cents']===6000,'configured rate stored as exact cents');
try{$admin->saveZone($owner,uid(),['id'=>$zone['id'],'area'=>$zone['area'],'fee'=>'50','active'=>true,'version'=>'stale']);throw new RuntimeException('Stale rate allowed');}catch(DomainException $e){ok(true,'stale pricing edit rejected');}
$report=$admin->report($owner,date('Y-m-d'),date('Y-m-d'));ok(isset($report['summary']['available_fund']),'accounting report separates fund from product collections');
$nextOrder=$id+2;$db->exec("INSERT INTO ops_orders(id,total_amount,notes) VALUES($nextOrder,10,'Shipping address: Synthetic street')");
$newBody=$body;$newBody['order']=$nextOrder;$newBody['uuid']=uid();$newJob=$core->command($front,$newBody);$n=$newJob['id'];
$note=['action'=>'note','id'=>$n,'uuid'=>uid(),'note'=>'Synthetic delivery note'];ok($core->command($driver,$note)===$core->command($driver,$note),'note retry creates one history entry');
$core->command($driver,['action'=>'fail','id'=>$n,'uuid'=>uid(),'reason'=>'Customer unavailable','note'=>'Synthetic test']);
ok($db->query("SELECT status FROM delivery_jobs WHERE id=$n")->fetchColumn()==='failed','failed delivery is not completed');
$ver=(int)$db->query("SELECT version FROM delivery_jobs WHERE id=$n")->fetchColumn();
$core->command($front,['action'=>'revise','id'=>$n,'uuid'=>uid(),'version'=>$ver,'driver'=>$driverId,'date'=>$newBody['date'],'address'=>'Synthetic street 2','mobile'=>'+264810000009','reason'=>'Customer requested tomorrow']);
ok($db->query("SELECT status FROM delivery_jobs WHERE id=$n")->fetchColumn()==='ready','reschedule reuses delivery and retains failure history');
ok((int)$db->query("SELECT COUNT(*) FROM delivery_events WHERE delivery_id=$n AND event_type='failed'")->fetchColumn()===1,'failure evidence preserved');
try{$core->command($driver,['action'=>'cancel','id'=>$n,'uuid'=>uid(),'version'=>$ver+1,'reason'=>'Not authorized']);throw new RuntimeException('Driver cancellation allowed');}catch(DomainException $e){ok(true,'Driver cannot perform Front cancellation');}
