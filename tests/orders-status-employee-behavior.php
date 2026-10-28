<?php
// Real status action against disposable MariaDB tables; never customer records.
$dsn = getenv('ORDERS_TEST_DSN');
if (!$dsn) { echo "SKIP: isolated MariaDB runtime required for status persistence test\n"; exit; }
$pdo = new PDO($dsn, 'root', getenv('ORDERS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = 'orders_status_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$source = file_get_contents(__DIR__.'/../apps/operations/orders-board-action.php');
$start = strpos($source, "} elseif (\$field === 'status') {") + strlen("} elseif (\$field === 'status') {");
$code = substr($source, $start, strpos($source, "} elseif (\$field === 'payment_status') {", $start)-$start);
function db(){return $GLOBALS['pdo'];}
function ops_board_current_packing_employee(){return $GLOBALS['employee'];}
function ops_ensure_kpi_activity_events(){}
function ops_column_exists($table,$column){return false;}
function ops_current_employee_id(){return $GLOBALS['employee']['id']??900;}
function current_user(){return ['name'=>$GLOBALS['employee']['full_name']??'Test Owner'];}
function courier_requirement_save(...$args){}
function ops_board_log_automatic_packer_assignment($id,$employee,$status){$GLOBALS['assignments'][]=[$id,$employee['id'],$status];}
function ops_kpi_record_event(...$args){$GLOBALS['events'][]=$args;}
function ops_activity_log(...$args){$GLOBALS['activity'][]=$args;}
function ops_log_order_stage_event(...$args){}
function notifications_order_summary($id){return ['order_number'=>$id];}
function notifications_create_for_roles(...$args){}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
define('BASE_URL','https://test.invalid');
// The separate EPI policy guard is unchanged; this fixture isolates status persistence/attribution.
eval('namespace Hambelela\EPI; class OrdersStageBridge {static function assertStatusAllowed($db,$order,$status){}}');
try {
 $pdo->exec('CREATE TABLE ops_orders(id INT PRIMARY KEY,order_number VARCHAR(30),status VARCHAR(30),assigned_packer_id INT NULL,created_at DATETIME,updated_at DATETIME,packed_at DATETIME NULL,completed_at DATETIME NULL)');
 $pdo->exec('CREATE TABLE kpi_status_events(module VARCHAR(30),record_id INT,old_status VARCHAR(30),new_status VARCHAR(30),changed_by INT,changed_at DATETIME)');
 $employee=['id'=>42,'full_name'=>'Test Packing Employee'];$courierDetails=null;$previousOrder=[];
 foreach([1=>'2026-10-01',2=>'2026-10-27',3=>'2026-10-28'] as $orderId=>$date){
   $pdo->prepare("INSERT INTO ops_orders(id,order_number,status,created_at) VALUES(?,?,'new_order',?)")->execute([$orderId,'fixture-'.$orderId,$date.' 09:00:00']);
   foreach(['in_progress','completed'] as $value){eval($code);$saved=$pdo->query('SELECT * FROM ops_orders WHERE id='.$orderId)->fetch(PDO::FETCH_ASSOC);
     check($saved['status']===$value,'Status persists on every date');
     check((int)$saved['assigned_packer_id']===42,'Authenticated packer, not Owner, gets attribution');
     check(substr($saved['created_at'],0,10)===$date,'Order date unchanged');
   }
 }
 $employee=null;$orderId=4;$value='completed';$pdo->exec("INSERT INTO ops_orders(id,order_number,status,created_at) VALUES(4,'fixture-owner','new_order','2026-10-27 09:00:00')");
 try {eval($code);throw new LogicException('Missing packer should be rejected');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'select who packed'),'Existing completion guard retained');}
 check($pdo->query('SELECT status FROM ops_orders WHERE id=4')->fetchColumn()==='new_order','Failure rolls back');
 check((int)$pdo->query('SELECT COUNT(*) FROM kpi_status_events WHERE changed_by=42')->fetchColumn()===6,'Employee status evidence retained');
 echo "PASS: actual status handler persists older/current dates, auto-attributes authenticated packing employee, retains creation dates/KPI events, rejects Owner completion without packer and rolls back\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec("DROP DATABASE `$schema`");}
