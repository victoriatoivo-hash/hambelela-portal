<?php
declare(strict_types=1);require __DIR__.'/delivery-production-core.php';
$extra=$body;$extra['uuid']=uid();$extra['additional_delivery']=true;$extra['additional_reason']='Verified second trip requested';$new=$core->command($front,$extra);$extraId=$new['id'];
$before=$db->query("SELECT SUM(amount_cents) FROM order_payment_allocations WHERE order_id=$id")->fetchColumn();
$core->command($driver,['action'=>'start','id'=>$extraId,'uuid'=>uid()]);$receipt=$core->command($driver,['action'=>'collect','id'=>$extraId,'uuid'=>uid(),'goods'=>'0','fee'=>'60','method'=>'cash']);$core->command($driver,['action'=>'complete','id'=>$extraId,'uuid'=>uid()]);$core->command($front,['action'=>'reconcile','id'=>$extraId,'uuid'=>uid(),'receipt'=>$receipt['receipt_id']]);
ok($before===$db->query("SELECT SUM(amount_cents) FROM order_payment_allocations WHERE order_id=$id")->fetchColumn(),'additional fee does not credit or duplicate product payments');
ok((int)$db->query("SELECT additional_delivery FROM delivery_jobs WHERE id=$extraId")->fetchColumn()===1,'additional delivery is explicitly marked and audited');
$extra['uuid']=uid();$extra['additional_reason']='';try{$core->command($front,$extra);throw new RuntimeException('Unexplained additional trip accepted');}catch(DomainException $e){ok(true,'additional delivery requires reason');}
