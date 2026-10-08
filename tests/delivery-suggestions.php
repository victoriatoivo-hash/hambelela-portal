<?php
declare(strict_types=1);
require __DIR__.'/delivery-production-core.php';
require_once __DIR__.'/../shared/delivery/OrderSuggestions.php';
foreach(['fulfilment_mode'=>"VARCHAR(40) NULL",'order_type'=>"VARCHAR(40) NULL"] as $name=>$def){$s=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ops_orders' AND column_name=?");$s->execute([$name]);if(!$s->fetchColumn())$db->exec("ALTER TABLE ops_orders ADD COLUMN $name $def");}
$prefix='SUGGEST-'.random_int(100000,999999);
$cases=[['new_order','delivery'],['ready_for_delivery','delivery'],['packed','delivery'],['completed','delivery'],['cancelled','delivery'],['ready_for_collection','collection'],['out_for_delivery','delivery']];
$ids=[];
foreach($cases as $n=>$c){$oid=random_int(100000000,900000000);$ids[]=$oid;$db->prepare('INSERT INTO ops_orders(id,order_number,total_amount,status,fulfilment_mode,notes) VALUES(?,?,450,?,?,?)')->execute([$oid,$prefix.'-'.$n,$c[0],$c[1],'Shipping address: Synthetic street']);}
$rows=\Hambelela\Delivery\OrderSuggestions::search($db,$prefix,true);
ok(count($rows)===3&&$rows[0]['id']==$ids[1]&&$rows[1]['id']==$ids[2],'suggestions exclude collection/closed/dispatched and rank readiness');
$core->command($front,array_merge($body,['order'=>$ids[1],'uuid'=>uid()]));
$rows=\Hambelela\Delivery\OrderSuggestions::search($db,$prefix,true);
ok(count($rows)===2,'active delivery omitted from suggestions');
$search=\Hambelela\Delivery\OrderSuggestions::search($db,$prefix.'-1',false);
ok(count($search)===1&&$search[0]['active_delivery_id'],'search still opens already-arranged delivery');
echo "Suggestion eligibility tests passed.\n";
