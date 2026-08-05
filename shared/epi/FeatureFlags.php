<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use Throwable;

final class FeatureFlags
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isEnabled(string $key = 'epi_enabled'): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        } catch (Throwable $error) {
            return false;
        }
    }

    public function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description, updated_by)
             VALUES ('epi_enabled', ?, 'boolean', 'Master feature flag for background EPI recording.', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
        );
        $stmt->execute([$enabled ? '1' : '0', $updatedBy]);
    }
}
