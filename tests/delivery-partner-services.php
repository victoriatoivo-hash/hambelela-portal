<?php
declare(strict_types=1);
require __DIR__.'/delivery-remaining-services.php';
require __DIR__.'/../shared/delivery/PartnerService.php';require __DIR__.'/../shared/delivery/PartnerAuth.php';
$db->prepare('INSERT INTO delivery_partners(name,code,created_by_employee_id) VALUES(?,?,?)')->execute(['Synthetic partner','SYN-'.bin2hex(random_bytes(5)),$id]);$partnerId=(int)$db->lastInsertId();
$email='synthetic-'.bin2hex(random_bytes(6)).'@example.invalid';$secret='Synthetic-Partner-Only-Secret';
$db->prepare('INSERT INTO delivery_partner_users(partner_id,display_name,email,password_hash,active) VALUES(?,?,?,?,1)')->execute([$partnerId,'Synthetic partner user',$email,password_hash($secret,PASSWORD_DEFAULT)]);$partnerUser=(int)$db->lastInsertId();
$auth=new \Hambelela\Delivery\PartnerAuth($db);$session=$auth->authenticate($email,$secret,'synthetic:'.uid());$partner=$auth->actor($session);$svc=new \Hambelela\Delivery\PartnerService($db);
\Hambelela\Delivery\PartnerRouting::save($db,$ownerId,$partnerId,$driverId,'Verified synthetic routing');
$pbody=['customer_name'=>'Synthetic COD customer','mobile'=>'+264810000003','address'=>'Synthetic address','reference'=>'SYN-'.uid(),'zone_id'=>$zone['id'],'fee_payer'=>'partner','cod'=>'500','date'=>$newBody['date'],'notes'=>'Synthetic only'];$uuid=uid();
$job=$svc->arrange($partner,$uuid,$pbody);ok($svc->arrange($partner,$uuid,$pbody)===$job,'partner assignment retry is idempotent');
ok((int)$db->query('SELECT driver_employee_id FROM delivery_jobs WHERE id='.(int)$job['id'])->fetchColumn()===$driverId,'partner request automatically reaches explicitly configured Driver');
$j=$job['id'];$v=(int)$db->query("SELECT version FROM delivery_jobs WHERE id=$j")->fetchColumn();
$core->command($front,['action'=>'revise','id'=>$j,'uuid'=>uid(),'version'=>$v,'driver'=>$driverId,'date'=>$pbody['date'],'address'=>$pbody['address'],'mobile'=>$pbody['mobile'],'reason'=>'Assign verified test Driver']);
$core->command($driver,['action'=>'start','id'=>$j,'uuid'=>uid()]);
$receipt=$core->command($driver,['action'=>'collect','id'=>$j,'uuid'=>uid(),'goods'=>'0','fee'=>'0','cod'=>'500','method'=>'cash']);
$core->command($driver,['action'=>'complete','id'=>$j,'uuid'=>uid()]);$core->command($front,['action'=>'reconcile','id'=>$j,'uuid'=>uid(),'receipt'=>$receipt['receipt_id']]);
$s=$db->prepare('SELECT account,SUM(amount_cents) amount FROM delivery_ledger WHERE delivery_id=? GROUP BY account');$s->execute([$j]);$totals=array_column($s->fetchAll(PDO::FETCH_ASSOC),'amount','account');ok((int)$totals['fee_earned']===6000&&(int)$totals['partner_cod_held']===50000&&!isset($totals['fee_received']),'partner COD is separate from earned and received fees');
$admin->partnerSettlement($owner,uid(),$partnerId,'fee_received','60','SYN FEE','Verified synthetic payment');$admin->partnerSettlement($owner,uid(),$partnerId,'partner_cod_remitted','500','SYN COD','Verified synthetic remittance');
$s->execute([$j]);$totals=array_column($s->fetchAll(PDO::FETCH_ASSOC),'amount','account');ok((int)$totals['fee_received']===6000&&(int)$totals['partner_cod_remitted']===50000,'partner fee and COD settle separately without netting');
try{$admin->partnerSettlement($owner,uid(),$partnerId,'fee_received','1','SYN','Overpayment test');throw new RuntimeException('Over-settlement allowed');}catch(DomainException $e){ok(true,'partner over-settlement rejected');}
$visible=$svc->board($partner);ok(count($visible['jobs'])===1&&(int)$visible['jobs'][0]['id']===$j,'partner sees own requests only');
$db->prepare('INSERT INTO delivery_partner_users(partner_id,display_name,email,password_hash,active) VALUES(?,?,?,?,1)')->execute([$partnerId,'Synthetic colleague','colleague-'.bin2hex(random_bytes(6)).'@example.invalid',password_hash($secret,PASSWORD_DEFAULT)]);$colleague=['kind'=>'partner','id'=>(int)$db->lastInsertId(),'partner_id'=>$partnerId,'active'=>true];ok(count($svc->board($colleague)['jobs'])===0,'same-company colleague cannot read another creator’s deliveries');
try{$core->board($partner);throw new RuntimeException('Partner staff board access allowed');}catch(DomainException $e){ok(true,'partner denied staff and Driver board');}
$db->prepare('UPDATE delivery_partner_users SET session_version=session_version+1 WHERE id=?')->execute([$partnerUser]);try{$auth->actor($session);throw new RuntimeException('Old partner session allowed');}catch(DomainException $e){ok(true,'partner credential reset revokes old session');}
$ip='limit-test:'.uid();for($i=0;$i<5;$i++){try{$auth->authenticate($email,'wrong-secret',$ip);throw new RuntimeException('Wrong partner secret accepted');}catch(DomainException $e){}}
try{$auth->authenticate($email,$secret,$ip);throw new RuntimeException('Partner throttle bypassed');}catch(DomainException $e){ok(true,'partner login locks after repeated failures');}
require __DIR__.'/../shared/delivery/PartnerProvisioning.php';$provision=new \Hambelela\Delivery\PartnerProvisioning($db);$setup=['company'=>'Synthetic setup company','code'=>'T-'.bin2hex(random_bytes(5)),'name'=>'Synthetic verified contact','email'=>'setup-'.bin2hex(random_bytes(5)).'@example.invalid'];$created=$provision->save($ownerId,$setup,$secret);ok($created['user_id']>0,'Owner creates separate restricted partner account');
try{$provision->save($id,$setup,$secret);throw new RuntimeException('Front provisioned partner');}catch(DomainException $e){ok(true,'Front cannot provision partner identities');}
$withoutReference=$pbody;$withoutReference['reference']='';$request=uid();$auto=$svc->arrange($partner,$request,$withoutReference);ok(preg_match('/^PART-[0-9]+$/D',$auto['reference'])===1,'missing partner reference receives separate generated numbering');ok($svc->arrange($partner,$request,$withoutReference)===$auto,'generated reference is stable on retry');
