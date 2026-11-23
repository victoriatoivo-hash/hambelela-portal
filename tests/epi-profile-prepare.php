<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$name=$argv[1]??'';
if(!preg_match('/^epi_p0_audit_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Existing synthetic test database required.');
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names=$db->query('SELECT full_name FROM ops_employees')->fetchAll(PDO::FETCH_COLUMN);
foreach($names as $n)if(strpos($n,'Synthetic')!==0)throw new RuntimeException('Not exclusively synthetic identities.');
$db->exec("CREATE TABLE IF NOT EXISTS ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80),name VARCHAR(100));
 INSERT IGNORE INTO ops_roles VALUES(1,'owner_admin','Owner'),(2,'front_desk_admin','Front Desk'),(3,'packer','Packer')");
$columns=$db->query('SHOW COLUMNS FROM ops_employees')->fetchAll(PDO::FETCH_COLUMN);
foreach(['role_id'=>'INT DEFAULT 2','status'=>"VARCHAR(20) DEFAULT 'active'",'email'=>'VARCHAR(190)',
 'hire_date'=>'DATE','working_days'=>'VARCHAR(60)','shift_start'=>'TIME','shift_end'=>'TIME','late_grace_minutes'=>'INT DEFAULT 0'] as $key=>$type)
 if(!in_array($key,$columns,true))$db->exec('ALTER TABLE ops_employees ADD '.$key.' '.$type);
$db->exec("UPDATE ops_employees SET role_id=1 WHERE id=999");
if(($argv[2]??'')==='--quality-review-fixture'){
    require_once dirname(__DIR__).'/shared/epi/bootstrap.php';
    require_once dirname(__DIR__).'/shared/epi/PerformanceRefreshRuntime.php';
    if(!$db->query('SELECT id FROM ops_error_logs WHERE id=71')->fetchColumn())throw new RuntimeException('Cash-review test fixture required.');
    $db->exec("UPDATE ops_error_logs SET attribution_type='employee',affects_kpi_accuracy=1 WHERE id=71");
    \Hambelela\EPI\V2OperationalBridge::record($db,'error_log','error_owner_reviewed',71,
        ['employee_id'=>999,'actor_employee_id'=>999,'occurred_at'=>'2026-10-01 15:00:00']);
    \Hambelela\EPI\PerformanceRefreshRuntime::run($db);
}
echo "Synthetic profile fixture prepared: ".$name.PHP_EOL;
