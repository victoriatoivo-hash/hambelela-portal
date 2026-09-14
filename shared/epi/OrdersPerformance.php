<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateInterval;
use DateTimeImmutable;
use PDO;

/** Read-only Front Desk analytics. Every calculation is based on EPI evidence. */
final class OrdersPerformance
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getSummary(array $filters = []): array
    {
        list($from, $to) = $this->period($filters);
        $rows = $this->evidenceRows($filters, $from, $to, 10000);
        $latest = [];
        $counts = ['collection' => 0, 'delivery' => 0, 'courier' => 0, 'walk_in' => 0, 'unknown' => 0];
        $completed = $reopened = $late = $paymentVerified = $paymentCorrected = $communications = 0;
        $completionMinutes = [];
        $walkInCompleted = $walkInOverdue = 0;

        foreach ($rows as &$row) {
            $row['metadata'] = $this->metadata($row);
            $reference = (string) $row['reference_number'];
            if (!isset($latest[$reference])) $latest[$reference] = $row;
            if ($row['action'] === 'order_completed') {
                $completed++;
                $type = $this->rowType($row);
                if (!isset($counts[$type])) $type = 'unknown';
                $counts[$type]++;
                if (!empty($row['metadata']['is_walk_in'])) {
                    $walkInCompleted++;
                    if (!empty($row['metadata']['overdue'])) $walkInOverdue++;
                }
                if ($row['working_minutes'] !== null) $completionMinutes[] = (float) $row['working_minutes'];
            }
            if ($row['action'] === 'order_reopened') $reopened++;
            if ($row['action'] === 'deduction_candidate_late_completion') $late++;
            if ($row['action'] === 'payment_verified') $paymentVerified++;
            if ($row['action'] === 'payment_corrected') $paymentCorrected++;
            if (in_array($row['action'], ['customer_communication_recorded', 'customer_contact_updated'], true)) $communications++;
        }
        unset($row);

        $outstanding = $pending = $overdue = 0;
        $oldestWalkIn = null;
        $now = new DateTimeImmutable('now');
        foreach ($latest as $row) {
            $status = strtolower((string) (($row['status_after'] ?? '') ?: ($row['metadata']['order_status'] ?? '')));
            if (!in_array($status, ['completed', 'packed', 'verified', 'cancelled', 'canceled', 'refunded'], true)) {
                $outstanding++;
                if (in_array($status, ['new_order', 'new', 'assigned', 'pending', ''], true)) $pending++;
                $due = !empty($row['metadata']['due_at']) ? new DateTimeImmutable((string) $row['metadata']['due_at']) : null;
                if ($due && $now > $due) $overdue++;
                if (!empty($row['metadata']['is_walk_in']) && ($oldestWalkIn === null || $row['occurred_at'] < $oldestWalkIn['occurred_at'])) $oldestWalkIn = $row;
            }
        }

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'employee_id' => isset($filters['employee_id']) && $filters['employee_id'] !== '' ? (int) $filters['employee_id'] : null,
            'orders_completed' => $completed, 'orders_outstanding' => $outstanding, 'orders_pending' => $pending,
            'orders_overdue' => $overdue, 'orders_reopened' => $reopened, 'late_orders' => $late,
            'average_completion_minutes' => $completionMinutes ? round(array_sum($completionMinutes) / count($completionMinutes), 2) : null,
            'fastest_completion_minutes' => $completionMinutes ? min($completionMinutes) : null,
            'slowest_completion_minutes' => $completionMinutes ? max($completionMinutes) : null,
            'order_types' => $counts,
            'walk_ins' => ['completed' => $walkInCompleted, 'overdue' => $walkInOverdue, 'oldest_outstanding' => $oldestWalkIn],
            'payment' => ['verified' => $paymentVerified, 'corrections' => $paymentCorrected],
            'customer_communication_events' => $communications,
            'evidence_count' => count($rows),
            'activity_count' => $this->activityCount($filters, $from, $to),
            'deduction_candidates' => count(array_filter($rows, static function (array $row): bool { return strpos((string) $row['action'], 'deduction_candidate_') === 0; })),
            'bonus_candidates' => count(array_filter($rows, static function (array $row): bool { return strpos((string) $row['action'], 'bonus_candidate_') === 0; })),
            'scoring_status' => 'not_calculated',
        ];
    }

    public function getEmployee(int $employeeId, array $filters = []): array
    {
        $filters['employee_id'] = $employeeId;
        $summary = $this->getSummary($filters);
        $stmt = $this->pdo->prepare('SELECT e.id,e.full_name,e.email,r.role_key,r.name role_name FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1');
        $stmt->execute([$employeeId]);
        return ['employee' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null, 'summary' => $summary];
    }

    public function getEvidence(array $filters = [], int $limit = 250): array
    {
        list($from, $to) = $this->period($filters);
        return $this->evidenceRows($filters, $from, $to, $limit);
    }

    public function getWalkIns(array $filters = [], int $limit = 250): array
    {
        return array_values(array_filter($this->getEvidence($filters, $limit), function (array $row): bool {
            return !empty($this->metadata($row)['is_walk_in']);
        }));
    }

    public function getOutstanding(array $filters = [], int $limit = 250): array
    {
        $latest = [];
        foreach ($this->getEvidence($filters, min(1000, $limit * 5)) as $row) if (!isset($latest[$row['reference_number']])) $latest[$row['reference_number']] = $row;
        return array_values(array_filter($latest, static function (array $row): bool {
            $status = strtolower((string) ($row['status_after'] ?? ''));
            return !in_array($status, ['completed', 'packed', 'verified', 'cancelled', 'canceled', 'refunded'], true);
        }));
    }

    public function employeeOptions(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT e.id,e.full_name FROM ops_employees e JOIN epi_employee_evidence ev ON ev.employee_id=e.id AND ev.module='Orders' LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND COALESCE(r.role_key,'') NOT IN ('owner_admin','accountant') AND LOWER(CONCAT_WS(' ',e.full_name,e.email,COALESCE(r.role_key,''))) NOT REGEXP 'karina|kaarina|test|preview' ORDER BY e.full_name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function evidenceRows(array $filters, DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        $where = ["module='Orders'", 'occurred_at >= ?', 'occurred_at < ?'];
        $params = [$from->format('Y-m-d 00:00:00'), $to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];
        if (isset($filters['employee_id']) && $filters['employee_id'] !== '') { $where[] = 'employee_id=?'; $params[] = (int) $filters['employee_id']; }
        if (!empty($filters['action'])) { $where[] = 'action=?'; $params[] = (string) $filters['action']; }
        $limit = max(1, min(10000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE ' . implode(' AND ', $where) . ' ORDER BY occurred_at DESC,id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function activityCount(array $filters, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $where = ["module='Orders'", 'occurred_at>=?', 'occurred_at<?'];
        $params = [$from->format('Y-m-d 00:00:00'), $to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];
        if (isset($filters['employee_id']) && $filters['employee_id'] !== '') { $where[] = 'employee_id=?'; $params[] = (int) $filters['employee_id']; }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM epi_employee_activity WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function period(array $filters): array
    {
        $today = new DateTimeImmutable('today');
        $key = (string) ($filters['period'] ?? 'previous_month');
        if ($key === 'custom' && !empty($filters['date_from']) && !empty($filters['date_to'])) return [new DateTimeImmutable($filters['date_from']), new DateTimeImmutable($filters['date_to'])];
        $map = [
            'today' => [$today, $today], 'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'this_week' => [$today->modify('monday this week'), $today],
            'last_week' => [$today->modify('monday last week'), $today->modify('sunday last week')],
            'this_month' => [$today->modify('first day of this month'), $today],
            'previous_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'quarter' => [$today->modify('-' . (((int) $today->format('n') - 1) % 3) . ' months')->modify('first day of this month'), $today],
            'year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
        ];
        return $map[$key] ?? $map['previous_month'];
    }

    private function metadata(array $row): array
    {
        $decoded = json_decode((string) ($row['metadata_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function rowType(array $row): string
    {
        if (!empty($row['metadata']['is_walk_in'])) return 'walk_in';
        return strtolower((string) ($row['metadata']['order_type'] ?? 'unknown'));
    }
}
