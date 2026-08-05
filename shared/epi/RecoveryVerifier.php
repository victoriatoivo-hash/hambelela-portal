<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use RuntimeException;

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
        $modules = ['Orders', 'Packing List', 'Tasks', 'Courier', 'Bookkeeping', 'Attendance'];
        $evidence = [];
        $activity = [];
        foreach ($modules as $module) {
            $key = Support::dedupe([$runId, $module, 'controlled_test']);
            $base = [
                'module' => $module,
                'reference_number' => $runId . '-' . strtoupper(str_replace(' ', '-', $module)),
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'department' => 'TEST DATA',
                'recording_mode' => 'test',
                'activity_source' => 'epi_recovery_step_1',
                'metadata' => ['test_data' => true, 'excluded_from_scoring' => true, 'test_run_id' => $runId],
            ];
            $evidenceId = Performance::recordEvidence($base + [
                'action' => 'controlled_test_event',
                'action_description' => 'TEST DATA — controlled Recovery Step 1 verification event.',
                'deduplication_key' => $key,
                'verified' => false,
            ]);
            $duplicateId = Performance::recordEvidence($base + [
                'action' => 'controlled_test_event',
                'action_description' => 'TEST DATA — duplicate replay.',
                'deduplication_key' => $key,
                'verified' => false,
            ]);
            $activityId = Performance::recordActivity($base + [
                'activity_type' => 'controlled_test_activity',
                'description' => 'TEST DATA — controlled activity verification.',
                'deduplication_key' => Support::dedupe([$runId, $module, 'activity']),
            ]);
            $evidence[$module] = ['first' => $evidenceId, 'duplicate' => $duplicateId, 'deduplicated' => $evidenceId !== null && $evidenceId === $duplicateId];
            $activity[$module] = $activityId;
        }

        $ownershipReference = $runId . '-OWNERSHIP';
        $ownership = Performance::recordOwnership([
            'module' => 'Orders', 'reference_number' => $ownershipReference,
            'original_owner_id' => $employeeId, 'original_owner_name' => $employeeName,
            'current_owner_id' => $employeeId, 'current_owner_name' => $employeeName,
            'changed_by' => $employeeId, 'change_reason' => 'TEST DATA — Recovery Step 1 ownership verification.',
            'recording_mode' => 'test', 'metadata' => ['test_data' => true, 'excluded_from_scoring' => true],
        ]);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM epi_employee_evidence WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.test_run_id')) = ?");
        $count->execute([$runId]);
        $activityCount = $this->pdo->prepare("SELECT COUNT(*) FROM epi_employee_activity WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.test_run_id')) = ?");
        $activityCount->execute([$runId]);

        return [
            'run_id' => $runId,
            'modules' => $modules,
            'evidence' => $evidence,
            'activity' => $activity,
            'evidence_rows' => (int) $count->fetchColumn(),
            'activity_rows' => (int) $activityCount->fetchColumn(),
            'ownership_uuid' => $ownership,
            'business_minutes' => Performance::businessMinutes('2026-08-03 08:00:00', '2026-08-03 17:00:00'),
            'test_data' => true,
            'excluded_from_scoring' => true,
        ];
    }
}
