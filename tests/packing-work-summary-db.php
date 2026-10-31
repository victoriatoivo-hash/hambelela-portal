<?php
declare(strict_types=1);
// Disposable loopback database only. Does not load portal configuration.
$pdo=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+02:00'");
function db(){global$pdo;return $pdo;}
function ops_hr_db(){return db();}
function require_login(){}
function current_role_key(){global$argv;return $argv[3]??'owner_admin';}
function ops_current_employee_id(){return 7;}
function ops_rows($sql,$args=[]){$q=db()->prepare($sql);$q->execute($args);return $q->fetchAll();}
function ops_table_exists($table){return (bool)ops_rows('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table]);}
function ops_column_exists($table,$column){return (bool)ops_rows('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]);}
if(($argv[1]??'')==='child'){
    if(!preg_match('/^packing_work_[a-f0-9]{16}$/D',$argv[2]))exit(2);
    $pdo->exec('USE `'.$argv[2].'`');define('BASE_PATH',dirname(__DIR__));
    $_GET=['id'=>(int)($argv[4]??7),'from'=>'2026-10-01','to'=>'2026-10-29'];$_SERVER['REQUEST_METHOD']=$argv[5]??'GET';
    $source=file_get_contents(BASE_PATH.'/apps/operations/kpi-packing-work-summary.php');
    eval(substr(str_replace("require_once __DIR__.'/operations.php';",'',$source),5));exit;
}
$name='packing_work_'.bin2hex(random_bytes(8));$pdo->exec("CREATE DATABASE `$name`");$pdo->exec("USE `$name`");
try{
    $pdo->exec("CREATE TABLE ops_roles(id INT,role_key VARCHAR(40));INSERT INTO ops_roles VALUES(1,'packer'),(2,'front_desk');
      CREATE TABLE ops_employees(id INT,full_name VARCHAR(100),role_id INT);INSERT INTO ops_employees VALUES(7,'Packer A',1),(8,'Packer B',1),(9,'Front',2);
      CREATE TABLE ops_packing_tasks(id INT,item_name VARCHAR(100),assigned_employee_id INT,packing_status VARCHAR(50),quantity_planned TEXT,quantity_packed TEXT,priority VARCHAR(20),date_loaded DATETIME,created_at DATETIME,date_started DATETIME,date_completed DATETIME,deleted_at DATETIME);
      INSERT INTO ops_packing_tasks VALUES(1,'Chia',7,'done','100g (500)','100g (500)','critical','2026-09-01 08:00:00','2026-09-01 08:00:00','2026-10-02 16:30:00','2026-10-05 09:00:00',NULL),(2,'Other employee',8,'done','500ml(200)','500ml(200)','critical','2026-10-01 08:00:00','2026-10-01 08:00:00','2026-10-02 16:30:00','2026-10-05 09:00:00',NULL),(3,'Labels unfinished',7,'packed_label_needed','100g(20)','100g(20)','high','2026-10-01 08:00:00','2026-10-01 08:00:00',NULL,'2026-10-05 09:00:00',NULL);
      CREATE TABLE kpi_status_events(id INT,module VARCHAR(30),record_id INT,old_status VARCHAR(50),new_status VARCHAR(50),changed_by INT,changed_at DATETIME);
      INSERT INTO kpi_status_events VALUES(1,'packing',1,'not_started','packing',7,'2026-10-02 14:30:00'),(2,'packing',1,'packing','done',7,'2026-10-05 07:00:00');
      CREATE TABLE ops_activity_logs(id INT,entity_id INT,entity_type VARCHAR(30),action VARCHAR(80),metadata TEXT,employee_id INT,created_at TIMESTAMP);
      CREATE TABLE ops_packing_assignment_log(id INT,packing_task_id INT,old_employee_id INT,new_employee_id INT,changed_at DATETIME);
      CREATE TABLE employee_user_links(portal_user_id INT,hr_employee_id INT,linked_at DATETIME,active INT);INSERT INTO employee_user_links VALUES(7,101,'2026-01-01 00:00:00',1);
      CREATE TABLE leave_requests(employee_id INT,status VARCHAR(20),start_date DATE,end_date DATE,days DECIMAL(5,2),approved_at DATETIME,approved_by INT);
      INSERT INTO leave_requests VALUES(101,'approved','2026-10-03','2026-10-03',1,'2026-09-01 08:00:00',1);");
    $call=static function($role='owner_admin',$id=7,$method='GET')use($name){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' child '.escapeshellarg($name).' '.escapeshellarg($role).' '.(int)$id.' '.escapeshellarg($method);$out=shell_exec($cmd);$result=json_decode((string)$out,true);if(!$result)throw new RuntimeException('Bad response: '.$out);return$result;};
    $before=ops_rows('SELECT * FROM ops_packing_tasks');$p=$call();
    if(empty($p['ok'])||$p['summary']['completed']!==1||$p['summary']['open']!==1||$p['summary']['packages']!=500||$p['summary']['kg']!=50)throw new RuntimeException('Output quantity / carry-in / labels failed: '.json_encode($p));
    if($p['summary']['timing']['median_minutes']!=90||$p['summary']['evidence'][0]['finished_at']!=='2026-10-05 09:00:00')throw new RuntimeException('UTC conversion or approved absence failed');
    if($call('packer',8)['ok']!==false||$call('owner_admin',9)['ok']!==false||$call('owner_admin',7,'POST')['ok']!==false)throw new RuntimeException('Permission/write protection failed');
    if($call('packer',7)['summary']!==$p['summary'])throw new RuntimeException('Owner/employee views differ');
    $q=$pdo->prepare('INSERT INTO ops_activity_logs VALUES(1,1,\'packing_task\',\'packing_packing_status_updated\',?,7,\'2026-10-05 09:00:00\')');$q->execute([json_encode(['field'=>'packing_status','previous_value'=>'packing','new_value'=>'done'])]);
    $again=$call();if($again['summary']['completed']!==1||$again['summary']['packages']!=500)throw new RuntimeException('Mirrored history double count');
    if($before!==ops_rows('SELECT * FROM ops_packing_tasks'))throw new RuntimeException('Report mutated records');
    if($p['policy']['stalled_alerts']!==false||$p['policy']['fixed_deadlines']!==false||$p['policy']['score_changes']!==false)throw new RuntimeException('Cancelled alerts or scoring introduced');
    $pdo->exec("UPDATE leave_requests SET days=0.5");$p=$call();if($p['summary']['absence_source_verified']!==false||$p['summary']['timing']['median_minutes']!=330)throw new RuntimeException('Ambiguous half-day falsely deducted');
    echo "PASS: Packing List endpoint permissions, read-only data, historical carry-in, UTC, no duplicate completions, approved absence and no alerts.\n";
}finally{$pdo->exec("DROP DATABASE `$name`");}
