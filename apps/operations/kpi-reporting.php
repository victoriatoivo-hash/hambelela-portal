<?php

declare(strict_types=1);

/**
 * Resolve one reporting period for every KPI endpoint.
 *
 * URL/query parameters are authoritative. Callers must not independently
 * reinterpret the period because that is how initial render and AJAX totals
 * previously drifted apart.
 *
 * @return array{key:string,from:DateTimeImmutable,to:DateTimeImmutable}
 */
function kpi_resolve_reporting_period(array $input, ?DateTimeImmutable $today = null): array
{
    $zone = new DateTimeZone('Africa/Windhoek');
    $today = ($today ?? new DateTimeImmutable('today', $zone))->setTimezone($zone)->setTime(0, 0);
    $key = strtolower(trim((string) ($input['period'] ?? 'today')));

    switch ($key) {
        case 'yesterday':
            $from = $today->modify('-1 day');
            $to = $from;
            break;
        case 'this_week':
            $from = $today->modify('monday this week');
            $to = $today;
            break;
        case 'last_week':
            $from = $today->modify('monday last week');
            $to = $from->modify('+6 days');
            break;
        case 'this_month':
            $from = $today->modify('first day of this month');
            $to = $today;
            break;
        case 'last_month':
            $from = $today->modify('first day of last month');
            $to = $from->modify('last day of this month');
            break;
        case 'custom':
            $customFrom = trim((string) ($input['date_from'] ?? ''));
            $customTo = trim((string) ($input['date_to'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) {
                throw new RuntimeException('Choose valid custom dates.');
            }
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', $customFrom, $zone);
            $fromErrors = DateTimeImmutable::getLastErrors();
            if (!$from || ($fromErrors !== false && ($fromErrors['warning_count'] > 0 || $fromErrors['error_count'] > 0)) || $from->format('Y-m-d') !== $customFrom) {
                throw new RuntimeException('Choose a valid start date.');
            }
            $to = DateTimeImmutable::createFromFormat('!Y-m-d', $customTo, $zone);
            $toErrors = DateTimeImmutable::getLastErrors();
            if (!$to || ($toErrors !== false && ($toErrors['warning_count'] > 0 || $toErrors['error_count'] > 0)) || $to->format('Y-m-d') !== $customTo) {
                throw new RuntimeException('Choose a valid end date.');
            }
            break;
        default:
            $key = 'today';
            $from = $today;
            $to = $today;
    }

    if ($to < $from) {
        throw new RuntimeException('The end date must be on or after the start date.');
    }

    return ['key' => $key, 'from' => $from, 'to' => $to];
}

function kpi_paid_revenue_condition(string $alias = 'o'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . "payment_status = 'paid'"
        . ' AND ' . $prefix . "status NOT IN ('cancelled','canceled','refunded','failed','error_logged')"
        . ' AND ' . $prefix . "payment_status NOT IN ('refunded','cancelled','canceled','failed')";
}

function kpi_period_response(array $period, DateTimeImmutable $adoption, ?DateTimeImmutable $effectiveFrom = null): array
{
    /** @var DateTimeImmutable $from */
    $from = $period['from'];
    /** @var DateTimeImmutable $to */
    $to = $period['to'];
    return [
        'key' => (string) $period['key'],
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'effective_from' => ($effectiveFrom ?? $from)->format('Y-m-d'),
        'adoption_date' => $adoption->format('Y-m-d'),
        'show_adoption_banner' => $from < $adoption,
    ];
}

/**
 * Build the evidence envelope used by KPI cards and drill-downs.
 */
function kpi_metric(
    mixed $value,
    string $unit,
    ?int $numerator,
    ?int $denominator,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $source,
    ?string $status = null
): array {
    $coverage = $denominator !== null && $denominator > 0 && $numerator !== null
        ? round(($numerator / $denominator) * 100, 1)
        : null;
    return [
        'value' => $value,
        'unit' => $unit,
        'numerator' => $numerator,
        'denominator' => $denominator,
        'coverage_percent' => $coverage,
        'period_start' => $from->format('Y-m-d'),
        'period_end' => $to->format('Y-m-d'),
        'source' => $source,
        'status' => $status ?? ($value === null ? 'unmeasured' : (($coverage !== null && $coverage < 100) ? 'partial_data' : 'ok')),
    ];
}

/**
 * Convert raw presence rows into non-overlapping daily intervals. Overlapping
 * sessions and reconnects within the heartbeat grace window count once.
 *
 * @param array<int,array<string,mixed>> $rawRows
 * @return array<int,array<string,mixed>>
 */
function kpi_merge_presence_rows(array $rawRows, int $mergeGapSeconds = 90): array
{
    $utc = new DateTimeZone('UTC');
    $local = new DateTimeZone('Africa/Windhoek');
    $grouped = [];
    foreach ($rawRows as $row) {
        $startRaw = trim((string) ($row['login_at'] ?? ''));
        $endRaw = trim((string) ($row['logout_at'] ?? $row['last_seen_at'] ?? ''));
        if ($startRaw === '' || $endRaw === '') {
            continue;
        }
        try {
            $start = new DateTimeImmutable($startRaw, $utc);
            $end = new DateTimeImmutable($endRaw, $utc);
        } catch (Throwable) {
            continue;
        }
        if ($end <= $start) {
            continue;
        }
        $startLocal = $start->setTimezone($local);
        $endLocal = $end->setTimezone($local);
        $employeeId = (int) ($row['user_id'] ?? 0);
        $day = $startLocal->format('Y-m-d');
        $key = $employeeId . '|' . $day;
        $grouped[$key]['employee'] = (string) ($row['employee'] ?? '');
        $grouped[$key]['user_id'] = $employeeId;
        $grouped[$key]['day'] = $day;
        $grouped[$key]['intervals'][] = [$startLocal, $endLocal];
    }

    $result = [];
    foreach ($grouped as $group) {
        $intervals = $group['intervals'];
        usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $start->getTimestamp() <= $merged[$lastIndex][1]->getTimestamp() + $mergeGapSeconds) {
                if ($end > $merged[$lastIndex][1]) {
                    $merged[$lastIndex][1] = $end;
                }
                continue;
            }
            $merged[] = [$start, $end];
        }
        $seconds = 0;
        foreach ($merged as [$start, $end]) {
            $seconds += $end->getTimestamp() - $start->getTimestamp();
        }
        $result[] = [
            'user_id' => $group['user_id'],
            'employee' => $group['employee'],
            'day' => $group['day'],
            'first_login' => $merged[0][0]->format('Y-m-d H:i:s'),
            'last_activity' => $merged[count($merged) - 1][1]->format('Y-m-d H:i:s'),
            'portal_active_hours' => round($seconds / 3600, 2),
            'sessions' => count($intervals),
            'merged_intervals' => count($merged),
        ];
    }
    usort($result, static fn(array $a, array $b): int => [$b['day'], $a['employee']] <=> [$a['day'], $b['employee']]);
    return $result;
}

