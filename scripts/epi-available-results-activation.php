<?php
declare(strict_types=1);
// Run only from the private verified release folder; no public installer.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0)throw new RuntimeException('Private release directory required.');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$published=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($published['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))throw new RuntimeException('Fresh verified backup and publication required.');
require_once $root.'/shared/database.php';require_once $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';require_once $root.'/shared/epi/EmployeePerformanceService.php';
use Hambelela\EPI\{V2Store,PerformanceRefreshRuntime,EmployeePerformanceService,RateScoreCalculator};
$db=db();$db->exec("SET time_zone='+02:00'");$period=date('Y-m-01');
$protected=static function()use($db,$period){$q=$db->prepare('SELECT h.employee_id,h.period_start,h.current_result_id,h.locked_at,h.locked_by,r.result_json FROM epi_v2_employee_result_heads h LEFT JOIN epi_v2_employee_results r ON r.id=h.current_result_id WHERE h.period_start<? OR h.locked_at IS NOT NULL ORDER BY h.employee_id,h.period_start');$q->execute([$period]);return hash('sha256',json_encode($q->fetchAll(PDO::FETCH_ASSOC)));};
$legacy=static function()use($db){$q=$db->query('SELECT * FROM epi_employee_evidence ORDER BY id');$hash=hash_init('sha256');$n=0;while($r=$q->fetch(PDO::FETCH_ASSOC)){hash_update($hash,json_encode($r));$n++;}$q->closeCursor();return ['count'=>$n,'sha256'=>hash_final($hash)];};
$beforeLegacy=$legacy();$beforeProtected=$protected();
$lock='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
$q=$db->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Worker busy; retry safely.');
try{
    $report=V2Store::transaction($db,function()use($db,$period,$legacy,$protected,$beforeLegacy,$beforeProtected){
        $service=new EmployeePerformanceService($db);$projector=new Hambelela\EPI\OperationalPerformanceProjector($db);$out=[];
        $q=$db->prepare('SELECT a.employee_id FROM epi_v2_scorecard_assignments a LEFT JOIN epi_v2_employee_result_heads h ON h.employee_id=a.employee_id AND h.period_start=a.period_start WHERE a.period_start=? AND h.locked_at IS NULL');$q->execute([$period]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
        foreach($ids as $id){$p=$projector->employee((int)$id,$period);$r=$service->recalculate((int)$id,$period,$p['opportunities'],$p['coverage']);
            if($r['official_score_hundredths']!==null||$r['financial_use_allowed'])throw new RuntimeException('Unexpected official/financial activation.');
            $observed=0;foreach($r['categories'] as $category)foreach($category['metrics'] as $metric)if($metric['eligible_volume']>0)$observed++;
            $out[$id]=['result_id'=>$r['result_id'],'available'=>EmployeePerformanceService::availableDisplay($r),'observed_kpis'=>$observed,'official'=>null];
        }
        if($legacy()!==$beforeLegacy||$protected()!==$beforeProtected)throw new RuntimeException('Historical or locked records changed; publication rolled back.');
        return ['state'=>'available_results_active','calculation'=>RateScoreCalculator::VERSION,'current_period'=>$period,'profiles'=>$out,'history'=>$beforeLegacy['count'],'history_hash_preserved'=>true,'locked_and_previous_heads_preserved'=>true,'schema_changes'=>false,'financial_use_allowed'=>false];
    });
    file_put_contents(__DIR__.'/activation-result.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report),PHP_EOL;
}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
