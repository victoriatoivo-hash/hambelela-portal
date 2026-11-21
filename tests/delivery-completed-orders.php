<?php
// Included only by the isolated synthetic arrangement database fixture.
require_once __DIR__.'/../shared/delivery/OrderSuggestions.php';
foreach([105=>$front,106=>$owner] as $oid=>$actor){
    $db->prepare("INSERT INTO ops_orders(id,order_number,woo_order_id,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status,portal_paid_confirmed) VALUES(?,?,?,120,'paid','Cash','Synthetic completed customer','0810000000','Shipping address: Test street','delivery','completed',1)")
        ->execute([$oid,'TEST-COMPLETED-'.$oid,$oid]);
    $remote['id']=$oid;$remote['status']='completed';
    $q=$core->command($actor,['action'=>'quote','source_order'=>true,'order'=>$oid]);
    ok($q['paid_cents']===12000&&$q['collect_cents']===0,'Completed paid order opens delivery form for actor '.$actor['id']);
    ok(count(\Hambelela\Delivery\OrderSuggestions::search($db,'TEST-COMPLETED-'.$oid,true))===1,'Completed order without a delivery remains suggested');
    $send=array_merge($new,['order'=>$oid,'board_terms'=>'verified','verification'=>$q['verification'],'uuid'=>requestId()]);
    $result=$core->command($actor,$send);
    ok($result['id']>0,'Completed paid order can be arranged by actor '.$actor['id']);
    ok(sameResponse($result,$core->command($actor,$send)),'Completed-order retry returns same delivery');
    denied(fn()=>$core->command($actor,array_merge($send,['uuid'=>requestId()])),'Completed order cannot receive duplicate active delivery');
    ok(count(\Hambelela\Delivery\OrderSuggestions::search($db,'TEST-COMPLETED-'.$oid,true))===0,'Arranged completed order removed from suggestions');
    $row=$db->query('SELECT status,portal_paid_confirmed FROM ops_orders WHERE id='.$oid)->fetch();
    ok($row['status']==='completed'&&(int)$row['portal_paid_confirmed']===1,'Arrangement preserves completed status and Paid tick');
    $remaining=$core->board($driver,['id'=>$result['id']])['jobs'][0]['remaining'];
    ok(array_sum($remaining)===0,'Driver never collects the paid order total again');
}
// A completed delivery is distinct from a completed order and stays protected.
$remote['id']=102;$remote['status']='processing';
$db->exec("UPDATE ops_orders SET status='completed' WHERE id=102");
$q=$core->command($front,['action'=>'quote','source_order'=>true,'order'=>102]);
denied(fn()=>$core->command($front,array_merge($new,['order'=>102,'verification'=>$q['verification'],'uuid'=>requestId()])),'Actually completed delivery cannot be dispatched again');
ok(count(\Hambelela\Delivery\OrderSuggestions::search($db,'TEST-PAID',true))===0,'Delivered orders stay out of suggested deliveries');
// Completion never fabricates a financial receipt if historic payment evidence conflicts.
$db->exec("INSERT INTO ops_orders(id,order_number,woo_order_id,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status,portal_paid_confirmed) VALUES(107,'TEST-COMPLETED-UNPAID',107,120,'unpaid','Cash','Synthetic conflicting payment','0810000000','Shipping address: Test street','delivery','completed',0)");
$remote['id']=107;
$q=$core->command($front,['action'=>'quote','source_order'=>true,'order'=>107]);
ok($q['paid_cents']===0&&$q['collect_cents']===12000,'Completed status alone does not rewrite payment evidence');
foreach(['cancelled','canceled','refunded','dispatched','out_for_delivery','delivered'] as $blocked){
    $db->prepare('UPDATE ops_orders SET status=? WHERE id=107')->execute([$blocked]);
    denied(fn()=>$core->command($front,['action'=>'quote','source_order'=>true,'order'=>107]),'Still blocks '.$blocked.' orders');
}
$db->exec("UPDATE ops_orders SET status='completed',deleted_at=UTC_TIMESTAMP() WHERE id=107");
denied(fn()=>$core->command($front,['action'=>'quote','source_order'=>true,'order'=>107]),'Deleted completed order remains blocked');
$db->exec("UPDATE ops_orders SET deleted_at=NULL,archived_at=UTC_TIMESTAMP() WHERE id=107");
denied(fn()=>$core->command($front,['action'=>'quote','source_order'=>true,'order'=>107]),'Archived completed order remains blocked');
echo "PASS completed-order delivery eligibility and duplicate/payment protections\n";
