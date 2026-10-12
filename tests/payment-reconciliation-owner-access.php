<?php
declare(strict_types=1);
// Execute the production request guard in separate processes: denied requests must terminate before data work.
if (($argv[1]??'')==='request') {
 define('BASE_URL','');
 $GLOBALS['testUser']=['id'=>(int)$argv[2],'role_key'=>$argv[3]];
 function current_user():array{return $GLOBALS['testUser'];}
 function current_role_key():string{return current_user()['role_key'];}
 require __DIR__.'/../shared/employee-features.php';
 $_SERVER['SCRIPT_NAME']=$argv[4];$_SERVER['REQUEST_METHOD']=$argv[5];$_GET['action']=$argv[6];
 register_shutdown_function(function(){echo '|STATUS:'.http_response_code();});
 enforce_employee_feature_for_current_request();
 echo 'AUTHORIZED';exit;
}
require __DIR__.'/../shared/employee-features.php';
function verify($yes,string $message):void{if(!$yes)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
$roles=[15=>'marketing_sales',2=>'front_desk_admin',7=>'packer',17=>'delivery_driver',12=>'accountant',99=>'supervisor_manager',98=>'unknown_staff'];
foreach($roles as $id=>$role){
 verify(!portal_user_can_access_feature('payment_reconciliation',['id'=>$id,'role_key'=>$role]),'Owner-only visibility for '.$role);
 foreach(['GET'=>['list','detail','export','export-selected','export-receipts'],'POST'=>['confirm','bulk','import-preview','import-save','match','flag','resolve','reopen']] as $method=>$actions){foreach($actions as $action){
  $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' request '.$id.' '.escapeshellarg($role).' /apps/accounts/payment-reconciliation-api.php '.$method.' '.escapeshellarg($action);
  $out=shell_exec($cmd);if(strpos($out,'|STATUS:403')===false||strpos($out,'AUTHORIZED')!==false||strpos($out,'access required for Payment Reconciliation')===false)throw new RuntimeException('API failed closed check: '.$role.' '.$action);
 }}
 $out=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' request '.$id.' '.escapeshellarg($role).' /apps/accounts/payment-reconciliation.php GET page');
 verify(strpos($out,'|STATUS:403')!==false&&strpos($out,'AUTHORIZED')===false,'Direct page and every API action denied for '.$role);
}
verify(portal_user_can_access_feature('payment_reconciliation',['id'=>1,'role_key'=>'owner_admin']),'Owner capability granted');
$out=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' request 1 owner_admin /apps/accounts/payment-reconciliation.php GET page');verify(strpos($out,'AUTHORIZED')!==false,'Owner request passes route gate');
foreach(['marketing_sales','front_desk_admin','front_desk_admin_employee'] as $role)foreach(['orders','bookkeeping','cash_tools'] as $feature)verify(portal_role_can_access_feature($role,$feature),'Preserved '.$role.' '.$feature);
foreach(['/apps/accounts/payment-reconciliation.php','/apps/accounts/payment-reconciliation-api.php'] as $path)verify(employee_feature_for_request($path)[0]==='payment_reconciliation','Route registered '.$path);
echo "All Owner-only reconciliation request tests passed.\n";
