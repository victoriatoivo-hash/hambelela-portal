<?php
declare(strict_types=1);
require __DIR__.'/../apps/operations/orders-pos-method.php';
$src=file_get_contents(__DIR__.'/../apps/operations/operations.php');
foreach (['ops_can_update_order_payment_method','ops_normalize_payment_code'] as $f) {
    if (!preg_match('/^function '.preg_quote($f,'/').'\(.*?^\}/ms',$src,$m)) throw new RuntimeException($f);
    eval($m[0]);
}
function user_has_role(...$roles){return in_array(current_role_key(),$roles,true);}
function current_role_key(){return $GLOBALS['role'];}
function current_user(){return ['id'=>$GLOBALS['emp']];}
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
foreach ([[15,'marketing_sales',true],[16,'marketing_sales',false],[15,'guest',false],[5,'front_desk_admin_employee',true]] as [$emp,$role,$expect]) check(ops_can_update_order_payment_method()===$expect,'role: '.$role);
$order=['status'=>'completed','total'=>'425','payment_method'=>'eft','payment_method_title'=>'EFT','meta_data'=>[['key'=>'_hpos_sale','value'=>1]]];
$t=ops_pos_single_tender($order);
check($t['method']==='eft' && $t['amount_cents']===0,'unpaid');
$stored=[['method'=>'card_swipe','amount_cents'=>1899,'transaction_reference'=>'sample']];
$v='2026-11-02T14:30:00';$prior='2026-11-02T14:20:00';
$next=ops_pos_method_updates($t,$stored,'pos',$v,$prior);
check($next===[['method'=>'eft','amount_cents'=>1899,'transaction_reference'=>'sample']],'partial payment preserved');
check(ops_pos_method_updates($t,$next,'pos',$v,$v)===null,'replay');
check(ops_pos_method_updates($t,$stored,'pos',$prior,$v)===null,'stale');
check(ops_pos_method_updates($t,$stored,'delivery',$v,$prior)===null,'driver guard');
check(ops_pos_method_updates($t,array_merge($stored,$stored),'pos',$v,$prior)===null,'split guard');
check(ops_pos_method_updates($t,$stored,'order_list',$v,$prior)===null,'unacknowledged portal edit');
$order['meta_data'][]=['key'=>'_hpos_payment_allocations','value'=>'[{"method":"card_swipe","amount_cents":0}]'];
check(ops_pos_single_tender($order)['method']==='eft','stale mirror');
$order['meta_data'][]=['key'=>'_hpos_split','value'=>'[{"method":"cash","amount":10},{"method":"eft","amount":20}]'];
check(ops_pos_single_tender($order)===null,'split POS');
echo "Payment permission and method-only reconciliation fixtures passed.\n";
