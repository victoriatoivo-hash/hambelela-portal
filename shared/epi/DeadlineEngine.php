<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;
use PDO;

/** Explicit operational command service. Never constructed from a read-only view. */
final class DeadlineEngine
{
    private $pdo;
    private $events;
    private $ownership;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->events = new CanonicalEventRegistry($pdo);
        $this->ownership = new OwnershipPeriodEngine($pdo);
    }

    public function schedule(array $input): string
    {
        $module = Support::requireModule((string) ($input['module'] ?? ''));
        $reference = trim((string) ($input['object_reference'] ?? ''));
        $obligation = strtolower(trim((string) ($input['obligation_key'] ?? '')));
        $breachKey = strtolower(trim((string) ($input['breach_event_key'] ?? '')));
        if ($reference === '' || $obligation === '' || $breachKey === '') throw new InvalidArgumentException('Deadline reference, obligation and breach event are required.');
        $event = $this->events->event($breachKey);
        if ((string) $event['module'] !== $module) throw new InvalidArgumentException('Deadline event module does not match obligation module.');
        $starts = Support::timestamp($input['starts_at'] ?? null);
        $due = Support::timestamp($input['due_at'] ?? null);
        if ($due <= $starts) throw new InvalidArgumentException('Deadline due_at must be after starts_at.');
        $key = Support::dedupe([$module,$reference,$obligation,$starts->format('Y-m-d H:i:s')]);
        $uuid = Support::uuidFromHash($key);
        $stmt = $this->pdo->prepare(
            'INSERT INTO epi_v2_operational_deadlines
             (deadline_uuid,idempotency_key,module,object_reference,obligation_key,breach_event_key,responsible_employee_snapshot,
              responsible_team,ownership_snapshot_json,source_event,starts_at,due_at,state,grace_minutes,exception_id,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,\'open\',?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE updated_at=NOW()'
        );
        $stmt->execute([
            $uuid,$key,$module,$reference,$obligation,$breachKey,$input['responsible_employee_id'] ?? null,
            $input['responsible_team'] ?? null,Support::json($input['ownership_snapshot'] ?? null),
            $input['source_event'] ?? null,$starts->format('Y-m-d H:i:s'),$due->format('Y-m-d H:i:s'),
            max(0,(int)($input['grace_minutes'] ?? 0)),$input['exception_id'] ?? null,
        ]);
        return $uuid;
    }

    public function fulfil(string $deadlineUuid, int $employeeId, $at = null): bool
    {
        $fulfilledAt=Support::timestamp($at)->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE epi_v2_operational_deadlines SET fulfilled_at=?,fulfilled_by=?,state='fulfilled',updated_at=NOW()
                 WHERE deadline_uuid=? AND state IN('open','breached','needs_attribution') AND fulfilled_at IS NULL"
            );
            $stmt->execute([$fulfilledAt,$employeeId,$deadlineUuid]);
            $changed=$stmt->rowCount()===1;
            if($changed){
                $resolve=$this->pdo->prepare("UPDATE epi_v2_performance_incidents SET current_risk_state='resolved',resolved_at=? WHERE deadline_uuid=? AND current_risk_state='open'");
                $resolve->execute([$fulfilledAt,$deadlineUuid]);
            }
            $this->pdo->commit();
            return$changed;
        }catch(\Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    public function fulfilObject(string$module,string$reference,string$obligationKey,int$employeeId,$at=null):int
    {
        $module=Support::requireModule($module);$query=$this->pdo->prepare("SELECT deadline_uuid FROM epi_v2_operational_deadlines WHERE module=? AND object_reference=? AND obligation_key=? AND state IN('open','breached','needs_attribution') AND fulfilled_at IS NULL ORDER BY starts_at");$query->execute([$module,trim($reference),trim($obligationKey)]);$count=0;foreach($query->fetchAll(PDO::FETCH_COLUMN)?:[]as$uuid)if($this->fulfil((string)$uuid,$employeeId,$at))$count++;return$count;
    }

    public function processDue($now = null, int $limit = 500): array
    {
        $at = Support::timestamp($now);
        $limit = max(1,min(5000,$limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM epi_v2_operational_deadlines
             WHERE state='open' AND fulfilled_at IS NULL AND DATE_ADD(due_at,INTERVAL grace_minutes MINUTE)<?
             ORDER BY due_at,id LIMIT {$limit}"
        );
        $stmt->execute([$at->format('Y-m-d H:i:s')]);
        $result = ['examined'=>0,'breached'=>0,'needs_attribution'=>0,'excused'=>0,'duplicates'=>0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $deadline) {
            $result['examined']++;
            $outcome = $this->breach($deadline, $at);
            $result[$outcome] = ($result[$outcome] ?? 0) + 1;
        }
        return $result;
    }

    private function breach(array $deadline, \DateTimeImmutable $detectedAt): string
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM epi_v2_operational_deadlines WHERE id=? FOR UPDATE');
            $lock->execute([(int)$deadline['id']]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$current || (string)$current['state'] !== 'open' || !empty($current['fulfilled_at'])) {
                $this->pdo->commit();
                return 'duplicates';
            }
            if (!empty($current['exception_id'])) {
                $this->pdo->prepare("UPDATE epi_v2_operational_deadlines SET state='excused',updated_at=NOW() WHERE id=? AND state='open'")
                    ->execute([(int)$current['id']]);
                $this->pdo->commit();
                return 'excused';
            }
            $owner = $this->ownership->ownerAt((string)$current['module'],(string)$current['object_reference'],(string)$current['due_at']);
            $employeeId = $owner ? (int)$owner['employee_id'] : 0;
            $state = $employeeId > 0 ? 'breached' : 'needs_attribution';
            $incidentUuid = Support::uuidFromHash('deadline-breach|' . $current['deadline_uuid']);
            $meta = [
                'deadline_uuid'=>$current['deadline_uuid'],'obligation_key'=>$current['obligation_key'],
                'responsible_employee_at_breach'=>$employeeId ?: null,'ownership_uuid'=>$owner['ownership_uuid'] ?? null,
                'ownership_effective_from'=>$owner['effective_from'] ?? null,'ownership_effective_to'=>$owner['effective_to'] ?? null,
                'insufficient_attribution'=>$employeeId <= 0,'excluded_from_scoring'=>$employeeId <= 0,
            ];
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO epi_v2_performance_incidents
                 (incident_uuid,deadline_uuid,event_key,module,object_reference,responsible_employee_at_breach,occurred_at,due_at,
                  current_risk_state,historical_state,eligibility_state,exclusion_reason,metadata_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
            );
            $insert->execute([
                $incidentUuid,$current['deadline_uuid'],$current['breach_event_key'],$current['module'],$current['object_reference'],
                $employeeId ?: null,$current['due_at'],$current['due_at'],'open','breach',
                $employeeId > 0 ? 'pending_rule' : 'needs_review',$employeeId > 0 ? null : 'insufficient_attribution',Support::json($meta),
            ]);
            $update = $this->pdo->prepare('UPDATE epi_v2_operational_deadlines SET state=?,breach_incident_uuid=?,updated_at=NOW() WHERE id=? AND state=\'open\'');
            $update->execute([$state,$incidentUuid,(int)$current['id']]);
            $this->pdo->commit();
            return $state === 'breached' ? 'breached' : 'needs_attribution';
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
