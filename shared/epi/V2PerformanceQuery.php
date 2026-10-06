<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Current ownership is separate from immutable breach responsibility. Read-only. */
final class V2PerformanceQuery
{
 private $pdo;
 public function __construct(PDO $pdo){$this->pdo=$pdo;}
 public function personalRisk(int $employee,$at=null):array {
    $time=Support::timestamp($at)->format('Y-m-d H:i:s');
    $s=$this->pdo->prepare("SELECT * FROM epi_v2_operational_deadlines WHERE state IN('open','breached','needs_attribution') AND due_at<? ORDER BY due_at,id");$s->execute([$time]);
    $engine=new OwnershipPeriodEngine($this->pdo);$rows=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){$owner=$engine->ownerAt($row['module'],$row['object_reference'],$time);if($owner&&(int)$owner['employee_id']===$employee){$row['current_owner']=$owner;$rows[]=$row;}}
    $s=$this->pdo->prepare("SELECT * FROM epi_v2_performance_incidents WHERE module='Error Log' AND responsible_employee_at_breach=? AND current_risk_state='open'");$s->execute([$employee]);
    return array_merge($rows,$s->fetchAll(PDO::FETCH_ASSOC));
 }
 public function teamRisk(string $team):array {
    $s=$this->pdo->prepare("SELECT * FROM epi_v2_operational_deadlines WHERE responsible_team=? AND state IN('open','breached','needs_attribution') ORDER BY due_at,id");$s->execute([$team]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 public function history(int $employee,string $from,string $to):array {
    $s=$this->pdo->prepare('SELECT * FROM epi_v2_performance_incidents WHERE responsible_employee_at_breach=? AND occurred_at>=? AND occurred_at<? ORDER BY occurred_at DESC,id DESC');$s->execute([$employee,$from,Support::timestamp($to)->modify('+1 day')->format('Y-m-d')]);return $s->fetchAll(PDO::FETCH_ASSOC);
 }
 public function explain(string $uuid):array {
    $row=V2Store::one($this->pdo,'SELECT * FROM epi_v2_performance_incidents WHERE incident_uuid=?',[$uuid]);if(!$row)return [];
    $row['breach_snapshot']=json_decode($row['metadata_json'],true);
    $s=$this->pdo->prepare('SELECT *,CASE WHEN superseded_at IS NULL THEN eligible ELSE 0 END AS effective_eligible FROM epi_v2_quality_revisions WHERE root_incident_id=? ORDER BY id');$s->execute([$row['root_incident_id']]);$row['revisions']=$s->fetchAll(PDO::FETCH_ASSOC);
    if($row['deadline_uuid'])$row['current_deadline']=V2Store::one($this->pdo,'SELECT * FROM epi_v2_operational_deadlines WHERE deadline_uuid=?',[$row['deadline_uuid']]);
    return $row;
 }
 public function watchdogHealth():array {
    $last=V2Store::one($this->pdo,'SELECT * FROM epi_v2_watchdog_runs ORDER BY id DESC LIMIT 1');
    $success=V2Store::one($this->pdo,"SELECT finished_at FROM epi_v2_watchdog_runs WHERE status='success' ORDER BY id DESC LIMIT 1");
    $flag=V2Store::one($this->pdo,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_watchdog_enabled'");
    $enabled=($flag['setting_value']??'0')==='1';
    $stale=!$success||Support::timestamp($success['finished_at'])<Support::timestamp()->modify('-3 minutes');
    return ['enabled'=>$enabled,'status'=>!$enabled?'disabled':(($last['status']??'never_run')==='failed'?'failed':($stale?'stale':($last['status']??'unknown'))),'last_run'=>$last,'last_success'=>$success['finished_at']??null,'unhealthy'=>$enabled&&($stale||($last['status']??'')==='failed')];
 }
}
