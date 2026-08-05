<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if(!hash_equals('74f0cb069fd24e8eb97e3530bf7d9a61',(string)($_GET['token']??''))){http_response_code(404);exit('Not found');}
require_once __DIR__.'/config.php';require_once BASE_PATH.'/shared/database.php';
try{
 $sql=(string)file_get_contents(__DIR__.'/operations-epi-bookkeeping-performance-migration.sql');
 foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]))as$statement){if($statement===''||(strpos(ltrim($statement),'--')===0&&strpos($statement,"\n")===false))continue;db()->exec($statement);}
 foreach(['bookkeeping_module_enabled','bookkeeping_cash_entry_grace_minutes','bookkeeping_cash_entry_deadline','bookkeeping_deposit_deadline','bookkeeping_deposit_schedule','bookkeeping_variance_tolerance_cents']as$key){$s=db()->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$s->execute([$key]);echo$key.'='.(string)$s->fetchColumn()."\n";}
 foreach(['epi_bookkeeping_match_reviews','epi_bookkeeping_exceptions']as$table){$s=db()->prepare('SHOW TABLES LIKE ?');$s->execute([$table]);echo$table.'='.(string)$s->fetchColumn()."\n";}
 $s=db()->query("SELECT grace_key,minutes FROM epi_employee_grace_periods WHERE module='Bookkeeping' ORDER BY grace_key");foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row)echo$row['grace_key'].'='.$row['minutes']."\n";
 echo"MIGRATION_OK\n";
}catch(Throwable$e){http_response_code(500);echo'ERROR: '.$e->getMessage();}
