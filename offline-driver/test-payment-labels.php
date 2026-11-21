<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/shared/order-payment-methods.php';
// Only pure functions, extracted from the actual application. No config, DB or network.
$source=file_get_contents(dirname(__DIR__).'/apps/operations/operations.php');
foreach(['ops_website_payment_methods','ops_normalize_payment_code','ops_payment_label','ops_normalize_website_payment_method','ops_collect_order_payment_text','ops_wc_payment_method','ops_wc_payment_allocations'] as $name){
 $start=strpos($source,'function '.$name.'(');$end=strpos($source,"\n}",$start);if($start===false||$end===false)throw new RuntimeException('Function boundary missing');eval(substr($source,$start,$end+2-$start));
}
$checks=0;function checkPayment($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
foreach(ops_payment_method_map() as $code=>$label){checkPayment(in_array($label,ops_website_payment_methods(),true),'canonical option '.$code);checkPayment(ops_payment_label(ops_normalize_payment_code($label))===$label,'canonical round trip '.$code);}
foreach(['Swipe','Card','Card/Swipe','card_swipe'] as $alias){checkPayment(ops_normalize_website_payment_method($alias)==='Swipe','legacy unpaid '.$alias);checkPayment(ops_wc_payment_method(['payment_method_title'=>$alias,'status'=>'pending'])==='Swipe','pending title '.$alias);checkPayment(ops_wc_payment_method(['payment_method'=>$alias,'status'=>'completed','total'=>'100'])==='Swipe','paid method '.$alias);}
$order=['meta_data'=>[['key'=>'_hpos_split','value'=>[['method'=>'card','amount'=>'25'],['method'=>'eft','amount'=>'75']]]]];
checkPayment(ops_wc_payment_method($order)==='Swipe / EFT','split labels');checkPayment(array_sum(array_column(ops_wc_payment_allocations($order),'amount_cents'))===10000,'split cents preserved');
checkPayment(ops_wc_payment_method(['payment_method_title'=>'Split payment'])==='','unknown split not guessed');
checkPayment(ops_wc_payment_method(['payment_method'=>'bacs','status'=>'pending'])==='EFT','unpaid bank transfer label');
echo "PASS $checks payment label checks\n";
