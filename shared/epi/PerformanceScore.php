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

    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->ensureAutomaticScoringSchema(); }

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
        $sourceEngine = new SourceCompletenessEngine($this->pdo);
        $coverage = $sourceEngine->evaluate($employeeId, (string) ($employee['role_key'] ?? ''), $start->format('Y-m-d'), $end->format('Y-m-d'));
        $events = $this->confirmedEvents($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $pending = $this->pendingCount($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $applied = $this->deduplicateIncidents($events);
        $positiveCap = $this->settingInt('epi_positive_monthly_cap_hundredths', 1000);
        $eventByCategory = [];
        foreach ($applied as $event) $eventByCategory[(string) $event['category_key']][] = $event;

        $categoryRows = [];
        $overall = 0; $allPositive = 0; $allDeductions = 0; $used = 0;
        foreach ($categories as $category) {
            $categoryEligibility = $sourceEngine->categoryStatus((string) $category['category_key'], $coverage);
            $positive = 0; $deduction = 0; $categoryEvents = $eventByCategory[$category['category_key']] ?? [];
            foreach ($categoryEvents as $event) {
                if ($event['event_kind'] === 'positive') $positive += (int) $event['final_impact_hundredths'];
                else $deduction += (int) $event['final_impact_hundredths'];
            }
            $positive = min($positive, $positiveCap);
            $final = max((int) $category['minimum_hundredths'], min((int) $category['maximum_hundredths'], 10000 - $deduction + $positive));
            $weighted = (int) round(($final * (int) $category['weight_hundredths']) / 10000, 0, PHP_ROUND_HALF_UP);
            $overall += $weighted; $allPositive += $positive; $allDeductions += $deduction; $used += count($categoryEvents);
            $categoryRows[] = ['category_key'=>$category['category_key'],'category_name'=>$category['category_name'],'weight_hundredths'=>(int)$category['weight_hundredths'],'opening_hundredths'=>10000,'positive_hundredths'=>$positive,'deduction_hundredths'=>$deduction,'final_hundredths'=>$final,'weighted_contribution_hundredths'=>$weighted,'event_count'=>count($categoryEvents),'calculation_status'=>$categoryEligibility['status'],'official_score_hundredths'=>in_array($categoryEligibility['status'],['calculated','provisional'],true)?$final:null,'missing_sources_json'=>Support::json($categoryEligibility['missing']),'confidence_label'=>$categoryEligibility['confidence'],'explanation_json'=>Support::json(['event_ids'=>array_map('intval',array_column($categoryEvents,'id')),'source_status'=>$categoryEligibility])];
        }
        $overall = max(0, min(10000, $overall));
        $evidenceCount = $this->evidenceCount($employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $completeness = (int) $coverage['completeness_hundredths'];
        $confidence = (string) $coverage['confidence'];
        $resultType = (string) $coverage['result_type'];
        $insufficientCategories = array_filter($categoryRows, static function(array $r): bool { return $r['calculation_status'] === 'insufficient_data'; });
        if ($insufficientCategories && $this->settingInt('epi_provisional_weight_redistribution', 0) !== 1) $resultType = 'insufficient_historical_data';
        $officialOverall = $resultType === 'insufficient_historical_data' ? null : $overall;
        $officialLevel = $officialOverall === null ? 'Not Available' : $this->level($officialOverall);
        $storedOverall = $officialOverall === null ? 0 : $officialOverall;
        $auditRef = Support::uuid();
        $status = $resultType === 'insufficient_historical_data' ? 'insufficient_historical_data' : ($resultType === 'provisional_calculated' ? 'provisional' : ($end < new DateTimeImmutable('today') ? 'ready_for_review' : 'live'));
        $this->pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO epi_scoring_monthly_scores(employee_id,employee_name,role_key,department_key,score_year,score_month,period_start,period_end,opening_hundredths,positive_hundredths,deduction_hundredths,final_hundredths,performance_level,evidence_count,confirmed_deduction_count,positive_evidence_count,pending_review_count,data_completeness_hundredths,confidence_label,calculation_version,scorecard_id,scorecard_version,calculation_timestamp,calculated_by,score_status,recalculated,recalculation_reason,previous_final_hundredths,audit_reference) VALUES(?,?,?,?,?,?,?,?,10000,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?) ON DUPLICATE KEY UPDATE employee_name=VALUES(employee_name),role_key=VALUES(role_key),department_key=VALUES(department_key),positive_hundredths=VALUES(positive_hundredths),deduction_hundredths=VALUES(deduction_hundredths),final_hundredths=VALUES(final_hundredths),performance_level=VALUES(performance_level),evidence_count=VALUES(evidence_count),confirmed_deduction_count=VALUES(confirmed_deduction_count),positive_evidence_count=VALUES(positive_evidence_count),pending_review_count=VALUES(pending_review_count),data_completeness_hundredths=VALUES(data_completeness_hundredths),confidence_label=VALUES(confidence_label),calculation_version=VALUES(calculation_version),scorecard_id=VALUES(scorecard_id),scorecard_version=VALUES(scorecard_version),calculation_timestamp=VALUES(calculation_timestamp),calculated_by=VALUES(calculated_by),score_status=VALUES(score_status),recalculated=VALUES(recalculated),recalculation_reason=VALUES(recalculation_reason),previous_final_hundredths=VALUES(previous_final_hundredths),audit_reference=VALUES(audit_reference)';
            $stmt=$this->pdo->prepare($sql);
            $deductionCount=count(array_filter($applied,static function(array $e):bool{return $e['event_kind']==='deduction';}));
            $positiveCount=count(array_filter($applied,static function(array $e):bool{return $e['event_kind']==='positive';}));
            $stmt->execute([$employeeId,$employee['full_name'],$employee['role_key']??null,$employee['department_key']??null,$year,$month,$start->format('Y-m-d'),$end->format('Y-m-d'),$allPositive,$allDeductions,$storedOverall,$officialLevel,$evidenceCount,$deductionCount,$positiveCount,$pending,$completeness,$confidence,$this->setting('epi_step3b_calculation_version','EPI Step 3B Version 1.0'),$scorecard['id'],$scorecard['version'],date('Y-m-d H:i:s'),$calculatedBy,$status,$existing?1:0,$reason,$existing?(int)$existing['final_hundredths']:null,$auditRef]);
            $monthly=$this->monthly($employeeId,$year,$month); $monthlyId=(int)$monthly['id'];
            $this->pdo->prepare('UPDATE epi_scoring_monthly_scores SET result_type=?,official_score_hundredths=?,official_performance_level=?,missing_critical_sources_json=?,missing_core_sources_json=?,source_coverage_json=?,superseded=0,superseded_at=NULL,superseded_reason=NULL WHERE id=?')->execute([$resultType,$officialOverall,$officialLevel,Support::json($coverage['missing_critical_sources']),Support::json($coverage['missing_core_sources']),Support::json($coverage),$monthlyId]);
            $this->pdo->prepare('DELETE FROM epi_employee_monthly_category_scores WHERE monthly_score_id=?')->execute([$monthlyId]);
            $insert=$this->pdo->prepare('INSERT INTO epi_employee_monthly_category_scores(monthly_score_id,category_key,category_name,weight_hundredths,opening_hundredths,positive_hundredths,deduction_hundredths,final_hundredths,weighted_contribution_hundredths,event_count,explanation_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            foreach($categoryRows as $row){$insert->execute([$monthlyId,$row['category_key'],$row['category_name'],$row['weight_hundredths'],10000,$row['positive_hundredths'],$row['deduction_hundredths'],$row['final_hundredths'],$row['weighted_contribution_hundredths'],$row['event_count'],$row['explanation_json']]);$this->pdo->prepare('UPDATE epi_employee_monthly_category_scores SET calculation_status=?,official_score_hundredths=?,missing_sources_json=?,confidence_label=? WHERE monthly_score_id=? AND category_key=?')->execute([$row['calculation_status'],$row['official_score_hundredths'],$row['missing_sources_json'],$row['confidence_label'],$monthlyId,$row['category_key']]);}
            $audit=$this->pdo->prepare('INSERT INTO epi_employee_score_audits(audit_uuid,employee_id,period_start,period_end,trigger_name,triggered_by,input_evidence_json,rule_versions_json,scorecard_json,category_calculations_json,previous_hundredths,new_hundredths,cache_state_json,calculation_version,calculation_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $audit->execute([$auditRef,$employeeId,$start->format('Y-m-d'),$end->format('Y-m-d'),$trigger,$calculatedBy,Support::json(array_column($applied,'evidence_uuid')),Support::json(array_column($applied,'rule_version_id')),Support::json(['scorecard'=>$scorecard,'source_coverage'=>$coverage]),Support::json($categoryRows),$existing?(int)$existing['final_hundredths']:null,$storedOverall,Support::json(['invalidated'=>true,'official_score_hundredths'=>$officialOverall]),$this->setting('epi_step3b_calculation_version','EPI Step 3B Version 1.0'),$resultType]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $this->monthlyDetail((int)$this->monthly($employeeId,$year,$month)['id']);
    }

    public function syncEvidenceEvents(int $employeeId, int $year, int $month): int
    {
        $start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        if($this->isMonthLocked($employeeId,$year,$month))return 0;
        (new SourceCompletenessEngine($this->pdo))->classifyPeriod($employeeId,$start,$end);
        $sql="SELECT e.evidence_uuid,e.module,e.reference_number,e.employee_id,e.action,e.action_description,e.previous_value,e.new_value,e.status_before,e.status_after,e.occurred_at,e.business_date,e.recording_mode,e.verified,e.metadata_json,r.id rule_id,r.rule_name,r.rule_kind,r.category_key,v.id version_id,v.impact_hundredths,v.severity_multiplier_basis,v.automatic_application,v.minimum_confidence,v.owner_review_required,v.owner_confirmation_required FROM epi_employee_evidence e JOIN epi_performance_rules r ON r.active=1 AND LOWER(r.module)=LOWER(e.module) AND r.event_type=e.action JOIN epi_performance_rule_versions v ON v.rule_id=r.id AND v.effective_from<=e.business_date AND (v.effective_to IS NULL OR v.effective_to>=e.business_date) WHERE e.employee_id=? AND e.business_date BETWEEN ? AND ? AND e.eligibility_state IN('automatically_eligible','verified_eligible') AND e.recording_mode<>'test'";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$employeeId,$start,$end]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$count=0;
        $insert=$this->pdo->prepare('INSERT IGNORE INTO epi_performance_score_events(event_uuid,employee_id,period_start,period_end,evidence_uuid,root_incident_id,rule_id,rule_version_id,category_key,event_kind,base_hundredths,severity_multiplier_basis,final_impact_hundredths,confirmation_status,automatic_status,confidence_level,exception_status,expected_result,actual_result,applied_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($rows as$r){$meta=json_decode((string)($r['metadata_json']??''),true);if(!is_array($meta))$meta=[];$root=!empty($meta['root_incident_id'])?(string)$meta['root_incident_id']:(string)$r['module'].'|'.(string)$r['reference_number'];$impact=self::multiplyImpact((int)$r['impact_hundredths'],(int)$r['severity_multiplier_basis']);$decision=$this->automaticDecision($r,$meta);$insert->execute([Support::uuid(),$employeeId,$start,$end,$r['evidence_uuid'],$root,$r['rule_id'],$r['version_id'],$r['category_key'],$r['rule_kind'],(int)$r['impact_hundredths'],(int)$r['severity_multiplier_basis'],$impact,$decision['confirmation_status'],$decision['automatic_status'],$decision['confidence_level'],$decision['exception_status'],$r['previous_value']??$r['status_before']??null,$r['new_value']??$r['status_after']??null,$decision['automatic_status']==='automatically_applied'?date('Y-m-d H:i:s'):null]);$count+=$insert->rowCount();}
        $this->reclassifyPeriod($employeeId,$year,$month);
        return $count;
    }

    public function reclassifyPeriod(int $employeeId,int $year,int $month):array
    {
        if($this->isMonthLocked($employeeId,$year,$month))return['locked'=>true,'automatically_applied'=>0,'automatically_excluded'=>0,'needs_review'=>0,'insufficient_data'=>0,'reversed'=>0];
        $start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $sql="SELECT s.id,s.reversed,e.evidence_uuid,e.module,e.reference_number,e.employee_id,e.action,e.action_description,e.previous_value,e.new_value,e.status_before,e.status_after,e.occurred_at,e.business_date,e.recording_mode,e.verified,e.metadata_json,r.rule_name,v.automatic_application,v.minimum_confidence,v.owner_review_required,v.owner_confirmation_required FROM epi_performance_score_events s JOIN epi_employee_evidence e ON e.evidence_uuid=s.evidence_uuid JOIN epi_performance_rules r ON r.id=s.rule_id JOIN epi_performance_rule_versions v ON v.id=s.rule_version_id WHERE s.employee_id=? AND s.period_start=? AND s.period_end=? AND s.reversed=0 AND s.confirmation_status='pending'";
        $q=$this->pdo->prepare($sql);$q->execute([$employeeId,$start,$end]);$update=$this->pdo->prepare('UPDATE epi_performance_score_events SET confirmation_status=?,automatic_status=?,confidence_level=?,exception_status=?,expected_result=COALESCE(expected_result,?),actual_result=COALESCE(actual_result,?),applied_at=? WHERE id=? AND confirmation_status=\'pending\' AND reversed=0');
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[]as$r){$meta=json_decode((string)($r['metadata_json']??''),true);$decision=$this->automaticDecision($r,is_array($meta)?$meta:[]);$update->execute([$decision['confirmation_status'],$decision['automatic_status'],$decision['confidence_level'],$decision['exception_status'],$r['previous_value']??$r['status_before']??null,$r['new_value']??$r['status_after']??null,$decision['automatic_status']==='automatically_applied'?date('Y-m-d H:i:s'):null,$r['id']]);}
        return $this->eventStatusSummary($employeeId,$year,$month);
    }

    public function eventStatusSummary(int$employeeId,int$year,int$month):array
    {$start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');$s=$this->pdo->prepare("SELECT automatic_status,COUNT(*) total FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? GROUP BY automatic_status");$s->execute([$employeeId,$start,$end]);$out=['automatically_applied'=>0,'automatically_excluded'=>0,'needs_review'=>0,'insufficient_data'=>0,'reversed'=>0];foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[]as$r)$out[(string)$r['automatic_status']]=(int)$r['total'];return$out;}

    public function reviewEvent(int $eventId,string $status,int $reviewerId,string $note):void
    { if(!in_array($status,['confirmed','dismissed','excused','duplicate','rejected'],true))throw new RuntimeException('Invalid review status.');if(trim($note)==='')throw new RuntimeException('A review note is required.');$s=$this->pdo->prepare('SELECT employee_id FROM epi_performance_score_events WHERE id=?');$s->execute([$eventId]);$employee=(int)$s->fetchColumn();if(!$employee)throw new RuntimeException('Score event not found.');if($employee===$reviewerId)throw new RuntimeException('Employees cannot review their own score events.');$automatic=$status==='confirmed'?'automatically_applied':($status==='excused'?'excused':'automatically_excluded');$this->pdo->prepare('UPDATE epi_performance_score_events SET confirmation_status=?,automatic_status=?,confirmed_by=?,confirmed_at=NOW(),reviewer_note=?,applied_at=CASE WHEN ?=\'confirmed\' THEN NOW() ELSE applied_at END WHERE id=? AND reversed=0')->execute([$status,$automatic,$reviewerId,trim($note),$status,$eventId]); }

    public function reverseEvent(int $eventId,int $reviewerId,string $reason):int
    {if(trim($reason)==='')throw new RuntimeException('A reversal reason is required.');$s=$this->pdo->prepare('SELECT * FROM epi_performance_score_events WHERE id=? AND reversed=0');$s->execute([$eventId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Active score event not found.');$kind=$r['event_kind']==='positive'?'deduction':'positive';$uuid=Support::uuid();$this->pdo->beginTransaction();try{$i=$this->pdo->prepare('INSERT INTO epi_performance_score_events(event_uuid,employee_id,period_start,period_end,evidence_uuid,root_incident_id,rule_id,rule_version_id,category_key,event_kind,base_hundredths,severity_multiplier_basis,repeat_multiplier_basis,financial_multiplier_basis,responsibility_basis,final_impact_hundredths,confirmation_status,automatic_status,confidence_level,supersedes_event_id,confirmed_by,confirmed_at,reviewer_note,manual) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'confirmed\',\'reversed\',?,?,?,NOW(),?,1)');$i->execute([$uuid,$r['employee_id'],$r['period_start'],$r['period_end'],null,'reversal:'.$r['event_uuid'],$r['rule_id'],$r['rule_version_id'],$r['category_key'],$kind,$r['base_hundredths'],$r['severity_multiplier_basis'],$r['repeat_multiplier_basis'],$r['financial_multiplier_basis'],$r['responsibility_basis'],$r['final_impact_hundredths'],$r['confidence_level']??'high',$eventId,$reviewerId,trim($reason)]);$id=(int)$this->pdo->lastInsertId();$this->pdo->prepare("UPDATE epi_performance_score_events SET reversed=1,automatic_status='reversed',reversal_event_id=?,reversal_reason=? WHERE id=?")->execute([$id,trim($reason),$eventId]);$this->pdo->commit();return$id;}catch(\Throwable$e){$this->pdo->rollBack();throw$e;}}

    public function overrideEvent(int$eventId,string$action,int$reviewerId,string$reason):int
    {
        $allowed=['excuse','reassign','mark_system_error','mark_business_error','mark_external_dependency','correct_responsibility','correct_reference','restore_automatic_event'];
        if(!in_array($action,$allowed,true))throw new RuntimeException('Invalid override action.');if(trim($reason)==='')throw new RuntimeException('An override reason is required.');
        $s=$this->pdo->prepare('SELECT * FROM epi_performance_score_events WHERE id=?');$s->execute([$eventId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Score event not found.');
        $status=$action==='restore_automatic_event'?'automatically_applied':($action==='reassign'||strpos($action,'correct_')===0?'needs_review':'automatically_excluded');$confirmation=$status==='automatically_applied'?'confirmed':($status==='needs_review'?'pending':'excused');$exception=str_replace('mark_','',$action);
        $this->pdo->beginTransaction();try{$i=$this->pdo->prepare('INSERT INTO epi_performance_score_events(event_uuid,employee_id,period_start,period_end,evidence_uuid,root_incident_id,rule_id,rule_version_id,category_key,event_kind,base_hundredths,final_impact_hundredths,confirmation_status,automatic_status,confidence_level,exception_status,supersedes_event_id,confirmed_by,confirmed_at,reviewer_note,manual) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,1)');$i->execute([Support::uuid(),$r['employee_id'],$r['period_start'],$r['period_end'],null,'override:'.$r['event_uuid'].':'.date('YmdHis'),$r['rule_id'],$r['rule_version_id'],$r['category_key'],$r['event_kind'],0,0,$confirmation,$status,$r['confidence_level']??'high',$exception,$eventId,$reviewerId,trim($reason)]);$id=(int)$this->pdo->lastInsertId();$this->pdo->prepare('UPDATE epi_performance_score_events SET confirmation_status=?,automatic_status=?,exception_status=?,reviewer_note=?,confirmed_by=?,confirmed_at=NOW() WHERE id=?')->execute([$confirmation,$status,$exception,trim($reason),$reviewerId,$eventId]);$this->pdo->commit();return$id;}catch(\Throwable$e){$this->pdo->rollBack();throw$e;}
    }

    public function lockMonth(int $employeeId,int $year,int $month,int $reviewerId,string $note,bool $override=false):void
    {$m=$this->monthly($employeeId,$year,$month);if(!$m)throw new RuntimeException('Calculate the month before locking it.');if(($m['official_score_hundredths']??null)===null||($m['result_type']??'')==='insufficient_historical_data')throw new RuntimeException('Insufficient Historical Data cannot be locked as a performance score.');if((int)$m['pending_review_count']>0&&!$override)throw new RuntimeException('Pending review events must be resolved before locking.');if(trim($note)==='')throw new RuntimeException('A lock note is required.');$this->pdo->prepare('INSERT INTO epi_employee_month_locks(employee_id,score_year,score_month,monthly_score_id,locked_by,locked_at,lock_note,override_incomplete) VALUES(?,?,?,?,?,NOW(),?,?) ON DUPLICATE KEY UPDATE monthly_score_id=VALUES(monthly_score_id),locked_by=VALUES(locked_by),locked_at=VALUES(locked_at),lock_note=VALUES(lock_note),override_incomplete=VALUES(override_incomplete),unlocked_by=NULL,unlocked_at=NULL,unlock_reason=NULL')->execute([$employeeId,$year,$month,$m['id'],$reviewerId,trim($note),$override?1:0]);$this->pdo->prepare("UPDATE epi_scoring_monthly_scores SET locked=1,locked_by=?,locked_at=NOW(),lock_note=?,score_status='locked' WHERE id=?")->execute([$reviewerId,trim($note),$m['id']]);}

    public function unlockMonth(int $employeeId,int $year,int $month,int $reviewerId,string $reason):void
    {if(trim($reason)==='')throw new RuntimeException('An unlock reason is required.');$this->pdo->prepare('UPDATE epi_employee_month_locks SET unlocked_by=?,unlocked_at=NOW(),unlock_reason=? WHERE employee_id=? AND score_year=? AND score_month=?')->execute([$reviewerId,trim($reason),$employeeId,$year,$month]);$this->pdo->prepare("UPDATE epi_scoring_monthly_scores SET locked=0,locked_by=NULL,locked_at=NULL,lock_note=NULL,score_status='ready_for_review' WHERE employee_id=? AND score_year=? AND score_month=?")->execute([$employeeId,$year,$month]);}

    public function getMonthlyScore(int $employeeId,int $year,int $month):array { $m=$this->monthly($employeeId,$year,$month);return$m?$this->monthlyDetail((int)$m['id']):[]; }
    public function getSourceCoverage(int $employeeId,int $year,int $month):array{$from=sprintf('%04d-%02d-01',$year,$month);$to=(new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');$employee=$this->employee($employeeId);return(new SourceCompletenessEngine($this->pdo))->evaluate($employeeId,(string)($employee['role_key']??''),$from,$to);}
    public function eligibilityTotals(int $employeeId,int $year,int $month):array{$from=sprintf('%04d-%02d-01',$year,$month);$to=(new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');(new SourceCompletenessEngine($this->pdo))->classifyPeriod($employeeId,$from,$to);$s=$this->pdo->prepare('SELECT module,eligibility_state,COUNT(*) total FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? GROUP BY module,eligibility_state ORDER BY module,eligibility_state');$s->execute([$employeeId,$from,$to]);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    public function supersedeInvalidHundreds($createdBy=null):array{$rows=$this->pdo->query("SELECT * FROM epi_scoring_monthly_scores WHERE final_hundredths=10000 AND confidence_label IN('Insufficient Data','Insufficient Historical Data') AND evidence_count=0 AND superseded=0")->fetchAll(PDO::FETCH_ASSOC)?:[];$corrected=0;$locked=0;foreach($rows as$r){if((int)$r['locked']===1){$locked++;continue;}$uuid=Support::uuid();$this->pdo->prepare("INSERT IGNORE INTO epi_score_superseding_corrections(correction_uuid,monthly_score_id,employee_id,period_start,period_end,previous_result_type,previous_score_hundredths,corrected_result_type,corrected_score_hundredths,correction_reason,locked_record,created_by) VALUES(?,?,?,?,?,?,?,'insufficient_historical_data',NULL,?,0,?)")->execute([$uuid,$r['id'],$r['employee_id'],$r['period_start'],$r['period_end'],$r['result_type']??'legacy',$r['final_hundredths'],'Historical 100% had no usable evidence and is superseded by Step 3B.',$createdBy]);$this->pdo->prepare("UPDATE epi_scoring_monthly_scores SET superseded=1,superseded_at=NOW(),superseded_reason=?,result_type='insufficient_historical_data',official_score_hundredths=NULL,official_performance_level='Not Available',score_status='insufficient_historical_data' WHERE id=? AND locked=0")->execute(['Historical 100% had no usable evidence.',$r['id']]);$corrected++;}return['found'=>count($rows),'corrected'=>$corrected,'locked_requires_audited_unlock'=>$locked];}
    public function getAudit(int $employeeId,int $limit=100):array{$s=$this->pdo->prepare('SELECT * FROM epi_employee_score_audits WHERE employee_id=? ORDER BY created_at DESC LIMIT '.max(1,min(500,$limit)));$s->execute([$employeeId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    public function getTrend(int $employeeId,int $months=12):array{$s=$this->pdo->prepare('SELECT score_year,score_month,official_score_hundredths,official_performance_level,result_type,confidence_label,locked FROM epi_scoring_monthly_scores WHERE employee_id=? ORDER BY score_year DESC,score_month DESC LIMIT '.max(1,min(60,$months)));$s->execute([$employeeId]);return array_reverse($s->fetchAll(PDO::FETCH_ASSOC));}
    public function aggregate(int $employeeId,string $from,string $to):array{$s=$this->pdo->prepare("SELECT COUNT(official_score_hundredths) months,ROUND(AVG(official_score_hundredths)) official_score_hundredths,SUM(deduction_hundredths) deduction_hundredths,SUM(positive_hundredths) positive_hundredths,SUM(pending_review_count) pending_review_count FROM epi_scoring_monthly_scores WHERE employee_id=? AND period_start>=? AND period_end<=? AND result_type IN('calculated','provisional_calculated')");$s->execute([$employeeId,$from,$to]);$r=$s->fetch(PDO::FETCH_ASSOC)?:[];$r['performance_level']=($r['official_score_hundredths']??null)===null?'Not Available':$this->level((int)$r['official_score_hundredths']);return$r;}
    public function employeeOptions():array{return$this->pdo->query("SELECT e.id,e.full_name,COALESCE(r.role_key,'') role_key FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);}
    public function rules():array{return$this->pdo->query('SELECT r.*,v.id version_id,v.version,v.impact_hundredths,v.severity_multiplier_basis,v.maximum_per_event_hundredths,v.maximum_per_day_hundredths,v.maximum_per_month_hundredths,v.automatic_application,v.minimum_confidence,v.owner_review_required,v.exclusion_conditions_json,v.grace_period_minutes,v.root_incident_grouping,v.owner_confirmation_required,v.effective_from,v.effective_to FROM epi_performance_rules r LEFT JOIN epi_performance_rule_versions v ON v.rule_id=r.id ORDER BY r.module,r.category_key,r.rule_name,v.version DESC')->fetchAll(PDO::FETCH_ASSOC);}
    public function scorecards():array{return$this->pdo->query('SELECT s.*,COALESCE(SUM(c.weight_hundredths),0) weight_total_hundredths,COUNT(c.id) category_count FROM epi_scorecards s LEFT JOIN epi_scorecard_categories c ON c.scorecard_id=s.id AND c.is_active=1 GROUP BY s.id ORDER BY s.scorecard_name,s.version DESC')->fetchAll(PDO::FETCH_ASSOC);}

    private function automaticDecision(array$row,array$meta):array
    {
        $text=strtolower((string)($row['rule_name']??'').' '.(string)($row['action']??''));
        $subjective=(bool)preg_match('/wrong product|missing product|customer complaint|shared responsibility|financial loss|repeat error|critical error|misconduct|supplier|courier fault|business error|system error|conflicting attribution/',$text);
        $confidence=strtolower((string)($meta['evidence_confidence']??$meta['confidence_level']??'high'));if(!in_array($confidence,['high','moderate','low','insufficient'],true))$confidence='insufficient';
        $exception=null;foreach(['approved_leave','approved_exception','system_error','business_error','external_dependency','system_outage','internet_outage','device_failure','supplier_delay','courier_delay','customer_delay','approved_role_coverage']as$key)if(!empty($meta[$key])){$exception=$key;break;}
        $complete=(int)($row['employee_id']??0)>0&&!empty($row['evidence_uuid'])&&!empty($row['occurred_at'])&&!empty($row['business_date'])&&!empty($row['reference_number'])&&!empty($row['action']);
        if((string)($row['recording_mode']??'')==='test')return['confirmation_status'=>'excused','automatic_status'=>'automatically_excluded','confidence_level'=>$confidence,'exception_status'=>'test_data'];
        if($exception!==null)return['confirmation_status'=>'excused','automatic_status'=>'automatically_excluded','confidence_level'=>$confidence,'exception_status'=>$exception];
        if(!$complete||$confidence==='insufficient')return['confirmation_status'=>'insufficient_data','automatic_status'=>'insufficient_data','confidence_level'=>'insufficient','exception_status'=>null];
        if($subjective||(int)($row['owner_review_required']??0)===1||(int)($row['owner_confirmation_required']??0)===1||(int)($row['automatic_application']??1)!==1||$confidence==='low')return['confirmation_status'=>'pending','automatic_status'=>'needs_review','confidence_level'=>$confidence,'exception_status'=>$subjective?'ambiguous_responsibility':null];
        $minimum=strtolower((string)($row['minimum_confidence']??'high'));$rank=['insufficient'=>0,'low'=>1,'moderate'=>2,'high'=>3];if(($rank[$confidence]??0)<($rank[$minimum]??3))return['confirmation_status'=>'pending','automatic_status'=>'needs_review','confidence_level'=>$confidence,'exception_status'=>'confidence_below_rule_minimum'];
        return['confirmation_status'=>'confirmed','automatic_status'=>'automatically_applied','confidence_level'=>$confidence,'exception_status'=>null];
    }

    private function ensureAutomaticScoringSchema():void
    {
        $ruleColumns=['automatic_application'=>"TINYINT(1) NOT NULL DEFAULT 1",'minimum_confidence'=>"VARCHAR(20) NOT NULL DEFAULT 'high'",'owner_review_required'=>"TINYINT(1) NOT NULL DEFAULT 0",'exclusion_conditions_json'=>'LONGTEXT NULL','grace_period_minutes'=>'INT UNSIGNED NOT NULL DEFAULT 0','root_incident_grouping'=>"VARCHAR(80) NOT NULL DEFAULT 'module_reference'"];
        $eventColumns=['automatic_status'=>"VARCHAR(40) NOT NULL DEFAULT 'needs_review'",'confidence_level'=>"VARCHAR(20) NOT NULL DEFAULT 'insufficient'",'exception_status'=>'VARCHAR(40) NULL','expected_result'=>'TEXT NULL','actual_result'=>'TEXT NULL','exception_id'=>'BIGINT UNSIGNED NULL','supersedes_event_id'=>'BIGINT UNSIGNED NULL'];
        $ruleSchemaAdded=false;
        foreach(['epi_performance_rule_versions'=>$ruleColumns,'epi_performance_score_events'=>$eventColumns]as$table=>$columns)foreach($columns as$name=>$definition)if(!$this->columnExists($table,$name)){$this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");if($table==='epi_performance_rule_versions')$ruleSchemaAdded=true;}
        if($ruleSchemaAdded){
            $this->pdo->exec("INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES('epi_scoring_enabled','1','boolean','Evidence-first automatic scoring with review only for ambiguity.') ON DUPLICATE KEY UPDATE setting_value='1',description=VALUES(description)");
            $this->pdo->exec("UPDATE epi_performance_rule_versions v JOIN epi_performance_rules r ON r.id=v.rule_id SET v.automatic_application=1,v.owner_review_required=0,v.owner_confirmation_required=0,v.minimum_confidence='high' WHERE LOWER(CONCAT(r.rule_name,' ',r.event_type)) REGEXP 'completed|completion|in progress|late|overdue|deadline|checklist|required note|required evidence|quantity variance|website confirmation|waybill|bookkeeping|opening balance|closing balance|login'");
            $this->pdo->exec("UPDATE epi_performance_rule_versions v JOIN epi_performance_rules r ON r.id=v.rule_id SET v.automatic_application=0,v.owner_review_required=1,v.owner_confirmation_required=1,v.minimum_confidence='high' WHERE LOWER(CONCAT(r.rule_name,' ',r.event_type)) REGEXP 'wrong product|missing product|customer complaint|shared responsibility|financial loss|repeat error|critical error|misconduct|supplier|courier fault|business error|system error|conflicting attribution'");
        }
        $this->pdo->exec("UPDATE epi_performance_score_events SET automatic_status='automatically_applied',confidence_level=IF(confidence_level='insufficient','high',confidence_level) WHERE confirmation_status='confirmed' AND reversed=0 AND automatic_status='needs_review'");
        $this->pdo->exec("UPDATE epi_performance_score_events SET automatic_status='automatically_excluded' WHERE confirmation_status IN('dismissed','excused','duplicate','rejected') AND reversed=0 AND automatic_status='needs_review'");
        $this->pdo->exec("UPDATE epi_performance_score_events SET automatic_status='reversed' WHERE reversed=1");
    }

    private function columnExists(string$table,string$column):bool
    {$s=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return(int)$s->fetchColumn()>0;}

    private function isMonthLocked(int$employeeId,int$year,int$month):bool
    {$s=$this->pdo->prepare('SELECT locked FROM epi_scoring_monthly_scores WHERE employee_id=? AND score_year=? AND score_month=?');$s->execute([$employeeId,$year,$month]);return(int)$s->fetchColumn()===1;}

    private function employee(int$id):array{$s=$this->pdo->prepare("SELECT e.id,e.full_name,COALESCE(r.role_key,'') role_key,'' department_key FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Employee not found.');return$r;}
    private function scorecard(array$e,string$date):array{$role=(string)$e['role_key'];if($role==='packer_production_staff')$role='packer';if($role==='front_desk_admin_employee')$role='front_desk_admin';$s=$this->pdo->prepare("SELECT * FROM epi_scorecards WHERE status='active' AND effective_from<=? AND(effective_to IS NULL OR effective_to>=?) AND(employee_id=? OR role_key=? OR(employee_id IS NULL AND role_key IS NULL)) ORDER BY (employee_id IS NOT NULL) DESC,(role_key IS NOT NULL) DESC,version DESC LIMIT 1");$s->execute([$date,$date,$e['id'],$role]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('No effective scorecard exists for this employee.');return$r;}
    private function categories(int$id):array{$s=$this->pdo->prepare('SELECT * FROM epi_scorecard_categories WHERE scorecard_id=? AND is_active=1 ORDER BY id');$s->execute([$id]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function validateWeights(array$c):void{if(array_sum(array_map('intval',array_column($c,'weight_hundredths')))!==10000)throw new RuntimeException('Scorecard category weights must total exactly 100%.');}
    private function confirmedEvents(int$id,string$from,string$to):array{$s=$this->pdo->prepare("SELECT * FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? AND confirmation_status='confirmed' AND reversed=0 ORDER BY created_at,id");$s->execute([$id,$from,$to]);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function deduplicateIncidents(array$events):array{$best=[];foreach($events as$e){$key=($e['root_incident_id']?:$e['event_uuid']).'|'.$e['category_key'].'|'.$e['event_kind'];if(!isset($best[$key])||(int)$e['final_impact_hundredths']>(int)$best[$key]['final_impact_hundredths'])$best[$key]=$e;}return array_values($best);}
    private function pendingCount(int$id,string$f,string$t):int{$s=$this->pdo->prepare("SELECT COUNT(*) FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? AND automatic_status='needs_review' AND reversed=0");$s->execute([$id,$f,$t]);return(int)$s->fetchColumn();}
    private function evidenceCount(int$id,string$f,string$t):int{$s=$this->pdo->prepare("SELECT COUNT(*) FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND eligibility_state IN('automatically_eligible','verified_eligible') AND recording_mode<>'test'");$s->execute([$id,$f,$t]);return(int)$s->fetchColumn();}
    private function monthly(int$id,int$y,int$m){$s=$this->pdo->prepare('SELECT * FROM epi_scoring_monthly_scores WHERE employee_id=? AND score_year=? AND score_month=?');$s->execute([$id,$y,$m]);return$s->fetch(PDO::FETCH_ASSOC);}
    private function monthlyDetail(int$id):array{$s=$this->pdo->prepare('SELECT * FROM epi_scoring_monthly_scores WHERE id=?');$s->execute([$id]);$m=$s->fetch(PDO::FETCH_ASSOC)?:[];$s=$this->pdo->prepare('SELECT * FROM epi_employee_monthly_category_scores WHERE monthly_score_id=? ORDER BY id');$s->execute([$id]);$m['categories']=$s->fetchAll(PDO::FETCH_ASSOC);$s=$this->pdo->prepare('SELECT * FROM epi_performance_score_events WHERE employee_id=? AND period_start=? AND period_end=? ORDER BY confirmation_status,created_at DESC');$s->execute([$m['employee_id'],$m['period_start'],$m['period_end']]);$m['events']=$s->fetchAll(PDO::FETCH_ASSOC);return$m;}
    private function level(int$v):string{if($v>=$this->settingInt('epi_level_gold_min',9000))return'Gold';if($v>=$this->settingInt('epi_level_silver_min',8500))return'Silver';if($v>=$this->settingInt('epi_level_bronze_min',7500))return'Bronze';return'No Bonus';}
    private function setting(string$key,string$default):string{$s=$this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
    private function settingInt(string$key,int$default):int{return(int)$this->setting($key,(string)$default);}
}
