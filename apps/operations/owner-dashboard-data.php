<?php

declare(strict_types=1);

function owner_dashboard_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Dashboard schema helpers should not block the KPI report page.
    }
}

function owner_dashboard_bootstrap(): void
{
    owner_dashboard_try_sql(
        "CREATE TABLE IF NOT EXISTS owner_error_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            description TEXT NOT NULL,
            logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reference_type VARCHAR(80) NULL,
            reference_id VARCHAR(120) NULL,
            logged_by VARCHAR(160) NULL,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            resolved_at DATETIME NULL,
            resolved_by INT NULL,
            INDEX idx_owner_error_resolved (resolved, logged_at),
            INDEX idx_owner_error_reference (reference_type, reference_id)
        )"
    );
}

function owner_dashboard_minutes(?string $from, ?string $to): ?float
{
    if (!$from || !$to) {
        return null;
    }
    try {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return null;
    }
    if ($end <= $start) {
        return 0.0;
    }
    return round(($end->getTimestamp() - $start->getTimestamp()) / 60, 1);
}

function owner_dashboard_duration(?float $minutes): string
{
    if ($minutes === null) {
        return '-';
    }
    if ($minutes < 60) {
        return number_format($minutes, 0) . 'm';
    }
    $hours = floor($minutes / 60);
    $mins = (int) round($minutes - ($hours * 60));
    return $hours . 'h ' . $mins . 'm';
}

function owner_dashboard_money(float $amount): string
{
    return 'N$' . number_format($amount, 0);
}

function owner_dashboard_progress(float $value, float $max): int
{
    if ($max <= 0) {
        return 0;
    }
    return (int) min(100, max(0, round(($value / $max) * 100)));
}

