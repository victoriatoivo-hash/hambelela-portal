<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[2]??'');$mode=$argv[1]??'inspect';
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0||!in_array($mode,['inspect','activate'],true))throw new RuntimeException('Private CLI directory and portal root required.');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/ProvisionalTimingPolicy.php';
use Hambelela\EPI\{V2Store,ProvisionalTimingPolicy,HrConnection,HrAbsenceEvidence};
$db=db();$db->exec("SET time_zone='+02:00'");
$values=array_combine(array_keys(ProvisionalTimingPolicy::FIELDS),ProvisionalTimingPolicy::APPROVED);
$before=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
$report=['history_before'=>$before,'approved_minutes'=>$values,'current_version'=>ProvisionalTimingPolicy::current($db),
 'official_scoring'=>false,'financial_use'=>false,'disciplinary_use'=>false,'database_schema_changes'=>false];
$hr=HrConnection::connect();$report['hr_available']=$hr!==null;
foreach([2,6,7] as $employee)$report['availability'][$employee]=HrAbsenceEvidence::inspect($db,$hr,$employee,date('Y-m-d H:i:s'))['state'];
if($mode==='inspect'){echo json_encode($report),PHP_EOL;exit;}
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['state']??'')!=='published'||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql'))||time()-filemtime(__DIR__.'/backup/verified.json')>3600)throw new RuntimeException('Fresh verified backup and publication required.');
if(!$hr||in_array('needs_review',$report['availability'],true))throw new RuntimeException('HR availability must be verifiable before timing activation.');
foreach(['epi_v2_capture_enabled','epi_v2_watchdog_enabled','epi_v2_results_enabled','epi_v2_provisional_capture_enabled'] as $key){
 $flag=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[$key]);
 if(($flag['setting_value']??'0')!=='1')throw new RuntimeException('Required existing runtime flag is not active: '.$key);
}
if(!$report['current_version'])$report['current_version']=ProvisionalTimingPolicy::approve($db,$values,(int)V2Store::activation($db)['approved_by'],
 'Owner explicitly approved 3h/18h Packing List, 8h website, 30m cash/first response; responsibility-only clock; provisional, no financial/disciplinary use.');
elseif($report['current_version']['minutes']!==$values)throw new RuntimeException('Existing timing settings differ; do not overwrite owner changes.');
$report['history_after']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
$report['state']='provisional_timings_active';
file_put_contents(__DIR__.'/timing-activation-result.json',json_encode($report,JSON_PRETTY_PRINT));
echo json_encode($report),PHP_EOL;
