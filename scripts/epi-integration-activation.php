<?php
declare(strict_types=1);
// Owner-authorised provisional activation. Run only from a private release folder.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0)throw new RuntimeException('Private release folder and portal root required.');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600
    ||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))
    throw new RuntimeException('Fresh verified database/file backup and published release required.');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/ProvisionalScorecardAssignments.php';
require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';
use Hambelela\EPI\{V2Store,ProvisionalScorecardAssignments,PerformanceRefreshRuntime,HrAbsenceEvidence,HrConnection};
$db=db();$db->exec("SET time_zone='+02:00'");$owner=(int)(V2Store::activation($db)['approved_by']??0);
if($owner<1)throw new RuntimeException('Existing owner-authorised activation required.');
foreach(['epi_v2_capture_enabled','epi_v2_results_enabled','epi_v2_provisional_capture_enabled','epi_v2_watchdog_enabled'] as $flag)
    if((V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[$flag])['setting_value']??'0')!=='1')throw new RuntimeException('Existing runtime is not active: '.$flag);
if((V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_official_capture_enabled'")['setting_value']??'0')!=='0')throw new RuntimeException('This operation may not change an official-scoring activation.');
$before=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
$lock='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Background worker busy; retry without changing configuration.');
try{
    $report=['front_orders'=>ProvisionalScorecardAssignments::reviseFrontOrders($db,$owner,
        'Owner explicitly approved provisional Front Orders 85% completion SLA / 15% first-time-right; category remains 25%; New-stage non-walk-in packing belongs to packers.'),
        'official_scoring'=>false,'financial_use_allowed'=>false,'schema_changes'=>false,'history_before'=>$before];
    $person=V2Store::one($db,"SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=15 AND e.status='active'");
    $availability=HrAbsenceEvidence::inspect($db,HrConnection::connect(),15,date('Y-m-d H:i:s'));
    if(($person['role_key']??'')==='marketing_sales'&&$availability['state']!=='needs_review')
        $report['coverage']=ProvisionalScorecardAssignments::addFrontCoverage($db,$owner,15,
            'Owner requested Hope Front Desk cover to be tracked like Front Desk only during accepted duty/counter ownership; not an overall Marketing score.');
    else $report['coverage']=['status'=>'needs_review','reason'=>'Marketing identity/HR link must be verified; no employee-role substitution made'];
    $report['refresh']=PerformanceRefreshRuntime::run($db);
    $report['history_after']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
    if($report['history_after']!==$before)throw new RuntimeException('Historical evidence count changed; investigate before claiming successful activation.');
    $report['state']=$report['refresh']['failed']?'refresh_retry_required':'provisional_integration_active';
    file_put_contents(__DIR__.'/integration-activation-result.json',json_encode($report,JSON_PRETTY_PRINT));
    echo json_encode($report),PHP_EOL;
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
