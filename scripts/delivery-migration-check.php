<?php
declare(strict_types=1);
// Consume the JSON from delivery-readonly-preflight.php. No database connection or DDL.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../shared/delivery/MigrationPreflight.php';
try{
    $inventory=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    if(!is_array($inventory)||!isset($inventory['database_version'],$inventory['tables']))throw new RuntimeException('Valid read-only inventory required.');
    $tables=\Hambelela\Delivery\MigrationPreflight::approvedTables(__DIR__.'/../database/delivery');
    $result=\Hambelela\Delivery\MigrationPreflight::assess($inventory,$tables);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit($result['schema_preflight_passed']?0:2);
}catch(Throwable $e){fwrite(STDERR,'Migration check stopped: '.$e->getMessage()."\n");exit(1);}
