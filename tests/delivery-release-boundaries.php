<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/employee-features.php';
require __DIR__.'/../shared/delivery/DriverSession.php';
use Hambelela\Delivery\DriverSession;
foreach(['dashboard','orders','bookkeeping','cash_tools','hr','accounts','settings','notifications','marketing','packing_list','input_vat'] as $feature){
 if(portal_role_can_access_feature('delivery_driver',$feature))throw new RuntimeException('Driver feature leak');
 if(portal_user_can_access_feature($feature,['id'=>7,'role_key'=>'delivery_driver']))throw new RuntimeException('Driver override leak');
}
if(!portal_role_can_access_feature('marketing_sales','packing_list')||!portal_role_can_access_feature('marketing_sales','hr'))throw new RuntimeException('Newer Marketing access lost');
try{DriverSession::open(false);throw new RuntimeException('Disabled gate failed');}catch(DomainException $e){}
unset($_SERVER['HTTPS']);
try{DriverSession::open(true);throw new RuntimeException('TLS gate failed');}catch(DomainException $e){}
echo "PASS: Driver denied legacy features and employee overrides; current Marketing rights preserved; disabled/TLS gates fail closed.\n";
