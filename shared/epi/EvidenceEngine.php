<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;
use PDO;
use Throwable;

final class EvidenceEngine
{
    private $pdo;
    private $flags;
    private $businessTime;

    public function __construct(PDO $pdo, FeatureFlags $flags, BusinessTimeEngine $businessTime)
    {
        $this->pdo = $pdo;
        $this->flags = $flags;
        $this->businessTime = $businessTime;
    }

    public function record(array $evidence): ?string
    {
        if (!$this->flags->isEnabled()) {
            return null;
        }
        $module = Support::requireModule((string) ($evidence['module'] ?? ''));
        $reference = trim((string) ($evidence['reference_number'] ?? ''));
        $action = trim((string) ($evidence['action'] ?? ''));
        $source = trim((string) ($evidence['activity_source'] ?? ''));
        if ($reference === '' || $action === '' || $source === '') {
            throw new InvalidArgumentException('EPI evidence requires module, reference_number, action and activity_source.');
        }

        $occurredAt = Support::timestamp($evidence['timestamp'] ?? null);
        $uuid = (string) ($evidence['evidence_uuid'] ?? Support::uuid());
        $dedupe = (string) ($evidence['deduplication_key'] ?? Support::dedupe([
            $module, $reference, $evidence['employee_id'] ?? '', $action,
            $occurredAt->format('Y-m-d H:i:s'), $evidence['previous_value'] ?? '', $evidence['new_value'] ?? '', $source,
        ]));
        $workingMinutes = $evidence['working_minutes'] ?? null;
        if ($workingMinutes === null && !empty($evidence['duration_start']) && !empty($evidence['duration_end'])) {
            $workingMinutes = $this->businessTime->workingMinutes($evidence['duration_start'], $evidence['duration_end']);
        }

        $sql = "INSERT IGNORE INTO epi_employee_evidence
            (evidence_uuid, deduplication_key, module, reference_number, employee_id, employee_name, department,
             action, action_description, previous_value, new_value, status_before, status_after, priority,
             occurred_at, business_date, working_minutes, duration_seconds, recording_mode, activity_source,
             financial_impact, score_impact, verified, verified_by, metadata_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $uuid, $dedupe, $module, $reference, $evidence['employee_id'] ?? null,
                $evidence['employee_name'] ?? null, $evidence['department'] ?? null, $action,
                $evidence['action_description'] ?? null, Support::json($evidence['previous_value'] ?? null),
                Support::json($evidence['new_value'] ?? null), $evidence['status_before'] ?? null,
                $evidence['status_after'] ?? null, $evidence['priority'] ?? null,
                $occurredAt->format('Y-m-d H:i:s'), (string) ($evidence['business_date'] ?? $occurredAt->format('Y-m-d')),
                $workingMinutes, $evidence['duration'] ?? $evidence['duration_seconds'] ?? null,
                !empty($evidence['manual']) ? 'manual' : ($evidence['recording_mode'] ?? 'automatic'), $source,
                $evidence['financial_impact'] ?? null, $evidence['score_impact'] ?? null,
                !empty($evidence['verified']) ? 1 : 0, $evidence['verified_by'] ?? null,
                Support::json($evidence['metadata'] ?? null),
            ]);
            if ($stmt->rowCount() > 0) {
                return $uuid;
            }
            $lookup = $this->pdo->prepare('SELECT evidence_uuid FROM epi_employee_evidence WHERE deduplication_key = ? LIMIT 1');
            $lookup->execute([$dedupe]);
            return ($existing = $lookup->fetchColumn()) ? (string) $existing : null;
        } catch (Throwable $error) {
            $this->logFailure('evidence', $error, ['module' => $module, 'reference' => $reference, 'action' => $action]);
            return null;
        }
    }

    public function get(array $filters = [], int $limit = 200): array
    {
        $where = ['1=1'];
        $params = [];
        foreach (['employee_id', 'module', 'reference_number', 'action', 'business_date'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== '') {
                $where[] = $field . ' = ?';
                $params[] = $filters[$field];
            }
        }
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE ' . implode(' AND ', $where) . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    private function logFailure(string $component, Throwable $error, array $context): void
    {
        error_log('EPI ' . $component . ' recording failed: ' . $error->getMessage());
        try {
            $stmt = $this->pdo->prepare('INSERT INTO epi_performance_logs (level, component, message, context_json) VALUES (?,?,?,?)');
            $stmt->execute(['error', $component, $error->getMessage(), Support::json($context)]);
        } catch (Throwable $ignored) {
        }
    }
}
