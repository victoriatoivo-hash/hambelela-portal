<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use PDO;
use InvalidArgumentException;

/** Read-only diagnostic denominators. Shadow evidence never becomes an official score. */
final class DeadlineRateQuery
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function month(int $year, int $month, $now = null): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('Invalid reporting month.');
        }
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = Support::timestamp($start)->modify('+1 month')->format('Y-m-d H:i:s');
        $at = Support::timestamp($now)->format('Y-m-d H:i:s');
        // A deadline belongs to its due month, not the month somebody eventually clicked.
        $q = $this->db->prepare('SELECT d.*,i.incident_uuid,i.responsible_employee_at_breach,
            i.eligibility_state,i.exclusion_reason,i.metadata_json AS incident_metadata_json,
            i.current_risk_state FROM epi_v2_operational_deadlines d
            LEFT JOIN epi_v2_performance_incidents i ON i.deadline_uuid=d.deadline_uuid
            WHERE d.due_at>=? AND d.due_at<? ORDER BY d.due_at,d.id');
        $q->execute([$start,$end]); $deadlines = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        $owners = new OwnershipPeriodEngine($this->db);
        $rows = []; $groups = [];
        foreach ($deadlines as $d) {
            $snapshot = json_decode((string)$d['policy_snapshot_json'],true) ?: [];
            $meta = json_decode((string)($d['incident_metadata_json'] ?? ''),true) ?: [];
            $finished = $d['fulfilled_at'] && $d['fulfilled_at'] <= $at;
            $late = ($finished && $d['fulfilled_at'] > $d['breach_eligible_at']) ||
                (!$finished && $at > $d['breach_eligible_at']);
            $outcome = $finished ? ($late ? 'late' : 'on_time') : ($late ? 'late' : 'pending');
            $owner = null; $employee = null;
            if ($d['incident_uuid']) {
                // Immutable breach identity takes precedence over today's assignment/finisher.
                $employee = (int)($d['responsible_employee_at_breach'] ?? 0) ?: null;
                $owner = $meta['owner_interval'] ?? null;
            } elseif ($outcome === 'on_time') {
                $owner = $owners->ownerAt($d['module'],$d['object_reference'],$d['fulfilled_at']);
                $employee = $owner ? (int)$owner['employee_id'] : null;
            }
            $reason = $d['exclusion_reason'] ?: null;
            if ((int)$d['historical_backfill']) $reason = 'historical_backfill';
            elseif (empty($snapshot['event']['score_eligible'])) $reason = 'non_employee_obligation';
            elseif ($d['state'] === 'excused') $reason = $reason ?: 'approved_exception';
            // Cancellation after breach retains that breach in historical observations.
            elseif ($d['state'] === 'cancelled' && !$d['incident_uuid']) $reason = 'cancelled_before_breach';
            elseif ($late && !$d['incident_uuid']) $reason = 'awaiting_watchdog_evidence';
            elseif ($outcome !== 'pending' && !$employee) $reason = 'insufficient_attribution';
            $central = EligibilityPolicy::exclusionReason(['module'=>$d['module'],
                'employee_id'=>$employee], $meta);
            $officialReason = $central ?: $reason ?: 'shadow_validation_not_official';
            $observed = $outcome !== 'pending' && $reason === null;
            $row = ['deadline_uuid'=>$d['deadline_uuid'],'incident_uuid'=>$d['incident_uuid'],
                'module'=>$d['module'],'object_reference'=>$d['object_reference'],
                'obligation_key'=>$d['obligation_key'],'event_key'=>$d['breach_event_key'],
                'employee_id'=>$employee,'ownership'=>$owner,'starts_at'=>$d['starts_at'],
                'due_at'=>$d['due_at'],'grace_until'=>$d['breach_eligible_at'],
                'fulfilled_at'=>$d['fulfilled_at'],'fulfilled_by'=>$d['fulfilled_by'],
                'outcome'=>$outcome,'current_state'=>$d['state'],'observation_included'=>$observed,
                'observation_exclusion'=>$reason,'official_eligible'=>false,
                'official_exclusion'=>$officialReason,'policy_version'=>$snapshot['sla_version'] ?? null];
            $rows[] = $row;
            $key = ($employee ?? 0).'|'.$d['module'].'|'.$d['obligation_key'];
            if (!isset($groups[$key])) $groups[$key] = ['employee_id'=>$employee,
                'module'=>$d['module'],'obligation_key'=>$d['obligation_key'],
                'observed_volume'=>0,'on_time'=>0,'late'=>0,'pending'=>0,'excluded'=>0];
            if ($reason !== null) $groups[$key]['excluded']++;
            elseif ($outcome === 'pending') $groups[$key]['pending']++;
            elseif (!$observed) $groups[$key]['excluded']++;
            else { $groups[$key]['observed_volume']++; $groups[$key][$outcome]++; }
        }
        foreach ($groups as &$group) {
            $group['observed_on_time_hundredths'] = $group['observed_volume'] > 0 ?
                (int)round(10000 * $group['on_time'] / $group['observed_volume']) : null;
            $group['official_score_hundredths'] = null;
        }
        unset($group);
        return ['period'=>sprintf('%04d-%02d',$year,$month),'as_of'=>$at,
            'read_only'=>true,'mode'=>'shadow_diagnostics','official_score_hundredths'=>null,
            'coverage'=>'Recorded deadlines only; missing obligations and uncaptured modules are not inferred.',
            'groups'=>array_values($groups),'evidence'=>$rows];
    }
}
