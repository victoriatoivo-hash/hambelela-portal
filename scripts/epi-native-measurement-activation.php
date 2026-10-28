<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0)throw new RuntimeException('Private release folder and portal root required.');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$release=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($release['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))throw new RuntimeException('Fresh verified backup and publication required.');
require $root.'/shared/database.php';require $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/OperationalMeasurementPolicy.php';require_once $root.'/shared/epi/PhysicalEvidenceCapture.php';require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';
use Hambelela\EPI\{V2Store,OperationalMeasurementPolicy,PhysicalEvidenceCapture,PerformanceRefreshRuntime};
$db=db();$db->exec("SET time_zone='+02:00'");$owner=(int)(V2Store::activation($db)['approved_by']??0);
if($owner<1)throw new RuntimeException('Approving owner required.');
foreach(['epi_v2_capture_enabled','epi_v2_results_enabled','epi_v2_provisional_capture_enabled','epi_v2_watchdog_enabled','epi_v2_orders_sla_enabled'] as $flag)
    if((V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[$flag])['setting_value']??'0')!=='1')throw new RuntimeException('Existing prospective runtime is not active: '.$flag);
if((V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_official_capture_enabled'")['setting_value']??'0')!=='0')throw new RuntimeException('Official scoring must remain off.');
$weights=static function()use($db){$out=[];$q=$db->query('SELECT a.employee_id,a.period_start,s.policy_json FROM epi_v2_scorecard_assignments a JOIN epi_v2_scorecard_documents s ON s.version=a.scorecard_version ORDER BY a.employee_id,a.period_start');
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$p=json_decode($r['policy_json'],true);$w=[];foreach($p['categories'] as $k=>$c){$w[$k]=['category'=>$c['weight_hundredths'],'internal'=>array_map(static function($m){return $m['weight_hundredths'];},$c['metrics'])];}$out[$r['employee_id'].'|'.$r['period_start']]=$w;}return $out;};
$locked=static function()use($db){return $db->query('SELECT employee_id,period_start,current_result_id,locked_at,locked_by FROM epi_v2_employee_result_heads WHERE locked_at IS NOT NULL ORDER BY employee_id,period_start')->fetchAll(PDO::FETCH_ASSOC);};
$before=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();$oldWeights=$weights();$oldLocked=$locked();
$key='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$key]);if(!(int)$q->fetchColumn())throw new RuntimeException('Worker busy; retry safely.');
try{
    $report=V2Store::transaction($db,function()use($db,$owner,$before,$oldWeights,$oldLocked,$weights,$locked){
        $r=['policy'=>OperationalMeasurementPolicy::activate($db,$owner,'Owner withdrew the unmeasured choice and requested all role measurement paths completed. Preserve weights; allocate each courier packing failure once; only real evidence; no official/financial/disciplinary use.'),
            'history_before'=>$before,'official_scoring'=>false,'financial_use_allowed'=>false,'schema_changes'=>false,'attendance_schedules'=>[]];
        $config=json_decode(V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'")['setting_value'],true);
        $from=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        foreach($config['employees'] as $entry){$id=(int)$entry['employee_id'];
            if(V2Store::one($db,'SELECT id FROM epi_attendance_schedules WHERE employee_id=? AND (effective_to IS NULL OR effective_to>=?)',[$id,$from])){$r['attendance_schedules'][$id]='existing schedule preserved';continue;}
            $r['attendance_schedules'][$id]=PhysicalEvidenceCapture::schedule($db,$id,$owner,$from,'Owner supplied office working hours and requested attendance measurement completed prospectively. Physical evidence is separately required; no login-derived absence or lateness.');
        }
        $r['category_and_internal_weights_preserved']=$weights()===$oldWeights;$r['locked_results_preserved']=$locked()===$oldLocked;
        if(!$r['category_and_internal_weights_preserved']||!$r['locked_results_preserved'])throw new RuntimeException('Weights or locked history changed unexpectedly.');
        return $r;
    });
    $report['refresh']=PerformanceRefreshRuntime::run($db);$report['history_after']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
    if($report['history_after']!==$before)throw new RuntimeException('Historical evidence count changed; investigate.');
    $report['state']=$report['refresh']['failed']?'refresh_retry_required':'prospective_native_measurements_active';
    file_put_contents(__DIR__.'/native-activation-result.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report),PHP_EOL;
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);}
