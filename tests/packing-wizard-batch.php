<?php
declare(strict_types=1);
// Execute the production create_invoice_rows branch against an isolated CI MariaDB.
// Authentication/notifications are boundary stubs; all validation, inserts, workload,
// locking, reconciliation and transaction rollback use the production branch.
function db(): PDO { static $pdo; if(!$pdo) $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=packing_wizard_ci','root','synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); return $pdo; }
function ops_column_exists(string $table,string $column): bool { $stmt=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$table,$column]);return (bool)$stmt->fetchColumn(); }
function ops_post_string(string $key,int $limit): string {return substr((string)($_POST[$key]??''),0,$limit);}
function ops_rows(string $sql,array $values=[]): array {$s=db()->prepare($sql);$s->execute($values);return $s->fetchAll(PDO::FETCH_ASSOC);}
function ops_employee_can_receive_packing(int $id,bool $auto): bool {return in_array($id,[101,102],true);}
function current_user(): array {return ['id'=>1,'name'=>'Synthetic owner'];}
function packing_portal_now(): string {return '2026-10-13 12:00:00';}
function packing_active_where(): string {return '1=1';}
function notifications_schema_ready(): bool {return true;}
function packing_publish_assignment(...$args): void {}
function notifications_notify_packing_loaded(...$args): void {}
function ops_activity_log(string $action,string $type,int $id,array $metadata=[]): void {
 if(($GLOBALS['scenario']??'')==='sql-failure'&&$action==='packing_item_created'&&++$GLOBALS['created_events']===2)db()->exec('INSERT INTO missing_table VALUES (1)');
}
function sourceFunctions(): void {
 $s=file_get_contents(__DIR__.'/../apps/operations/packing-list-action.php');
 eval(substr($s,strpos($s,'function packing_unit_meta('),strpos($s,'function packing_ensure_quantity_text_column(')-strpos($s,'function packing_unit_meta(')));
 eval(substr($s,strpos($s,'function packing_invoice_row_key('),strpos($s,'function packing_find_existing_invoice_row(')-strpos($s,'function packing_invoice_row_key(')));
}
if(($argv[1]??'')==='--child') {
 sourceFunctions();$scenario=$argv[2];$GLOBALS['scenario']=$scenario;$GLOBALS['created_events']=0;
 $rows=[['item_name'=>'CI Tea','received_weight'=>'1000units','unit'=>'units','quantity_planned'=>'50(20)','priority'=>'medium','assigned_employee_id'=>102,'assignment_source'=>'manual','general_instructions'=>'Apply labels to all 1000 units','notes'=>'Keep 17 boxes upright'],['item_name'=>'CI Oil','received_weight'=>'1L','unit'=>'L','quantity_planned'=>'250ml(4)','priority'=>'high','assigned_employee_id'=>101,'assignment_source'=>'manual']];
 if($scenario==='invalid')$rows[1]['quantity_planned']='250ml(5)';
 if($scenario==='ineligible')$rows[1]['assigned_employee_id']=999;
 $_POST=['rows_json'=>json_encode($rows),'declared_count'=>2,'import_id'=>in_array($scenario,['valid','retry','concurrent'],true)?'wizard-retry-batch':'wizard-'.$scenario,'draft_mode'=>'manual','distribution_reviewed'=>'1','sync_mode'=>'create_only','reviewed_assignments_json'=>json_encode(array_map(static function($row,$index){return ['index'=>$index]+$row;},$rows,array_keys($rows)))];
 if($scenario==='tampered') { $reviews=json_decode($_POST['reviewed_assignments_json'],true);$reviews[1]['assigned_employee_id']=102;$_POST['reviewed_assignments_json']=json_encode($reviews); }
 $canManage=$scenario!=='employee';$currentEmployeeId=1;$action='create_invoice_rows';
 $s=file_get_contents(__DIR__.'/../apps/operations/packing-list-action.php');$start=strpos($s,"    if (\$action === 'create_invoice_rows') {");$end=strpos($s,"    if (\$action === 'confirm_frontdesk_website_update') {",$start);
 try {eval(substr($s,$start,$end-$start));}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(!empty($batchLock)){$r=db()->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$batchLock]);}echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
 exit;
}
$root=new PDO('mysql:host=127.0.0.1;port=3306','root','synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE IF NOT EXISTS packing_wizard_ci');
db()->exec('DROP TABLE IF EXISTS ops_packing_tasks');
db()->exec('CREATE TABLE ops_packing_tasks (id INT AUTO_INCREMENT PRIMARY KEY,item_name VARCHAR(190), received_weight VARCHAR(80),priority VARCHAR(30),date_loaded DATETIME,quantity_planned VARCHAR(255),assigned_employee_id INT,packing_status VARCHAR(40),date_completed DATETIME NULL,workload_points DECIMAL(12,2),notes TEXT,created_by INT,packing_row_key VARCHAR(100),packing_website_confirmed INT DEFAULT 0,date_started DATETIME NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,workload_parse_status VARCHAR(40),workload_package_count DECIMAL(12,2),workload_weight_grams DECIMAL(15,2),workload_volume_ml DECIMAL(15,2),workload_unit_count DECIMAL(15,2),workload_breakdown_json TEXT) ENGINE=InnoDB');
function invoke(string $scenario): array { $p=proc_open([PHP_BINARY,__FILE__,'--child',$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$code=proc_close($p);if($code!==0)throw new RuntimeException($error);$data=json_decode($text,true);if(!is_array($data))throw new RuntimeException($text);return $data; }
function countRows(): int {return (int)db()->query('SELECT COUNT(*) FROM ops_packing_tasks')->fetchColumn();}
foreach(['invalid','ineligible','tampered','employee','sql-failure'] as $scenario){$result=invoke($scenario);if(countRows()!==0||($result['success']??$result['ok']??true)!==false)throw new RuntimeException($scenario.' failed isolation');echo "PASS $scenario saves zero items\n";}
$first=invoke('valid');if(($first['inserted_count']??0)!==2||countRows()!==2)throw new RuntimeException(json_encode($first));
$rows=db()->query('SELECT * FROM ops_packing_tasks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if((int)$rows[0]['assigned_employee_id']!==102||(float)$rows[0]['workload_package_count']!==20.0||(float)$rows[0]['workload_unit_count']!==1000.0||strpos($rows[0]['notes'],'Keep 17 boxes upright')===false||strpos($rows[0]['notes'],'Apply labels to all 1000 units')===false)throw new RuntimeException('Assignment/notes/workload not preserved');
$retry=invoke('retry');if(($retry['skipped_count']??0)!==2||countRows()!==2)throw new RuntimeException('Duplicate retry');
// Run two genuine concurrent retries to exercise the connection-scoped import lock.
$processes=[];foreach([1,2] as $i){$pipes=[];$processes[]=[$p=proc_open([PHP_BINARY,__FILE__,'--child','concurrent'],[1=>['pipe','w'],2=>['pipe','w']],$pipes),$pipes];}
foreach($processes as [$p,$pipes]){$r=json_decode(stream_get_contents($pipes[1]),true);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);if(proc_close($p)!==0||($r['skipped_count']??0)!==2)throw new RuntimeException('Concurrent retry: '.$errors);}
if(countRows()!==2)throw new RuntimeException('Duplicate items');
echo "PASS real MariaDB atomic creation, server revalidation, exact reviewed assignments, preserved notes/workload, retry and concurrent duplicate prevention\n";
