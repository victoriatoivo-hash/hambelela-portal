<?php
declare(strict_types=1);
// Private CLI only; run after the comparison/backup/publication release helper.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');$mode=$argv[2]??'inspect';
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0||!in_array($mode,['inspect','apply'],true))throw new RuntimeException('Private release directory required');
$ids=array_map('intval',array_slice($argv,3));if(!$ids||min($ids)<1||count($ids)>20)throw new RuntimeException('Explicit bounded task IDs required');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$published=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($published['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))throw new RuntimeException('Fresh verified backup and publication required');
require_once $root.'/shared/database.php';require_once $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/TaskReleaseCoverageRepair.php';
require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';
require_once $root.'/shared/epi/EmployeePerformanceService.php';
use Hambelela\EPI\{V2Store,TaskReleaseCoverageRepair,PerformanceRefreshRuntime};
$db=db();$db->exec("SET time_zone='+02:00'");$period=date('Y-m-01');
$protected=static function()use($db,$period){$s=$db->prepare('SELECT h.*,r.result_json FROM epi_v2_employee_result_heads h LEFT JOIN epi_v2_employee_results r ON r.id=h.current_result_id WHERE h.period_start<? OR h.locked_at IS NOT NULL ORDER BY h.employee_id,h.period_start');$s->execute([$period]);return hash('sha256',json_encode($s->fetchAll(PDO::FETCH_ASSOC)));};
$history=static function()use($db){$s=$db->query('SELECT * FROM epi_employee_evidence ORDER BY id');$hash=hash_init('sha256');$n=0;while($r=$s->fetch(PDO::FETCH_ASSOC)){hash_update($hash,json_encode($r));$n++;}$s->closeCursor();return ['count'=>$n,'hash'=>hash_final($hash)];};
$before=$history();$locked=$protected();$owner=(int)V2Store::activation($db)['approved_by'];
$lock='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Worker busy');
try{
    $report=V2Store::transaction($db,static function()use($db,$ids,$owner,$mode,$history,$protected,$before,$locked){
        $out=[];foreach($ids as $id)$out[]=TaskReleaseCoverageRepair::run($db,$id,$owner,$mode==='apply');
        if($history()!==$before||$protected()!==$locked)throw new RuntimeException('Historical records changed; rolled back');
        if($mode==='apply')PerformanceRefreshRuntime::invalidate($db,'task_release_coverage_repair');
        return ['mode'=>$mode,'tasks'=>$out,'history_preserved'=>true,'history_count'=>$before['count'],'locked_results_preserved'=>true,'schema_changes'=>false,'financial_use_allowed'=>false];
    });
    file_put_contents(__DIR__.'/task-release-'.$mode.'.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report),PHP_EOL;
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
