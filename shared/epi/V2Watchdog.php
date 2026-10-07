<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

final class V2Watchdog
{
 public static function run(PDO $db,$now=null,?PDO $hr=null):array {
    $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_watchdog_enabled'");
    if(!$flag||$flag['setting_value']!=='1')return ['status'=>'disabled'];
    if(!V2Store::activation($db))throw new RuntimeException('Explicit activation required');
    if($db->inTransaction())throw new RuntimeException('Watchdog must use a dedicated connection');
    $key='epi_watchdog_'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
    $lock=V2Store::one($db,'SELECT GET_LOCK(?,0) acquired',[$key]);if(!(int)$lock['acquired'])return ['status'=>'already_running'];
    $start=microtime(true);$run=0;
    try{
        $db->exec('SET SESSION innodb_lock_wait_timeout=2');
        $db->exec("INSERT INTO epi_v2_watchdog_runs(started_at,status) VALUES(NOW(),'running')");$run=(int)$db->lastInsertId();
        if($hr===null && (FrontDeskRoster::enabled($db)||(new FrontDeskCoverage($db,null))->enabled()))$hr=HrConnection::connect();
        $roster=FrontDeskRoster::materialise($db,$hr,$now);
        $reminders=FrontCoverageNotifications::run($db,$hr,$now);
        V2OperationalBridge::replay($db,100);
        $result=(new DeadlineEngine($db,$hr))->processDue($now,500,true);
        $result['roster']=$roster;
        $result['coverage_reminders']=$reminders;
        $pending=(int)$db->query("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending' AND last_error IS NOT NULL")->fetchColumn();
        $errors=count($result['errors']??[])+$pending+(int)($reminders['failed']??0)+(($roster['status']??'')==='needs_review'?1:0);$status=$errors?'failed':'success';
        $db->prepare('UPDATE epi_v2_watchdog_runs SET finished_at=NOW(),status=?,rows_inspected=?,breaches_created=?,errors=?,runtime_ms=?,details_json=? WHERE id=?')->execute([$status,$result['examined'],$result['breached']+$result['needs_attribution'],$errors,(int)round((microtime(true)-$start)*1000),Support::json($result+['outbox_failures'=>$pending]),$run]);
        if($errors)error_log('EPI V2 WATCHDOG UNHEALTHY: run '.$run.'; owner review required');
        return $result+['status'=>$status,'run_id'=>$run];
    }catch(\Throwable $error){if($run)$db->prepare("UPDATE epi_v2_watchdog_runs SET status='failed',errors=1,finished_at=NOW(),runtime_ms=?,details_json=? WHERE id=?")->execute([(int)round((microtime(true)-$start)*1000),Support::json(['error'=>$error->getMessage()]),$run]);throw $error;}
    finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);}
 }
}
