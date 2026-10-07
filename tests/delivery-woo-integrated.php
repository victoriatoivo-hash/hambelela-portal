<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
// Reuse independent synthetic employee/role/order fixtures; never a live connection.
require __DIR__.'/delivery-production-core.php';
$linkedId=$id+2;
$db->exec("INSERT INTO ops_orders(id,woo_order_id,total_amount,shipping_total,shipping_tax_total,notes) VALUES($linkedId,$linkedId,510,NULL,NULL,'Shipping address: Saved synthetic address')");
$calls=0;$unavailable=false;$shippingChanged=false;
$source=static function(string $path)use($db,$linkedId,&$calls,&$unavailable,&$shippingChanged):array{
    $calls++;if($db->inTransaction())throw new RuntimeException('Network request under DB lock');
    if($unavailable)throw new RuntimeException('Simulated unavailable source');
    if($path!=='orders/'.$linkedId)throw new RuntimeException('Wrong source order');
    $fee=$shippingChanged?'50.00':'60.00';return ['id'=>$linkedId,'currency'=>'NAD','status'=>'processing','total'=>'510.00','shipping_total'=>$fee,'shipping_tax'=>'0.00','shipping_lines'=>[['total'=>$fee,'total_tax'=>'0.00']],'refunds'=>[]];
};
$linkedCore=new \Hambelela\Delivery\CoreApi($db,['cash'=>'Cash'],$source);
$linkedBody=$body;$linkedBody['order']=$linkedId;$linkedBody['uuid']=uid();
$unavailable=true;
try{$linkedCore->command($front,$linkedBody);throw new RuntimeException('Unavailable source accepted');}catch(DomainException $e){ok(!$db->query("SELECT id FROM delivery_jobs WHERE order_id=$linkedId")->fetchColumn(),'source outage creates no delivery');}
$unavailable=false;$linked=$linkedCore->command($front,$linkedBody);$linkedJobId=$linked['id'];
$evidence=json_decode($db->query("SELECT metadata_json FROM delivery_events WHERE delivery_id=$linkedJobId AND event_type='arranged'")->fetchColumn(),true);
ok($evidence['shipping_verification']['included_cents']===6000,'verified shipping evidence retained in existing audit event');
$savedCalls=$calls;$unavailable=true;
ok($linkedCore->command($front,$linkedBody)===$linked&&$calls===$savedCalls,'successful retry replays result even if WooCommerce is down');
try{$linkedCore->command($driver,$linkedBody);throw new RuntimeException('Driver arrangement allowed');}catch(DomainException $e){ok($calls===$savedCalls,'Driver denied before any external request');}
$unavailable=false;$shippingChanged=true;
try{$linkedCore->command($driver,['action'=>'start','id'=>$linkedJobId,'uuid'=>uid()]);throw new RuntimeException('Changed shipping accepted');}catch(DomainException $e){ok($db->query("SELECT status FROM delivery_jobs WHERE id=$linkedJobId")->fetchColumn()==='ready','changed shipping blocks progress before writes');}
$shippingChanged=false;$linkedCore->command($driver,['action'=>'start','id'=>$linkedJobId,'uuid'=>uid()]);
$linkedCore->command($driver,['action'=>'collect','id'=>$linkedJobId,'uuid'=>uid(),'goods'=>'450.00','fee'=>'60.00','method'=>'cash']);
$linkedCore->command($driver,['action'=>'complete','id'=>$linkedJobId,'uuid'=>uid()]);
$receipt=(int)$db->query("SELECT id FROM delivery_receipts WHERE delivery_id=$linkedJobId")->fetchColumn();
$linkedCore->command($front,['action'=>'reconcile','id'=>$linkedJobId,'uuid'=>uid(),'receipt'=>$receipt]);
ok((int)$db->query("SELECT SUM(amount_cents) FROM order_payment_allocations WHERE order_id=$linkedId")->fetchColumn()===51000,'included shipping credits total once, not twice');
ok((int)$db->query("SELECT SUM(amount_cents) FROM delivery_ledger WHERE delivery_id=$linkedJobId AND account='fee_received'")->fetchColumn()===6000,'only delivery fee enters delivery fund');
