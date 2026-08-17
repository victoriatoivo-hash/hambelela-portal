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
    if (ops_table_exists('ops_orders') && !ops_column_exists('ops_orders', 'fulfilment_mode')) {
        owner_dashboard_try_sql("ALTER TABLE ops_orders ADD COLUMN fulfilment_mode VARCHAR(40) NULL AFTER order_type");
        owner_dashboard_try_sql("UPDATE ops_orders SET fulfilment_mode = order_type WHERE fulfilment_mode IS NULL OR fulfilment_mode = ''");
    }
    if (ops_table_exists('ops_packing_tasks')) {
        $inventoryAfter = ops_column_exists('ops_packing_tasks', 'website_uploaded_at') ? 'website_uploaded_at' : 'website_uploaded';
        if (!ops_column_exists('ops_packing_tasks', 'inventory_updated_by')) {
            owner_dashboard_try_sql("ALTER TABLE ops_packing_tasks ADD COLUMN inventory_updated_by INT NULL AFTER {$inventoryAfter}");
        }
        if (!ops_column_exists('ops_packing_tasks', 'inventory_updated_at')) {
            owner_dashboard_try_sql("ALTER TABLE ops_packing_tasks ADD COLUMN inventory_updated_at DATETIME NULL AFTER inventory_updated_by");
        }
    }
    ops_reconcile_core_staff();
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
    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($status))) ?: '';
    $key = trim($key, '_');
    if (in_array($key, ['completed', 'complete', 'done', 'packed', 'verified', 'approved', 'sent', 'website'], true)) {
        return 'done';
    }
    if (in_array($key, ['in_progress', 'packing', 'assigned', 'needs_review', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true)) {
        return 'progress';
    }
    return 'new';
}

function owner_dashboard_is_hold_status(string $status): bool
{
    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($status))) ?: '';
    return in_array(trim($key, '_'), ['hold', 'on_hold', 'error_logged', 'correction_required', 'needs_review', 'missed'], true);
}

function owner_dashboard_is_done_status(string $status): bool
{
    return owner_dashboard_status_key($status) === 'done';
}

function owner_dashboard_is_progress_status(string $status): bool
{
    return owner_dashboard_status_key($status) === 'progress';
}

function owner_dashboard_employee_slug(string $name): string
{
    $name = strtolower($name);
    if (strpos($name, 'cecil') !== false || strpos($name, 'secil') !== false) return 'cecilia';
    if (strpos($name, 'klaud') !== false) return 'klaudia';
    if (strpos($name, 'ndin') !== false) return 'ndinelao';
    return 'default';
}

function owner_dashboard_board_row(array $employee): array
{
    return [
        'employee_id' => (int) ($employee['id'] ?? 0),
        'name' => (string) ($employee['full_name'] ?? 'Employee'),
        'role_key' => (string) ($employee['role_key'] ?? ''),
        'new' => 0,
        'in_progress' => 0,
        'done' => 0,
        'hold' => 0,
        'stale' => 0,
        'walk_ins' => 0,
        'total_items' => 0,
        'not_started' => 0,
        'inventory_updated' => 0,
        'avg_time' => '-',
    ];
}

function owner_dashboard_row_has_activity(array $row, array $keys): bool
{
    foreach ($keys as $key) {
        if ((int) ($row[$key] ?? 0) > 0) return true;
    }
    return false;
}

function owner_dashboard_average_duration(array $minutes): string
{
    return $minutes ? owner_dashboard_duration(array_sum($minutes) / count($minutes)) : '-';
}

