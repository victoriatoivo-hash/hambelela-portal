<?php
declare(strict_types=1);
// CLI only. Inventory only: no DDL, account creation, uploads or application bootstrap.
if(PHP_SAPI!=='cli')exit(1);
$root=isset($argv[1])?realpath($argv[1]):false;
if(!$root||!is_file($root.'/config.php')){fwrite(STDERR,"Usage: php delivery-readonly-preflight.php PORTAL_ROOT\n");exit(1);}
try{
    require $root.'/config.php';
    $db=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s',DB_HOST,DB_NAME,DB_CHARSET),DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    $tables=['ops_employees','ops_roles','ops_permissions','ops_role_permissions','ops_security_events','ops_orders','order_payment_allocations','order_payment_allocation_audit',
        'delivery_partners','delivery_partner_users','delivery_driver_profiles','delivery_employee_grants','delivery_zones',
        'delivery_jobs','delivery_requests','delivery_events','delivery_receipts','delivery_expenses','delivery_ledger','delivery_outbox',
        'delivery_driver_credentials','delivery_login_limits','delivery_order_sync_reviews'];
    $report=['kind'=>'read-only inventory, not release approval','database_version'=>$db->query('SELECT VERSION()')->fetchColumn(),'tables'=>[],'file_sha256'=>[]];
    $s=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    foreach($tables as $table){$s->execute([$table]);$columns=$s->fetchAll();$report['tables'][$table]=['present'=>(bool)$columns,'columns'=>$columns];
        if($columns){$create=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);$report['tables'][$table]['definition']=$create[1];}}
    // No employee/customer/order contents or credentials are selected or printed.
    $db->rollBack();
    foreach(['apps/operations/operations.php','apps/operations/orders-board-action.php','apps/operations/sync-orders.php','shared/employee-features.php','shared/order-payment-methods.php'] as $file){$report['file_sha256'][$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;}
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Read-only inventory failed. Check connection and metadata permissions privately; no release action was attempted.\n");exit(1);}
