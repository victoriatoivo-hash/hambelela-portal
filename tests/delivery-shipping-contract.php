<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/PortalOrderRepository.php';
use Hambelela\Delivery\PortalOrderRepository;
foreach([[],['shipping_total'=>'60.00'],['shipping_total'=>null,'shipping_tax_total'=>'0.00'],['shipping_total'=>'','shipping_tax_total'=>'0.00']] as $order){
    try{PortalOrderRepository::shippingComponent($order);throw new RuntimeException('Unknown shipping accepted as zero.');}
    catch(DomainException $e){echo "PASS: unverified shipping rejected\n";}
}
if(PortalOrderRepository::shippingComponent(['shipping_total'=>'0.00','shipping_tax_total'=>'0.00'])!==0)throw new RuntimeException('Verified zero failed.');
if(PortalOrderRepository::shippingComponent(['shipping_total'=>'60.00','shipping_tax_total'=>'9.00'])!==6900)throw new RuntimeException('Tax inclusion failed.');
echo "PASS: verified zero and shipping tax retain exact cents\n";