function owner_dashboard_status_key(string $status): string
{
    $key = strtolower(trim($status));
    if (in_array($key, ['completed', 'complete', 'done', 'packed', 'verified', 'approved', 'sent', 'website'], true)) {
        return 'done';
    }
    if (in_array($key, ['in_progress', 'packing', 'assigned', 'needs_review', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true)) {
        return 'progress';
    }
    return 'new';
}

function owner_dashboard_count_rows(string $table, string $dateColumn, string $start, string $end, string $extra = '', array $params = []): int
{
    if (!ops_table_exists($table)) {
        return 0;
    }
    $rows = ops_rows("SELECT COUNT(*) AS total FROM {$table} WHERE {$dateColumn} >= ? AND {$dateColumn} < ? {$extra}", array_merge([$start, $end], $params));
    return (int) ($rows[0]['total'] ?? 0);
}

function owner_dashboard_settings(): array
{
    return [
        'orders_max' => 20,
        'revenue_daily_target' => 10000,
        'dispatch_target_minutes' => 180,
        'pack_speed_target_minutes' => 45,
        'stale_minutes' => 30,
    ];
}

function owner_dashboard_build(string $fromDate, string $toDate): array
{
    owner_dashboard_bootstrap();

    $settings = owner_dashboard_settings();
    $from = $fromDate . ' 00:00:00';
    $to = (new DateTimeImmutable($toDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
    $now = date('Y-m-d H:i:s');

    $orders = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT id, order_number, customer_name, customer_contact, payment_status, payment_method, order_type,
                assigned_packer_id, status, total_amount, created_at, completed_at, packing_started_at, packed_at, updated_at
         FROM ops_orders
         WHERE created_at >= ? AND created_at < ?",
        [$from, $to]
    ) : [];

    $orderCounts = ['new' => 0, 'progress' => 0, 'done' => 0];
    $walkinCounts = ['new' => 0, 'progress' => 0, 'done' => 0];
    $completionTimes = [];
    $packTimes = [];
    $dispatchOnTime = 0;
    $dispatchTotal = 0;
    $revenue = 0.0;
    $walkins = 0;
    foreach ($orders as $order) {
        $statusKey = owner_dashboard_status_key((string) ($order['status'] ?? ''));
        $orderCounts[$statusKey]++;
        $contact = strtolower((string) ($order['customer_contact'] ?? ''));
        $isWalkin = strpos($contact, 'walk') !== false || strpos(strtolower((string) ($order['customer_name'] ?? '')), 'ho customer') !== false;
        if ($isWalkin) {
            $walkins++;
            $walkinCounts[$statusKey]++;
        }
        if (!empty($order['completed_at'])) {
            $minutes = owner_dashboard_minutes((string) $order['created_at'], (string) $order['completed_at']);
            if ($minutes !== null) {
                $completionTimes[] = $minutes;
                $dispatchTotal++;
                if ($minutes <= (float) $settings['dispatch_target_minutes']) {
                    $dispatchOnTime++;
                }
            }
            if (ops_is_valid_revenue_status((string) ($order['status'] ?? ''), (string) ($order['payment_status'] ?? ''))) {
                $revenue += (float) ($order['total_amount'] ?? 0);
            }
        }
        if (!empty($order['packing_started_at']) && !empty($order['packed_at'])) {
            $pack = owner_dashboard_minutes((string) $order['packing_started_at'], (string) $order['packed_at']);
            if ($pack !== null) {
                $packTimes[] = $pack;
            }
        }
    }

    $avgFulfilment = $completionTimes ? array_sum($completionTimes) / count($completionTimes) : null;
    $avgPack = $packTimes ? array_sum($packTimes) / count($packTimes) : null;
    $onTimePct = $dispatchTotal ? round(($dispatchOnTime / $dispatchTotal) * 100) : 0;

    $packingArchiveFilter = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'archived_at')
        ? " AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00')"
        : '';
    $packingRows = ops_table_exists('ops_packing_tasks') ? ops_rows(
        "SELECT id, assigned_employee_id, packing_status, date_loaded, date_completed, updated_at
         FROM ops_packing_tasks
         WHERE date_loaded >= ? AND date_loaded < ?
           {$packingArchiveFilter}",
        [$from, $to]
    ) : [];
    $packingTotal = count($packingRows);
    $packingDone = 0;
    $packingProgress = 0;
    $packingNew = 0;
    $packingDurations = [];
    foreach ($packingRows as $row) {
        $statusKey = owner_dashboard_status_key((string) ($row['packing_status'] ?? ''));
        if ($statusKey === 'done') $packingDone++;
        elseif ($statusKey === 'progress') $packingProgress++;
        else $packingNew++;
        if (!empty($row['date_loaded']) && !empty($row['date_completed'])) {
            $duration = owner_dashboard_minutes((string) $row['date_loaded'], (string) $row['date_completed']);
            if ($duration !== null) $packingDurations[] = $duration;
        }
    }
    $avgPackingItemTime = $packingDurations ? array_sum($packingDurations) / count($packingDurations) : null;

    $taskRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT id, assigned_employee_id, status, deadline, completed_at, created_at
         FROM ops_checklist_tasks
         WHERE created_at >= ? AND created_at < ?",
        [$from, $to]
    ) : [];
    $taskCounts = ['new' => 0, 'progress' => 0, 'done' => 0, 'overdue' => 0];
    foreach ($taskRows as $task) {
        $status = (string) ($task['status'] ?? '');
        $statusKey = owner_dashboard_status_key($status);
        $taskCounts[$statusKey]++;
        if (!empty($task['deadline']) && empty($task['completed_at']) && strtotime((string) $task['deadline']) < time()) {
            $taskCounts['overdue']++;
        }
    }

    $employeeRows = ops_table_exists('ops_employees') ? ops_rows(
        "SELECT e.id, e.full_name, COALESCE(r.name, r.role_key) AS role_name, r.role_key,
                COALESCE(ea.availability_status, 'available') AS availability_status
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'front_desk_admin', 'packer', 'supervisor_manager', 'owner_admin'), e.full_name"
    ) : [];

    $staff = [];
    foreach ($employeeRows as $employee) {
        $id = (int) $employee['id'];
        $name = (string) $employee['full_name'];
        $assignedOrders = array_values(array_filter($orders, static fn (array $row): bool => (int) ($row['assigned_packer_id'] ?? 0) === $id));
        $assignedPacking = array_values(array_filter($packingRows, static fn (array $row): bool => (int) ($row['assigned_employee_id'] ?? 0) === $id));
        $assignedTasks = array_values(array_filter($taskRows, static fn (array $row): bool => (int) ($row['assigned_employee_id'] ?? 0) === $id));
        $staffOrders = ['new' => 0, 'progress' => 0, 'done' => 0];
        foreach ($assignedOrders as $row) $staffOrders[owner_dashboard_status_key((string) ($row['status'] ?? ''))]++;
        $staffPacking = ['new' => 0, 'progress' => 0, 'done' => 0];
        foreach ($assignedPacking as $row) $staffPacking[owner_dashboard_status_key((string) ($row['packing_status'] ?? ''))]++;
        $staffChecklist = ['new' => 0, 'progress' => 0, 'done' => 0];
        foreach ($assignedTasks as $row) $staffChecklist[owner_dashboard_status_key((string) ($row['status'] ?? ''))]++;
        $durations = [];
        foreach ($assignedOrders as $row) {
            if (!empty($row['completed_at'])) {
                $duration = owner_dashboard_minutes((string) $row['created_at'], (string) $row['completed_at']);
                if ($duration !== null) $durations[] = $duration;
            }
        }
        foreach ($assignedPacking as $row) {
            if (!empty($row['date_completed'])) {
                $duration = owner_dashboard_minutes((string) $row['date_loaded'], (string) $row['date_completed']);
                if ($duration !== null) $durations[] = $duration;
            }
        }
        $stale = 0;
        foreach (array_merge($assignedOrders, $assignedPacking, $assignedTasks) as $row) {
            $updated = $row['updated_at'] ?? $row['created_at'] ?? $row['date_loaded'] ?? null;
            $status = (string) ($row['status'] ?? $row['packing_status'] ?? '');
            if ($updated && owner_dashboard_status_key($status) === 'progress' && owner_dashboard_minutes((string) $updated, $now) > $settings['stale_minutes']) {
                $stale++;
            }
        }
        $staff[] = [
            'name' => $name,
            'initials' => implode('', array_map(static fn (string $part): string => strtoupper(substr($part, 0, 1)), array_slice(preg_split('/\s+/', trim($name)) ?: ['?'], 0, 2))),
            'avatar' => stripos($name, 'cecil') !== false || stripos($name, 'secil') !== false ? 'cecilia' : (stripos($name, 'klaud') !== false ? 'klaudia' : (stripos($name, 'ndin') !== false ? 'ndinelao' : 'default')),
            'role' => (string) ($employee['role_name'] ?? '-'),
            'role_key' => (string) ($employee['role_key'] ?? ''),
            'orders' => $staffOrders,
            'packing' => $staffPacking,
            'picking' => $staffOrders,
            'checklist' => $staffChecklist,
            'avg_time' => owner_dashboard_duration($durations ? array_sum($durations) / count($durations) : null),
            'stale' => $stale,
            'status' => ((string) ($employee['availability_status'] ?? 'available')) === 'available' ? 'Active' : 'Away',
        ];
    }

    $ownerErrors = ops_table_exists('owner_error_log') ? ops_rows(
        "SELECT id, description, logged_at, reference_type, reference_id, logged_by
         FROM owner_error_log
         WHERE resolved = 0
         ORDER BY logged_at DESC
         LIMIT 10"
    ) : [];

    $pickingHold = count(array_filter($orders, static fn (array $row): bool => owner_dashboard_status_key((string) ($row['status'] ?? '')) === 'progress' && owner_dashboard_minutes((string) ($row['updated_at'] ?? $row['created_at']), date('Y-m-d H:i:s')) > 30));
    $dashboardStaff = array_map(static function (array $row): array {
        $orderCount = array_sum($row['orders']);
        $packingCount = array_sum($row['packing']);
        $taskCount = array_sum($row['checklist']);
        return [
            'name' => $row['name'],
            'initials' => $row['initials'],
            'avatar' => $row['avatar'],
            'role' => $row['role'],
            'orders' => $orderCount,
            'packing' => $packingCount,
            'tasks' => $taskCount,
            'avg_time' => $row['avg_time'],
            'stale_items' => $row['stale'],
            'status' => $row['status'],
        ];
    }, $staff);
    $dashboardErrors = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'description' => (string) $row['description'],
            'logged_at' => date('d M H:i', strtotime((string) $row['logged_at'])),
            'reference_type' => (string) ($row['reference_type'] ?? ''),
            'reference_id' => (string) ($row['reference_id'] ?? ''),
            'logged_by' => (string) ($row['logged_by'] ?: 'System'),
        ];
    }, $ownerErrors);

    return [
        'from' => $fromDate,
        'to' => $toDate,
        'updatedAt' => date('H:i:s'),
        'settings' => $settings,
        'metrics' => [
            ['key' => 'orders_today', 'label' => 'Orders today', 'value' => number_format(count($orders)), 'subtitle' => 'Orders loaded in range', 'progress' => owner_dashboard_progress(count($orders), (float) $settings['orders_max'])],
            ['key' => 'avg_fulfillment_time', 'label' => 'Avg fulfilment time', 'value' => owner_dashboard_duration($avgFulfilment), 'subtitle' => 'Loaded to complete', 'progress' => $avgFulfilment === null ? 0 : max(0, 100 - owner_dashboard_progress($avgFulfilment, 360))],
            ['key' => 'on_time_dispatch', 'label' => 'On-time dispatch %', 'value' => number_format($onTimePct) . '%', 'subtitle' => 'Within target time', 'progress' => (int) $onTimePct],
            ['key' => 'walk_ins_today', 'label' => 'Walk-ins today', 'value' => number_format($walkins), 'subtitle' => 'Walk-in customer orders', 'progress' => owner_dashboard_progress($walkins, 20)],
            ['key' => 'avg_pack_speed', 'label' => 'Avg pack speed', 'value' => owner_dashboard_duration($avgPackingItemTime ?? $avgPack), 'subtitle' => 'Packing list completion', 'progress' => ($avgPackingItemTime ?? $avgPack) === null ? 0 : max(0, 100 - owner_dashboard_progress((float) ($avgPackingItemTime ?? $avgPack), (float) $settings['pack_speed_target_minutes'] * 2))],
            ['key' => 'revenue_today', 'label' => 'Revenue today', 'value' => owner_dashboard_money($revenue), 'subtitle' => 'Valid paid/completed orders', 'progress' => owner_dashboard_progress($revenue, (float) $settings['revenue_daily_target'])],
        ],
        'boards' => [
            ['label' => 'Order board', 'new' => $orderCounts['new'], 'in_progress' => $orderCounts['progress'], 'done' => $orderCounts['done'], 'hold' => 0],
            ['label' => 'Packing list', 'new' => $packingNew, 'in_progress' => $packingProgress, 'done' => $packingDone, 'hold' => max(0, $packingTotal - $packingDone - $packingProgress - $packingNew)],
            ['label' => 'Picking board', 'new' => $orderCounts['new'], 'in_progress' => $orderCounts['progress'], 'done' => $orderCounts['done'], 'hold' => $pickingHold],
            ['label' => 'Checklist board', 'new' => $taskCounts['new'], 'in_progress' => $taskCounts['progress'], 'done' => $taskCounts['done'], 'hold' => $taskCounts['overdue']],
        ],
        'staff' => $dashboardStaff,
        'owner_errors' => $dashboardErrors,
    ];
}
