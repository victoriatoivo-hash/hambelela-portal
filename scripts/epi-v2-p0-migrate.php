<?php
declare(strict_types=1);
// Explicit deployment operation. Defaults to preflight only; never loaded by GET.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/shared/database.php';
try {
 $db=db();$version=(string)$db->query('SELECT VERSION()')->fetchColumn();
 if(stripos($version,'MariaDB')!==false){if(version_compare($version,'10.6','<'))throw new RuntimeException('MariaDB 10.6+ required');}
 elseif(version_compare($version,'8.0.29','<'))throw new RuntimeException('MySQL 8.0.29+ required for enforced checks and idempotent triggers');
 $columns=['epi_employee_performance_settings'=>['setting_key','setting_value','value_type','description'],'ops_employees'=>['id']];
 foreach($columns as$table=>$required)foreach($required as$column){$s=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);if(!(int)$s->fetchColumn())throw new RuntimeException('Missing prerequisite: '.$table.'.'.$column);}
 foreach(['epi_v2_operational_deadlines'=>'policy_snapshot_json','epi_v2_performance_incidents'=>'root_incident_id','epi_v2_duty_periods'=>'superseded_at','epi_v2_quality_revisions'=>'active_root']as$table=>$column){
  $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$found=$s->fetchAll(PDO::FETCH_COLUMN);if($found&&!in_array($column,$found,true))throw new RuntimeException('Older P0 schema detected: '.$table.'; STOP for additive upgrade review, do not replay blindly');
 }
 $s=$db->query("SELECT setting_key FROM epi_employee_performance_settings WHERE setting_key IN('epi_v2_capture_enabled','epi_v2_watchdog_enabled') AND setting_value<>'0'");
 if($s->fetch())throw new RuntimeException('Capture/watchdog must be disabled explicitly before schema operations');
 if(!in_array('--apply',$argv,true)){echo "Preflight passed; no migration applied.\n";exit;}
 $lock=$db->query("SELECT GET_LOCK('epi_v2_p0_migration',0)")->fetchColumn();if(!(int)$lock)throw new RuntimeException('Migration already running');
 try{foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);}
 finally{$db->query("SELECT RELEASE_LOCK('epi_v2_p0_migration')");}
 echo "Shadow schema installed. Capture/watchdog remain disabled; no activation created.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
