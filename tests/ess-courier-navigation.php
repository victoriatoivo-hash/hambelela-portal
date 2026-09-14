<?php
declare(strict_types=1);
define('BASE_URL','');
$testUser=['id'=>1,'role_key'=>'owner_admin'];
function current_user():array{return $GLOBALS['testUser'];}
function current_role_key():string{return current_user()['role_key'];}
require __DIR__.'/../shared/employee-features.php';
require __DIR__.'/../shared/ess-navigation.php';
function checkNav(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
checkNav(count(ess_shell_apps())===9,'Owner modules');
foreach(['packer','front_desk_admin','marketing_sales'] as $role){
 $testUser=['id'=>99,'role_key'=>$role];$names=array_column(ess_shell_apps(),'name');
 checkNav(in_array('Courier',$names,true),'Courier retained');
 checkNav(in_array('Task Management',$names,true),'Tasks retained');
 checkNav(in_array('Bookkeeping',$names,true),'Bookkeeping retained');
 checkNav(!in_array('Cost Workbook',$names,true),'No owner-only links');
}
$testUser=['id'=>7,'role_key'=>'packer'];
checkNav(in_array('Input VAT',array_column(ess_shell_apps(),'name'),true),'Klaudia Input VAT override retained');
$testUser=['id'=>99,'role_key'=>'packer'];
checkNav(!in_array('Input VAT',array_column(ess_shell_apps(),'name'),true),'Input VAT not granted to other packers');
echo "Courier shared navigation: owner, staff features and individual Input VAT override passed.\n";
