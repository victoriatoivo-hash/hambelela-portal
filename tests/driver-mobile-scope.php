<?php
declare(strict_types=1);
require __DIR__.'/../shared/delivery/DriverMobile.php';
use Hambelela\Delivery\DriverMobile;
function check_driver_mobile(bool $test,string $message):void {if(!$test)throw new RuntimeException($message);}
check_driver_mobile(DriverMobile::enabled(['id'=>17,'role_key'=>'delivery_driver']),'Verified Driver profile');
foreach(['owner_admin','front_desk_admin_employee','marketing_sales','packer','guest','partner_admin'] as $role){
 check_driver_mobile(!DriverMobile::enabled(['id'=>17,'role_key'=>$role]),'Wrong role '.$role);
}
foreach([0,1,16,18] as $id){
 check_driver_mobile(!DriverMobile::enabled(['id'=>$id,'role_key'=>'delivery_driver','full_name'=>'Isarael Maluena']),'Display name cannot enable profile '.$id);
}
check_driver_mobile(!DriverMobile::enabled([]),'Anonymous denied');
$paths=array_column(DriverMobile::links(),1);
check_driver_mobile(in_array('/apps/delivery/index.php?view=day',$paths,true),'Separate day destination');
check_driver_mobile(in_array('/notifications.php',$paths,true)&&in_array('/profile.php',$paths,true),'Alerts and authorized profile retained');
foreach($paths as $path)check_driver_mobile(strpos($path,'accounting')===false&&strpos($path,'orders')===false,'No expanded access');
echo "PASS: verified account/role scope; other roles and Drivers excluded; existing authorized destinations\n";
