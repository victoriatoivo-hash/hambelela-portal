<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use InvalidArgumentException;
use PDO;
use Throwable;

final class OwnershipEngine
{
    private $pdo;
    private $flags;

    public function __construct(PDO $pdo, FeatureFlags $flags)
    {
        $this->pdo = $pdo;
        $this->flags = $flags;
    }

    public function record(array $ownership): ?string
    {
        if (!$this->flags->allowsRecording($ownership)) {
            return null;
        }
        $module = Support::requireModule((string) ($ownership['module'] ?? ''));
        $reference = trim((string) ($ownership['reference_number'] ?? ''));
        if ($reference === '') {
            throw new InvalidArgumentException('EPI ownership requires reference_number.');
        }
        $previous = $this->current($module, $reference);
        $uuid = Support::uuid();
        $effective = Support::timestamp($ownership['effective_at'] ?? null);
        try {
        $stmt = $this->pdo->prepare(
            'INSERT INTO epi_employee_ownership_history
             (ownership_uuid, module, reference_number, original_owner_id, original_owner_name,
              current_owner_id, current_owner_name, completed_by_id, completed_by_name,
              verified_by_id, verified_by_name, change_reason, changed_by, effective_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $uuid, $module, $reference,
            $previous['original_owner_id'] ?? $ownership['original_owner_id'] ?? $ownership['current_owner_id'] ?? null,
            $previous['original_owner_name'] ?? $ownership['original_owner_name'] ?? $ownership['current_owner_name'] ?? null,
            $ownership['current_owner_id'] ?? $previous['current_owner_id'] ?? null,
            $ownership['current_owner_name'] ?? $previous['current_owner_name'] ?? null,
            $ownership['completed_by_id'] ?? $previous['completed_by_id'] ?? null,
            $ownership['completed_by_name'] ?? $previous['completed_by_name'] ?? null,
            $ownership['verified_by_id'] ?? $previous['verified_by_id'] ?? null,
            $ownership['verified_by_name'] ?? $previous['verified_by_name'] ?? null,
            $ownership['change_reason'] ?? null, $ownership['changed_by'] ?? null,
            $effective->format('Y-m-d H:i:s'),
        ]);
        return $uuid;
        } catch (Throwable $error) {
            error_log('EPI ownership recording failed: ' . $error->getMessage());
            try {
                $stmt = $this->pdo->prepare('INSERT INTO epi_performance_logs (level, component, message, context_json) VALUES (?,?,?,?)');
                $stmt->execute(['error', 'ownership', $error->getMessage(), Support::json(['module' => $module, 'reference' => $reference])]);
            } catch (Throwable $ignored) {
            }
            return null;
        }
    }

    public function current(string $module, string $reference): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_ownership_history WHERE module = ? AND reference_number = ? ORDER BY effective_at DESC, id DESC LIMIT 1');
        $stmt->execute([$module, $reference]);
        return $stmt->fetch() ?: null;
    }

    public function history(string $module, string $reference): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_ownership_history WHERE module = ? AND reference_number = ? ORDER BY effective_at, id');
        $stmt->execute([$module, $reference]);
        return $stmt->fetchAll() ?: [];
    }
}