/** Calculate elapsed minutes falling inside configured company handling hours. */
function kpi_business_minutes(DateTimeImmutable $start, DateTimeImmutable $end, array $holidays = []): int
{
    if ($end <= $start) {
        return 0;
    }
    $zone = new DateTimeZone('Africa/Windhoek');
    $start = $start->setTimezone($zone);
    $end = $end->setTimezone($zone);
    $holidayMap = array_fill_keys(array_map('strval', $holidays), true);
    $cursor = $start->setTime(0, 0);
    $lastDay = $end->setTime(0, 0);
    $seconds = 0;
    while ($cursor <= $lastDay) {
        $date = $cursor->format('Y-m-d');
        $weekday = (int) $cursor->format('N');
        if (!isset($holidayMap[$date]) && $weekday <= 6) {
            [$openHour, $closeHour] = $weekday === 6 ? [9, 13] : [8, 17];
            $open = $cursor->setTime($openHour, 0);
            $close = $cursor->setTime($closeHour, 0);
            $rangeStart = $start > $open ? $start : $open;
            $rangeEnd = $end < $close ? $end : $close;
            if ($rangeEnd > $rangeStart) {
                $seconds += $rangeEnd->getTimestamp() - $rangeStart->getTimestamp();
            }
        }
        $cursor = $cursor->modify('+1 day');
    }
    return (int) floor($seconds / 60);
}
