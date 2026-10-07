<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
define('BASE_URL','');define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/auth.php';
require BASE_PATH.'/shared/employee-features.php';
require BASE_PATH.'/shared/delivery/EmployeeDriverSession.php';
require BASE_PATH.'/shared/delivery/DeliveryPolicy.php';
use Hambelela\Delivery\EmployeeDriverSession as Driver;
use Hambelela\Delivery\DeliveryPolicy as Policy;
function expectDriver($condition,$message){if(!$condition)throw new RuntimeException($message);}
expectDriver(portal_post_login_destination(['role_key'=>'delivery_driver'],'/settings.php')==='/apps/delivery/driver/index.php','Driver landing ignores unrelated return');
expectDriver(portal_post_login_destination(['role_key'=>'marketing_sales'])==='/apps/marketing/index.php','Marketing landing unchanged');
foreach(['/apps/delivery/driver/index.php','/apps/delivery/driver/api.php','/apps/hr-portal/portal-login.php','/change-access-code.php'] as $p)expectDriver(Driver::allowedPath($p),'Allowed own-service route');
foreach(['/settings.php','/apps/delivery/front-api.php','/apps/delivery/accounting.php','/apps/hr-portal/employees.php','/apps/hr-portal/save-employee.php','/apps/operations/orders-board.php','/evil/apps/delivery/driver/api.php'] as $p)expectDriver(!Driver::allowedPath($p),'Unrelated route denied');
expectDriver(portal_user_can_access_feature('hr',['id'=>7,'role_key'=>'delivery_driver']),'Personal HR available');
expectDriver(!portal_user_can_access_feature('input_vat',['id'=>7,'role_key'=>'delivery_driver']),'Overrides do not expand Driver access');
$db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=delivery_order_adapter_test;charset=utf8mb4','root','delivery-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();
try{
 $id=random_int(100000000,200000000);$db->exec("INSERT INTO ops_roles(id,role_key) VALUES($id,'delivery_driver')");$db->exec("INSERT INTO ops_employees(id,role_id,status) VALUES($id,$id,'active')");$db->exec("INSERT INTO delivery_driver_profiles(employee_id,active,verified_by_employee_id,verified_at) VALUES($id,1,$id,UTC_TIMESTAMP())");
 $actor=Driver::actor($db,$id);expectDriver(Policy::canView($actor,['driver_employee_id'=>$id]),'Own deliveries visible');expectDriver(!Policy::canView($actor,['driver_employee_id'=>$id+1]),'Other Driver jobs hidden');expectDriver(!Policy::can($actor,'delivery_reconcile'),'Driver cannot reconcile');
 $db->exec("UPDATE ops_roles SET role_key='marketing_sales' WHERE id=$id");try{Driver::actor($db,$id);throw new RuntimeException('Role change ignored');}catch(DomainException $expected){}
 $db->exec("UPDATE ops_roles SET role_key='delivery_driver' WHERE id=$id");$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=$id");try{Driver::actor($db,$id);throw new RuntimeException('Inactive account accepted');}catch(DomainException $expected){}
}finally{$db->rollBack();}
$_SESSION=['driver'=>['id'=>123]];expectDriver(!portal_validate_authenticated_session(),'Old mobile cookie is not employee authentication');
$_SESSION=['user'=>['id'=>123,'role_key'=>'delivery_driver']];portal_initialize_authenticated_session($_SESSION['user']);expectDriver(portal_validate_authenticated_session(),'Standard employee session accepted');$_SESSION['absolute_expires_at']=1;expectDriver(!portal_validate_authenticated_session(),'Expired employee session denied');
$hr=file_get_contents(BASE_PATH.'/apps/hr-portal/portal-login.php');expectDriver(strpos($hr,'WHERE portal_user_id = ? AND active = 1')!==false,'HR remains explicitly employee-linked');
echo "PASS: employee Driver landing, own HR allowlist, assigned-only jobs, role/status revocation, shared-session expiry and unchanged Marketing landing.\n";
