<?php
declare(strict_types=1);
// Pure CLI checks only: no configuration, database, sessions or account changes.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../shared/delivery/CoreApi.php';
require_once __DIR__.'/../shared/delivery/DriverAuth.php';
require_once __DIR__.'/../shared/delivery/PaymentReviewService.php';
use Hambelela\Delivery\DeliveryPolicy;
use Hambelela\Delivery\DriverAuth;
$checks=[
    'exact_currency'=>DeliveryPolicy::cents('450.01')===45001,
    'mobile_normalization'=>DriverAuth::mobile('00264 81 000 0002')==='+264810000002',
    'front_role'=>DeliveryPolicy::can(['kind'=>'employee','active'=>true,'id'=>1,'role'=>'front_desk_admin'],'delivery_arrange'),
    'driver_restricted'=>!DeliveryPolicy::can(['kind'=>'employee','active'=>true,'id'=>2,'role'=>'delivery_driver','driver_verified'=>true,'grants'=>['delivery_reconcile']],'delivery_reconcile'),
    'inactive_denied'=>!DeliveryPolicy::can(['kind'=>'employee','active'=>false,'id'=>1,'role'=>'owner_admin'],'delivery_view'),
];
$adapter=new Hambelela\Delivery\OrderBalanceAdapter();
$checks['compatible_callback']=Closure::fromCallable([$adapter,'read']) instanceof Closure;
foreach($checks as $name=>$passed){echo ($passed?'PASS':'FAIL').': '.$name."\n";if(!$passed)exit(1);}
echo 'PHP '.PHP_VERSION." pure runtime checks passed. No database accessed.\n";