function owner_dashboard_is_stale(?string $updatedAt, string $now, int $hours = 24): bool
{
    $minutes = owner_dashboard_minutes($updatedAt, $now);
    return $minutes !== null && $minutes > ($hours * 60);
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
    $today = date('Y-m-d');

    $rawEmployeeRows = ops_table_exists('ops_employees') ? ops_rows(
        "SELECT e.id, e.full_name, COALESCE(r.name, r.role_key) AS role_name, r.role_key,
                COALESCE(ea.availability_status, 'available') AS availability_status
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'front_desk_admin', 'packer', 'supervisor_manager', 'owner_admin'), e.full_name"
    ) : [];
    $employeeAliasMap = ops_employee_alias_map($rawEmployeeRows);
    $employeeRows = ops_canonical_employee_rows($rawEmployeeRows);
    $employeesById = [];
    $orderEmployees = [];
    $packerEmployees = [];
    $frontDeskEmployees = [];
    foreach ($employeeRows as $employee) {
        $id = (int) $employee['id'];
        $employeesById[$id] = $employee;
        if (in_array((string) $employee['role_key'], ['front_desk_admin', 'packer', 'supervisor_manager'], true)) {
            $orderEmployees[$id] = $employee;
        }
        if ((string) $employee['role_key'] === 'packer') {
            $packerEmployees[$id] = $employee;
        }
        if ((string) $employee['role_key'] === 'front_desk_admin') {
            $frontDeskEmployees[$id] = $employee;
        }
    }

    $fulfilmentSelect = ops_column_exists('ops_orders', 'fulfilment_mode') ? 'COALESCE(NULLIF(fulfilment_mode, \'\'), order_type) AS fulfilment_mode' : 'order_type AS fulfilment_mode';
    $orders = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT id, order_number, customer_name, customer_contact, payment_status, payment_method, order_type,
                {$fulfilmentSelect}, assigned_packer_id, status, total_amount, created_at, completed_at,
                packing_started_at, packed_at, updated_at
         FROM ops_orders
         WHERE created_at >= ? AND created_at < ?",
        [$from, $to]
    ) : [];
    $orderIds = array_map(static fn (array $row): int => (int) $row['id'], $orders);
    $events = [];
    if ($orderIds && ops_table_exists('ops_order_stage_events')) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $events = ops_rows(
            "SELECT order_id, stage_key, employee_id, occurred_at
             FROM ops_order_stage_events
             WHERE order_id IN ({$placeholders})
             ORDER BY occurred_at ASC",
            $orderIds
        );
    }
    $eventsByOrder = [];
    foreach ($events as $event) {
        $eventsByOrder[(int) $event['order_id']][] = $event;
    }

    $lastProgressByOrder = [];
    $doneByOrder = [];
    $progressByOrder = [];
    foreach ($eventsByOrder as $orderId => $orderEvents) {
        foreach ($orderEvents as $event) {
            if (!empty($event['employee_id'])) {
                $event['employee_id'] = ops_canonical_employee_id((int) $event['employee_id'], $employeeAliasMap);
            }
            $stage = (string) ($event['stage_key'] ?? '');
            if ($stage === 'in_progress') {
                $progressByOrder[$orderId] = $event;
                $lastProgressByOrder[$orderId] = $event;
            }
            if ($stage === 'completed' || $stage === 'done') {
                $doneByOrder[$orderId] = $event;
            }
        }
    }

    $boardStatusRows = [];
    $boardDurations = [];
    foreach ($employeeRows as $employee) {
        $id = (int) $employee['id'];
        $name = (string) $employee['full_name'];
        $boardStatusRows[$id] = [
            'employee_id' => $id,
            'name' => $name,
            'initials' => implode('', array_map(static fn (string $part): string => strtoupper(substr($part, 0, 1)), array_slice(preg_split('/\s+/', trim($name)) ?: ['?'], 0, 2))),
            'avatar' => owner_dashboard_employee_slug($name),
            'role' => (string) ($employee['role_name'] ?? '-'),
            'role_key' => (string) ($employee['role_key'] ?? ''),
            'order_board' => 0,
            'packing_list' => 0,
            'checklist' => 0,
            'mode' => 0,
            'avg_time' => '-',
            'stale' => 0,
            'status' => ((string) ($employee['availability_status'] ?? 'available')) === 'available' ? 'Active' : 'Away',
        ];
        $boardDurations[$id] = [];
    }

    $orderBoardRows = [];
    $orderPackDurations = [];
    foreach ($orderEmployees as $id => $employee) {
        $orderBoardRows[$id] = owner_dashboard_board_row($employee);
        $orderPackDurations[$id] = [];
    }
    $revenue = 0.0;
    $completionTimes = [];
    $dispatchOnTime = 0;
    $dispatchTotal = 0;
    foreach ($orders as $order) {
        $orderId = (int) $order['id'];
        $status = (string) ($order['status'] ?? '');
        $statusKey = owner_dashboard_status_key($status);
        $assignedId = ops_canonical_employee_id((int) ($order['assigned_packer_id'] ?? 0), $employeeAliasMap);
        $progressEmployeeId = (int) ($progressByOrder[$orderId]['employee_id'] ?? 0);
        $doneEmployeeId = (int) ($doneByOrder[$orderId]['employee_id'] ?? 0);
        $primaryId = $doneEmployeeId ?: ($progressEmployeeId ?: $assignedId);
        $contact = strtolower((string) ($order['customer_contact'] ?? ''));
        $isWalkin = strpos($contact, 'walk') !== false || strpos(strtolower((string) ($order['customer_name'] ?? '')), 'ho customer') !== false || strtolower((string) ($order['fulfilment_mode'] ?? '')) === 'collection' && strpos($contact, 'customer') !== false;

        if (owner_dashboard_is_hold_status($status) && $primaryId && isset($orderBoardRows[$primaryId])) {
            $orderBoardRows[$primaryId]['hold']++;
        } elseif ($statusKey === 'new' && $assignedId && isset($orderBoardRows[$assignedId])) {
            $orderBoardRows[$assignedId]['new']++;
        } elseif ($statusKey === 'progress' && $progressEmployeeId && isset($orderBoardRows[$progressEmployeeId])) {
            $orderBoardRows[$progressEmployeeId]['in_progress']++;
        } elseif ($statusKey === 'done' && $primaryId && isset($orderBoardRows[$primaryId])) {
            $orderBoardRows[$primaryId]['done']++;
        }
        $orderBoardEmployeeId = $assignedId ?: $primaryId;
        if ($orderBoardEmployeeId && isset($boardStatusRows[$orderBoardEmployeeId])) {
            if ($statusKey === 'progress' || owner_dashboard_is_hold_status($status)) {
                $boardStatusRows[$orderBoardEmployeeId]['order_board']++;
            }
            if ($statusKey !== 'done' && owner_dashboard_is_stale((string) ($order['updated_at'] ?? $order['created_at']), $now)) {
                $boardStatusRows[$orderBoardEmployeeId]['stale']++;
            }
        }

        if ($isWalkin) {
            foreach ($frontDeskEmployees as $frontId => $_frontEmployee) {
                $orderBoardRows[$frontId]['walk_ins']++;
            }
        }
        if ($doneEmployeeId && isset($orderPackDurations[$doneEmployeeId]) && !empty($lastProgressByOrder[$orderId]['occurred_at']) && !empty($doneByOrder[$orderId]['occurred_at'])) {
            $duration = owner_dashboard_minutes((string) $lastProgressByOrder[$orderId]['occurred_at'], (string) $doneByOrder[$orderId]['occurred_at']);
            if ($duration !== null) $orderPackDurations[$doneEmployeeId][] = $duration;
            if (isset($boardDurations[$doneEmployeeId])) $boardDurations[$doneEmployeeId][] = $duration;
        } elseif ($primaryId && isset($orderPackDurations[$primaryId]) && !empty($order['packing_started_at']) && !empty($order['completed_at'])) {
            $duration = owner_dashboard_minutes((string) $order['packing_started_at'], (string) $order['completed_at']);
            if ($duration !== null) $orderPackDurations[$primaryId][] = $duration;
            if ($duration !== null && isset($boardDurations[$primaryId])) $boardDurations[$primaryId][] = $duration;
        }
        if (!empty($order['completed_at'])) {
            $duration = owner_dashboard_minutes((string) $order['created_at'], (string) $order['completed_at']);
            if ($duration !== null) {
                $completionTimes[] = $duration;
                $dispatchTotal++;
                if ($duration <= (float) $settings['dispatch_target_minutes']) $dispatchOnTime++;
            }
        }
        if (ops_is_valid_revenue_status($status, (string) ($order['payment_status'] ?? ''))) {
            $revenue += (float) ($order['total_amount'] ?? 0);
        }
    }
    foreach ($orderPackDurations as $employeeId => $durations) {
        if (isset($orderBoardRows[$employeeId])) {
            $orderBoardRows[$employeeId]['avg_time'] = owner_dashboard_average_duration($durations);
        }
    }

    $packingArchiveFilter = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'archived_at')
        ? " AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00')"
        : '';
    $websiteUploadedAtSelect = ops_column_exists('ops_packing_tasks', 'website_uploaded_at') ? 'website_uploaded_at' : 'NULL AS website_uploaded_at';
    $inventoryUpdatedBySelect = ops_column_exists('ops_packing_tasks', 'inventory_updated_by') ? 'inventory_updated_by' : 'NULL AS inventory_updated_by';
    $inventoryUpdatedAtSelect = ops_column_exists('ops_packing_tasks', 'inventory_updated_at') ? 'inventory_updated_at' : 'NULL AS inventory_updated_at';
    $packingRows = ops_table_exists('ops_packing_tasks') ? ops_rows(
        "SELECT id, assigned_employee_id, packing_status, date_loaded, date_completed, updated_at, website_uploaded,
                {$websiteUploadedAtSelect}, {$inventoryUpdatedBySelect}, {$inventoryUpdatedAtSelect}
         FROM ops_packing_tasks
         WHERE date_loaded >= ? AND date_loaded < ?
           {$packingArchiveFilter}",
        [$from, $to]
    ) : [];

    $packingBoardRows = [];
    $packingListRows = [];
    $packingDurations = [];
    foreach ($packerEmployees as $id => $employee) {
        $packingBoardRows[$id] = owner_dashboard_board_row($employee);
        $packingListRows[$id] = owner_dashboard_board_row($employee);
        $packingDurations[$id] = [];
    }
    foreach ($frontDeskEmployees as $id => $employee) {
        $packingListRows[$id] = owner_dashboard_board_row($employee);
    }
    foreach ($packingRows as $row) {
        $employeeId = ops_canonical_employee_id((int) ($row['assigned_employee_id'] ?? 0), $employeeAliasMap);
        $status = (string) ($row['packing_status'] ?? '');
        $statusKey = owner_dashboard_status_key($status);
        if (isset($packingBoardRows[$employeeId])) {
            if ($statusKey === 'done') $packingBoardRows[$employeeId]['done']++;
            elseif ($statusKey === 'progress') $packingBoardRows[$employeeId]['in_progress']++;
            else $packingBoardRows[$employeeId]['new']++;
            if ($statusKey === 'progress' && owner_dashboard_minutes((string) ($row['updated_at'] ?? $row['date_loaded']), $now) > (float) $settings['stale_minutes']) {
                $packingBoardRows[$employeeId]['stale']++;
                $packingBoardRows[$employeeId]['hold']++;
            }
            if (!empty($row['date_loaded']) && !empty($row['date_completed'])) {
                $duration = owner_dashboard_minutes((string) $row['date_loaded'], (string) $row['date_completed']);
                if ($duration !== null) $packingDurations[$employeeId][] = $duration;
                if ($duration !== null && isset($boardDurations[$employeeId])) $boardDurations[$employeeId][] = $duration;
            }
        }
        if ($employeeId && isset($boardStatusRows[$employeeId])) {
            $packingIsStale = $statusKey !== 'done' && owner_dashboard_is_stale((string) ($row['updated_at'] ?? $row['date_loaded']), $now);
            if ($statusKey === 'progress' || $packingIsStale) {
                $boardStatusRows[$employeeId]['packing_list']++;
            }
            if ($packingIsStale) {
                $boardStatusRows[$employeeId]['stale']++;
            }
        }
        if (isset($packingListRows[$employeeId])) {
            $packingListRows[$employeeId]['total_items']++;
            if ($statusKey === 'done') $packingListRows[$employeeId]['done']++;
            elseif ($statusKey === 'progress') $packingListRows[$employeeId]['in_progress']++;
            else $packingListRows[$employeeId]['not_started']++;
        }
        $inventoryBy = ops_canonical_employee_id((int) ($row['inventory_updated_by'] ?? 0), $employeeAliasMap);
        if (!$inventoryBy && !empty($row['website_uploaded']) && !empty($frontDeskEmployees)) {
            $inventoryBy = (int) array_key_first($frontDeskEmployees);
        }
        if ($inventoryBy && isset($packingListRows[$inventoryBy]) && !empty($row['website_uploaded'])) {
            $packingListRows[$inventoryBy]['inventory_updated']++;
        }
    }
    foreach ($packingDurations as $employeeId => $durations) {
        if (isset($packingBoardRows[$employeeId])) $packingBoardRows[$employeeId]['avg_time'] = owner_dashboard_average_duration($durations);
        if (isset($packingListRows[$employeeId])) $packingListRows[$employeeId]['avg_time'] = owner_dashboard_average_duration($durations);
    }

    $taskRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT id, assigned_employee_id, status, deadline, completed_at, COALESCE(released_at,date_assigned) created_at
         FROM ops_checklist_tasks
         WHERE employee_visible=1 AND (scheduled_at IS NULL OR released_at IS NOT NULL)
           AND COALESCE(released_at,date_assigned) >= ? AND COALESCE(released_at,date_assigned) < ?",
        [$from, $to]
    ) : [];
    $checklistRows = [];
    foreach ($taskRows as $task) {
        $employeeId = ops_canonical_employee_id((int) ($task['assigned_employee_id'] ?? 0), $employeeAliasMap);
        if (!$employeeId || !isset($employeesById[$employeeId])) continue;
        if (!isset($checklistRows[$employeeId])) $checklistRows[$employeeId] = owner_dashboard_board_row($employeesById[$employeeId]);
        $status = (string) ($task['status'] ?? '');
        if (!empty($task['deadline']) && empty($task['completed_at']) && strtotime((string) $task['deadline']) < time()) {
            $checklistRows[$employeeId]['hold']++;
            if (isset($boardStatusRows[$employeeId])) $boardStatusRows[$employeeId]['checklist']++;
        } elseif (owner_dashboard_is_done_status($status)) {
            $checklistRows[$employeeId]['done']++;
        } elseif (owner_dashboard_is_progress_status($status)) {
            $checklistRows[$employeeId]['in_progress']++;
            if (isset($boardStatusRows[$employeeId])) $boardStatusRows[$employeeId]['checklist']++;
        } else {
            $checklistRows[$employeeId]['new']++;
        }
    }

    $modeRows = [];
    foreach ($employeeRows as $employee) {
        $id = (int) $employee['id'];
        $modeRows[$id] = ['employee_id' => $id, 'name' => (string) $employee['full_name'], 'courier' => 0, 'collection' => 0, 'total' => 0];
    }
    foreach ($orders as $order) {
        $orderId = (int) $order['id'];
        $doneEmployeeId = (int) ($doneByOrder[$orderId]['employee_id'] ?? 0);
        $handledBy = $doneEmployeeId ?: ops_canonical_employee_id((int) ($order['assigned_packer_id'] ?? 0), $employeeAliasMap);
        if (!$handledBy || !isset($modeRows[$handledBy])) continue;
        $mode = strtolower((string) ($order['fulfilment_mode'] ?? $order['order_type'] ?? ''));
        if (strpos($mode, 'courier') !== false || strpos($mode, 'delivery') !== false) {
            $modeRows[$handledBy]['courier']++;
            $modeRows[$handledBy]['total']++;
            if (isset($boardStatusRows[$handledBy])) $boardStatusRows[$handledBy]['mode']++;
        } elseif (strpos($mode, 'collection') !== false || strpos($mode, 'walk') !== false) {
            $modeRows[$handledBy]['collection']++;
            $modeRows[$handledBy]['total']++;
        }
    }
    foreach ($boardDurations as $employeeId => $durations) {
        if (isset($boardStatusRows[$employeeId])) {
            $boardStatusRows[$employeeId]['avg_time'] = owner_dashboard_average_duration($durations);
        }
    }
    $modeRows = array_values(array_filter($modeRows, static fn (array $row): bool => (int) $row['total'] > 0));
    $modeSummary = [
        'courier' => array_sum(array_column($modeRows, 'courier')),
        'collection' => array_sum(array_column($modeRows, 'collection')),
        'total' => array_sum(array_column($modeRows, 'total')),
    ];

    $avgFulfilment = $completionTimes ? array_sum($completionTimes) / count($completionTimes) : null;
    $onTimePct = $dispatchTotal ? round(($dispatchOnTime / $dispatchTotal) * 100) : 0;
    $allPackingDurations = [];
    foreach ($packingDurations as $durationSet) {
        $allPackingDurations = array_merge($allPackingDurations, $durationSet);
    }

    $ownerErrors = ops_table_exists('owner_error_log') ? ops_rows(
        "SELECT id, description, logged_at, reference_type, reference_id, logged_by
         FROM owner_error_log
         WHERE resolved = 0
         ORDER BY logged_at DESC
         LIMIT 10"
    ) : [];
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
            ['key' => 'walk_ins_today', 'label' => 'Walk-ins today', 'value' => number_format(array_sum(array_column($orderBoardRows, 'walk_ins'))), 'subtitle' => 'Walk-in customer orders', 'progress' => owner_dashboard_progress(array_sum(array_column($orderBoardRows, 'walk_ins')), 20)],
            ['key' => 'avg_pack_speed', 'label' => 'Avg pack speed', 'value' => owner_dashboard_average_duration($allPackingDurations), 'subtitle' => 'Packing list completion', 'progress' => $allPackingDurations ? max(0, 100 - owner_dashboard_progress(array_sum($allPackingDurations) / count($allPackingDurations), (float) $settings['pack_speed_target_minutes'] * 2)) : 0],
            ['key' => 'revenue_today', 'label' => 'Revenue today', 'value' => owner_dashboard_money($revenue), 'subtitle' => 'Valid paid/completed orders', 'progress' => owner_dashboard_progress($revenue, (float) $settings['revenue_daily_target'])],
        ],
        'boards' => [
            ['key' => 'orders', 'label' => 'Order Board', 'summary' => array_sum(array_column($orderBoardRows, 'in_progress')) . ' in progress · ' . array_sum(array_column($orderBoardRows, 'hold')) . ' on hold', 'columns' => ['New', 'In Progress', 'Done', 'Hold', 'Walk-ins', 'Avg pack time'], 'rows' => array_values($orderBoardRows)],
            ['key' => 'packing_board', 'label' => 'Packing Board', 'summary' => array_sum(array_column($packingBoardRows, 'in_progress')) . ' in progress · ' . array_sum(array_column($packingBoardRows, 'stale')) . ' stale', 'columns' => ['New', 'In Progress', 'Done', 'Stale', 'Avg pack time'], 'rows' => array_values($packingBoardRows)],
            ['key' => 'packing_list', 'label' => 'Packing List', 'summary' => array_sum(array_column($packingListRows, 'done')) . ' done · ' . array_sum(array_column($packingListRows, 'inventory_updated')) . ' inventory updated', 'columns' => ['Total', 'Done / Ticked', 'In Progress', 'Not Started', 'Inventory Updated', 'Avg time'], 'rows' => array_values($packingListRows)],
            ['key' => 'checklist', 'label' => 'Checklist Board', 'summary' => array_sum(array_column($checklistRows, 'in_progress')) . ' in progress · ' . array_sum(array_column($checklistRows, 'hold')) . ' overdue', 'columns' => ['New', 'In Progress', 'Done', 'Overdue'], 'rows' => array_values($checklistRows)],
        ],
        'mode' => [
            'label' => 'Mode',
            'summary' => $modeSummary,
            'rows' => $modeRows,
        ],
        'board_status' => array_values($boardStatusRows),
        'staff' => array_map(static function (array $employee) use ($orderBoardRows, $packingListRows, $checklistRows): array {
            $id = (int) $employee['id'];
            return [
                'name' => (string) $employee['full_name'],
                'initials' => implode('', array_map(static fn (string $part): string => strtoupper(substr($part, 0, 1)), array_slice(preg_split('/\s+/', trim((string) $employee['full_name'])) ?: ['?'], 0, 2))),
                'avatar' => owner_dashboard_employee_slug((string) $employee['full_name']),
                'role' => (string) ($employee['role_name'] ?? '-'),
                'orders' => isset($orderBoardRows[$id]) ? ((int) $orderBoardRows[$id]['new'] + (int) $orderBoardRows[$id]['in_progress'] + (int) $orderBoardRows[$id]['done'] + (int) $orderBoardRows[$id]['hold'] + (int) $orderBoardRows[$id]['walk_ins']) : 0,
                'packing' => (int) (($packingListRows[$id]['total_items'] ?? 0)),
                'tasks' => isset($checklistRows[$id]) ? ((int) $checklistRows[$id]['new'] + (int) $checklistRows[$id]['in_progress'] + (int) $checklistRows[$id]['done'] + (int) $checklistRows[$id]['hold']) : 0,
                'avg_time' => (string) (($orderBoardRows[$id]['avg_time'] ?? '-') ?: '-'),
                'stale_items' => (int) (($packingListRows[$id]['stale'] ?? 0) + ($checklistRows[$id]['hold'] ?? 0)),
                'status' => ((string) ($employee['availability_status'] ?? 'available')) === 'available' ? 'Active' : 'Away',
            ];
        }, $employeeRows),
        'owner_errors' => $dashboardErrors,
    ];
}
