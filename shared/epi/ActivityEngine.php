<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;
use PDO;
use Throwable;

final class ActivityEngine
{
    private $pdo;
    private $flags;

    public function __construct(PDO $pdo, FeatureFlags $flags)
    {
        $this->pdo = $pdo;
        $this->flags = $flags;
    }

    public function record(array $activity): ?string
    {
        if (!$this->flags->allowsRecording($activity)) {
            return null;
        }
        $module = Support::requireModule((string) ($activity['module'] ?? ''));
        $type = trim((string) ($activity['activity_type'] ?? $activity['action'] ?? ''));
        $source = trim((string) ($activity['activity_source'] ?? ''));
        if ($type === '' || $source === '') {
            throw new InvalidArgumentException('EPI activity requires activity_type and activity_source.');
        }
        $occurredAt = Support::timestamp($activity['timestamp'] ?? null);
        $uuid = (string) ($activity['activity_uuid'] ?? Support::uuid());
        $dedupe = (string) ($activity['deduplication_key'] ?? Support::dedupe([
            $module, $activity['reference_number'] ?? '', $activity['employee_id'] ?? '', $type,
            $occurredAt->format('Y-m-d H:i:s'), $source,
        ]));
        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO epi_employee_activity
                (activity_uuid, deduplication_key, employee_id, employee_name, department, module, reference_number,
                 activity_type, description, activity_source, recording_mode, occurred_at, business_date, metadata_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $uuid, $dedupe, $activity['employee_id'] ?? null, $activity['employee_name'] ?? null,
                $activity['department'] ?? null, $module, $activity['reference_number'] ?? null, $type,
                $activity['description'] ?? null, $source,
                !empty($activity['manual']) ? 'manual' : ($activity['recording_mode'] ?? 'automatic'),
                $occurredAt->format('Y-m-d H:i:s'), $activity['business_date'] ?? $occurredAt->format('Y-m-d'),
                Support::json($activity['metadata'] ?? null),
            ]);
            if ($stmt->rowCount() > 0) {
                return $uuid;
            }
            $lookup = $this->pdo->prepare('SELECT activity_uuid FROM epi_employee_activity WHERE deduplication_key = ? LIMIT 1');
            $lookup->execute([$dedupe]);
            return ($existing = $lookup->fetchColumn()) ? (string) $existing : null;
        } catch (Throwable $error) {
            error_log('EPI activity recording failed: ' . $error->getMessage());
            try {
                $stmt = $this->pdo->prepare('INSERT INTO epi_performance_logs (level, component, message, context_json) VALUES (?,?,?,?)');
                $stmt->execute(['error', 'activity', $error->getMessage(), Support::json(['module' => $module, 'activity_type' => $type])]);
            } catch (Throwable $ignored) {
            }
            return null;
        }
    }

    public function timeline(array $filters = [], int $limit = 200): array
    {
        $where = ['1=1'];
        $params = [];
        foreach (['employee_id', 'module', 'reference_number', 'activity_type', 'business_date'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== '') {
                $where[] = $field . ' = ?';
                $params[] = $filters[$field];
            }
        }
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_activity WHERE ' . implode(' AND ', $where) . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }
}
