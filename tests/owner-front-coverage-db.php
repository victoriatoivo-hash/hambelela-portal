<?php
declare(strict_types=1);
// Disposable loopback-only synthetic database. No application configuration or live records.
require_once dirname(__DIR__).'/shared/epi/bootstrap.php';
require_once dirname(__DIR__).'/shared/epi/OwnerFrontCoverage.php';
use Hambelela\EPI\{OwnerFrontCoverage,OwnershipPeriodEngine,V2Store};
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='coverage_test_'.bin2hex(random_bytes(8));$db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");$db->exec("SET time_zone='+02:00'");
$results=[];
function check($name,$expected,$actual){global $results;$results[]=['test'=>$name,'pass'=>$expected===$actual];if($expected!==$actual)throw new RuntimeException($name.' failed: '.json_encode($actual));}
function rejected(callable $fn):bool{try{$fn();return false;}catch(RuntimeException $e){return true;}}
// Reuse production interval table definitions rather than a permissive test double.
$sql=file_get_contents(dirname(__DIR__).'/operations-epi-v2-p0-migration.sql');
foreach(['activation','scope_locks','duty_periods','ownership_periods','ownership_audits','exceptions','object_duties'] as $table){
    if(!preg_match('/CREATE TABLE IF NOT EXISTS epi_v2_'.$table.'\b.*?;/s',$sql,$m))throw new RuntimeException('Missing fixture table '.$table);
    $db->exec($m[0]);
}
$db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(80),name VARCHAR(100));
CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name VARCHAR(100),role_id INT,status VARCHAR(20));
CREATE TABLE employee_user_links(id INT PRIMARY KEY,portal_user_id INT,hr_employee_id INT,linked_at DATETIME,active INT);
CREATE TABLE employees(id INT PRIMARY KEY);
CREATE TABLE leave_requests(id INT PRIMARY KEY,employee_id INT,status VARCHAR(20),start_date DATE,end_date DATE,approved_at DATETIME,approved_by INT);
INSERT INTO ops_roles VALUES(1,'owner_admin','Owner'),(2,'front_desk_admin','Front Desk'),(3,'marketing_sales','Marketing'),(4,'packer','Packer');
INSERT INTO ops_employees VALUES(1,'Owner',1,'active'),(2,'Front',2,'active'),(3,'Cover',3,'active'),(4,'Packer',4,'active'),(5,'Inactive',3,'inactive');
INSERT INTO employees VALUES(102),(103);
INSERT INTO employee_user_links VALUES(1,2,102,'2026-01-01 00:00:00',1),(2,3,103,'2026-01-01 00:00:00',1);");
$engine=new OwnershipPeriodEngine($db);$day='2026-11-02';
$legacy=new \Hambelela\EPI\FrontDeskCoverage($db,$db);
check('old employee handover workflow retired',false,$legacy->enabled());
check('old prompts disabled',['enabled'=>false],$legacy->status(2));
check('no legacy reminder delivery',['sent'=>0,'failed'=>0],$legacy->reminders(function(){throw new RuntimeException('Unexpected employee prompt');},'2026-11-02 13:00:00'));
$plan=['day'=>$day,'primary'=>2,'start'=>'08:00','end'=>'17:00','cover'=>3,'lunch_start'=>'13:00','lunch_end'=>'14:00','reason'=>'Synthetic test'];
function fresh(array $plan):array{global $db;return array_replace($plan,['revision'=>OwnerFrontCoverage::revision(OwnerFrontCoverage::rows($db,$plan['day']))]);}
function savePlan(array $plan,string $now='2026-11-01 10:00:00'):array{global $db;return OwnerFrontCoverage::save($db,$db,1,fresh($plan),$now);}
check('non-owner rejected',true,rejected(function()use($db,$plan){OwnerFrontCoverage::save($db,$db,3,fresh($plan),'2026-11-01 10:00:00');}));
check('invalid date rejected',true,rejected(function(){OwnerFrontCoverage::date('2026-02-31');}));
check('overnight rejected',true,rejected(function()use($plan){OwnerFrontCoverage::intervals(array_replace($plan,['end'=>'07:00']),'2026-11-01 10:00:00');}));
check('lunch outside hours rejected',true,rejected(function()use($plan){OwnerFrontCoverage::intervals(array_replace($plan,['lunch_end'=>'18:00']),'2026-11-01 10:00:00');}));
check('past edit rejected',true,rejected(function()use($plan){OwnerFrontCoverage::intervals($plan,'2026-11-03 10:00:00');}));
check('no HR fails closed',true,rejected(function()use($db,$plan){OwnerFrontCoverage::save($db,null,1,fresh($plan),'2026-11-01 10:00:00');}));
check('ineligible profile rejected',true,rejected(function()use($plan){savePlan(array_replace($plan,['primary'=>4]));}));
check('inactive employee rejected',true,rejected(function()use($plan){savePlan(array_replace($plan,['cover'=>5]));}));
$stale=fresh($plan);savePlan($plan);
check('no fictitious employee acceptance',0,(int)$db->query('SELECT COUNT(*) FROM epi_v2_duty_periods WHERE accepted_by IS NOT NULL OR accepted_at IS NOT NULL')->fetchColumn());
foreach(['07:59:59'=>null,'08:00:00'=>2,'12:59:59'=>2,'13:00:00'=>3,'13:59:59'=>3,'14:00:00'=>2,'16:59:59'=>2,'17:00:00'=>null] as $at=>$employee)check('boundary '.$at,$employee,isset(($r=$engine->dutyAt('front_desk',$day.' '.$at))['employee_id'])?(int)$r['employee_id']:null);
foreach(['Orders','Courier','Bookkeeping'] as $module){$db->prepare('INSERT INTO epi_v2_object_duties VALUES(?,?,?)')->execute([$module,'TEST-1','front_desk']);check($module.' follows lunch',3,(int)$engine->ownerAt($module,'TEST-1',$day.' 13:15:00')['employee_id']);check($module.' returns automatically',2,(int)$engine->ownerAt($module,'TEST-1',$day.' 14:00:00')['employee_id']);}
$engine->assign(['module'=>'Orders','object_reference'=>'EXPLICIT','employee_id'=>2,'assigned_by'=>1,'accepted_by'=>2,'effective_from'=>$day.' 08:00:00']);
$db->exec("INSERT INTO epi_v2_object_duties VALUES('Orders','EXPLICIT','front_desk')");
check('explicit assignment preserved',2,(int)$engine->ownerAt('Orders','EXPLICIT',$day.' 13:15:00')['employee_id']);
check('stale save rejected',true,rejected(function()use($db,$stale){OwnerFrontCoverage::save($db,$db,1,$stale,'2026-11-01 10:00:00');}));
savePlan(array_replace($plan,['cover'=>0]));check('remove future lunch cover',2,(int)$engine->dutyAt('front_desk',$day.' 13:15:00')['employee_id']);
savePlan($plan);$past=$engine->dutyAt('front_desk',$day.' 12:00:00');
savePlan(array_replace($plan,['primary'=>3,'cover'=>0]),$day.' 13:30:00');
check('elapsed responsibility retained',2,(int)$engine->dutyAt('front_desk',$day.' 12:00:00')['employee_id']);
check('past identity retained',$past['duty_uuid'],$engine->dutyAt('front_desk',$day.' 12:00:00')['duty_uuid']);
check('current change immediate',3,(int)$engine->dutyAt('front_desk',$day.' 13:30:00')['employee_id']);
savePlan(array_replace($plan,['primary'=>2,'cover'=>0,'start'=>'15:00','end'=>'16:00']),$day.' 14:00:00');
check('outside head retained',3,(int)$engine->dutyAt('front_desk',$day.' 14:30:00')['employee_id']);
check('selected window changed',2,(int)$engine->dutyAt('front_desk',$day.' 15:30:00')['employee_id']);
check('outside tail retained',3,(int)$engine->dutyAt('front_desk',$day.' 16:30:00')['employee_id']);
$before=OwnerFrontCoverage::revision(OwnerFrontCoverage::rows($db,$day));
$db->exec("INSERT INTO leave_requests VALUES(1,102,'approved','2026-11-02','2026-11-02','2026-11-01 00:00:00',1)");
check('approved leave rejected',true,rejected(function()use($plan,$day){savePlan($plan,$day.' 14:00:00');}));
check('failed save atomic',$before,OwnerFrontCoverage::revision(OwnerFrontCoverage::rows($db,$day)));
check('audit present',true,(int)$db->query('SELECT COUNT(*) FROM epi_v2_ownership_audits')->fetchColumn()>=5);
echo json_encode(['passed'=>count($results),'tests'=>$results],JSON_PRETTY_PRINT).PHP_EOL;
