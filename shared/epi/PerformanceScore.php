<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Deterministic Phase 9 scoring. All point values are integer hundredths. */
final class PerformanceScore
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function multiplyImpact(int $base, int $severity = 10000, int $repeat = 10000, int $financial = 10000, int $responsibility = 10000): int
    {
        $value = $base;
        foreach ([$severity, $repeat, $financial, $responsibility] as $basis) {
            $value = (int) round(($value * $basis) / 10000, 0, PHP_ROUND_HALF_UP);
        }
        return max(0, $value);
    }

    public function calculateMonthly(int $employeeId, int $year, int $month, $calculatedBy = null, string $trigger = 'manual', ?string $reason = null): array
    {
        if ($month < 1 || $month > 12) throw new RuntimeException('Invalid score month.');
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        $existing = $this->monthly($employeeId, $year, $month);
        if ($existing && (int) $existing['locked'] === 1) return $this->monthlyDetail((int) $existing['id']);

        $employee = $this->employee($employeeId);
        $scorecard = $this->scorecard($employee, $start->format('Y-m-d'));
        $categories = $this->categories((int) $scorecard['id']);
        $this->validateWeights($categories);
        $events = $this->confirmedEvents($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $pending = $this->pendingCount($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $applied = $this->deduplicateIncidents($events);
        $positiveCap = $this->settingInt('epi_positive_monthly_cap_hundredths', 1000);
        $eventByCategory = [];
        foreach ($applied as $event) $eventByCategory[(string) $event['category_key']][] = $event;

        $categoryRows = [];
        $overall = 0; $allPositive = 0; $allDeductions = 0; $used = 0;
        foreach ($categories as $category) {
            $positive = 0; $deduction = 0; $categoryEvents = $eventByCategory[$category['category_key']] ?? [];
            foreach ($categoryEvents as $event) {
                if ($event['event_kind'] === 'positive') $positive += (int) $event['final_impact_hundredths'];
                else $deduction += (int) $event['final_impact_hundredths'];
            }
            $positive = min($positive, $positiveCap);
            $final = max((int) $category['minimum_hundredths'], min((int) $category['maximum_hundredths'], 10000 - $deduction + $positive));
            $weighted = (int) round(($final * (int) $category['weight_hundredths']) / 10000, 0, PHP_ROUND_HALF_UP);
            $overall += $weighted; $allPositive += $positive; $allDeductions += $deduction; $used += count($categoryEvents);
            $categoryRows[] = ['category_key'=>$category['category_key'],'category_name'=>$category['category_name'],'weight_hundredths'=>(int)$category['weight_hundredths'],'opening_hundredths'=>10000,'positive_hundredths'=>$positive,'deduction_hundredths'=>$deduction,'final_hundredths'=>$final,'weighted_contribution_hundredths'=>$weighted,'event_count'=>count($categoryEvents),'explanation_json'=>Support::json(['event_ids'=>array_map('intval',array_column($categoryEvents,'id'))])];
        }
        $overall = max(0, min(10000, $overall));
        $evidenceCount = $this->evidenceCount($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $covered = count(array_filter($categoryRows, static function(array $r): bool { return $r['event_count'] > 0; }));
        $completeness = count($categoryRows) ? (int) round(($covered / count($categoryRows)) * 10000) : 0;
        $confidence = $evidenceCount === 0 ? 'Insufficient Data' : ($pending > 0 || $completeness < 5000 ? 'Low' : ($completeness < 8000 ? 'Moderate' : 'High'));
        $auditRef = Support::uuid();
        $status = $end < new DateTimeImmutable('today') ? 'ready_for_review' : 'live';
        $this->pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO epi_scoring_monthly_scores(employee_id,employee_name,role_key,department_key,score_year,score_month,period_start,period_end,opening_hundredths,positive_hundredths,deduction_hundredths,final_hundredths,performance_level,evidence_count,confirmed_deduction_count,positive_evidence_count,pending_review_count,data_completeness_hundredths,confidence_label,calculation_version,scorecard_id,scorecard_version,calculation_timestamp,calculated_by,score_status,recalculated,recalculation_reason,previous_final_hundredths,audit_reference) VALUES(?,?,?,?,?,?,?,?,10000,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?) ON DUPLICATE KEY UPDATE employee_name=VALUES(employee_name),role_key=VALUES(role_key),department_key=VALUES(department_key),positive_hundredths=VALUES(positive_hundredths),deduction_hundredths=VALUES(deduction_hundredths),final_hundredths=VALUES(final_hundredths),performance_level=VALUES(performance_level),evidence_count=VALUES(evidence_count),confirmed_deduction_count=VALUES(confirmed_deduction_count),positive_evidence_count=VALUES(positive_evidence_count),pending_review_count=VALUES(pending_review_count),data_completeness_hundredths=VALUES(data_completeness_hundredths),confidence_label=VALUES(confidence_label),calculation_version=VALUES(calculation_version),scorecard_id=VALUES(scorecard_id),scorecard_version=VALUES(scorecard_version),calculation_timestamp=VALUES(calculation_timestamp),calculated_by=VALUES(calculated_by),score_status=VALUES(score_status),recalculated=VALUES(recalculated),recalculation_reason=VALUES(recalculation_reason),previous_final_hundredths=VALUES(previous_final_hundredths),audit_reference=VALUES(audit_reference)';
            $stmt=$this->pdo->prepare($sql);
            $deductionCount=count(array_filter($applied,static function(array $e):bool{return $e['event_kind']==='deduction';}));
            $positiveCount=count(array_filter($applied,static function(array $e):bool{return $e['event_kind']==='positive';}));
            $stmt->execute([$employeeId,$employee['full_name'],$employee['role_key']??null,$employee['department_key']??null,$year,$month,$start->format('Y-m-d'),$end->format('Y-m-d'),$allPositive,$allDeductions,$overall,$this->level($overall),$evidenceCount,$deductionCount,$positiveCount,$pending,$completeness,$confidence,$this->setting('epi_scoring_version','EPI Scoring Version 1.0'),$scorecard['id'],$scorecard['version'],date('Y-m-d H:i:s'),$calculatedBy,$status,$existing?1:0,$reason,$existing?(int)$existing['final_hundredths']:null,$auditRef]);
            $monthly=$this->monthly($employeeId,$year,$month); $monthlyId=(int)$monthly['id'];
            $this->pdo->prepare('DELETE FROM epi_employee_monthly_category_scores WHERE monthly_score_id=?')->execute([$monthlyId]);
            $insert=$this->pdo->prepare('INSERT INTO epi_employee_monthly_category_scores(monthly_score_id,category_key,category_name,weight_hundredths,opening_hundredths,positive_hundredths,deduction_hundredths,final_hundredths,weighted_contribution_hundredths,event_count,explanation_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            foreach($categoryRows as $row)$insert->execute([$monthlyId,$row['category_key'],$row['category_name'],$row['weight_hundredths'],10000,$row['positive_hundredths'],$row['deduction_hundredths'],$row['final_hundredths'],$row['weighted_contribution_hundredths'],$row['event_count'],$row['explanation_json']]);
            $audit=$this->pdo->prepare('INSERT INTO epi_employee_score_audits(audit_uuid,employee_id,period_start,period_end,trigger_name,triggered_by,input_evidence_json,rule_versions_json,scorecard_json,category_calculations_json,previous_hundredths,new_hundredths,cache_state_json,calculation_version,calculation_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $audit->execute([$auditRef,$employeeId,$start->format('Y-m-d'),$end->format('Y-m-d'),$trigger,$calculatedBy,Support::json(array_column($applied,'evidence_uuid')),Support::json(array_column($applied,'rule_version_id')),Support::json($scorecard),Support::json($categoryRows),$existing?(int)$existing['final_hundredths']:null,$overall,Support::json(['invalidated'=>true]),$this->setting('epi_scoring_version','EPI Scoring Version 1.0'),'complete']);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $this->monthlyDetail((int)$this->monthly($employeeId,$year,$month)['id']);
    }

    public function syncEvidenceEvents(int $employeeId, int $year, int $month): int
    {
        $start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $sql="SELECT e.evidence_uuid,e.module,e.reference_number,e.action,e.metadata_json,r.id rule_id,r.rule_kind,r.category_key,v.id version_id,v.impact_hundredths,v.severity_multiplier_basis FROM epi_employee_evidence e JOIN epi_performance_rules r ON r.active=1 AND LOWER(r.module)=LOWER(e.module) AND r.event_type=e.action JOIN epi_performance_rule_versions v ON v.rule_id=r.id AND v.effective_from<=e.business_date AND (v.effective_to IS NULL OR v.effective_to>=e.business_date) WHERE e.employee_id=? AND e.business_date BETWEEN ? AND ? AND e.verified=1";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$employeeId,$start,$end]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$count=0;
        $insert=$this->pdo->prepare('INSERT IGNORE INTO epi_performance_score_events(event_uuid,employee_id,period_start,period_end,evidence_uuid,root_incident_id,rule_id,rule_version_id,category_key,event_kind,base_hundredths,severity_multiplier_basis,final_impact_hundredths,confirmation_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')');
        foreach($rows as$r){$meta=json_decode((string)($r['metadata_json']??''),true);$root=is_array($meta)&&!empty($meta['root_incident_id'])?(string)$meta['root_incident_id']:(string)$r['module'].'|'.(string)$r['reference_number'];$impact=self::multiplyImpact((int)$r['impact_hundredths'],(int)$r['severity_multiplier_basis']);$insert->execute([Support::uuid(),$employeeId,$start,$end,$r['evidence_uuid'],$root,$r['rule_id'],$r['version_id'],$r['category_key'],$r['rule_kind'],(int)$r['impact_hundredths'],(int)$r['severity_multiplier_basis'],$impact]);$count+=$insert->rowCount();}
        return $count;
    }

    public function reviewEvent(int $eventId,string $status,int $reviewerId,string $note):void
    { if(!in_array($status,['confirmed','dismissed','excused','duplicate','rejected'],true))throw new RuntimeException('Invalid review status.');if(trim($note)==='')throw new RuntimeException('A review note is required.');$s=$this->pdo->prepare('SELECT employee_id FROM epi_performance_score_events WHERE id=?');$s->execute([$eventId]);$employee=(int)$s->fetchColumn();if(!$employee)throw new RuntimeException('Score event not found.');if($employee===$reviewerId)throw new RuntimeException('Employees cannot approve their own score events.');$this->pdo->prepare('UPDATE epi_performance_score_events SET confirmation_status=?,confirmed_by=?,confirmed_at=NOW(),reviewer_note=? WHERE id=? AND reversed=0')->execute([$status,$reviewerId,trim($note),$eventId]); }

    public function reverseEvent(int $eventId,int $reviewerId,string $reason):int
    {if(trim($reason)==='')throw new RuntimeException('A reversal reason is required.');$s=$this->pdo->prepare('SELECT * FROM epi_performance_score_events WHERE id=? AND reversed=0');$s->execute([$eventId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Active score event not found.');$kind=$r['event_kind']==='positive'?'deduction':'positive';$uuid=Support::uuid();$this->pdo->beginTransaction();try{$i=$this->pdo->prepare('INSERT INTO epi_performance_score_events(event_uuid,employee_id,period_start,period_end,evidence_uuid,root_incident_id,rule_id,rule_version_id,category_key,event_kind,base_hundredths,severity_multiplier_basis,repeat_multiplier_basis,financial_multiplier_basis,responsibility_basis,final_impact_hundredths,confirmation_status,confirmed_by,confirmed_at,reviewer_note,manual) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'confirmed\',?,NOW(),?,1)');$i->execute([$uuid,$r['employee_id'],$r['period_start'],$r['period_end'],null,'reversal:'.$r['event_uuid'],$r['rule_id'],$r['rule_version_id'],$r['category_key'],$kind,$r['base_hundredths'],$r['severity_multiplier_basis'],$r['repeat_multiplier_basis'],$r['financial_multiplier_basis'],$r['responsibility_basis'],$r['final_impact_hundredths'],$reviewerId,trim($reason)]);$id=(int)$this->pdo->lastInsertId();$this->pdo->prepare('UPDATE epi_performance_score_events SET reversed=1,reversal_event_id=?,reversal_reason=? WHERE id=?')->execute([$id,trim($reason),$eventId]);$this->pdo->commit();return$id;}catch(\Throwable$e){$this->pdo->rollBack();throw$e;}}

    public function lockMonth(int $employeeId,int $year,int $month,int $reviewerId,string $note,bool $override=false):void
    {$m=$this->monthly($employeeId,$year,$month);if(!$m)throw new RuntimeException('Calculate the month before locking it.');if((int)$m['pending_review_count']>0&&!$override)throw new RuntimeException('Pending review events must be resolved before locking.');if(trim($note)==='')throw new RuntimeException('A lock note is required.');$this->pdo->prepare('INSERT INTO epi_employee_month_locks(employee_id,score_year,score_month,monthly_score_id,locked_by,locked_at,lock_note,override_incomplete) VALUES(?,?,?,?,?,NOW(),?,?) ON DUPLICATE KEY UPDATE monthly_score_id=VALUES(monthly_score_id),locked_by=VALUES(locked_by),locked_at=VALUES(locked_at),lock_note=VALUES(lock_note),override_incomplete=VALUES(override_incomplete),unlocked_by=NULL,unlocked_at=NULL,unlock_reason=NULL')->execute([$employeeId,$year,$month,$m['id'],$reviewerId,trim($note),$override?1:0]);$this->pdo->prepare("UPDATE epi_scoring_monthly_scores SET locked=1,locked_by=?,locked_at=NOW(),lock_note=?,score_status='locked' WHERE id=?")->execute([$reviewerId,trim($note),$m['id']]);}

    public function unlockMonth(int $employeeId,int $year,int $month,int $reviewerId,string $reason):void
    {if(trim($reason)==='')throw new RuntimeException('An unlock reason is required.');$this->pdo->prepare('UPDATE epi_employee_month_locks SET unlocked_by=?,unlocked_at=NOW(),unlock_reason=? WHERE employee_id=? AND score_year=? AND score_month=?')->execute([$reviewerId,trim($reason),$employeeId,$year,$month]);$this->pdo->prepare("UPDATE epi_scoring_monthly_scores SET locked=0,locked_by=NULL,locked_at=NULL,lock_note=NULL,score_status='ready_for_review' WHERE employee_id=? AND score_year=? AND score_month=?")->execute([$employeeId,$year,$month]);}

    public function getMonthlyScore(int $employeeId,int $year,int $month):array { $m=$this->monthly($employeeId,$year,$month);return$m?$this->monthlyDetail((int)$m['id']):[]; }
    public function getAudit(int $employeeId,int $limit=100):array{$s=$this->pdo->prepare('SELECT * FROM epi_employee_score_audits WHERE employee_id=? ORDER BY created_at DESC LIMIT '.max(1,min(500,$limit)));$s->execute([$employeeId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function getTrend(int $employeeId,int $months=12):array{$s=$this->pdo->prepare('SELECT score_year,score_month,final_hundredths,performance_level,confidence_label,locked FROM epi_scoring_monthly_scores WHERE employee_id=? ORDER BY score_year DESC,score_month DESC LIMIT '.max(1,min(60,$months)));$s->execute([$employeeId]);return array_reverse($s->fetchAll(PDO::FETCH_ASSOC));}
    public function aggregate(int $employeeId,string $from,string $to):array{$s=$this->pdo->prepare('SELECT COUNT(*) months,ROUND(AVG(final_hundredths)) final_hundredths,SUM(deduction_hundredths) deduction_hundredths,SUM(positive_hundredths) positive_hundredths,SUM(pending_review_count) pending_review_count FROM epi_scoring_monthly_scores WHERE employee_id=? AND period_start>=? AND period_end<=?');$s->execute([$employeeId,$from,$to]);$r=$s->fetch(PDO::FETCH_ASSOC)?:[];$r['performance_level']=$this->level((int)($r['final_hundredths']??0));return$r;}
    public function employeeOptions():array{return$this->pdo->query("SELECT e.id,e.full_name,COALESCE(r.role_key,'') role_key FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);}
    public function rules():array{return$this->pdo->query('SELECT r.*,v.id version_id,v.version,v.impact_hundredths,v.severity_multiplier_basis,v.maximum_per_event_hundredths,v.maximum_per_day_hundredths,v.maximum_per_month_hundredths,v.owner_confirmation_required,v.effective_from,v.effective_to FROM epi_performance_rules r LEFT JOIN epi_performance_rule_versions v ON v.rule_id=r.id ORDER BY r.module,r.category_key,r.rule_name,v.version DESC')->fetchAll(PDO::FETCH_ASSOC);}
    public function scorecards():array{return$this->pdo->query('SELECT s.*,COALESCE(SUM(c.weight_hundredths),0) weight_total_hundredths,COUNT(c.id) category_count FROM epi_scorecards s LEFT JOIN epi_scorecard_categories c ON c.scorecard_id=s.id AND c.is_active=1 GROUP BY s.id ORDER BY s.scorecard_name,s.version DESC')->fetchAll(PDO::FETCH_ASSOC);}

    private function employee(int$id):array{$s=$this->pdo->prepare("SELECT e.id,e.full_name,COALESCE(r.role_key,'') role_key,'' department_key FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Employee not found.');return$r;}
    private function scorecard(array$e,string$date):array{$role=(string)$e['role_key'];if($role==='packer_production_staff')$role='packer';if($role==='front_desk_admin_employee')$role='front_desk_admin';$s=$this->pdo->prepare("SELECT * FROM epi_scorecards WHERE status='active' AND effective_from<=? AND(effective_to IS NULL OR effective_to>=?) AND(employee_id=? OR role_key=? OR(employee_id IS NULL AND role_key IS NULL)) ORDER BY (employee_id IS NOT NULL) DESC,(role_key IS NOT NULL) DESC,version DESC LIMIT 1");$s->execute([$date,$date,$e['id'],$role]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('No effective scorecard exists for this employee.');return$r;}
    private function categories(int$id):array{$s=$this->pdo->prepare('SELECT * FROM epi_scorecard_categories WHERE scorecard_id=? AND is_active=1 ORDER BY id');$s->execute([$id]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function validateWeights(array$c):void{if(array_sum(array_map('intval',array_column($c,'weight_hundredths')))!==10000)throw new RuntimeException('Scorecard category weights must total exactly 100%.');}
    private function confirmedEvents(int$id,string$from,string$to):array{$s=$this->pdo->prepare("SELECT * FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? AND confirmation_status='confirmed' AND reversed=0 ORDER BY created_at,id");$s->execute([$id,$from,$to]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function deduplicateIncidents(array$events):array{$best=[];foreach($events as$e){$key=($e['root_incident_id']?:$e['event_uuid']).'|'.$e['category_key'].'|'.$e['event_kind'];if(!isset($best[$key])||(int)$e['final_impact_hundredths']>(int)$best[$key]['final_impact_hundredths'])$best[$key]=$e;}return array_values($best);}
    private function pendingCount(int$id,string$f,string$t):int{$s=$this->pdo->prepare("SELECT COUNT(*) FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? AND confirmation_status='pending' AND reversed=0");$s->execute([$id,$f,$t]);return(int)$s->fetchColumn();}
    private function evidenceCount(int$id,string$f,string$t):int{$s=$this->pdo->prepare('SELECT COUNT(*) FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND verified=1');$s->execute([$id,$f,$t]);return(int)$s->fetchColumn();}
    private function monthly(int$id,int$y,int$m){$s=$this->pdo->prepare('SELECT * FROM epi_scoring_monthly_scores WHERE employee_id=? AND score_year=? AND score_month=?');$s->execute([$id,$y,$m]);return$s->fetch(PDO::FETCH_ASSOC);}
    private function monthlyDetail(int$id):array{$s=$this->pdo->prepare('SELECT * FROM epi_scoring_monthly_scores WHERE id=?');$s->execute([$id]);$m=$s->fetch(PDO::FETCH_ASSOC)?:[];$s=$this->pdo->prepare('SELECT * FROM epi_employee_monthly_category_scores WHERE monthly_score_id=? ORDER BY id');$s->execute([$id]);$m['categories']=$s->fetchAll(PDO::FETCH_ASSOC);$s=$this->pdo->prepare('SELECT * FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? ORDER BY confirmation_status,created_at DESC');$s->execute([$m['employee_id'],$m['period_start'],$m['period_end']]);$m['events']=$s->fetchAll(PDO::FETCH_ASSOC);return$m;}
    private function level(int$v):string{if($v>=$this->settingInt('epi_level_gold_min',9000))return'Gold';if($v>=$this->settingInt('epi_level_silver_min',8500))return'Silver';if($v>=$this->settingInt('epi_level_bronze_min',7500))return'Bronze';return'No Bonus';}
    private function setting(string$key,string$default):string{$s=$this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
    private function settingInt(string$key,int$default):int{return(int)$this->setting($key,(string)$default);}
}
