<?php
declare(strict_types=1);

/** Read-only activity; equal elapsed seven-day windows in Namibia time. */
function pw_business_activity(DateTimeImmutable $now, ?callable $read = null): array
{
    $read = $read ?? static function (string $sql, array $params): array {
        $statement = db()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    };
    $now = $now->setTimezone(new DateTimeZone('Africa/Windhoek'));
    $start = $now->modify('-6 days')->setTime(0, 0);
    $eligible = "LOWER(status) NOT IN ('cancelled','canceled','refunded','failed','error_logged','trash','trashed')";
    $hasPayments = ops_column_exists('ops_orders', 'portal_paid_confirmed') && ops_column_exists('ops_orders', 'portal_paid_decided_at');
    $windows = [];
    foreach ([[$start, $now], [$start->modify('-7 days'), $now->modify('-7 days')]] as $window) {
        [$from, $to] = $window;
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $from->modify('+' . $i . ' days')->format('Y-m-d');
            $days[$date] = ['date' => $date, 'orders' => 0, 'paid_value' => $hasPayments ? 0.0 : null];
        }
        $rows = $read("SELECT DATE(created_at) day, COUNT(*) total FROM ops_orders WHERE {$eligible} AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        foreach ($rows as $row) if (isset($days[$row['day']])) $days[$row['day']]['orders'] = (int) $row['total'];
        if ($hasPayments) {
            // ops_set_portal_paid_confirmation writes UTC_TIMESTAMP(). Confirmation
            // dates are not receipt dates; never substitute creation or edit dates.
            $rows = $read("SELECT DATE(DATE_ADD(portal_paid_decided_at, INTERVAL 2 HOUR)) day, COALESCE(SUM(total_amount),0) total FROM ops_orders WHERE {$eligible} AND portal_paid_confirmed=1 AND portal_paid_decided_at BETWEEN ? AND ? GROUP BY DATE(DATE_ADD(portal_paid_decided_at, INTERVAL 2 HOUR))", [$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            foreach ($rows as $row) if (isset($days[$row['day']])) $days[$row['day']]['paid_value'] = round((float) $row['total'], 2);
        }
        $windows[] = ['days' => array_values($days), 'orders' => array_sum(array_column($days, 'orders')), 'paid_value' => $hasPayments ? round(array_sum(array_column($days, 'paid_value')), 2) : null];
    }
    $pending = $read("SELECT COUNT(*) total, COALESCE(SUM(total_amount),0) value FROM ops_orders WHERE LOWER(status) IN ('new','new_order','new-order','new order','pending','assigned','in_progress','processing','packing') AND created_at <= ?", [$now->format('Y-m-d H:i:s')]);
    return ['available' => true, 'from' => $start->format('Y-m-d'), 'to' => $now->format(DATE_ATOM), 'current' => $windows[0], 'previous' => $windows[1], 'awaiting' => ['count' => (int) ($pending[0]['total'] ?? 0), 'value' => (float) ($pending[0]['value'] ?? 0)]];
}
