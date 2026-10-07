<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/WooShippingSource.php';
use Hambelela\Delivery\WooShippingSource;
function shippingCheck(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo 'PASS: '.$label."\n";}
$order=['id'=>1,'woo_order_id'=>42,'total_amount'=>'519.00','updated_at'=>'2026-10-07 10:00:00'];
$remote=['id'=>42,'currency'=>'NAD','status'=>'processing','total'=>'519.00','shipping_total'=>'60.00','shipping_tax'=>'9.00','shipping_lines'=>[['total'=>'60.00','total_tax'=>'9.00']],'refunds'=>[]];
$verified=WooShippingSource::verify($order,$remote);
shippingCheck($verified['included_cents']===6900,'shipping and tax verified without local shipping columns');
WooShippingSource::assertCurrent($order,$verified);
foreach(['identity','total','currency','refund','missing','lines','status'] as $fault){
    $bad=$remote;
    switch($fault){case 'identity':$bad['id']=43;break;case 'total':$bad['total']='520.00';break;case 'currency':$bad['currency']='USD';break;case 'refund':$bad['refunds']=[['id'=>1]];break;case 'missing':unset($bad['shipping_total']);break;case 'lines':$bad['shipping_lines']=[];break;case 'status':$bad['status']='cancelled';break;}
    try{WooShippingSource::verify($order,$bad);throw new RuntimeException('Accepted '.$fault);}catch(DomainException $e){shippingCheck(true,$fault.' mismatch fails closed');}
}
$zero=$remote;$zero['shipping_total']='0.00';$zero['shipping_tax']='0.00';$zero['shipping_lines']=[];
shippingCheck(WooShippingSource::verify($order,$zero)['included_cents']===0,'explicit verified zero is supported');
$changed=$order;$changed['updated_at']='2026-10-07 10:00:01';
try{WooShippingSource::assertCurrent($changed,$verified);throw new RuntimeException('Race accepted');}catch(DomainException $e){shippingCheck(true,'concurrent portal update rejected');}
$stale=$verified;$stale['verified_at']=time()-61;
try{WooShippingSource::assertCurrent($order,$stale);throw new RuntimeException('Stale evidence accepted');}catch(DomainException $e){shippingCheck(true,'expired evidence rejected');}
