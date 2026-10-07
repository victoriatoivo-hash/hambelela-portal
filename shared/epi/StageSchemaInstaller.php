<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Explicit first-install operation only. Never called by runtime bootstrap or GET. */
final class StageSchemaInstaller
{
    private const FILES = [
        'operations-epi-orders-sla-migration.sql' => '283dac06425dad4d1c0e7bbbce82c24611a8af939b4888176e6883f2b4c54317',
        'operations-epi-front-roster-migration.sql' => 'f56895864210d989d40a041e8ec14ed44732978b73ee136ba7a4c662cbe3dace',
        'operations-epi-front-coverage-migration.sql' => 'f1d698ba8634c32240f8905546d04fbb28bf21323ef20dd7737f9d572155792d',
    ];
    private const TABLES = ['epi_v2_orders_policy','epi_v2_orders_tracking','epi_v2_front_rosters','epi_v2_front_plans','epi_v2_front_reminders'];
    private const FLAGS = ['epi_v2_orders_sla_enabled','epi_v2_front_roster_enabled','epi_v2_front_coverage_enabled'];

    public static function inspect(PDO $db, string $root): array
    {
        $blocks=[];
        $version=(string)$db->query('SELECT VERSION()')->fetchColumn();
        // These files use MariaDB CREATE TRIGGER IF NOT EXISTS. Do not guess MySQL compatibility.
        if(stripos($version,'MariaDB')===false || version_compare($version,'10.6','<'))$blocks[]='MariaDB 10.6+ required';
        $hashes=[];
        foreach(self::FILES as $file=>$expected){
            $path=$root.'/'.$file;
            $sql=is_file($path)?file_get_contents($path):false;
            $hashes[$file]=$sql===false?null:hash('sha256',str_replace("\r\n","\n",$sql));
            if($hashes[$file]!==$expected)$blocks[]='migration_hash:'.$file;
        }
        foreach(['epi_employee_performance_settings'=>['setting_key','setting_value','value_type','description'],
            'epi_v2_activation'=>['id','mode'],
            'epi_v2_event_registry'=>['event_key','module','description','responsibility_type','polarity','score_eligible','owner_review_required','sla_obligation_key','category_key','applicable_roles_json']] as $table=>$columns){
            $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $s->execute([$table]);$found=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();
            if(array_diff($columns,$found))$blocks[]='prerequisite:'.$table;
        }
        $present=[];
        foreach(self::TABLES as $table){
            $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $s->execute([$table]);if((int)$s->fetchColumn())$present[]=$table;$s->closeCursor();
        }
        if($present)$blocks[]='existing_stage_schema_requires_review';
        if(!in_array('prerequisite:epi_v2_activation',$blocks,true)){
            if($db->query('SELECT mode FROM epi_v2_activation WHERE id=1')->fetchColumn()!=='shadow')$blocks[]='shadow_activation_required';
        }
        $flags=[];
        if(!in_array('prerequisite:epi_employee_performance_settings',$blocks,true)){
            foreach(self::FLAGS as $flag){
                $s=$db->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');
                $s->execute([$flag]);$value=$s->fetchColumn();$s->closeCursor();$flags[$flag]=$value===false?null:(string)$value;
                if($value!==false && (string)$value!=='0')$blocks[]='active_or_invalid_flag:'.$flag;
            }
        }
        return ['ready'=>!$blocks,'blockers'=>$blocks,'migration_sha256'=>$hashes,'existing_stage_tables'=>$present,'flags'=>$flags,
            'database_changes'=>false,'activation_performed'=>false,'official_scores_changed'=>false];
    }

    public static function apply(PDO $db,string $root,string $backupReference): array
    {
        // Reference records operator acknowledgement, not a claim that this tool took a backup.
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/D',$backupReference))throw new RuntimeException('A verified backup reference is required');
        if($db->inTransaction())throw new RuntimeException('DDL cannot be nested in a transaction');
        if((int)$db->query("SELECT GET_LOCK('epi_stage_schema_install',0)")->fetchColumn()!==1)throw new RuntimeException('Another stage installer holds the lock');
        try{
            $report=self::inspect($db,$root);
            if(!$report['ready'])throw new RuntimeException('Preflight refused: '.implode(', ',$report['blockers']));
            foreach(self::FILES as $file=>$expected){
                $sql=str_replace("\r\n","\n",(string)file_get_contents($root.'/'.$file));
                if(hash('sha256',$sql)!==$expected)throw new RuntimeException('Migration changed after preflight');
                foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
            }
            $after=self::inspect($db,$root);
            if($after['existing_stage_tables']!==self::TABLES || count(array_filter($after['flags'],function($v){return $v==='0';}))!==3)
                throw new RuntimeException('Post-install verification failed; preserve partial schema for review');
            return ['status'=>'installed_disabled','backup_reference'=>$backupReference,'migration_sha256'=>$report['migration_sha256'],
                'database_changes'=>true,'activation_performed'=>false,'official_scores_changed'=>false,'flags'=>$after['flags']];
        } finally {$db->query("SELECT RELEASE_LOCK('epi_stage_schema_install')")->closeCursor();}
    }
}
