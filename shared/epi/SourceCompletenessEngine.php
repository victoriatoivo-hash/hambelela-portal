<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;

/** Source-based eligibility and completeness for Recovery Step 3B. */
final class SourceCompletenessEngine
{
    private $pdo;
    private $version = 'EPI Step 3C Version 1.0';

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function classifyPeriod(int $employeeId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? ORDER BY id');
        $stmt->execute([$employeeId, $from, $to]);
        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $decision = $this->eligibility($row);
            $state = $decision['state'];
            $totals[$state] = ($totals[$state] ?? 0) + 1;
            if ((string) ($row['eligibility_state'] ?? '') === $state && (string) ($row['eligibility_version'] ?? '') === $this->version) continue;
            $this->pdo->prepare('INSERT INTO epi_evidence_eligibility_audits(evidence_uuid,previous_state,new_state,reason_code,reason_text,calculation_version) VALUES(?,?,?,?,?,?)')
                ->execute([$row['evidence_uuid'], $row['eligibility_state'] ?? null, $state, $decision['reason'], $decision['explanation'], $this->version]);
            $this->pdo->prepare('UPDATE epi_employee_evidence SET eligibility_state=?,eligibility_reason=?,eligibility_version=?,eligibility_classified_at=NOW() WHERE id=?')
                ->execute([$state, $decision['reason'], $this->version, $row['id']]);
        }
        return $totals;
    }

    public function evaluate(int $employeeId, string $roleKey, string $from, string $to): array
    {
        $roleKey = $this->normaliseRole($roleKey);
        $this->classifyPeriod($employeeId, $from, $to);
        $requirements = $this->requirements($roleKey);
        $results = [];
        foreach ($requirements as $source) $results[] = $this->evaluateSource($employeeId, $from, $to, $source);
        $criticalMissing = array_values(array_filter($results, static function (array $r): bool { return $r['importance'] === 'critical' && $r['source_status'] === 'missing'; }));
        $criticalPartial = array_values(array_filter($results, static function (array $r): bool { return $r['importance'] === 'critical' && $r['source_status'] === 'partial'; }));
        $coreMissing = array_values(array_filter($results, static function (array $r): bool { return $r['importance'] === 'core' && $r['source_status'] === 'missing'; }));
        $coreCoverage = $this->importanceCoverage($results, 'core');
        $allCoverage = count($results) ? (int) round(array_sum(array_column($results, 'completeness_hundredths')) / count($results)) : 0;
        $coreMissingLimit = $this->settingInt('epi_source_core_missing_limit', 2);
        if (!$requirements || $criticalMissing || count($coreMissing) >= $coreMissingLimit) $resultType = 'insufficient_historical_data';
        elseif ($criticalPartial || $coreCoverage < 9000) $resultType = 'provisional_calculated';
        else $resultType = 'calculated';
        if ($resultType === 'insufficient_historical_data') $confidence = 'Insufficient Historical Data';
        elseif (!$criticalPartial && $coreCoverage >= $this->settingInt('epi_source_high_confidence_core_basis', 9000)) $confidence = 'High';
        elseif ($coreCoverage >= $this->settingInt('epi_source_moderate_confidence_core_basis', 6500)) $confidence = 'Moderate';
        else $confidence = 'Low';
        return [
            'role_key'=>$roleKey, 'result_type'=>$resultType, 'confidence'=>$confidence,
            'completeness_hundredths'=>$allCoverage, 'core_coverage_hundredths'=>$coreCoverage,
            'sources'=>$results,
            'missing_critical_sources'=>array_column($criticalMissing, 'source_key'),
            'partial_critical_sources'=>array_column($criticalPartial, 'source_key'),
            'missing_core_sources'=>array_column($coreMissing, 'source_key'),
        ];
    }

    public function categoryStatus(string $categoryKey, array $coverage): array
    {
        $sources = array_values(array_filter($coverage['sources'], static function (array $r) use ($categoryKey): bool { return $r['category_key'] === $categoryKey; }));
        if (!$sources) return ['status'=>'insufficient_data','confidence'=>'Insufficient Historical Data','missing'=>['role_source_mapping_missing']];
        $missingCritical = array_values(array_filter($sources, static function (array $r): bool { return $r['importance'] === 'critical' && $r['source_status'] !== 'complete'; }));
        $missingCore = array_values(array_filter($sources, static function (array $r): bool { return $r['importance'] === 'core' && $r['source_status'] === 'missing'; }));
        $allSourcesMissing = count(array_filter($sources, static function (array $r): bool { return $r['source_status'] === 'missing'; })) === count($sources);
        // A category cannot earn a numeric score when its own critical or core
        // source is entirely absent. The multi-source threshold applies only to
        // the overall monthly result, never to an individual category.
        if ($allSourcesMissing || $missingCritical || $missingCore) $status = 'insufficient_data';
        elseif (count(array_filter($sources, static function (array $r): bool { return $r['source_status'] !== 'complete'; }))) $status = 'provisional';
        else $status = 'calculated';
        return ['status'=>$status,'confidence'=>$status === 'calculated' ? 'High' : ($status === 'provisional' ? 'Moderate' : 'Insufficient Historical Data'),'missing'=>array_column(array_filter($sources, static function (array $r): bool { return $r['source_status'] !== 'complete'; }), 'source_key')];
    }

    private function eligibility(array $row): array
    {
        $meta = json_decode((string) ($row['metadata_json'] ?? ''), true); if (!is_array($meta)) $meta = [];
        $text = strtolower((string) ($row['module'] ?? '') . ' ' . (string) ($row['action'] ?? '') . ' ' . (string) ($row['action_description'] ?? ''));
        if ((string) ($row['recording_mode'] ?? '') === 'test' || !empty($meta['test_data'])) return $this->decision('test_data','test_data','TEST DATA is never eligible.');
        if (!empty($meta['duplicate']) || !empty($meta['duplicate_of'])) return $this->decision('duplicate','duplicate','Duplicate evidence is excluded.');
        if (!empty($meta['superseded'])) return $this->decision('superseded','superseded','Superseded evidence is excluded.');
        foreach (['system_error','business_error'] as $exception) if (!empty($meta[$exception]) || strpos($text, str_replace('_',' ',$exception)) !== false) return $this->decision($exception,$exception,'Business and System Errors are excluded from employee scoring.');
        foreach (['approved_leave','approved_exception','external_dependency','system_outage','internet_outage','device_failure','supplier_delay','courier_delay','customer_delay','approved_role_coverage'] as $exception) if (!empty($meta[$exception])) return $this->decision('excluded',$exception,'A confirmed exception excludes this evidence from employee scoring.');
        if (empty($row['employee_id']) || empty($row['occurred_at']) || empty($row['reference_number']) || empty($row['action'])) return $this->decision('invalid','missing_required_fields','Attribution, timestamp, reference and action are required.');
        $subjective = (bool) preg_match('/wrong product|missing product|customer complaint|financial loss|shared responsibility|repeat error|critical error|owner intervention|supplier|courier fault|conflicting attribution/', $text);
        if ($subjective) return !empty($row['verified']) ? $this->decision('verified_eligible','owner_verified','Subjective evidence was verified.') : $this->decision('needs_review','subjective_review_required','Subjective or responsibility-sensitive evidence requires review.');
        return $this->decision('automatically_eligible','objective_system_event','Objective timestamped and attributed system evidence.');
    }

    private function evaluateSource(int $employeeId, string $from, string $to, array $source): array
    {
        $pattern = trim((string) ($source['action_pattern'] ?? ''));
        $sql = "SELECT COUNT(*) expected, SUM(CASE WHEN eligibility_state IN('automatically_eligible','verified_eligible') THEN 1 ELSE 0 END) available, SUM(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) owned, SUM(CASE WHEN occurred_at IS NOT NULL THEN 1 ELSE 0 END) timestamped, SUM(CASE WHEN status_before IS NOT NULL OR status_after IS NOT NULL THEN 1 ELSE 0 END) statused FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND LOWER(module)=LOWER(?)";
        $params = [$employeeId,$from,$to,$source['module']];
        if ($pattern !== '') { $sql .= " AND LOWER(CONCAT(COALESCE(action,''),' ',COALESCE(action_description,''))) REGEXP ?"; $params[] = $pattern; }
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$counts=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        $expected=(int)($counts['expected']??0);$available=(int)($counts['available']??0);
        $ownership=$expected?(int)round(((int)$counts['owned']/$expected)*10000):0;
        $timestamp=$expected?(int)round(((int)$counts['timestamped']/$expected)*10000):0;
        $statusRequired=(int)$source['status_history_required']===1;
        $status=$expected?(int)round(((int)$counts['statused']/$expected)*10000):0;
        $parts=[$expected?(int)round(($available/$expected)*10000):0];
        if((int)$source['ownership_required']===1)$parts[]=$ownership;if((int)$source['timestamp_required']===1)$parts[]=$timestamp;if($statusRequired)$parts[]=$status;
        $completeness=$parts?(int)round(array_sum($parts)/count($parts)):0;
        $sourceStatus=$expected===0?'missing':($completeness>=9000?'complete':'partial');
        $reason=$expected===0?'No matching historical source records were found.':($sourceStatus==='partial'?'Some records lack eligible evidence, ownership, timestamps or status history.':null);
        // A perfect ratio among recovered records must not conceal a known gap in
        // the underlying July source history (for example sessions starting 21 July).
        $audit=$this->historicalAudit($employeeId,$from,$to,(string)$source['source_key']);
        if($audit){
            $expected=max($expected,(int)$audit['legacy_records_found']+(int)$audit['unresolved_records']);
            if((int)$audit['unresolved_records']>0||in_array((string)$audit['source_reliability'],['moderate','low','insufficient'],true)){
                $sourceStatus=$expected===0?'missing':'partial';
                $reason=(string)($audit['limitation_note']?:'Historical source audit identified unresolved coverage.');
                $completeness=$expected?(int)round(($available/$expected)*10000):0;
            }
        }
        $reliability=$sourceStatus==='complete'?'high':($sourceStatus==='partial'?'moderate':'insufficient');
        $row=['source_id'=>(int)$source['id'],'source_key'=>$source['source_key'],'source_name'=>$source['source_name'],'category_key'=>$source['category_key'],'importance'=>$source['importance'],'records_expected'=>$expected,'records_available'=>$available,'ownership_coverage_hundredths'=>$ownership,'timestamp_coverage_hundredths'=>$timestamp,'status_history_coverage_hundredths'=>$status,'completeness_hundredths'=>$completeness,'source_status'=>$sourceStatus,'source_reliability'=>$reliability,'reason_missing'=>$reason];
        $this->storeSource($employeeId,$from,$to,$source,$row);
        return $row;
    }

    private function storeSource(int $employeeId,string $from,string $to,array $source,array $row):void
    {
        $sql='INSERT INTO epi_monthly_source_completeness(employee_id,period_start,period_end,role_key,source_id,source_key,category_key,importance,records_expected,records_available,ownership_coverage_hundredths,timestamp_coverage_hundredths,status_history_coverage_hundredths,completeness_hundredths,source_status,source_reliability,reason_missing,calculation_version,calculated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE records_expected=VALUES(records_expected),records_available=VALUES(records_available),ownership_coverage_hundredths=VALUES(ownership_coverage_hundredths),timestamp_coverage_hundredths=VALUES(timestamp_coverage_hundredths),status_history_coverage_hundredths=VALUES(status_history_coverage_hundredths),completeness_hundredths=VALUES(completeness_hundredths),source_status=VALUES(source_status),source_reliability=VALUES(source_reliability),reason_missing=VALUES(reason_missing),calculation_version=VALUES(calculation_version),calculated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$employeeId,$from,$to,$this->normaliseRole($source['role_key']),$source['id'],$source['source_key'],$source['category_key'],$source['importance'],$row['records_expected'],$row['records_available'],$row['ownership_coverage_hundredths'],$row['timestamp_coverage_hundredths'],$row['status_history_coverage_hundredths'],$row['completeness_hundredths'],$row['source_status'],$row['source_reliability'],$row['reason_missing'],$this->version]);
    }

    private function requirements(string $roleKey):array{$s=$this->pdo->prepare('SELECT * FROM epi_role_required_sources WHERE role_key=? AND active=1 ORDER BY FIELD(importance,\'critical\',\'core\',\'supporting\',\'optional\'),id');$s->execute([$roleKey]);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    private function normaliseRole(string $role):string{if($role==='packer_production_staff')return'packer';if($role==='front_desk_admin_employee')return'front_desk_admin';return$role;}
    private function importanceCoverage(array $rows,string $importance):int{$selected=array_values(array_filter($rows,static function(array$r)use($importance):bool{return$r['importance']===$importance;}));return$selected?(int)round(array_sum(array_column($selected,'completeness_hundredths'))/count($selected)):10000;}
    private function decision(string$state,string$reason,string$explanation):array{return['state'=>$state,'reason'=>$reason,'explanation'=>$explanation];}
    private function settingInt(string$key,int$default):int{$s=$this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();return$v===false?$default:(int)$v;}
    private function historicalAudit(int$employeeId,string$from,string$to,string$sourceKey):?array{try{$s=$this->pdo->prepare('SELECT * FROM epi_historical_source_audits WHERE employee_id=? AND period_start=? AND period_end=? AND source_key=? LIMIT 1');$s->execute([$employeeId,$from,$to,$sourceKey]);$r=$s->fetch(PDO::FETCH_ASSOC);return$r?:null;}catch(\Throwable$error){return null;}}
}
