<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateInterval;
use DateTimeImmutable;
use PDO;

/** Read-only Phase 4 Task Management analytics. No final scores are calculated. */
final class TaskPerformance
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    public function getSummary(array $filters=[]): array
    {
        $rows=$this->rows($filters,10000);$tasks=[];$started=[];$completed=[];$reopened=[];$cancelled=[];$assigned=[];$manual=[];$recurring=[];
        $createdStarted=[];$startedCompleted=[];$assignedCompleted=[];$onTime=0;$late=0;$checkRequired=0;$checkGood=0;$noteRequired=0;$noteGood=0;$fileRequired=0;$fileGood=0;$deductions=0;$bonuses=0;
        $priority=[];$categories=[];$firstTimeRight=[];$correctionsCompleted=[];$correctionsOnTime=0;$correctionsLate=0;$repeatCorrections=[];
        foreach($rows as $row){$m=$this->metadata($row);$ref=(string)$row['reference_number'];$tasks[$ref]=true;$action=(string)$row['action'];
            if(in_array($action,['task_created','task_assigned','task_reassigned','task_released'],true))$assigned[$ref]=true;
            if(($m['task_kind']??'manual')==='recurring')$recurring[$ref]=true;else $manual[$ref]=true;
            $p=strtolower((string)($row['priority']??$m['priority']??'normal'));$priority[$p]=($priority[$p]??0)+($action==='task_created'?1:0);
            $category=(string)($m['category']??'general_operations');$categories[$category]=($categories[$category]??0)+($action==='task_completed'?1:0);
            if($action==='task_started'){$started[$ref]=true;if(is_numeric($m['created_to_started_minutes']??null))$createdStarted[]=(float)$m['created_to_started_minutes'];}
            if($action==='task_completed'){$completed[$ref]=true;if(is_numeric($m['started_to_completed_minutes']??null))$startedCompleted[]=(float)$m['started_to_completed_minutes'];if(is_numeric($m['assigned_to_completed_minutes']??null))$assignedCompleted[]=(float)$m['assigned_to_completed_minutes'];
                if(($m['due_result']??'')==='completed_late')$late++;else $onTime++;
                if((int)($m['correction_round_count']??0)>0){$correctionsCompleted[$ref.':'.(int)$m['correction_round_count']]=true;if(($m['due_result']??'')==='completed_late')$correctionsLate++;else $correctionsOnTime++;if((int)$m['correction_round_count']>1)$repeatCorrections[$ref]=true;}
                if(($m['checklist_required_count']??0)>0){$checkRequired++;if(!empty($m['checklist_complete']))$checkGood++;}
                if(!empty($m['completion_note_required'])){$noteRequired++;if(!empty($m['completion_note_present']))$noteGood++;}
                if(!empty($m['completion_evidence_required'])){$fileRequired++;if(!empty($m['evidence_supplied']))$fileGood++;}
            }
            if($action==='task_reopened')$reopened[$ref]=true;
            if(strpos($action,'cancel')!==false)$cancelled[$ref]=true;
            if($action==='bonus_candidate_first_time_right_completion')$firstTimeRight[$ref]=true;
            if(strpos($action,'deduction_candidate_')===0)$deductions++;
            if(strpos($action,'bonus_candidate_')===0)$bonuses++;
        }
        $eligible=max(0,count($assigned)-count($cancelled));$current=$this->getCurrentRisk($filters);
        return ['period'=>$this->periodPayload($filters),'workload'=>['tasks_assigned'=>count($assigned),'manual_tasks'=>count($manual),'recurring_occurrences'=>count($recurring),'by_priority'=>$priority,'by_category'=>$categories],
            'status'=>['started'=>count($started),'completed'=>count($completed),'reopened'=>count($reopened),'cancelled'=>count($cancelled),'still_open'=>max(0,$eligible-count($completed))],
            'timeliness'=>['created_to_started'=>$this->stats($createdStarted),'started_to_completed'=>$this->stats($startedCompleted),'assigned_to_completed'=>$this->stats($assignedCompleted),'on_time_completed'=>$onTime,'late_completed'=>$late,'on_time_percent'=>($onTime+$late)>0?round($onTime*100/($onTime+$late),2):null],
            'completion'=>['eligible_assigned'=>$eligible,'completed'=>count($completed),'completion_percent'=>$eligible>0?round(count($completed)*100/$eligible,2):null,'denominator'=>'Eligible assigned tasks; cancelled tasks excluded.'],
            'compliance'=>['checklist'=>$this->compliance($checkGood,$checkRequired),'notes'=>$this->compliance($noteGood,$noteRequired),'evidence'=>$this->compliance($fileGood,$fileRequired)],
            'quality'=>['first_time_right'=>count($firstTimeRight),'reopened'=>count($reopened),'correction_requests'=>count($reopened),'corrections_completed'=>count($correctionsCompleted),'corrections_completed_on_time'=>$correctionsOnTime,'corrections_completed_late'=>$correctionsLate,'repeat_corrections'=>count($repeatCorrections)],'current_risk'=>$current,
            'evidence_count'=>count($rows),'activity_count'=>$this->activityCount($filters),'deduction_candidates'=>$deductions,'bonus_candidates'=>$bonuses,'scoring_status'=>'not_calculated'];
    }

    public function getEmployeeSummary(array $filters=[]): array{return $this->getSummary($filters);}
    public function getStatusSummary(array $filters=[]): array{return ['status'=>$this->getSummary($filters)['status'],'current'=>$this->getCurrentRisk($filters)];}
    public function getTimeliness(array $filters=[]): array{return $this->getSummary($filters)['timeliness'];}
    public function getCompliance(array $filters=[]): array{return $this->getSummary($filters)['compliance'];}
    public function getPriorityPerformance(array $filters=[]): array{return ['assigned'=>$this->getSummary($filters)['workload']['by_priority'],'current'=>$this->getCurrentRisk($filters)['by_priority']];}
    public function getRecurringTaskPerformance(array $filters=[]): array{return ['occurrences'=>$this->getSummary($filters)['workload']['recurring_occurrences'],'system_failures_excluded'=>true];}

    public function getCurrentRisk(array $filters=[]): array
    {
        $where=['t.deleted_at IS NULL','t.archived_at IS NULL','t.employee_visible=1','(t.scheduled_at IS NULL OR t.released_at IS NOT NULL)',"LOWER(t.status) NOT IN ('complete','completed','cancelled')"];$params=[];
        if(isset($filters['employee_id'])&&$filters['employee_id']!==''){$where[]='t.assigned_employee_id=?';$params[]=(int)$filters['employee_id'];}
        if(isset($filters['priority'])&&$filters['priority']!==''){$where[]='t.priority=?';$params[]=(string)$filters['priority'];}
        if(isset($filters['status'])&&$filters['status']!==''){$where[]='t.status=?';$params[]=(string)$filters['status'];}
        $sql="SELECT t.id,t.task_name,t.status,t.priority,t.deadline,COALESCE(t.released_at,t.date_assigned) date_assigned,t.started_at,t.assigned_employee_id,e.full_name assigned_name FROM ops_checklist_tasks t LEFT JOIN ops_employees e ON e.id=t.assigned_employee_id WHERE ".implode(' AND ',$where).' ORDER BY COALESCE(t.deadline,\'9999-12-31\'),t.id';
        $s=$this->pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];$now=new DateTimeImmutable('now');
        $out=['total_open'=>count($rows),'new'=>0,'in_progress'=>0,'due_today'=>0,'overdue'=>0,'urgent_overdue'=>0,'important_overdue'=>0,'business_minutes_overdue'=>0.0,'oldest_new'=>null,'oldest_in_progress'=>null,'oldest_overdue'=>null,'by_priority'=>[]];
        foreach($rows as $r){$status=strtolower((string)$r['status']);$priority=strtolower((string)$r['priority']);$out['by_priority'][$priority]=($out['by_priority'][$priority]??0)+1;if($status==='new'){$out['new']++;if($out['oldest_new']===null)$out['oldest_new']=$r;}if($status==='in_progress'){$out['in_progress']++;if($out['oldest_in_progress']===null)$out['oldest_in_progress']=$r;}
            $deadline=$this->deadline($r['deadline']??null);if($deadline&&$deadline->format('Y-m-d')===$now->format('Y-m-d'))$out['due_today']++;if($deadline&&$deadline<$now){$out['overdue']++;$delay=Performance::businessMinutes($deadline,$now);$out['business_minutes_overdue']+=$delay;$r['business_minutes_overdue']=$delay;if($priority==='urgent')$out['urgent_overdue']++;if($priority==='important')$out['important_overdue']++;if($out['oldest_overdue']===null)$out['oldest_overdue']=$r;}}
        return $out;
    }
    private function deadline($value): ?DateTimeImmutable
    {
        $raw=trim((string)$value);if($raw==='')return null;$date=Support::timestamp($raw);
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw))$date=$date->setTime((int)$date->format('N')===6?13:17,0,0);
        return $date;
    }

    public function getEvidence(array $filters=[],int $limit=250): array{return $this->rows($filters,$limit);}
    public function getTimeline(array $filters=[],int $limit=250): array
    {
        list($from,$to)=$this->period($filters);$where=["module='Tasks'",'occurred_at>=?','occurred_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];
        if(isset($filters['employee_id'])&&$filters['employee_id']!==''){$where[]='employee_id=?';$params[]=(int)$filters['employee_id'];}if(!empty($filters['reference_number'])){$where[]='reference_number=?';$params[]=$filters['reference_number'];}
        $limit=max(1,min(1000,$limit));$s=$this->pdo->prepare('SELECT * FROM epi_employee_activity WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC,id DESC LIMIT '.$limit);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function employeeOptions(): array{$q=$this->pdo->query("SELECT e.id,e.full_name FROM ops_employees e WHERE e.status='active' ORDER BY e.full_name");return $q->fetchAll(PDO::FETCH_ASSOC)?:[];}

    private function rows(array $filters,int $limit): array
    {
        list($from,$to)=$this->period($filters);$where=["module='Tasks'",'occurred_at>=?','occurred_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];
        foreach(['employee_id','priority','status'] as$key){if(!isset($filters[$key])||$filters[$key]==='')continue;$where[]=$key==='status'?'status_after=?':$key.'=?';$params[]=$key==='employee_id'?(int)$filters[$key]:(string)$filters[$key];}
        foreach(['category','task_kind','due_result','review_status'] as$key){if(!isset($filters[$key])||$filters[$key]==='')continue;$where[]="JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.".$key."'))=?";$params[]=(string)$filters[$key];}
        $limit=max(1,min(10000,$limit));$s=$this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC,id DESC LIMIT '.$limit);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    private function metadata(array $row): array{$m=json_decode((string)($row['metadata_json']??''),true);return is_array($m)?$m:[];}
    private function stats(array $v): array{if(!$v)return ['average'=>null,'median'=>null,'fastest'=>null,'slowest'=>null,'count'=>0,'status'=>'insufficient_historical_data'];sort($v,SORT_NUMERIC);$n=count($v);$m=intdiv($n,2);return ['average'=>round(array_sum($v)/$n,2),'median'=>$n%2?$v[$m]:round(($v[$m-1]+$v[$m])/2,2),'fastest'=>$v[0],'slowest'=>$v[$n-1],'count'=>$n,'status'=>'verified'];}
    private function compliance(int $good,int $required): array{return ['required'=>$required,'compliant'=>$good,'missing'=>max(0,$required-$good),'percent'=>$required>0?round($good*100/$required,2):null,'status'=>$required>0?'verified':'not_applicable'];}
    private function activityCount(array $f): int{list($a,$b)=$this->period($f);$w=["module='Tasks'",'occurred_at>=?','occurred_at<?'];$p=[$a->format('Y-m-d 00:00:00'),$b->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];if(isset($f['employee_id'])&&$f['employee_id']!==''){$w[]='employee_id=?';$p[]=(int)$f['employee_id'];}$s=$this->pdo->prepare('SELECT COUNT(*) FROM epi_employee_activity WHERE '.implode(' AND ',$w));$s->execute($p);return(int)$s->fetchColumn();}
    private function periodPayload(array$f):array{list($a,$b)=$this->period($f);return['from'=>$a->format('Y-m-d'),'to'=>$b->format('Y-m-d')];}
    private function period(array$f):array{$t=new DateTimeImmutable('today');$k=(string)($f['period']??'previous_month');if($k==='custom'&&!empty($f['date_from'])&&!empty($f['date_to']))return[new DateTimeImmutable($f['date_from']),new DateTimeImmutable($f['date_to'])];$m=['today'=>[$t,$t],'yesterday'=>[$t->modify('-1 day'),$t->modify('-1 day')],'this_week'=>[$t->modify('monday this week'),$t],'last_week'=>[$t->modify('monday last week'),$t->modify('sunday last week')],'this_month'=>[$t->modify('first day of this month'),$t],'previous_month'=>[$t->modify('first day of last month'),$t->modify('last day of last month')],'quarter'=>[$t->modify('-'.(((int)$t->format('n')-1)%3).' months')->modify('first day of this month'),$t],'year'=>[$t->setDate((int)$t->format('Y'),1,1),$t]];return$m[$k]??$m['previous_month'];}
}
