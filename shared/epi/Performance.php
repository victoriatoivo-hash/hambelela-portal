<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use RuntimeException;

final class Performance
{
    private static $pdo;
    private static $flags;
    private static $businessTime;
    private static $evidence;
    private static $activity;
    private static $ownership;
    private static $grace;
    private static $engine;

    public static function configure(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$flags = new FeatureFlags($pdo);
        self::$businessTime = new BusinessTimeEngine($pdo);
        self::$evidence = new EvidenceEngine($pdo, self::$flags, self::$businessTime);
        self::$activity = new ActivityEngine($pdo, self::$flags);
        self::$ownership = new OwnershipEngine($pdo, self::$flags);
        self::$grace = new GracePeriodEngine($pdo, self::$businessTime);
        self::$engine = new PerformanceEngine(self::$evidence);
    }

    public static function enabled(): bool
    {
        self::ready();
        return self::$flags->isEnabled();
    }

    public static function recordEvidence(array $evidence): ?string
    {
        self::ready();
        return self::$engine->receive($evidence);
    }

    public static function recordActivity(array $activity): ?string
    {
        self::ready();
        return self::$activity->record($activity);
    }

    public static function recordOwnership(array $ownership): ?string
    {
        self::ready();
        return self::$ownership->record($ownership);
    }

    public static function getEvidence(array $filters = [], int $limit = 200): array
    {
        self::ready();
        return self::$evidence->get($filters, $limit);
    }

    public static function getTimeline(array $filters = [], int $limit = 200): array
    {
        self::ready();
        return self::$activity->timeline($filters, $limit);
    }

    public static function getEmployee(int $employeeId): ?array
    {
        self::ready();
        $stmt = self::$pdo->prepare(
            'SELECT e.id, e.full_name, e.email, e.status, r.role_key, r.name AS role_name
             FROM ops_employees e LEFT JOIN ops_roles r ON r.id = e.role_id WHERE e.id = ? LIMIT 1'
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetch() ?: null;
    }

    public static function getDepartment(string $departmentKey): ?array
    {
        self::ready();
        $stmt = self::$pdo->prepare('SELECT * FROM epi_employee_departments WHERE department_key = ? LIMIT 1');
        $stmt->execute([$departmentKey]);
        return $stmt->fetch() ?: null;
    }

    public static function businessMinutes($start, $end): float
    {
        self::ready();
        return self::$businessTime->workingMinutes($start, $end);
    }

    public static function gracePeriod(string $graceKey): array
    {
        self::ready();
        return self::$grace->get($graceKey);
    }

    public static function graceDueAt(string $graceKey, $start): \DateTimeImmutable
    {
        self::ready();
        return self::$grace->dueAt($graceKey, $start);
    }

    private static function ready(): void
    {
        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('EPI Performance API is not configured. Call Performance::configure($pdo).');
        }
    }
}
