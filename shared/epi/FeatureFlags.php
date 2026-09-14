<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use Throwable;

final class FeatureFlags
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_TEST = 'test';
    public const MODE_ENABLED = 'enabled';
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isEnabled(string $key = 'epi_enabled'): bool
    {
        if ($key === 'epi_enabled') {
            return $this->mode() === self::MODE_ENABLED;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        } catch (Throwable $error) {
            return false;
        }
    }

    public function mode(): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key = 'epi_mode' LIMIT 1");
            $stmt->execute();
            $mode = strtolower(trim((string) $stmt->fetchColumn()));
            if (in_array($mode, [self::MODE_DISABLED, self::MODE_TEST, self::MODE_ENABLED], true)) {
                return $mode;
            }
        } catch (Throwable $error) {
        }

        return self::MODE_DISABLED;
    }

    public function allowsRecording(array $payload): bool
    {
        $mode = $this->mode();
        if ($mode === self::MODE_ENABLED) {
            return true;
        }
        if ($mode !== self::MODE_TEST) {
            return false;
        }
        $metadata = (array) ($payload['metadata'] ?? []);
        return ($payload['recording_mode'] ?? '') === 'test' && !empty($metadata['test_data']);
    }

    public function setMode(string $mode, ?int $updatedBy, string $reason): void
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_DISABLED, self::MODE_TEST], true)) {
            throw new \InvalidArgumentException('Production EPI cannot be enabled during Recovery Step 1.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required for every EPI mode change.');
        }
        $previous = $this->mode();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description, updated_by)
                 VALUES ('epi_mode', ?, 'enum', 'disabled, test, or enabled EPI recording state.', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );
            $stmt->execute([$mode, $updatedBy]);
            $legacy = $this->pdo->prepare(
                "INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description, updated_by)
                 VALUES ('epi_enabled', '0', 'boolean', 'Legacy production-only EPI feature flag.', ?)
                 ON DUPLICATE KEY UPDATE setting_value = '0', updated_by = VALUES(updated_by)"
            );
            $legacy->execute([$updatedBy]);
            $log = $this->pdo->prepare('INSERT INTO epi_performance_logs (level, component, message, context_json) VALUES (?,?,?,?)');
            $log->execute(['info', 'feature_flag', 'EPI recording mode changed.', Support::json([
                'previous_mode' => $previous, 'new_mode' => $mode, 'changed_by' => $updatedBy, 'reason' => $reason,
            ])]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
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
