<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0)throw new RuntimeException('Private release folder and portal root required.');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600
    ||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))
    throw new RuntimeException('Fresh verified backup and published release required.');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';require_once $root.'/shared/epi/CourierPackerDeadlineBridge.php';
require_once $root.'/shared/epi/BookkeepingControlBridge.php';
use Hambelela\EPI\{V2Store,CourierPackerDeadlineBridge,PerformanceRefreshRuntime,BookkeepingControlBridge};
$db=db();$db->exec("SET time_zone='+02:00'");$owner=(int)(V2Store::activation($db)['approved_by']??0);
if($owner<1)throw new RuntimeException('Existing approving owner required.');
foreach(['epi_v2_capture_enabled','epi_v2_results_enabled','epi_v2_provisional_capture_enabled','epi_v2_watchdog_enabled','epi_v2_orders_sla_enabled'] as $flag)
    if((V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[$flag])['setting_value']??'0')!=='1')throw new RuntimeException('Existing prospective runtime is not active: '.$flag);
if((V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_official_capture_enabled'")['setting_value']??'0')!=='0')throw new RuntimeException('This operation may not enable official scoring.');
$before=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
$scorecards=hash('sha256',json_encode($db->query('SELECT version,policy_hash FROM epi_v2_scorecard_documents ORDER BY version')->fetchAll(PDO::FETCH_ASSOC)));
$lock='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Worker busy; retry before activation.');
try{
    $r=['configuration'=>CourierPackerDeadlineBridge::enable($db,$owner,
        'Owner requested completion of prospective courier upload tracking. Use approved weekday 13:30 dispatch; only start after packing readiness; preserve the existing Courier score split and leave unsupported readiness unmeasured.'),
        'scorecard_weights_changed'=>false,'official_scoring'=>false,'financial_use_allowed'=>false,'history_before'=>$before];
    $r['daily_cash_controls']=BookkeepingControlBridge::approveProvisional($db,
        ['opening_after_open_minutes'=>30,'closing_before_close_minutes'=>15],$owner,
        'Owner explicitly approved prospective provisional daily opening within 30 minutes and pre-close reconciliation 15 minutes before closing; no pay, bonus or disciplinary use.');
    $r['cash_schedule']=BookkeepingControlBridge::reconcile($db);
    $r['refresh']=PerformanceRefreshRuntime::run($db);
    $r['history_after']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
    if($r['history_after']!==$before)throw new RuntimeException('Historical evidence count changed; investigate.');
    $r['scorecard_versions_preserved']=hash_equals($scorecards,hash('sha256',json_encode($db->query('SELECT version,policy_hash FROM epi_v2_scorecard_documents ORDER BY version')->fetchAll(PDO::FETCH_ASSOC))));
    if(!$r['scorecard_versions_preserved'])throw new RuntimeException('A scorecard version changed unexpectedly; investigate.');
    $r['state']=$r['refresh']['failed']?'refresh_retry_required':'provisional_courier_upload_and_daily_cash_active';
    file_put_contents(__DIR__.'/courier-upload-activation-result.json',json_encode($r,JSON_PRETTY_PRINT));echo json_encode($r),PHP_EOL;
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
