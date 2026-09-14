<?php
declare(strict_types=1);

require_once __DIR__ . '/kpi-event-reporting.php';

/** Accounts eligible for employee KPI tracking. */
function kpi_performance_employee_predicate(string $employeeAlias = 'e', string $roleAlias = 'r'): string
{
    return "{$employeeAlias}.status = 'active'"
        . " AND COALESCE({$roleAlias}.role_key, '') NOT IN ('owner_admin', 'accountant')"
        . " AND LOWER(CONCAT_WS(' ', COALESCE({$employeeAlias}.full_name, ''), COALESCE({$employeeAlias}.email, ''), COALESCE({$roleAlias}.role_key, ''))) NOT REGEXP 'karina|kaarina|test|preview'";
}

function kpi_performance_employee_eligible(array $employee): bool
{
    $role = strtolower(trim((string) ($employee['role_key'] ?? '')));
    if (($employee['status'] ?? 'active') !== 'active' || in_array($role, ['owner_admin', 'accountant'], true)) return false;
    $identity = strtolower(implode(' ', [(string) ($employee['full_name'] ?? ''), (string) ($employee['email'] ?? ''), $role]));
    return !preg_match('/karina|kaarina|test|preview/', $identity);
}

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
        case 'since_trusted':
            $trusted = trim((string) ($input['trusted_start_date'] ?? '2026-07-10'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trusted)) $trusted = '2026-07-10';
            $from = new DateTimeImmutable($trusted, $zone);
            $to = $today;
            break;
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
        case 'last_3_months':
            $from = $today->modify('first day of this month')->modify('-2 months');
            $to = $today;
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
    $value,
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
        } catch (Throwable $error) {
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
        $grouped[$key]['intervals'][] = [
            $startLocal,
            $endLocal,
            (string) ($row['end_reason'] ?? ''),
            empty($row['logout_at']),
        ];
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
        $activePeriods = [];
        $inactiveGaps = [];
        foreach ($merged as $periodIndex => [$start, $end]) {
            $seconds += $end->getTimestamp() - $start->getTimestamp();
            $activePeriods[] = ['start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),'minutes'=>round(($end->getTimestamp()-$start->getTimestamp())/60,1)];
            if ($periodIndex > 0) {
                $previousEnd = $merged[$periodIndex - 1][1];
                $inactiveGaps[] = ['start'=>$previousEnd->format('Y-m-d H:i:s'),'end'=>$start->format('Y-m-d H:i:s'),'minutes'=>round(($start->getTimestamp()-$previousEnd->getTimestamp())/60,1)];
            }
        }
        $result[] = [
            'user_id' => $group['user_id'],
            'employee' => $group['employee'],
            'day' => $group['day'],
            'first_login' => $merged[0][0]->format('Y-m-d H:i:s'),
            'last_activity' => $merged[count($merged) - 1][1]->format('Y-m-d H:i:s'),
            'session_end' => count(array_filter($intervals, static fn(array $interval): bool => !empty($interval[3])))
                ? null
                : $merged[count($merged) - 1][1]->format('Y-m-d H:i:s'),
            'session_end_reason' => count(array_filter($intervals, static fn(array $interval): bool => !empty($interval[3])))
                ? 'still_online'
                : ((count(array_filter($intervals, static fn(array $interval): bool => ($interval[2] ?? '') === 'explicit_logout')) > 0)
                    ? 'explicit_logout'
                    : 'inactive_expiry'),
            'currently_online' => count(array_filter($intervals, static fn(array $interval): bool => !empty($interval[3]))) > 0,
            'authenticated_session_hours' => round($seconds / 3600, 2),
            'portal_active_hours' => round($seconds / 3600, 2),
            'sessions' => count($intervals),
            'merged_intervals' => count($merged),
            'active_periods' => $activePeriods,
            'inactive_gaps' => $inactiveGaps,
            'explicit_logouts' => count(array_filter($intervals, static fn(array $interval): bool => ($interval[2] ?? '') === 'explicit_logout')),
            'inactive_expiries' => count(array_filter($intervals, static fn(array $interval): bool => ($interval[2] ?? '') === 'inactive_expiry')),
            'source' => 'kpi_sessions',
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

/** Encode KPI payloads without allowing invalid database text to produce an empty response. */
function kpi_encode_json(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
}

/** Send one complete JSON response for both successful and failed KPI requests. */
function kpi_send_json(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    if (!array_key_exists('ok', $payload)) $payload['ok'] = $status >= 200 && $status < 300;
    if (!array_key_exists('success', $payload)) $payload['success'] = (bool) $payload['ok'];
    // Technical KPI identifiers remain stable, while API messages use the
    // portal's current user-facing Employee Performance terminology.
    if (isset($payload['message']) && is_string($payload['message'])) {
        $payload['message'] = str_replace(
            ['KPI data', 'KPI section', 'your own KPI', 'KPI settings', 'KPI dates'],
            ['Performance data', 'performance section', 'your own performance', 'performance settings', 'reporting dates'],
            $payload['message']
        );
    }
    try {
        echo kpi_encode_json($payload);
    } catch (Throwable $error) {
        error_log(date(DATE_ATOM).' KPI JSON encoding failed: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
        http_response_code(500);
        echo '{"ok":false,"success":false,"data":null,"message":"Performance data could not be loaded.","error_code":"KPI_JSON_ENCODING_FAILED"}';
    }
    exit;
}

/**
 * Calculate a disclosed role score without converting missing evidence to zero.
 * Measured components are renormalised and the result remains provisional.
 */
function kpi_calculate_role_score(array $components): array
{
    $measuredWeight = 0.0;
    $weightedPoints = 0.0;
    $rows = [];
    foreach ($components as $component) {
        $applicable = !array_key_exists('applicable',$component) || (bool)$component['applicable'];
        $result = $applicable && isset($component['result']) && is_numeric($component['result'])
            ? max(0.0, min(100.0, (float) $component['result']))
            : null;
        $weight = $applicable ? max(0.0, (float) ($component['weight'] ?? 0)) : 0.0;
        $contribution = $result === null ? null : round($result * $weight / 100, 2);
        if ($result !== null) {
            $measuredWeight += $weight;
            $weightedPoints += (float) $contribution;
        }
        $rows[] = [
            'key' => (string) ($component['key'] ?? ''),
            'label' => (string) ($component['label'] ?? ''),
            'result' => $result,
            'weight' => $weight,
            'contribution' => $contribution,
            'status' => !$applicable ? 'not_applicable' : (string)($component['status'] ?? ($result === null ? 'system_evidence_unavailable' : 'measured')),
            'evidence' => (string) ($component['evidence'] ?? ''),
            'numerator' => $component['numerator'] ?? null,
            'denominator' => $component['denominator'] ?? null,
            'evidence_count' => (int)($component['evidence_count'] ?? ($component['denominator'] ?? 0)),
            'internal_calculation' => (string)($component['internal_calculation'] ?? ''),
        ];
    }
    $rawScore = $measuredWeight > 0 ? $weightedPoints * 100 / $measuredWeight : null;
    $score = $rawScore === null ? null : round($rawScore, 1);
    $band = $rawScore === null ? 'Not Measured' : ($rawScore >= 90 ? 'Gold' : ($rawScore >= 85 ? 'Silver' : ($rawScore >= 75 ? 'Bronze' : 'No Bonus')));
    $confidence=$measuredWeight>=90?'High confidence':($measuredWeight>=70?'Moderate confidence':($measuredWeight>=40?'Low confidence':'Insufficient evidence'));
    return [
        'score' => $score,
        'band' => $band,
        'measured_weight' => round($measuredWeight, 1),
        'provisional' => $measuredWeight < 100,
        'renormalised' => $measuredWeight > 0 && $measuredWeight < 100,
        'earned_points' => round($weightedPoints,2),
        'configured_weight' => round(array_sum(array_map(static function(array $component):float{return (!array_key_exists('applicable',$component)||(bool)$component['applicable'])?(float)($component['weight']??0):0.0;},$components)),1),
        'evidence_confidence'=>$confidence,
        'message' => $measuredWeight < 100
            ? 'Provisional score — insufficient timestamp coverage for a final performance decision. Measured components are renormalised and missing data is not scored as zero.'
            : 'Final score based on complete measured components.',
        'components' => $rows,
    ];
}

function kpi_weighted_subscore(array $parts): ?float
{
    $weight=0.0;$points=0.0;
    foreach($parts as$part){if(!isset($part['score'])||!is_numeric($part['score']))continue;$share=max(0.0,(float)($part['share']??0));$weight+=$share;$points+=max(0.0,min(100.0,(float)$part['score']))*$share;}
    return $weight>0?round($points/$weight,1):null;
}

function kpi_role_weight_template(string $roleKey): array
{
    if(strpos($roleKey,'packer')!==false)return['version'=>'packer-v2-role-accountability-2026-08-29','role'=>'packer','effective_from'=>'2026-08-29','components'=>['packing'=>40,'orders'=>30,'tasks'=>10,'waybills'=>10,'quality'=>10]];
    return['version'=>'front-v5-outcome-accountability-2026-08-29','role'=>'front_person','effective_from'=>'2026-08-29','components'=>['orders'=>25,'packing'=>8,'tasks'=>7,'bookkeeping'=>18,'waybills'=>12,'quality'=>30]];
}
