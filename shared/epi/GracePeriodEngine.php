<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class GracePeriodEngine
{
    private $pdo;
    private $businessTime;

    public function __construct(PDO $pdo, BusinessTimeEngine $businessTime)
    {
        $this->pdo = $pdo;
        $this->businessTime = $businessTime;
    }

    public function get(string $graceKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_grace_periods WHERE grace_key = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$graceKey]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Unknown or disabled EPI grace period: ' . $graceKey);
        }
        return $row;
    }

    public function dueAt(string $graceKey, $start): DateTimeImmutable
    {
        $grace = $this->get($graceKey);
        $minutes = (int) $grace['minutes'];
        if ((bool) $grace['uses_business_time']) {
            return $this->businessTime->addWorkingMinutes($start, $minutes);
        }
        return Support::timestamp($start)->modify('+' . $minutes . ' minutes');
    }

    public function resolve(string $globalKey, ?string $moduleKey = null, ?array $recordOverride = null, ?DateTimeImmutable $at = null): array
    {
        $at = $at ?: new DateTimeImmutable('now');
        if ($recordOverride && !empty($recordOverride['is_active'])) {
            $expires = !empty($recordOverride['expires_at']) ? new DateTimeImmutable((string) $recordOverride['expires_at']) : null;
            if ($expires === null || $expires > $at) {
                return $recordOverride + ['source' => 'record'];
            }
        }
        if ($moduleKey !== null) {
            try {
                return $this->get($moduleKey) + ['source' => 'module'];
            } catch (RuntimeException $ignored) {
            }
        }
        return $this->get($globalKey) + ['source' => 'global'];
    }
}
