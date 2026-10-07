<?php
declare(strict_types=1);
// Dedicated loopback test DB only. Never loads production config or deletes historical DBs.
require_once dirname(__DIR__).'/shared/epi/StageSchemaInstaller.php';
use Hambelela\EPI\StageSchemaInstaller;
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='epi_stage_install_'.bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");
$root=dirname(__DIR__);$checks=[];
function verifyStage(string $label,bool $ok):void{global $checks;$checks[$label]=$ok;}
function refusedStage(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
$db->exec("CREATE TABLE epi_employee_performance_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(30),description TEXT);
CREATE TABLE epi_v2_activation(id INT PRIMARY KEY,mode VARCHAR(20));
CREATE TABLE epi_v2_event_registry(event_key VARCHAR(100) PRIMARY KEY,module VARCHAR(100),description TEXT,responsibility_type VARCHAR(100),polarity VARCHAR(30),score_eligible INT,owner_review_required INT,sla_obligation_key VARCHAR(100),category_key VARCHAR(100),applicable_roles_json LONGTEXT);
CREATE TABLE official_score_sentinel(id INT PRIMARY KEY,score INT);
INSERT INTO official_score_sentinel VALUES(1,91);");
verifyStage('missing activation refuses',!StageSchemaInstaller::inspect($db,$root)['ready']);
$db->exec("INSERT INTO epi_v2_activation VALUES(1,'shadow')");
$db->exec('START TRANSACTION READ ONLY');
try{verifyStage('preflight is read-only and ready',StageSchemaInstaller::inspect($db,$root)['ready']);}finally{$db->exec('ROLLBACK');}
verifyStage('bad files refuse',!StageSchemaInstaller::inspect($db,$root.'/tests')['ready']);
verifyStage('no backup acknowledgement refuses',refusedStage(function()use($db,$root){StageSchemaInstaller::apply($db,$root,'');}));
$peer=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$peer->query("SELECT GET_LOCK('epi_stage_schema_install',0)")->fetchColumn();
try{verifyStage('concurrent installer refuses',refusedStage(function()use($db,$root){StageSchemaInstaller::apply($db,$root,'fixture-backup');}));}
finally{$peer->query("SELECT RELEASE_LOCK('epi_stage_schema_install')")->fetchColumn();}
$db->exec('CREATE TABLE epi_v2_front_plans(id INT PRIMARY KEY)');
verifyStage('partial schema refuses before writes',refusedStage(function()use($db,$root){StageSchemaInstaller::apply($db,$root,'fixture-backup');}));
verifyStage('partial schema refusal adds no other stage table',StageSchemaInstaller::inspect($db,$root)['existing_stage_tables']===['epi_v2_front_plans']);
$db->exec('RENAME TABLE epi_v2_front_plans TO preserved_partial_fixture');
$db->exec("INSERT INTO epi_employee_performance_settings VALUES('epi_v2_orders_sla_enabled','1','boolean','fixture')");
verifyStage('active feature refuses',refusedStage(function()use($db,$root){StageSchemaInstaller::apply($db,$root,'fixture-backup');}));
$db->exec("UPDATE epi_employee_performance_settings SET setting_value='0'");
$r=StageSchemaInstaller::apply($db,$root,'synthetic-backup-only');
verifyStage('installs disabled',$r['status']==='installed_disabled' && array_values($r['flags'])===['0','0','0']);
verifyStage('official score unchanged',(int)$db->query('SELECT score FROM official_score_sentinel')->fetchColumn()===91);
verifyStage('no roster approval',(int)$db->query('SELECT COUNT(*) FROM epi_v2_front_rosters')->fetchColumn()===0);
verifyStage('no policy approval',(int)$db->query('SELECT COUNT(*) FROM epi_v2_orders_policy')->fetchColumn()===0);
verifyStage('no inferred coverage',(int)$db->query('SELECT COUNT(*) FROM epi_v2_front_plans')->fetchColumn()===0);
verifyStage('replay refuses existing schema',refusedStage(function()use($db,$root){StageSchemaInstaller::apply($db,$root,'fixture-backup');}));
$db->exec("INSERT INTO epi_v2_orders_policy(version,effective_from,approved_by,policy_json) VALUES('fixture','2026-10-08',999,'{}')");
verifyStage('policy immutability installed',refusedStage(function()use($db){$db->exec("UPDATE epi_v2_orders_policy SET policy_json='[]'");}));
$db->exec("INSERT INTO epi_v2_front_rosters(employee_id,effective_from,effective_to,approved_by,approved_at,hours_json,reason) VALUES(101,'2026-10-08','2026-10-09',999,NOW(),'{}','synthetic')");
verifyStage('roster immutability installed',refusedStage(function()use($db){$db->exec("UPDATE epi_v2_front_rosters SET reason='rewritten'");}));
echo json_encode(['database'=>$name,'checks'=>$checks,'passed'=>count(array_filter($checks)),'failed'=>count($checks)-count(array_filter($checks))],JSON_PRETTY_PRINT).PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
