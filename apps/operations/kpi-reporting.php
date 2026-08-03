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
