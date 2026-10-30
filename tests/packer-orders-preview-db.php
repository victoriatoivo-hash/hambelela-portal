<?php
declare(strict_types=1);
// Loopback-only disposable data; never loads production config or business records.
$pdo=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+02:00'");
function db(){global$pdo;return $pdo;}
function require_login(){}
function current_role_key(){global$argv;return $argv[3]??'owner_admin';}
function ops_current_employee_id(){return 7;}
function ops_rows($sql,$args=[]){$q=db()->prepare($sql);$q->execute($args);return $q->fetchAll();}
function ops_table_exists($table){return (bool)ops_rows('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);}
function ops_column_exists($table,$column){return (bool)ops_rows('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]);}
function ops_order_display_datetime_expr($alias){return "$alias.created_at";}
if(($argv[1]??'')==='child'){
    if(!preg_match('/^packer_preview_[a-f0-9]{16}$/D',$argv[2]))exit(2);
    $pdo->exec('USE `'.$argv[2].'`');define('BASE_PATH',dirname(__DIR__));
    $_GET=['id'=>(int)($argv[4]??7),'from'=>'2026-10-01','to'=>'2026-10-29'];$_SERVER['REQUEST_METHOD']=$argv[5]??'GET';
    $source=file_get_contents(BASE_PATH.'/apps/operations/kpi-packer-orders-preview.php');
    $source=str_replace("require_once __DIR__.'/operations.php';",'',$source);
    eval(substr($source,5));exit;
}
$name='packer_preview_'.bin2hex(random_bytes(8));$pdo->exec("CREATE DATABASE `$name`");$pdo->exec("USE `$name`");
try{
    $schema=file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql');
    foreach(['outbox','ownership_periods','exceptions']as$table){preg_match('/CREATE TABLE IF NOT EXISTS epi_v2_'.$table.'\b.*?;/s',$schema,$m);$pdo->exec($m[0]);}
    $pdo->exec("CREATE TABLE ops_roles(id INT,role_key VARCHAR(80));CREATE TABLE ops_employees(id INT,full_name VARCHAR(80),role_id INT);
      INSERT INTO ops_roles VALUES(1,'packer'),(2,'front_desk_admin');INSERT INTO ops_employees VALUES(7,'Test Packer',1),(8,'Other Packer',1),(9,'Front',2);
      CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number INT,fulfilment_mode VARCHAR(30),order_type VARCHAR(30),customer_contact VARCHAR(80),status VARCHAR(30),assigned_packer_id INT,payment_method VARCHAR(30),portal_paid_confirmed INT,created_at DATETIME,deleted_at DATETIME);
      CREATE TABLE kpi_status_events(id INT PRIMARY KEY,record_id INT,module VARCHAR(30),old_status VARCHAR(30),new_status VARCHAR(30),changed_by INT,changed_at DATETIME);
      INSERT INTO ops_orders VALUES(1,123,'collection','collection','081','in_progress',7,'cash',0,'2026-10-28 08:00:00',NULL),(2,124,'courier','courier','081','new_order',7,'eft',0,'2026-10-28 08:00:00',NULL),(3,125,'collection','collection','081','in_progress',8,'eft',1,'2026-10-28 08:00:00',NULL);
      INSERT INTO kpi_status_events VALUES(1,1,'order','new_order','in_progress',7,'2026-10-28 07:30:00'),(2,3,'order','new_order','in_progress',8,'2026-10-28 07:00:00');");
    foreach([1,2,3]as$id){$p=['metadata'=>['occurred_at'=>'2026-10-28 08:00:00','original_created_at'=>'2026-10-28 08:00:00'],'record'=>['status'=>'new_order','portal_paid_confirmed'=>0]];$pdo->prepare("INSERT INTO epi_v2_outbox(event_key,entity_type,entity_id,action,payload_json) VALUES(?,'order',?,'order_created',?)")->execute([hash('sha256',(string)$id),$id,json_encode($p)]);}
    $pdo->exec("INSERT INTO epi_v2_ownership_periods(ownership_uuid,module,object_reference,employee_id,role_key,ownership_reason,effective_from,assigned_by,source) VALUES('a','Packing','ORDER-1',7,'packer','approved_order_assignment','2026-10-28 08:00:00',9,'test')");
    $call=function($role='owner_admin',$id=7,$method='GET')use($name){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' child '.$name.' '.$role.' '.$id.' '.$method;$out=shell_exec($cmd);$p=json_decode($out,true);if(!is_array($p))throw new RuntimeException('Invalid endpoint response: '.$out);return $p;};
    $before=ops_rows('SELECT * FROM ops_orders');$p=$call();
    if(empty($p['ok']))throw new RuntimeException(json_encode($p));
    if(count($p['rows'])!==2||$p['summary']['packed']!==1||$p['summary']['score']!=100||$p['summary']['average']!=90||$p['summary']['waiting_paid']!==1)throw new RuntimeException('Incorrect database preview: '.json_encode($p['summary']));
    if($p['rows'][0]['packed_at']!=='2026-10-28 09:30:00')throw new RuntimeException('UTC conversion incorrect');
    if($call('packer',8)['ok']!==false)throw new RuntimeException('Employee isolation failed');
    if($call('owner_admin',9)['ok']!==false)throw new RuntimeException('Nonpacker isolation failed');
    if($call('owner_admin',7,'POST')['ok']!==false)throw new RuntimeException('Write method accepted');
    if($call('packer',7)['summary']!==$p['summary'])throw new RuntimeException('Owner/employee formula differs');
    if($before!==ops_rows('SELECT * FROM ops_orders'))throw new RuntimeException('Preview mutated orders');
    $pdo->exec("CREATE TABLE ops_activity_logs(id INT,entity_id INT,entity_type VARCHAR(30),action VARCHAR(60),metadata TEXT,employee_id INT,created_at DATETIME);
      CREATE TABLE ops_order_stage_events(id INT,order_id INT,stage_key VARCHAR(40),metadata TEXT,employee_id INT,occurred_at TIMESTAMP);
      CREATE TABLE kpi_activity_events(id INT,record_id INT,record_type VARCHAR(30),action_key VARCHAR(60),metadata_json TEXT,employee_id INT,occurred_at DATETIME,previous_status VARCHAR(30),new_status VARCHAR(30));
      INSERT INTO ops_orders VALUES(4,126,'delivery','delivery','081','completed',7,'cash',0,'2026-09-03 08:00:00',NULL),(5,127,'delivery','delivery','081','in_progress',7,'cash',0,'2026-10-03 09:00:00',NULL);
      INSERT INTO ops_order_stage_events VALUES(1,4,'in_progress','{}',7,'2026-09-03 09:00:00'),(2,5,'in_progress','{}',7,'2026-10-03 10:00:00');");
    // Database TIMESTAMP mirror written in UTC must merge with the explicit UTC KPI event.
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec("INSERT INTO ops_order_stage_events VALUES(3,1,'in_progress','{}',7,'2026-10-28 07:30:00')");
    $pdo->exec("SET time_zone='+02:00'");
    // Missing old transitions cannot inflate the current month's evidence/review counts.
    $pdo->exec("INSERT INTO ops_orders VALUES(6,128,'collection','collection','081','completed',7,'cash',1,'2026-08-03 08:00:00',NULL),(7,129,'courier','courier','081','completed',7,'cash',0,'2026-08-03 08:00:00',NULL)");
    $pdo->exec("INSERT INTO ops_orders VALUES(8,130,'collection','collection','081','new_order',7,'cash',0,'2021-08-03 08:00:00',NULL),(9,131,'courier','courier','081','new_order',7,'cash',0,'2021-08-03 08:00:00',NULL)");
    $p=$call();
    if(empty($p['ok'])||$p['summary']['packed']!==2||$p['summary']['timing_measured']!==2||$p['summary']['responsibility_review']!==1)throw new RuntimeException('Legacy current results failed: '.json_encode($p));
    if($p['previous']['summary']['packed']!==1||$p['previous']['summary']['average']!=60)throw new RuntimeException('Historical previous period unavailable');
    if(count($p['rows'])!==3||$p['summary']['not_measured']!==0||$p['summary']['average']!=75)throw new RuntimeException('Date scoping or database timezone mirror failed');
    if(count($p['comparison'])!==2||$call('packer',7)['comparison']!==[])throw new RuntimeException('Comparison permission failure');
    $before=ops_rows('SELECT * FROM ops_orders');$call();if($before!==ops_rows('SELECT * FROM ops_orders'))throw new RuntimeException('History reader mutated records');
    echo "PASS: legacy history, prior period, measured timing without responsibility, colleague privacy, UTC conversion and read-only behavior\n";
}finally{$pdo->exec("DROP DATABASE `$name`");}
