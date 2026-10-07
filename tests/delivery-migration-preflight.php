<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/MigrationPreflight.php';
use Hambelela\Delivery\MigrationPreflight;
function migrationExpect(bool $result,string $label):void{if(!$result)throw new RuntimeException($label);echo 'PASS: '.$label."\n";}
$tables=MigrationPreflight::approvedTables(__DIR__.'/../database/delivery');
migrationExpect(count($tables)===15,'approved hashes match all 15 distinct table definitions');
$inventory=['database_version'=>'11.4.13-MariaDB','tables'=>[]];
foreach(['ops_employees','ops_permissions','ops_orders'] as $parent)$inventory['tables'][$parent]=[
    'present'=>true,'definition'=>'CREATE TABLE test (...) ENGINE=InnoDB',
    'columns'=>[['COLUMN_NAME'=>'id','COLUMN_TYPE'=>'int(11)','COLUMN_KEY'=>'PRI']]];
$valid=MigrationPreflight::assess($inventory,$tables);
migrationExpect($valid['schema_preflight_passed']&&!$valid['writes_performed']&&!$valid['application_activation_approved'],'clean schema checks do not write or approve activation');
$changed=$inventory;$changed['tables']['delivery_login_limits']=['present'=>true];
migrationExpect(!MigrationPreflight::assess($changed,$tables)['schema_preflight_passed'],'partial prior migration blocks automatic replay');
$changed=$inventory;$changed['tables']['ops_employees']['columns'][0]['COLUMN_TYPE']='int(11) unsigned';
migrationExpect(!MigrationPreflight::assess($changed,$tables)['schema_preflight_passed'],'incompatible foreign-key signedness rejected');
$changed=$inventory;$changed['tables']['ops_orders']['definition']='ENGINE=MyISAM';
migrationExpect(!MigrationPreflight::assess($changed,$tables)['schema_preflight_passed'],'nontransactional dependency rejected');
$changed=$inventory;$changed['database_version']='5.7.44';
migrationExpect(!MigrationPreflight::assess($changed,$tables)['schema_preflight_passed'],'database without enforced CHECK constraints rejected');
