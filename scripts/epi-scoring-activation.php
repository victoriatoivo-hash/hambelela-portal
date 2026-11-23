<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[2]??'');$mode=$argv[1]??'inspect';
if(!$root || !is_file($root.'/shared/database.php') || strpos(__DIR__,$root)===0 || !in_array($mode,['inspect','activate','resume'],true))throw new RuntimeException('Private CLI package and portal root required');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require $root.'/shared/epi/StageReadiness.php';require $root.'/shared/epi/ProvisionalScorecardAssignments.php';
require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';
use Hambelela\EPI\{V2Store,StageReadiness,HrConnection,HrAbsenceEvidence,ProvisionalScorecardAssignments,PerformanceRefreshRuntime};
$db=db();$db->exec("SET time_zone='+02:00'");
$owner=(int)(V2Store::activation($db)['approved_by']??0);$hr=HrConnection::connect();
$ready=StageReadiness::inspect($db,$hr);
$people=$db->query("SELECT e.id,e.full_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key IN('front_desk_admin','packer','packer_production_staff') ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
$expected=[2=>'front_desk_admin',6=>'packer',7=>'packer'];
foreach($people as $p){$id=(int)$p['id'];$role=$p['role_key']==='packer_production_staff'?'packer':$p['role_key'];
    if(!isset($expected[$id])||$expected[$id]!==$role)throw new RuntimeException('Employee/role list changed; review exact identities');
    $absence=HrAbsenceEvidence::inspect($db,$hr,$id,date('Y-m-d H:i:s'));
    if($absence['state']==='needs_review')$ready['blockers'][]='unverified_hr_identity:'.$id;
}
if(count($people)!==3)throw new RuntimeException('Expected exact reviewed Front Desk and Packer identities');
$summary=['state'=>'inspected','blockers'=>$ready['blockers'],'employees'=>$people,
    'history_count'=>(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn(),
    'official_scoring'=>false,'financial_use'=>false];
if($mode==='inspect'){echo json_encode($summary),PHP_EOL;exit($ready['blockers']?2:0);}
if($ready['blockers'])throw new RuntimeException('Activation prerequisite failed; inspect report');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['state']??'')!=='published'||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))throw new RuntimeException('Verified fresh backup and publication required');
$summary['configuration']=$mode==='resume'?json_decode((V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'")['setting_value']??'null'),true):V2Store::transaction($db,function()use($db,$owner,$people){
    $config=ProvisionalScorecardAssignments::activate($db,$owner,array_map('intval',array_column($people,'id')));
    foreach(['epi_v2_orders_sla_enabled','epi_v2_front_roster_enabled','epi_v2_front_coverage_enabled'] as $key){
        $before=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[$key]);
        $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,'1']);
        V2Store::audit($db,'performance-activation|'.$key,$owner,'Owner requested existing approved prospective SLA/coverage integrations; no historical reassignment',$before,['value'=>'1']);
    }
    return $config;
});
if(!$summary['configuration'] || (int)$summary['configuration']['authorised_by']!==$owner)throw new RuntimeException('No committed owner activation to resume');
$summary['refresh']=PerformanceRefreshRuntime::run($db);
$summary['state']='provisional_active';
$summary['history_after']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
file_put_contents(__DIR__.'/activation-result.json',json_encode($summary,JSON_PRETTY_PRINT));
echo json_encode($summary),PHP_EOL;
