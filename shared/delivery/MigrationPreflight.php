<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Read-only release checks. This class never executes SQL or applies a migration. */
final class MigrationPreflight
{
    public static function approvedTables(string $directory):array
    {
        $approval=json_decode(file_get_contents($directory.'/APPROVALS.json'),true,16,JSON_THROW_ON_ERROR);
        $tables=[];
        foreach($approval['migrations'] as $file=>$hash){
            if(!preg_match('/^00[123]-[a-z-]+\.sql$/D',$file)||!hash_equals($hash,hash_file('sha256',$directory.'/'.$file)))
                throw new \RuntimeException('Approved migration checksum mismatch. Stop and review changed SQL.');
            $sql=file_get_contents($directory.'/'.$file);
            $sql=preg_replace('/^--[^\r\n]*/m','',$sql);
            foreach(explode(';',$sql) as $statement){
                $statement=trim($statement);if($statement==='')continue;
                if(!preg_match('/^CREATE TABLE (delivery_[a-z_]+)\s*\(/D',$statement,$match))
                    throw new \RuntimeException('Unexpected non-additive migration statement.');
                $tables[]=$match[1];
            }
        }
        if(count($tables)!==15||count(array_unique($tables))!==15)
            throw new \RuntimeException('Expected exactly 15 distinct approved Delivery tables.');
        return $tables;
    }

    public static function assess(array $inventory,array $tables):array
    {
        $issues=[];$version=(string)($inventory['database_version']??'');
        if(!preg_match('/^(\d+\.\d+\.\d+)/',$version,$match))$issues[]='Unverified database version.';
        else{
            $minimum=stripos($version,'MariaDB')!==false?'10.2.1':'8.0.16';
            if(version_compare($match[1],$minimum,'<'))$issues[]='Database must enforce CHECK constraints.';
        }
        foreach(['ops_employees','ops_permissions','ops_orders'] as $parent){
            $entry=$inventory['tables'][$parent]??[];
            if(empty($entry['present'])||strpos($entry['definition']??'','ENGINE=InnoDB')===false){
                $issues[]=$parent.': missing InnoDB dependency.';continue;
            }
            $id=null;foreach($entry['columns']??[] as $column)if($column['COLUMN_NAME']==='id')$id=$column;
            if(!$id||!preg_match('/^int(?:\(\d+\))?$/D',$id['COLUMN_TYPE'])||$id['COLUMN_KEY']!=='PRI')
                $issues[]=$parent.': expected signed INT primary key for approved foreign keys.';
        }
        $existing=[];foreach($tables as $table)if(!empty($inventory['tables'][$table]['present']))$existing[]=$table;
        if($existing)$issues[]='Existing Delivery tables require inspection; do not replay DDL or automatically drop them: '.implode(', ',$existing);
        return ['schema_preflight_passed'=>!$issues,'issues'=>$issues,'planned_table_count'=>count($tables),
            'writes_performed'=>false,'application_activation_approved'=>false,
            'note'=>'Schema-only check. Backup, migration execution verification, shipping-data integration and account setup remain separate release gates.'];
    }
}
