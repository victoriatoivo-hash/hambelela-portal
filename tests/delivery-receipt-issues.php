<?php
declare(strict_types=1);
require __DIR__.'/delivery-production-core.php';
$issueOrder=$id+5;$db->exec("INSERT INTO ops_orders(id,total_amount,notes) VALUES($issueOrder,450,'Shipping address: Synthetic issue address')");
$issueBody=$body;$issueBody['order']=$issueOrder;$issueBody['uuid']=uid();$issueJob=$core->command($front,$issueBody)['id'];
$core->command($driver,['action'=>'start','id'=>$issueJob,'uuid'=>uid()]);
$core->command($driver,['action'=>'collect','id'=>$issueJob,'uuid'=>uid(),'goods'=>'450','fee'=>'60','method'=>'cash']);
$core->command($driver,['action'=>'complete','id'=>$issueJob,'uuid'=>uid()]);
$receipt=(int)$db->query("SELECT id FROM delivery_receipts WHERE delivery_id=$issueJob")->fetchColumn();
$issue=['action'=>'receipt_issue','id'=>$issueJob,'uuid'=>uid(),'receipt'=>$receipt,'reason'=>'Synthetic shortfall awaiting verification'];
try{$core->command($driver,$issue);throw new RuntimeException('Driver allowed to alter handover');}catch(DomainException $e){ok(true,'Driver cannot control handover issue status');}
$saved=$core->command($front,$issue);ok($saved===$core->command($front,$issue),'collection issue retry is idempotent');
try{$core->command($front,['action'=>'reconcile','id'=>$issueJob,'uuid'=>uid(),'receipt'=>$receipt]);throw new RuntimeException('Disputed receipt reconciled');}catch(DomainException $e){ok(true,'disputed receipt cannot be reconciled');}
ok((int)$db->query("SELECT COUNT(*) FROM delivery_ledger WHERE delivery_id=$issueJob AND account='fee_received'")->fetchColumn()===0,'issue does not credit the delivery fund');
$core->command($front,['action'=>'resolve_receipt_issue','id'=>$issueJob,'uuid'=>uid(),'receipt'=>$receipt,'reason'=>'Synthetic full collection independently verified']);
ok($db->query("SELECT status FROM delivery_jobs WHERE id=$issueJob")->fetchColumn()==='awaiting_reconciliation','resolving issue still requires separate handover');
$core->command($front,['action'=>'reconcile','id'=>$issueJob,'uuid'=>uid(),'receipt'=>$receipt]);
ok($db->query("SELECT status FROM delivery_jobs WHERE id=$issueJob")->fetchColumn()==='completed','verified collection can be reconciled once');
