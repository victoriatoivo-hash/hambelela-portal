<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class RecoveryVerifier
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(int $employeeId, string $employeeName): array
    {
        if (Performance::mode() !== FeatureFlags::MODE_TEST) {
            throw new RuntimeException('Switch EPI to Recording Test Mode before running controlled tests.');
        }

        $runId = 'TEST-' . gmdate('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $employeeB = $this->secondEmployee($employeeId, $employeeName);
        $modules = ['Orders', 'Packing List', 'Tasks', 'Courier', 'Bookkeeping', 'Attendance'];
        $evidence = [];
        $activity = [];
        foreach ($modules as $module) {
            $reference = $runId . '-' . strtoupper(str_replace(' ', '-', $module));
            $key = Support::dedupe([$runId, $module, 'controlled_test']);
            $base = $this->base($runId, $module, $reference, $employeeId, $employeeName);
            $first = Performance::recordEvidence($base + [
                'action' => 'controlled_test_event',
                'action_description' => 'TEST DATA - controlled Recovery Step 1B event.',
                'previous_value' => 'test-before', 'new_value' => 'test-after',
                'status_before' => 'test_pending', 'status_after' => 'test_complete',
                'deduplication_key' => $key, 'verified' => false,
            ]);
            $duplicate = Performance::recordEvidence($base + [
                'action' => 'controlled_test_event',
                'action_description' => 'TEST DATA - duplicate replay.',
                'previous_value' => 'test-before', 'new_value' => 'test-after',
                'status_before' => 'test_pending', 'status_after' => 'test_complete',
                'deduplication_key' => $key, 'verified' => false,
            ]);
            $activityId = Performance::recordActivity($base + [
                'activity_type' => 'controlled_test_activity',
                'description' => 'TEST DATA - controlled activity verification.',
                'deduplication_key' => Support::dedupe([$runId, $module, 'activity']),
            ]);
            $evidence[$module] = ['first' => $first, 'duplicate' => $duplicate, 'deduplicated' => $first !== null && $first === $duplicate];
            $activity[$module] = $activityId;
        }

        $ownership = $this->ownershipTest($runId, $employeeId, $employeeName, $employeeB);
        $corrections = $this->immutabilityTests($runId, $employeeId, $employeeName, $employeeB);
        $businessTime = $this->businessTimeTests($employeeId);
        $grace = $this->graceTests($employeeId, $runId);
        $failure = $this->failureTest($runId);
        $urgentNotification = $this->urgentNotificationTest($runId, $employeeId);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM epi_employee_evidence WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.test_run_id')) = ?");
        $count->execute([$runId]);
        $activityCount = $this->pdo->prepare("SELECT COUNT(*) FROM epi_employee_activity WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.test_run_id')) = ?");
        $activityCount->execute([$runId]);

        return [
            'run_id' => $runId, 'modules' => $modules,
            'employees' => ['a' => ['id' => $employeeId, 'name' => $employeeName], 'b' => $employeeB],
            'evidence' => $evidence, 'activity' => $activity,
            'evidence_rows' => (int) $count->fetchColumn(), 'activity_rows' => (int) $activityCount->fetchColumn(),
            'ownership' => $ownership, 'corrections' => $corrections,
            'business_time' => $businessTime, 'grace' => $grace, 'failure' => $failure,
            'urgent_notification' => $urgentNotification,
            'test_data' => true, 'excluded_from_scoring' => true,
        ];
    }

    private function base(string $runId, string $module, string $reference, int $employeeId, string $employeeName): array
    {
        return [
            'module' => $module, 'reference_number' => $reference,
            'employee_id' => $employeeId, 'employee_name' => $employeeName,
            'department' => 'TEST DATA', 'recording_mode' => 'test',
            'activity_source' => 'epi_recovery_step_1b',
            'metadata' => ['test_data' => true, 'excluded_from_scoring' => true, 'test_run_id' => $runId],
        ];
    }

    private function secondEmployee(int $employeeId, string $employeeName): array
    {
        $stmt = $this->pdo->prepare("SELECT id, full_name FROM ops_employees WHERE id <> ? AND status='active' ORDER BY id LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['full_name']] : ['id' => $employeeId, 'name' => $employeeName . ' (fallback)'];
    }

    private function ownershipTest(string $runId, int $employeeAId, string $employeeAName, array $employeeB): array
    {
        $reference = $runId . '-OWNERSHIP';
        $common = ['module' => 'Orders', 'reference_number' => $reference, 'recording_mode' => 'test', 'metadata' => ['test_data' => true]];
        $initial = Performance::recordOwnership($common + [
            'original_owner_id' => $employeeAId, 'original_owner_name' => $employeeAName,
            'current_owner_id' => $employeeAId, 'current_owner_name' => $employeeAName,
            'changed_by' => $employeeAId, 'change_reason' => 'TEST DATA - initial assignment to Employee A.',
            'effective_at' => '2026-08-03 08:05:00',
        ]);
        $reassigned = Performance::recordOwnership($common + [
            'current_owner_id' => $employeeB['id'], 'current_owner_name' => $employeeB['name'],
            'changed_by' => $employeeAId, 'change_reason' => 'TEST DATA - reassigned from Employee A to Employee B.',
            'effective_at' => '2026-08-03 08:10:00',
        ]);
        $completed = Performance::recordOwnership($common + [
            'current_owner_id' => $employeeB['id'], 'current_owner_name' => $employeeB['name'],
            'completed_by_id' => $employeeB['id'], 'completed_by_name' => $employeeB['name'],
            'verified_by_id' => $employeeAId, 'verified_by_name' => $employeeAName,
            'changed_by' => $employeeB['id'], 'change_reason' => 'TEST DATA - Employee B completed the record.',
            'effective_at' => '2026-08-03 08:20:00',
        ]);
        foreach ([['assignment', $employeeAId, $employeeAName, '2026-08-03 08:05:00'], ['reassignment', $employeeB['id'], $employeeB['name'], '2026-08-03 08:10:00'], ['completion', $employeeB['id'], $employeeB['name'], '2026-08-03 08:20:00']] as $event) {
            Performance::recordActivity($this->base($runId, 'Orders', $reference, (int) $event[1], (string) $event[2]) + [
                'activity_type' => 'ownership_' . $event[0], 'description' => 'TEST DATA - ' . $event[0], 'timestamp' => $event[3],
                'deduplication_key' => Support::dedupe([$runId, 'ownership', $event[0]]),
            ]);
        }
        $engine = new OwnershipEngine($this->pdo, new FeatureFlags($this->pdo));
        $history = $engine->history('Orders', $reference);
        return ['reference' => $reference, 'uuids' => [$initial, $reassigned, $completed], 'history' => $history,
            'passed' => count($history) === 3 && (int) $history[2]['original_owner_id'] === $employeeAId && (int) $history[2]['current_owner_id'] === (int) $employeeB['id'] && (int) $history[2]['completed_by_id'] === (int) $employeeB['id']];
    }

    private function immutabilityTests(string $runId, int $employeeAId, string $employeeAName, array $employeeB): array
    {
        $statusReference = $runId . '-STATUS';
        $status = [];
        foreach ([['new_to_in_progress', 'New', 'In Progress'], ['status_correction', 'In Progress', 'New'], ['final_completion', 'New', 'Complete']] as $index => $event) {
            $status[] = Performance::recordEvidence($this->base($runId, 'Orders', $statusReference, $employeeB['id'], $employeeB['name']) + [
                'action' => $event[0], 'action_description' => 'TEST DATA - immutable status chain.',
                'previous_value' => $event[1], 'new_value' => $event[2], 'status_before' => $event[1], 'status_after' => $event[2],
                'timestamp' => '2026-08-03 09:' . str_pad((string) ($index * 5), 2, '0', STR_PAD_LEFT) . ':00',
                'deduplication_key' => Support::dedupe([$runId, 'status', $index]),
            ]);
        }
        $wrongOwner = Performance::recordEvidence($this->base($runId, 'Orders', $runId . '-OWNER-CORRECTION', $employeeAId, $employeeAName) + [
            'action' => 'incorrect_owner_attribution', 'previous_value' => null, 'new_value' => $employeeAId,
            'action_description' => 'TEST DATA - intentionally incorrect attribution.', 'deduplication_key' => Support::dedupe([$runId, 'owner-wrong']),
        ]);
        $ownerCorrectionBase = $this->base($runId, 'Orders', $runId . '-OWNER-CORRECTION', $employeeB['id'], $employeeB['name']);
        $ownerCorrectionBase['metadata'] += ['supersedes_evidence_uuid' => $wrongOwner, 'reason' => 'Controlled incorrect-ownership correction.'];
        $ownerCorrection = Performance::recordEvidence($ownerCorrectionBase + [
            'action' => 'ownership_correction', 'previous_value' => $employeeAId, 'new_value' => $employeeB['id'],
            'action_description' => 'TEST DATA - owner correction approved by owner.', 'verified_by' => $employeeAId,
            'deduplication_key' => Support::dedupe([$runId, 'owner-correction']),
        ]);
        $wrongReference = $runId . '-WRONG-REFERENCE';
        $correctReference = $runId . '-CORRECT-REFERENCE';
        $referenceOriginal = Performance::recordEvidence($this->base($runId, 'Orders', $wrongReference, $employeeAId, $employeeAName) + [
            'action' => 'incorrect_reference_link', 'previous_value' => null, 'new_value' => $wrongReference,
            'action_description' => 'TEST DATA - intentionally incorrect reference.', 'deduplication_key' => Support::dedupe([$runId, 'reference-wrong']),
        ]);
        $referenceCorrectionBase = $this->base($runId, 'Orders', $correctReference, $employeeAId, $employeeAName);
        $referenceCorrectionBase['metadata'] += ['supersedes_evidence_uuid' => $referenceOriginal, 'old_reference' => $wrongReference, 'current_reference' => $correctReference, 'reason' => 'Controlled reference correction.'];
        $referenceCorrection = Performance::recordEvidence($referenceCorrectionBase + [
            'action' => 'reference_correction', 'previous_value' => $wrongReference, 'new_value' => $correctReference,
            'action_description' => 'TEST DATA - corrected reference without deleting original.', 'verified_by' => $employeeAId,
            'deduplication_key' => Support::dedupe([$runId, 'reference-correction']),
        ]);
        $rows = Performance::getEvidence(['reference_number' => $statusReference], 20);
        return ['status' => ['reference' => $statusReference, 'uuids' => $status, 'rows' => $rows, 'passed' => count($rows) === 3],
            'ownership' => ['original' => $wrongOwner, 'correction' => $ownerCorrection, 'passed' => $wrongOwner !== $ownerCorrection],
            'reference' => ['old_reference' => $wrongReference, 'current_reference' => $correctReference, 'original' => $referenceOriginal, 'correction' => $referenceCorrection, 'passed' => $referenceOriginal !== $referenceCorrection]];
    }

    private function businessTimeTests(int $employeeId): array
    {
        $engine = new BusinessTimeEngine($this->pdo);
        $cases = [
            ['friday_to_monday', '2026-08-07 16:55:00', '2026-08-10 08:10:00', 255, 'Friday 5 minutes + Saturday 240 minutes + Monday 10 minutes; Sunday excluded'],
            ['saturday_exact', '2026-08-08 09:00:00', '2026-08-08 13:00:00', 240, 'Saturday 09:00-13:00'],
            ['saturday_bounded', '2026-08-08 08:00:00', '2026-08-08 14:00:00', 240, 'Only Saturday 09:00-13:00'],
            ['sunday', '2026-08-09 08:00:00', '2026-08-09 17:00:00', 0, 'Sunday closed'],
            ['after_hours', '2026-08-10 17:30:00', '2026-08-11 08:30:00', 30, 'After-hours excluded; Tuesday 08:00-08:30'],
        ];
        $results = [];
        foreach ($cases as $case) {
            $actual = $engine->workingMinutes($case[1], $case[2]);
            $results[] = ['case' => $case[0], 'start' => $case[1], 'end' => $case[2], 'schedule' => $case[4], 'expected' => $case[3], 'actual' => $actual, 'passed' => (float) $case[3] === (float) $actual];
        }
        $holidayDate = '2026-08-12';
        $existing = $this->pdo->prepare('SELECT * FROM epi_employee_business_calendar WHERE business_date=?');
        $existing->execute([$holidayDate]);
        $saved = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        $this->pdo->prepare("INSERT INTO epi_employee_business_calendar(business_date,calendar_type,label,is_working_day,opens_at,closes_at,created_by) VALUES(?, 'holiday','TEST DATA holiday',0,NULL,NULL,?) ON DUPLICATE KEY UPDATE calendar_type='holiday',label='TEST DATA holiday',is_working_day=0,opens_at=NULL,closes_at=NULL,created_by=VALUES(created_by)")->execute([$holidayDate, $employeeId]);
        $holidayActual = (new BusinessTimeEngine($this->pdo))->workingMinutes($holidayDate . ' 08:00:00', $holidayDate . ' 17:00:00');
        if ($saved) {
            $this->pdo->prepare('UPDATE epi_employee_business_calendar SET calendar_type=?,label=?,is_working_day=?,opens_at=?,closes_at=?,created_by=? WHERE business_date=?')->execute([$saved['calendar_type'], $saved['label'], $saved['is_working_day'], $saved['opens_at'], $saved['closes_at'], $saved['created_by'], $holidayDate]);
        } else {
            $this->pdo->prepare("DELETE FROM epi_employee_business_calendar WHERE business_date=? AND label='TEST DATA holiday'")->execute([$holidayDate]);
        }
        $results[] = ['case' => 'public_holiday', 'start' => $holidayDate . ' 08:00:00', 'end' => $holidayDate . ' 17:00:00', 'schedule' => 'Controlled weekday holiday; override removed/restored', 'expected' => 0, 'actual' => $holidayActual, 'passed' => $holidayActual === 0.0];
        return $results;
    }

    private function graceTests(int $employeeId, string $runId): array
    {
        $suffix = strtolower(substr(hash('sha256', $runId), 0, 16));
        $keys = ['recovery_test_global_' . $suffix, 'recovery_test_tasks_' . $suffix];
        $insert = $this->pdo->prepare('INSERT INTO epi_employee_grace_periods(grace_key,module,label,minutes,uses_business_time,is_active,updated_by) VALUES(?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE minutes=VALUES(minutes),is_active=VALUES(is_active),updated_by=VALUES(updated_by)');
        $insert->execute([$keys[0], 'Global', 'TEST DATA global grace', 10, 1, $employeeId]);
        $insert->execute([$keys[1], 'Tasks', 'TEST DATA module grace', 20, 1, $employeeId]);
        $engine = new GracePeriodEngine($this->pdo, new BusinessTimeEngine($this->pdo));
        $global = $engine->resolve($keys[0], null, null);
        $module = $engine->resolve($keys[0], $keys[1], null);
        $record = $engine->resolve($keys[0], $keys[1], ['minutes' => 30, 'uses_business_time' => true, 'is_active' => true]);
        $expired = $engine->resolve($keys[0], $keys[1], ['minutes' => 40, 'uses_business_time' => true, 'is_active' => true, 'expires_at' => '2026-08-01 00:00:00'], new DateTimeImmutable('2026-08-05 12:00:00'));
        $this->pdo->prepare('DELETE FROM epi_employee_grace_periods WHERE grace_key IN (?,?)')->execute($keys);
        return ['global' => $global, 'module' => $module, 'record' => $record, 'expired_record' => $expired,
            'passed' => (int) $global['minutes'] === 10 && (int) $module['minutes'] === 20 && (int) $record['minutes'] === 30 && (int) $expired['minutes'] === 20];
    }

    private function urgentNotificationTest(string $runId, int $employeeId): array
    {
        if (!function_exists('notifications_create')) {
            return ['created' => false, 'reason' => 'Notification service was unavailable.'];
        }
        $deduplicationKey = Support::dedupe([$runId, 'urgent-notification']);
        $id = \notifications_create([
            'title' => 'TEST DATA - Urgent notification verification',
            'message' => 'Controlled Recovery Step 1B notification. This record is test-only and excluded from scoring.',
            'module' => 'system',
            'priority' => 'urgent',
            'deadline_state' => 'overdue',
            'sound_key' => 'urgent',
            'scheduled_at' => gmdate('Y-m-d H:i:s'),
            'deduplication_key' => $deduplicationKey,
            'action_link' => '/apps/operations/my-account.php?section=portal',
            'required_delivery' => true,
        ], [$employeeId]);
        return ['created' => $id !== null, 'notification_id' => $id, 'deduplication_key' => $deduplicationKey];
    }

    private function failureTest(string $runId): array
    {
        $correlation = 'EPI-TEST-' . strtoupper(substr(hash('sha256', $runId), 0, 16));
        try {
            throw new RuntimeException('Controlled test-only EPI recording failure.');
        } catch (Throwable $error) {
            $context = ['module' => 'Orders', 'action' => 'controlled_failure', 'reference' => $runId . '-FAILURE', 'correlation_id' => $correlation, 'retriable' => true, 'test_data' => true, 'sensitive_details_hidden' => true];
            $stmt = $this->pdo->prepare('INSERT INTO epi_performance_logs(level,component,message,context_json) VALUES(?,?,?,?)');
            $stmt->execute(['error', 'recovery_verifier', 'Controlled EPI test failure was caught; operational action remained safe.', Support::json($context)]);
            $id = (int) $this->pdo->lastInsertId();
            $lookup = $this->pdo->prepare('SELECT id,level,component,message,context_json,created_at FROM epi_performance_logs WHERE id=?');
            $lookup->execute([$id]);
            return ['log' => $lookup->fetch(PDO::FETCH_ASSOC), 'correlation_id' => $correlation, 'operational_action_safe' => true, 'recovery' => 'Failure trigger ended with this request; no production hook installed.'];
        }
    }
}
