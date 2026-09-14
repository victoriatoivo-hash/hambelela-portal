<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'supervisor_manager');

$pageTitle = 'KPI Reports | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();

function kpi_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function kpi_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Optional KPI schema upgrades should not block the report page.
    }
}

function kpi_bootstrap_schema(): void
{
    if (!ops_database_ready()) {
        return;
    }

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_status_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(80) NOT NULL,
            record_id INT NOT NULL,
            field_name VARCHAR(80) NOT NULL DEFAULT 'status',
            old_value VARCHAR(120) NULL,
            new_value VARCHAR(120) NULL,
            changed_by_employee_id INT NULL,
            assigned_employee_id INT NULL,
            metadata JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ops_status_record (module, record_id, field_name),
            INDEX idx_ops_status_changed_by (changed_by_employee_id, created_at),
            INDEX idx_ops_status_assigned (assigned_employee_id, created_at)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_employee_inputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_month CHAR(7) NOT NULL,
            employee_id INT NOT NULL,
            salary_override DECIMAL(12,2) NULL,
            attendance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            reliability_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            communication_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            team_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            manual_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            notes TEXT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ops_kpi_input (period_month, employee_id),
            INDEX idx_ops_kpi_input_employee (employee_id)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_weights (
            role_group VARCHAR(40) NOT NULL,
            component_key VARCHAR(80) NOT NULL,
            component_label VARCHAR(160) NOT NULL,
            weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role_group, component_key)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_score_snapshots (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            period_month CHAR(7) NOT NULL,
            employee_id INT NOT NULL,
            role_group VARCHAR(40) NOT NULL,
            overall_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            component_scores JSON NULL,
            metrics_snapshot JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ops_kpi_snapshot_period (period_month, employee_id)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_report_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $weights = kpi_default_weights();
    $stmt = db()->prepare(
        "INSERT IGNORE INTO ops_kpi_weights (role_group, component_key, component_label, weight_percent)
         VALUES (?, ?, ?, ?)"
    );
    foreach ($weights as $roleGroup => $components) {
        foreach ($components as $key => $component) {
            $stmt->execute([$roleGroup, $key, $component['label'], $component['weight']]);
        }
    }

    $settings = kpi_default_settings();
    $stmt = db()->prepare(
        "INSERT IGNORE INTO ops_report_settings (setting_key, setting_value) VALUES (?, ?)"
    );
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
}

function kpi_default_settings(): array
{
    return [
        'kpi_target_assignment_minutes' => 45,
        'kpi_target_packing_minutes' => 90,
        'kpi_target_order_completion_minutes' => 240,
        'kpi_target_packing_task_minutes' => 240,
        'kpi_target_bookkeeping_minutes' => 90,
        'kpi_target_website_upload_minutes' => 120,
        'kpi_error_penalty_points' => 4,
        'kpi_monthly_bonus_percent' => 5,
    ];
}

function kpi_default_weights(): array
{
    return [
        'front' => [
            'order_flow' => ['label' => 'Order / walk-in completion', 'weight' => 20],
            'bookkeeping' => ['label' => 'Bookkeeping accuracy', 'weight' => 20],
            'website_stock' => ['label' => 'Website stock upload', 'weight' => 15],
            'tasks' => ['label' => 'Task completion', 'weight' => 15],
            'errors' => ['label' => 'Error score', 'weight' => 15],
            'communication' => ['label' => 'Communication / manual', 'weight' => 10],
            'reliability' => ['label' => 'Reliability / attendance', 'weight' => 5],
        ],
        'packer' => [
            'order_speed' => ['label' => 'Order packing speed', 'weight' => 20],
            'packing_productivity' => ['label' => 'Packing list productivity', 'weight' => 25],
            'packing_accuracy' => ['label' => 'Packing accuracy', 'weight' => 20],
            'tasks' => ['label' => 'Task / cleaning compliance', 'weight' => 15],
            'errors' => ['label' => 'Error score', 'weight' => 15],
            'team' => ['label' => 'Team contribution / manual', 'weight' => 5],
        ],
    ];
}

function kpi_setting(array $settings, string $key, $fallback)
{
    return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
}

function kpi_load_settings(): array
{
    $settings = kpi_default_settings();
    if (!ops_table_exists('ops_report_settings')) {
        return $settings;
    }

    foreach (ops_rows("SELECT setting_key, setting_value FROM ops_report_settings WHERE setting_key LIKE 'kpi_%'") as $row) {
        $settings[(string) $row['setting_key']] = is_numeric($row['setting_value'])
            ? (float) $row['setting_value']
            : (string) $row['setting_value'];
    }

    return $settings;
}

function kpi_load_weights(): array
{
    $defaults = kpi_default_weights();
    if (!ops_table_exists('ops_kpi_weights')) {
        return $defaults;
    }

    $weights = $defaults;
    foreach (ops_rows('SELECT role_group, component_key, component_label, weight_percent FROM ops_kpi_weights') as $row) {
        $role = (string) $row['role_group'];
        $key = (string) $row['component_key'];
        if (!isset($weights[$role])) {
            $weights[$role] = [];
        }
        $weights[$role][$key] = [
            'label' => (string) $row['component_label'],
            'weight' => (float) $row['weight_percent'],
        ];
    }

    return $weights;
}

function kpi_period_bounds(string $period): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        $period = date('Y-m');
    }

    try {
        $start = new DateTimeImmutable($period . '-01 00:00:00');
    } catch (Throwable $e) {
        $start = new DateTimeImmutable(date('Y-m-01 00:00:00'));
        $period = $start->format('Y-m');
    }

    $end = $start->modify('first day of next month');

    return [
        $period,
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $start->format('F Y'),
    ];
}

function kpi_business_window(DateTimeImmutable $day): ?array
{
    $dow = (int) $day->format('N');
    if ($dow === 7) {
        return null;
    }

    if ($dow === 6) {
        return [$day->setTime(9, 0), $day->setTime(13, 0)];
    }

    return [$day->setTime(8, 0), $day->setTime(17, 0)];
}

function kpi_business_minutes(?string $startText, ?string $endText): ?float
{
    if (!$startText || !$endText) {
        return null;
    }

    try {
        $start = new DateTimeImmutable($startText);
        $end = new DateTimeImmutable($endText);
    } catch (Throwable $e) {
        return null;
    }

    if ($end <= $start) {
        return 0.0;
    }

    $minutes = 0.0;
    $cursor = $start->setTime(0, 0);
    $guard = 0;

    while ($cursor < $end && $guard < 370) {
        $window = kpi_business_window($cursor);
        if ($window) {
            [$workStart, $workEnd] = $window;
            $segmentStart = $start > $workStart ? $start : $workStart;
            $segmentEnd = $end < $workEnd ? $end : $workEnd;
            if ($segmentEnd > $segmentStart) {
                $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
            }
        }
        $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        $guard++;
    }

    return round($minutes, 1);
}

function kpi_avg(array $values): ?float
{
    $clean = [];
    foreach ($values as $value) {
        if ($value !== null && is_numeric($value)) {
            $clean[] = (float) $value;
        }
    }

    if (!$clean) {
        return null;
    }

    return round(array_sum($clean) / count($clean), 1);
}

function kpi_ratio_score(float $good, float $total, float $neutral = 85.0): float
{
    if ($total <= 0) {
        return $neutral;
    }

    return round(max(0, min(100, ($good / $total) * 100)), 1);
}

function kpi_speed_score(?float $actualMinutes, float $targetMinutes, float $neutral = 85.0): float
{
    if ($actualMinutes === null || $actualMinutes <= 0 || $targetMinutes <= 0) {
        return $neutral;
    }

    if ($actualMinutes <= $targetMinutes) {
        return 100.0;
    }

    return round(max(20, min(100, ($targetMinutes / $actualMinutes) * 100)), 1);
}

function kpi_penalty_score(float $points, float $penalty = 4.0): float
{
    return round(max(0, 100 - ($points * $penalty)), 1);
}

function kpi_weighted_score(array $componentScores, array $weights): float
{
    $totalWeight = 0.0;
    $weighted = 0.0;
    foreach ($weights as $key => $component) {
        $weight = (float) ($component['weight'] ?? 0);
        $score = (float) ($componentScores[$key] ?? 0);
        $totalWeight += $weight;
        $weighted += $score * $weight;
    }

    if ($totalWeight <= 0) {
        return 0.0;
    }

    return round($weighted / $totalWeight, 1);
}

function kpi_score_class(float $score): string
{
    if ($score >= 90) {
        return 'excellent';
    }
    if ($score >= 80) {
        return 'good';
    }
    if ($score >= 70) {
        return 'needs-work';
    }

    return 'concern';
}

function kpi_tier(float $score): array
{
    if ($score >= 90) {
        return ['Excellent', 'Strong bonus / increment candidate', 1.0];
    }
    if ($score >= 80) {
        return ['Good', 'Bonus eligible if management approves', 0.75];
    }
    if ($score >= 70) {
        return ['Needs Improvement', 'Coaching before bonus decision', 0.25];
    }

    return ['Performance concern', 'No bonus recommended yet', 0.0];
}

function kpi_money(float $amount): string
{
    return 'N$ ' . number_format($amount, 2);
}

function kpi_minutes_label(?float $minutes): string
{
    if ($minutes === null) {
        return '-';
    }

    if ($minutes >= 60) {
        $hours = floor($minutes / 60);
        $mins = round($minutes - ($hours * 60));
        return number_format((float) $hours, 0) . 'h ' . number_format($mins, 0) . 'm';
    }

    return number_format($minutes, 0) . 'm';
}

function kpi_date_value(?string $date): string
{
    if (!$date) {
        return '';
    }

    try {
        return (new DateTimeImmutable($date))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

function kpi_role_group(string $roleKey): string
{
    if ($roleKey === 'packer') {
        return 'packer';
    }

    return 'front';
}

function kpi_parse_json_array(?string $value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function kpi_is_cash_method(?string $method): bool
{
    return strpos(strtolower((string) $method), 'cash') !== false;
}

function kpi_fetch_hr_salary_map(): array
{
    if (!ops_table_exists('employees') || !ops_column_exists('employees', 'basic_salary')) {
        return [];
    }

    $rows = ops_rows(
        "SELECT LOWER(COALESCE(email, '')) AS email_key,
                LOWER(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) AS name_key,
                basic_salary
         FROM employees"
    );

    $map = [];
    foreach ($rows as $row) {
        $salary = (float) ($row['basic_salary'] ?? 0);
        if ((string) ($row['email_key'] ?? '') !== '') {
            $map['email:' . (string) $row['email_key']] = $salary;
        }
        if ((string) ($row['name_key'] ?? '') !== '') {
            $map['name:' . (string) $row['name_key']] = $salary;
        }
    }

    return $map;
}

function kpi_load_inputs(string $period): array
{
    if (!ops_table_exists('ops_kpi_employee_inputs')) {
        return [];
    }

    $inputs = [];
    foreach (ops_rows('SELECT * FROM ops_kpi_employee_inputs WHERE period_month = ?', [$period]) as $row) {
        $inputs[(int) $row['employee_id']] = $row;
    }

    return $inputs;
}

function kpi_input_value(array $input, string $key, float $fallback = 85.0): float
{
    if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
        return $fallback;
    }

    return max(0, min(100, (float) $input[$key]));
}

function kpi_blank_employee(array $employee, float $salary, array $input): array
{
    return [
        'id' => (int) $employee['id'],
        'name' => (string) $employee['full_name'],
        'email' => (string) ($employee['email'] ?? ''),
        'role_key' => (string) $employee['role_key'],
        'role_name' => (string) $employee['role_name'],
        'role_group' => kpi_role_group((string) $employee['role_key']),
        'salary' => (float) ($input['salary_override'] ?? 0) > 0 ? (float) $input['salary_override'] : $salary,
        'input' => [
            'attendance_score' => kpi_input_value($input, 'attendance_score'),
            'reliability_score' => kpi_input_value($input, 'reliability_score'),
            'communication_score' => kpi_input_value($input, 'communication_score'),
            'team_score' => kpi_input_value($input, 'team_score'),
            'manual_score' => kpi_input_value($input, 'manual_score'),
            'notes' => (string) ($input['notes'] ?? ''),
        ],
        'metrics' => [
            'orders_created' => 0,
            'orders_created_completed' => 0,
            'orders_assigned' => 0,
            'orders_assigned_completed' => 0,
            'walkin_completed' => 0,
            'order_total_minutes' => [],
            'order_start_minutes' => [],
            'order_pack_minutes' => [],
            'overdue_orders' => 0,
            'stuck_new_orders' => 0,
            'stuck_progress_orders' => 0,
            'courier_late_orders' => 0,
            'unassigned_orders' => 0,
            'cash_orders_detected' => 0,
            'cash_orders_recorded' => 0,
            'cash_orders_missing' => 0,
            'late_bookkeeping' => 0,
            'cash_entries' => 0,
            'opening_count' => 0,
            'closing_count' => 0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'cash_variance' => 0.0,
            'packing_assigned' => 0,
            'packing_done' => 0,
            'packing_in_progress' => 0,
            'packing_not_started' => 0,
            'packing_label_needed' => 0,
            'packing_website' => 0,
            'packing_workload' => 0.0,
            'packing_start_minutes' => [],
            'packing_done_minutes' => [],
            'packing_total_minutes' => [],
            'website_uploads' => 0,
            'website_late' => 0,
            'website_missing' => 0,
            'tasks_assigned' => 0,
            'tasks_completed' => 0,
            'tasks_overdue' => 0,
            'tasks_in_progress' => 0,
            'tasks_pending' => 0,
            'tasks_needs_review' => 0,
            'task_completion_minutes' => [],
            'tasks_without_notes' => 0,
            'tasks_checklist_missing' => 0,
            'errors_total' => 0,
            'errors_low' => 0,
            'errors_medium' => 0,
            'errors_high' => 0,
            'errors_critical' => 0,
            'errors_repeat' => 0,
            'errors_unresolved' => 0,
            'errors_resolved' => 0,
            'errors_customer' => 0,
            'errors_financial' => 0,
            'error_points' => 0.0,
            'notes_added' => 0,
            'history_events' => 0,
        ],
        'components' => [],
        'overall_score' => 0.0,
        'tier' => ['Performance concern', 'No bonus recommended yet', 0.0],
    ];
}

function kpi_add_minutes(array &$employee, string $metricKey, ?float $minutes): void
{
    if ($minutes !== null) {
        $employee['metrics'][$metricKey][] = $minutes;
    }
}

function kpi_metric_avg(array $employee, string $metricKey): ?float
{
    return kpi_avg($employee['metrics'][$metricKey] ?? []);
}

function kpi_collect(string $period, string $start, string $end, array $settings, array $weights): array
{
    $salaryMap = kpi_fetch_hr_salary_map();
    $inputs = kpi_load_inputs($period);
    $employeeRows = ops_rows(
        "SELECT e.id, e.full_name, e.email, e.phone, r.role_key, r.name AS role_name
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'owner_admin', 'front_desk_admin', 'supervisor_manager', 'packer'), e.full_name"
    );

    $employees = [];
    foreach ($employeeRows as $row) {
        $nameKey = strtolower(trim((string) $row['full_name']));
        $emailKey = strtolower(trim((string) ($row['email'] ?? '')));
        $salary = 0.0;
        if ($emailKey !== '' && isset($salaryMap['email:' . $emailKey])) {
            $salary = $salaryMap['email:' . $emailKey];
        } elseif ($nameKey !== '' && isset($salaryMap['name:' . $nameKey])) {
            $salary = $salaryMap['name:' . $nameKey];
        }
        $id = (int) $row['id'];
        $employees[$id] = kpi_blank_employee($row, $salary, $inputs[$id] ?? []);
    }

    $global = [
        'orders_total' => 0,
        'orders_completed' => 0,
        'orders_unassigned' => 0,
        'orders_overdue' => 0,
        'courier_late' => 0,
        'cash_orders_missing' => 0,
        'packing_total' => 0,
        'packing_unassigned' => 0,
        'website_missing' => 0,
        'tasks_total' => 0,
        'tasks_overdue' => 0,
        'errors_total' => 0,
        'error_points' => 0.0,
        'history_events' => 0,
    ];

    $now = date('Y-m-d H:i:s');
    $targetAssignment = (float) kpi_setting($settings, 'kpi_target_assignment_minutes', 45);
    $targetOrderCompletion = (float) kpi_setting($settings, 'kpi_target_order_completion_minutes', 240);
    $targetPackingTask = (float) kpi_setting($settings, 'kpi_target_packing_task_minutes', 240);
    $targetBookkeeping = (float) kpi_setting($settings, 'kpi_target_bookkeeping_minutes', 90);
    $targetWebsiteUpload = (float) kpi_setting($settings, 'kpi_target_website_upload_minutes', 120);

    $ordersById = [];
    $orders = ops_rows(
        "SELECT *
         FROM ops_orders
         WHERE created_at >= ? AND created_at < ?
         ORDER BY created_at ASC",
        [$start, $end]
    );

    foreach ($orders as $order) {
        $global['orders_total']++;
        $orderId = (int) $order['id'];
        $ordersById[$orderId] = $order;
        $status = (string) ($order['status'] ?? '');
        $createdBy = (int) ($order['created_by'] ?? 0);
        $assignedId = (int) ($order['assigned_packer_id'] ?? 0);
        $createdAt = kpi_date_value((string) ($order['created_at'] ?? ''));
        $completedAt = kpi_date_value((string) ($order['completed_at'] ?? ''));
        $assignedAt = kpi_date_value((string) ($order['assigned_at'] ?? ''));
        $startedAt = kpi_date_value((string) ($order['packing_started_at'] ?? ''));
        $packedAt = kpi_date_value((string) ($order['packed_at'] ?? ''));
        $isCompleted = $status === 'completed' || $completedAt !== '';

        if ($isCompleted) {
            $global['orders_completed']++;
        }

        if ($createdBy > 0 && isset($employees[$createdBy])) {
            $employees[$createdBy]['metrics']['orders_created']++;
            if ($isCompleted) {
                $employees[$createdBy]['metrics']['orders_created_completed']++;
                kpi_add_minutes($employees[$createdBy], 'order_total_minutes', kpi_business_minutes($createdAt, $completedAt ?: $now));
            }
            if (kpi_is_cash_method((string) ($order['payment_method'] ?? ''))) {
                $employees[$createdBy]['metrics']['cash_orders_detected']++;
            }
            if (trim((string) ($order['notes'] ?? '')) !== '') {
                $employees[$createdBy]['metrics']['notes_added']++;
            }
        }

        if ($assignedId > 0 && isset($employees[$assignedId])) {
            $employees[$assignedId]['metrics']['orders_assigned']++;
            if ($isCompleted) {
                $employees[$assignedId]['metrics']['orders_assigned_completed']++;
            }
            kpi_add_minutes($employees[$assignedId], 'order_start_minutes', kpi_business_minutes($assignedAt ?: $createdAt, $startedAt));
            kpi_add_minutes($employees[$assignedId], 'order_pack_minutes', kpi_business_minutes($startedAt ?: $assignedAt ?: $createdAt, $completedAt ?: $packedAt));
        } elseif (!$isCompleted) {
            $global['orders_unassigned']++;
            if ($createdBy > 0 && isset($employees[$createdBy])) {
                $employees[$createdBy]['metrics']['unassigned_orders']++;
            }
        }

        $elapsed = kpi_business_minutes($createdAt, $isCompleted ? ($completedAt ?: $now) : $now);
        if (!$isCompleted && $elapsed !== null && $elapsed > $targetOrderCompletion) {
            $global['orders_overdue']++;
            $ownerId = $assignedId > 0 ? $assignedId : $createdBy;
            if ($ownerId > 0 && isset($employees[$ownerId])) {
                $employees[$ownerId]['metrics']['overdue_orders']++;
            }
        }

        if (!$isCompleted && $status === 'new_order' && $elapsed !== null && $elapsed > $targetAssignment) {
            $ownerId = $createdBy > 0 ? $createdBy : $assignedId;
            if ($ownerId > 0 && isset($employees[$ownerId])) {
                $employees[$ownerId]['metrics']['stuck_new_orders']++;
            }
        }

        if (!$isCompleted && $status === 'in_progress' && $elapsed !== null && $elapsed > $targetOrderCompletion) {
            $ownerId = $assignedId > 0 ? $assignedId : $createdBy;
            if ($ownerId > 0 && isset($employees[$ownerId])) {
                $employees[$ownerId]['metrics']['stuck_progress_orders']++;
            }
        }

        if ((string) ($order['order_type'] ?? '') === 'courier') {
            $late = false;
            if ($completedAt !== '') {
                try {
                    $late = (int) (new DateTimeImmutable($completedAt))->format('Hi') > 1400;
                } catch (Throwable $e) {
                    $late = false;
                }
            } elseif (!$isCompleted) {
                try {
                    $createdDay = new DateTimeImmutable($createdAt ?: $now);
                    $deadline = $createdDay->setTime(14, 0);
                    $late = new DateTimeImmutable($now) > $deadline;
                } catch (Throwable $e) {
                    $late = false;
                }
            }
            if ($late) {
                $global['courier_late']++;
                $ownerId = $assignedId > 0 ? $assignedId : $createdBy;
                if ($ownerId > 0 && isset($employees[$ownerId])) {
                    $employees[$ownerId]['metrics']['courier_late_orders']++;
                }
            }
        }
    }

    if (ops_table_exists('ops_cash_book_entries')) {
        $loggedCashOrders = [];
        $cashArchivedFilter = ops_column_exists('ops_cash_book_entries', 'archived_at') ? ' AND archived_at IS NULL' : '';
        foreach (ops_rows("SELECT DISTINCT related_order_id FROM ops_cash_book_entries WHERE related_order_id IS NOT NULL{$cashArchivedFilter}") as $row) {
            $loggedCashOrders[(int) $row['related_order_id']] = true;
        }

        foreach ($orders as $order) {
            if (!kpi_is_cash_method((string) ($order['payment_method'] ?? ''))) {
                continue;
            }
            $orderId = (int) $order['id'];
            $createdBy = (int) ($order['created_by'] ?? 0);
            if (!empty($loggedCashOrders[$orderId])) {
                if ($createdBy > 0 && isset($employees[$createdBy])) {
                    $employees[$createdBy]['metrics']['cash_orders_recorded']++;
                }
                continue;
            }

            $global['cash_orders_missing']++;
            if ($createdBy > 0 && isset($employees[$createdBy])) {
                $employees[$createdBy]['metrics']['cash_orders_missing']++;
            }
        }

        $cashRows = ops_rows(
            "SELECT c.*, o.created_at AS order_created_at, o.created_by AS order_created_by
             FROM ops_cash_book_entries c
             LEFT JOIN ops_orders o ON o.id = c.related_order_id
             WHERE " . (ops_column_exists('ops_cash_book_entries', 'archived_at') ? 'c.archived_at IS NULL AND ' : '') . "c.transaction_date >= ? AND c.transaction_date < ?",
            [$start, $end]
        );
        foreach ($cashRows as $row) {
            $employeeId = (int) ($row['recorded_by'] ?? 0);
            if ($employeeId <= 0 || !isset($employees[$employeeId])) {
                continue;
            }
            $employees[$employeeId]['metrics']['cash_entries']++;
            $type = (string) ($row['transaction_type'] ?? '');
            if ($type === 'opening_balance') {
                $employees[$employeeId]['metrics']['opening_count']++;
            }
            if ($type === 'closing_count') {
                $employees[$employeeId]['metrics']['closing_count']++;
            }
            $employees[$employeeId]['metrics']['cash_in'] += (float) ($row['cash_in'] ?? 0);
            $employees[$employeeId]['metrics']['cash_out'] += (float) ($row['cash_out'] ?? 0);
            if ($row['actual_count'] !== null && $row['actual_count'] !== '') {
                $employees[$employeeId]['metrics']['cash_variance'] += abs((float) $row['actual_count'] - (float) ($row['running_balance'] ?? 0));
            }
            if (!empty($row['order_created_at'])) {
                $entryDelay = kpi_business_minutes((string) $row['order_created_at'], (string) $row['transaction_date']);
                if ($entryDelay !== null && $entryDelay > $targetBookkeeping) {
                    $employees[$employeeId]['metrics']['late_bookkeeping']++;
                }
            }
            if (trim((string) ($row['notes'] ?? '')) !== '') {
                $employees[$employeeId]['metrics']['notes_added']++;
            }
        }
    }

    $packingRowsById = [];
    if (ops_table_exists('ops_packing_tasks')) {
        $packingRows = ops_rows(
            "SELECT *
             FROM ops_packing_tasks
             WHERE date_loaded >= ? AND date_loaded < ?
                OR (date_completed IS NOT NULL AND date_completed >= ? AND date_completed < ?)
             ORDER BY date_loaded ASC",
            [$start, $end, $start, $end]
        );

        foreach ($packingRows as $row) {
            $global['packing_total']++;
            $taskId = (int) $row['id'];
            $packingRowsById[$taskId] = $row;
            $employeeId = (int) ($row['assigned_employee_id'] ?? 0);
            $status = (string) ($row['packing_status'] ?? 'not_started');
            $dateLoaded = kpi_date_value((string) ($row['date_loaded'] ?? ''));
            $dateStarted = kpi_date_value((string) ($row['date_started'] ?? ''));
            $dateCompleted = kpi_date_value((string) ($row['date_completed'] ?? ''));

            if ($employeeId <= 0 || !isset($employees[$employeeId])) {
                $global['packing_unassigned']++;
            } else {
                $employees[$employeeId]['metrics']['packing_assigned']++;
                $employees[$employeeId]['metrics']['packing_workload'] += (float) ($row['workload_points'] ?? 0);
                if (trim((string) ($row['notes'] ?? '')) !== '') {
                    $employees[$employeeId]['metrics']['notes_added']++;
                }
                if (in_array($status, ['done', 'done_needs_label', 'label_created', 'website'], true) || $dateCompleted !== '') {
                    $employees[$employeeId]['metrics']['packing_done']++;
                }
                if ($status === 'packing') {
                    $employees[$employeeId]['metrics']['packing_in_progress']++;
                }
                if ($status === 'not_started') {
                    $employees[$employeeId]['metrics']['packing_not_started']++;
                }
                if ($status === 'done_needs_label') {
                    $employees[$employeeId]['metrics']['packing_label_needed']++;
                }
                if ($status === 'website') {
                    $employees[$employeeId]['metrics']['packing_website']++;
                }
                kpi_add_minutes($employees[$employeeId], 'packing_start_minutes', kpi_business_minutes($dateLoaded, $dateStarted));
                kpi_add_minutes($employees[$employeeId], 'packing_done_minutes', kpi_business_minutes($dateStarted ?: $dateLoaded, $dateCompleted));
                kpi_add_minutes($employees[$employeeId], 'packing_total_minutes', kpi_business_minutes($dateLoaded, $dateCompleted));
            }

            if ((int) ($row['website_uploaded'] ?? 0) !== 1) {
                $global['website_missing']++;
            }
        }
    }

    if (ops_table_exists('ops_status_history')) {
        $historyRows = ops_rows(
            "SELECT *
             FROM ops_status_history
             WHERE created_at >= ? AND created_at < ?",
            [$start, $end]
        );
        foreach ($historyRows as $row) {
            $global['history_events']++;
            $changedBy = (int) ($row['changed_by_employee_id'] ?? 0);
            if ($changedBy > 0 && isset($employees[$changedBy])) {
                $employees[$changedBy]['metrics']['history_events']++;
            }

            if ((string) $row['module'] === 'packing' && (string) $row['field_name'] === 'website_uploaded' && (string) $row['new_value'] === '1') {
                $employeeId = $changedBy;
                if ($employeeId > 0 && isset($employees[$employeeId])) {
                    $employees[$employeeId]['metrics']['website_uploads']++;
                    $packing = $packingRowsById[(int) $row['record_id']] ?? null;
                    if ($packing) {
                        $delay = kpi_business_minutes((string) ($packing['date_loaded'] ?? ''), (string) $row['created_at']);
                        if ($delay !== null && $delay > $targetWebsiteUpload) {
                            $employees[$employeeId]['metrics']['website_late']++;
                        }
                    }
                }
            }
        }
    }

    $frontIds = [];
    foreach ($employees as $id => $employee) {
        if ($employee['role_group'] === 'front') {
            $frontIds[] = (int) $id;
        }
    }

    if ($global['website_missing'] > 0 && $frontIds) {
        $share = (int) ceil($global['website_missing'] / max(1, count($frontIds)));
        foreach ($frontIds as $id) {
            $employees[$id]['metrics']['website_missing'] += $share;
        }
    }

    $taskWhere = "created_at >= ? AND created_at < ? OR (completed_at IS NOT NULL AND completed_at >= ? AND completed_at < ?)";
    $taskParams = [$start, $end, $start, $end];
    if (ops_column_exists('ops_checklist_tasks', 'date_completed')) {
        $taskWhere .= " OR (date_completed IS NOT NULL AND date_completed >= ? AND date_completed < ?)";
        $taskParams[] = $start;
        $taskParams[] = $end;
    }
    $tasks = ops_rows(
        "SELECT *
         FROM ops_checklist_tasks
         WHERE {$taskWhere}",
        $taskParams
    );
    foreach ($tasks as $task) {
        $global['tasks_total']++;
        $employeeId = (int) ($task['assigned_employee_id'] ?? 0);
        if ($employeeId <= 0 || !isset($employees[$employeeId])) {
            continue;
        }
        $status = (string) ($task['status'] ?? 'not_started');
        $deadline = kpi_date_value((string) ($task['deadline'] ?? ''));
        $createdAt = kpi_date_value((string) (($task['date_assigned'] ?? '') ?: ($task['created_at'] ?? '')));
        $completedAt = kpi_date_value((string) (($task['date_completed'] ?? '') ?: ($task['completed_at'] ?? '')));
        $done = in_array($status, ['done', 'completed', 'approved'], true) || $completedAt !== '';
        $employees[$employeeId]['metrics']['tasks_assigned']++;
        if ($done) {
            $employees[$employeeId]['metrics']['tasks_completed']++;
            kpi_add_minutes($employees[$employeeId], 'task_completion_minutes', kpi_business_minutes($createdAt, $completedAt));
            if (trim((string) ($task['completion_note'] ?? '')) === '') {
                $employees[$employeeId]['metrics']['tasks_without_notes']++;
            }
        } elseif ($status === 'in_progress') {
            $employees[$employeeId]['metrics']['tasks_in_progress']++;
        } elseif ($status === 'needs_review') {
            $employees[$employeeId]['metrics']['tasks_needs_review']++;
        } else {
            $employees[$employeeId]['metrics']['tasks_pending']++;
        }

        if (!$done && $deadline !== '' && kpi_date_value($now) > $deadline) {
            $global['tasks_overdue']++;
            $employees[$employeeId]['metrics']['tasks_overdue']++;
        }

        $items = kpi_parse_json_array((string) ($task['checklist_items'] ?? ''));
        $checked = kpi_parse_json_array((string) ($task['checked_items'] ?? ''));
        if ($done && $items && count(array_intersect($items, $checked)) < count($items)) {
            $employees[$employeeId]['metrics']['tasks_checklist_missing']++;
        }
        if (trim((string) ($task['notes'] ?? '')) !== '' || trim((string) ($task['completion_note'] ?? '')) !== '') {
            $employees[$employeeId]['metrics']['notes_added']++;
        }
    }

    if (ops_table_exists('ops_error_logs')) {
        $statusSelect = ops_column_exists('ops_error_logs', 'status') ? 'status' : "'open' AS status";
        $errors = ops_rows(
            "SELECT *, {$statusSelect}
             FROM ops_error_logs
             WHERE logged_at >= ? AND logged_at < ?",
            [$start, $end]
        );
        $severityPoints = ['low' => 1, 'medium' => 3, 'high' => 6, 'critical' => 10];
        foreach ($errors as $error) {
            $global['errors_total']++;
            $people = kpi_parse_json_array((string) ($error['people_involved'] ?? ''));
            if (!$people && (int) ($error['employee_id'] ?? 0) > 0) {
                $people = [(int) $error['employee_id']];
            }
            if (!$people && (int) ($error['logged_by'] ?? 0) > 0) {
                $people = [(int) $error['logged_by']];
            }
            $severity = (string) ($error['severity'] ?? 'low');
            $points = (float) ($severityPoints[$severity] ?? 1);
            if ((int) ($error['repeat_issue'] ?? 0) === 1) {
                $points += 3;
            }
            if (trim((string) ($error['customer_impact'] ?? '')) !== '') {
                $points += 2;
            }
            if ((float) ($error['financial_impact'] ?? 0) > 0) {
                $points += 2;
            }
            if ((string) ($error['status'] ?? 'open') !== 'resolved') {
                $points += 1;
            }
            $global['error_points'] += $points;

            foreach ($people as $personId) {
                $employeeId = (int) $personId;
                if ($employeeId <= 0 || !isset($employees[$employeeId])) {
                    continue;
                }
                $employees[$employeeId]['metrics']['errors_total']++;
                $employees[$employeeId]['metrics']['errors_' . $severity] = ($employees[$employeeId]['metrics']['errors_' . $severity] ?? 0) + 1;
                if ((int) ($error['repeat_issue'] ?? 0) === 1) {
                    $employees[$employeeId]['metrics']['errors_repeat']++;
                }
                if (trim((string) ($error['customer_impact'] ?? '')) !== '') {
                    $employees[$employeeId]['metrics']['errors_customer']++;
                }
                if ((float) ($error['financial_impact'] ?? 0) > 0) {
                    $employees[$employeeId]['metrics']['errors_financial']++;
                }
                if ((string) ($error['status'] ?? 'open') === 'resolved') {
                    $employees[$employeeId]['metrics']['errors_resolved']++;
                } else {
                    $employees[$employeeId]['metrics']['errors_unresolved']++;
                }
                $employees[$employeeId]['metrics']['error_points'] += $points;
            }
        }
    }

    $errorPenalty = (float) kpi_setting($settings, 'kpi_error_penalty_points', 4);
    $targetPacking = (float) kpi_setting($settings, 'kpi_target_packing_minutes', 90);

    foreach ($employees as $id => &$employee) {
        $m = $employee['metrics'];
        $errorScore = kpi_penalty_score((float) $m['error_points'], $errorPenalty);
        $taskScore = max(
            0,
            min(
                100,
                kpi_ratio_score((float) $m['tasks_completed'], (float) $m['tasks_assigned'])
                - ($m['tasks_overdue'] * 6)
                - ($m['tasks_without_notes'] * 3)
                - ($m['tasks_checklist_missing'] * 5)
            )
        );

        if ($employee['role_group'] === 'front') {
            $orderScore = max(
                0,
                min(
                    100,
                    (kpi_ratio_score((float) $m['orders_created_completed'], (float) $m['orders_created']) * 0.55)
                    + (kpi_speed_score(kpi_metric_avg($employee, 'order_total_minutes'), $targetOrderCompletion) * 0.35)
                    + 10
                    - ($m['unassigned_orders'] * 4)
                    - ($m['stuck_new_orders'] * 4)
                    - ($m['overdue_orders'] * 5)
                )
            );
            $bookkeepingScore = max(
                0,
                min(
                    100,
                    100
                    - ($m['cash_orders_missing'] * 12)
                    - ($m['late_bookkeeping'] * 4)
                    - min(20, $m['cash_variance'] / 10)
                    + min(5, $m['cash_entries'])
                )
            );
            if ($m['cash_orders_detected'] === 0 && $m['cash_entries'] === 0) {
                $bookkeepingScore = 85;
            }
            $websiteTotal = $m['website_uploads'] + $m['website_missing'];
            $websiteScore = max(0, min(100, kpi_ratio_score((float) $m['website_uploads'], (float) $websiteTotal) - ($m['website_late'] * 5)));
            $employee['components'] = [
                'order_flow' => round($orderScore, 1),
                'bookkeeping' => round($bookkeepingScore, 1),
                'website_stock' => round($websiteScore, 1),
                'tasks' => round($taskScore, 1),
                'errors' => round($errorScore, 1),
                'communication' => $employee['input']['communication_score'],
                'reliability' => round(($employee['input']['attendance_score'] + $employee['input']['reliability_score']) / 2, 1),
            ];
        } else {
            $orderSpeedScore = max(
                0,
                min(
                    100,
                    (kpi_speed_score(kpi_metric_avg($employee, 'order_start_minutes'), $targetAssignment) * 0.35)
                    + (kpi_speed_score(kpi_metric_avg($employee, 'order_pack_minutes'), $targetPacking) * 0.45)
                    + (kpi_ratio_score((float) $m['orders_assigned_completed'], (float) $m['orders_assigned']) * 0.2)
                )
            );
            $packingProductivity = max(
                0,
                min(
                    100,
                    (kpi_ratio_score((float) $m['packing_done'], (float) $m['packing_assigned']) * 0.65)
                    + (kpi_speed_score(kpi_metric_avg($employee, 'packing_total_minutes'), $targetPackingTask) * 0.25)
                    + min(10, $m['packing_workload'] / 10)
                )
            );
            $packingAccuracy = max(
                0,
                min(100, $errorScore - ($m['packing_label_needed'] * 3) - ($m['packing_not_started'] * 2))
            );
            $employee['components'] = [
                'order_speed' => round($orderSpeedScore, 1),
                'packing_productivity' => round($packingProductivity, 1),
                'packing_accuracy' => round($packingAccuracy, 1),
                'tasks' => round($taskScore, 1),
                'errors' => round($errorScore, 1),
                'team' => $employee['input']['team_score'],
            ];
        }

        $employee['overall_score'] = kpi_weighted_score($employee['components'], $weights[$employee['role_group']] ?? []);
        $employee['tier'] = kpi_tier((float) $employee['overall_score']);
    }
    unset($employee);

    uasort($employees, static function (array $a, array $b): int {
        if ($a['overall_score'] === $b['overall_score']) {
            return strcmp($a['name'], $b['name']);
        }

        return $a['overall_score'] < $b['overall_score'] ? 1 : -1;
    });

    return [$employees, $global, $ordersById, $packingRowsById];
}

function kpi_metric_card(string $label, string $value, string $hint, string $icon = 'activity', string $tone = 'metric-blue'): void
{
    echo '<article class="work-metric-card ' . kpi_e($tone) . '">';
    echo '<span class="metric-icon"><i data-lucide="' . kpi_e($icon) . '"></i></span>';
    echo '<div><span>' . kpi_e($label) . '</span><strong>' . kpi_e($value) . '</strong><small>' . kpi_e($hint) . '</small></div>';
    echo '</article>';
}

function kpi_employee_row(array $employee, string $period): void
{
    [$tierLabel, $recommendation, $bonusFactor] = $employee['tier'];
    $score = (float) $employee['overall_score'];
    $bonusPercent = (float) kpi_setting(kpi_load_settings(), 'kpi_monthly_bonus_percent', 5);
    $estimatedBonus = (float) $employee['salary'] * ($bonusPercent / 100) * (float) $bonusFactor;
    echo '<tr>';
    echo '<td><strong>' . kpi_e($employee['name']) . '</strong><small>' . kpi_e($employee['role_name']) . '</small></td>';
    echo '<td><span class="kpi-score-pill ' . kpi_e(kpi_score_class($score)) . '">' . number_format($score, 1) . '%</span></td>';
    echo '<td>' . kpi_e($tierLabel) . '</td>';
    echo '<td>' . kpi_money((float) $employee['salary']) . '</td>';
    echo '<td>' . kpi_money($estimatedBonus) . '</td>';
    echo '<td>' . kpi_e($recommendation) . '</td>';
    echo '<td><a class="button small" href="reports.php?period=' . kpi_e($period) . '&tab=employees&employee=' . (int) $employee['id'] . '">View profile</a></td>';
    echo '</tr>';
}

function kpi_component_list(array $employee, array $weights): void
{
    echo '<div class="kpi-component-list">';
    foreach ($weights[$employee['role_group']] ?? [] as $key => $component) {
        $score = (float) ($employee['components'][$key] ?? 0);
        echo '<div><span>' . kpi_e($component['label']) . '<small>' . number_format((float) $component['weight'], 0) . '% weight</small></span><strong>' . number_format($score, 1) . '%</strong><em><i style="width:' . max(0, min(100, $score)) . '%"></i></em></div>';
    }
    echo '</div>';
}

if ($ready) {
    kpi_bootstrap_schema();
}

[$period, $periodStart, $periodEnd, $periodLabel] = kpi_period_bounds((string) ($_GET['period'] ?? date('Y-m')));
$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'front', 'packers', 'employees', 'orders', 'packing', 'bookkeeping', 'tasks', 'errors', 'bonus'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$message = '';
$messageType = 'success';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'save_kpi_settings') {
            $settingKeys = array_keys(kpi_default_settings());
            $stmt = db()->prepare(
                "INSERT INTO ops_report_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            foreach ($settingKeys as $key) {
                $value = max(0, (float) ($_POST[$key] ?? 0));
                $stmt->execute([$key, (string) $value]);
            }
            $message = 'KPI targets saved.';
        }

        if ($action === 'save_kpi_weights') {
            $weightsPost = $_POST['weights'] ?? [];
            $stmt = db()->prepare(
                "INSERT INTO ops_kpi_weights (role_group, component_key, component_label, weight_percent)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE weight_percent = VALUES(weight_percent)"
            );
            foreach (kpi_default_weights() as $role => $components) {
                foreach ($components as $key => $component) {
                    $weight = max(0, min(100, (float) ($weightsPost[$role][$key] ?? $component['weight'])));
                    $stmt->execute([$role, $key, $component['label'], $weight]);
                }
            }
            $message = 'KPI weights saved.';
        }

        if ($action === 'save_kpi_inputs') {
            $periodPost = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period_month'] ?? ''))
                ? (string) $_POST['period_month']
                : $period;
            $stmt = db()->prepare(
                "INSERT INTO ops_kpi_employee_inputs
                 (period_month, employee_id, salary_override, attendance_score, reliability_score, communication_score, team_score, manual_score, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    salary_override = VALUES(salary_override),
                    attendance_score = VALUES(attendance_score),
                    reliability_score = VALUES(reliability_score),
                    communication_score = VALUES(communication_score),
                    team_score = VALUES(team_score),
                    manual_score = VALUES(manual_score),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by)"
            );
            foreach ($_POST['inputs'] ?? [] as $employeeId => $input) {
                $employeeId = (int) $employeeId;
                if ($employeeId <= 0) {
                    continue;
                }
                $stmt->execute([
                    $periodPost,
                    $employeeId,
                    (float) ($input['salary_override'] ?? 0) > 0 ? (float) $input['salary_override'] : null,
                    max(0, min(100, (float) ($input['attendance_score'] ?? 85))),
                    max(0, min(100, (float) ($input['reliability_score'] ?? 85))),
                    max(0, min(100, (float) ($input['communication_score'] ?? 85))),
                    max(0, min(100, (float) ($input['team_score'] ?? 85))),
                    max(0, min(100, (float) ($input['manual_score'] ?? 85))),
                    substr(trim((string) ($input['notes'] ?? '')), 0, 1500),
                    ops_current_employee_id(),
                ]);
            }
            $period = $periodPost;
            $message = 'Employee KPI inputs saved.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$settings = $ready ? kpi_load_settings() : kpi_default_settings();
$weights = $ready ? kpi_load_weights() : kpi_default_weights();
[$employees, $global, $ordersById, $packingRowsById] = $ready
    ? kpi_collect($period, $periodStart, $periodEnd, $settings, $weights)
    : [[], [], [], []];

$frontEmployees = array_filter($employees, static function (array $employee): bool {
    return $employee['role_group'] === 'front';
});
$packerEmployees = array_filter($employees, static function (array $employee): bool {
    return $employee['role_group'] === 'packer';
});
$averageScore = $employees ? round(array_sum(array_column($employees, 'overall_score')) / count($employees), 1) : 0;
$excellentCount = count(array_filter($employees, static function (array $employee): bool {
    return (float) $employee['overall_score'] >= 90;
}));
$concernCount = count(array_filter($employees, static function (array $employee): bool {
    return (float) $employee['overall_score'] < 70;
}));
$selectedEmployeeId = (int) ($_GET['employee'] ?? 0);
$selectedEmployee = $selectedEmployeeId > 0 && isset($employees[$selectedEmployeeId]) ? $employees[$selectedEmployeeId] : null;

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-module">
    <section class="module-header kpi-hero">
        <div>
            <p class="eyebrow">KPI Reports</p>
            <h1>Employee Performance Engine</h1>
            <p>Role-based performance using orders, packing, bookkeeping, tasks, errors, HR salary data and working-hour timing.</p>
        </div>
        <form class="kpi-period-form" method="get">
            <input type="hidden" name="tab" value="<?= kpi_e($tab) ?>">
            <label>Report month<input type="month" name="period" value="<?= kpi_e($period) ?>"></label>
            <button class="button primary" type="submit"><i data-lucide="refresh-cw"></i> Load</button>
        </form>
    </section>

    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php if ($message !== '') { ops_flash($message, $messageType); } ?>

    <section class="work-metric-grid kpi-summary-grid">
        <?php
        kpi_metric_card('Average KPI Score', number_format((float) $averageScore, 1) . '%', $periodLabel, 'gauge', 'metric-blue');
        kpi_metric_card('Employees Tracked', number_format(count($employees)), 'Active operations employees', 'users', 'metric-purple');
        kpi_metric_card('Bonus Candidates', number_format($excellentCount), 'Employees scoring 90%+', 'badge-check', 'metric-green');
        kpi_metric_card('Coaching Needed', number_format($concernCount), 'Employees below 70%', 'triangle-alert', 'metric-red');
        kpi_metric_card('Overdue Orders', number_format((int) ($global['orders_overdue'] ?? 0)), 'Outside target business hours', 'clock-alert', 'metric-orange');
        kpi_metric_card('Missing Cash Entries', number_format((int) ($global['cash_orders_missing'] ?? 0)), 'Cash orders not in bookkeeping', 'wallet-cards', 'metric-pink');
        ?>
    </section>

    <nav class="kpi-tabs" aria-label="KPI report tabs">
        <?php
        $tabs = [
            'overview' => 'Overview Dashboard',
            'front' => 'Front Desk Performance',
            'packers' => 'Packer Performance',
            'employees' => 'Individual Profiles',
            'orders' => 'Orders KPI',
            'packing' => 'Packing KPI',
            'bookkeeping' => 'Bookkeeping KPI',
            'tasks' => 'Task KPI',
            'errors' => 'Error KPI',
            'bonus' => 'Bonus / Increment Score',
        ];
        foreach ($tabs as $key => $label):
            $class = $tab === $key ? 'active' : '';
        ?>
            <a class="<?= kpi_e($class) ?>" href="reports.php?period=<?= kpi_e($period) ?>&tab=<?= kpi_e($key) ?>"><?= kpi_e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($selectedEmployee): ?>
        <section class="panel kpi-profile-panel">
            <div class="section-row">
                <div>
                    <p class="eyebrow">Individual Employee Profile</p>
                    <h2><?= kpi_e($selectedEmployee['name']) ?></h2>
                    <p><?= kpi_e($selectedEmployee['role_name']) ?> · <?= kpi_e($periodLabel) ?></p>
                </div>
                <a class="button" href="reports.php?period=<?= kpi_e($period) ?>&tab=employees"><i data-lucide="arrow-left"></i> Back to profiles</a>
            </div>
            <div class="kpi-profile-grid">
                <article class="kpi-score-card <?= kpi_e(kpi_score_class((float) $selectedEmployee['overall_score'])) ?>">
                    <span>Overall Score</span>
                    <strong><?= number_format((float) $selectedEmployee['overall_score'], 1) ?>%</strong>
                    <small><?= kpi_e($selectedEmployee['tier'][0]) ?> · <?= kpi_e($selectedEmployee['tier'][1]) ?></small>
                </article>
                <article><span>Salary from HR / override</span><strong><?= kpi_money((float) $selectedEmployee['salary']) ?></strong><small>Used for advisory bonus estimate.</small></article>
                <article><span>Errors</span><strong><?= number_format((int) $selectedEmployee['metrics']['errors_total']) ?></strong><small><?= number_format((float) $selectedEmployee['metrics']['error_points'], 1) ?> penalty points</small></article>
                <article><span>Notes / history</span><strong><?= number_format((int) $selectedEmployee['metrics']['notes_added']) ?></strong><small><?= number_format((int) $selectedEmployee['metrics']['history_events']) ?> status changes</small></article>
            </div>
            <?php kpi_component_list($selectedEmployee, $weights); ?>
            <div class="kpi-profile-breakdown">
                <article>
                    <h3>Orders</h3>
                    <p>Created <?= number_format((int) $selectedEmployee['metrics']['orders_created']) ?>, assigned <?= number_format((int) $selectedEmployee['metrics']['orders_assigned']) ?>, completed <?= number_format((int) ($selectedEmployee['metrics']['orders_created_completed'] + $selectedEmployee['metrics']['orders_assigned_completed'])) ?>.</p>
                    <small>Avg business-hour completion: <?= kpi_minutes_label(kpi_metric_avg($selectedEmployee, 'order_total_minutes')) ?>. Avg pack time: <?= kpi_minutes_label(kpi_metric_avg($selectedEmployee, 'order_pack_minutes')) ?>.</small>
                </article>
                <article>
                    <h3>Packing</h3>
                    <p><?= number_format((int) $selectedEmployee['metrics']['packing_done']) ?> of <?= number_format((int) $selectedEmployee['metrics']['packing_assigned']) ?> assigned packing rows done.</p>
                    <small><?= number_format((float) $selectedEmployee['metrics']['packing_workload'], 1) ?> workload points. Avg task completion <?= kpi_minutes_label(kpi_metric_avg($selectedEmployee, 'packing_total_minutes')) ?>.</small>
                </article>
                <article>
                    <h3>Tasks</h3>
                    <p><?= number_format((int) $selectedEmployee['metrics']['tasks_completed']) ?> of <?= number_format((int) $selectedEmployee['metrics']['tasks_assigned']) ?> completed.</p>
                    <small><?= number_format((int) $selectedEmployee['metrics']['tasks_overdue']) ?> overdue, <?= number_format((int) $selectedEmployee['metrics']['tasks_without_notes']) ?> missing completion notes.</small>
                </article>
                <article>
                    <h3>Bookkeeping / Website</h3>
                    <p><?= number_format((int) $selectedEmployee['metrics']['cash_orders_missing']) ?> missing cash entries, <?= number_format((int) $selectedEmployee['metrics']['website_uploads']) ?> website stock uploads.</p>
                    <small><?= number_format((int) $selectedEmployee['metrics']['late_bookkeeping']) ?> late cash entries, <?= number_format((int) $selectedEmployee['metrics']['website_late']) ?> late website updates.</small>
                </article>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'overview'): ?>
        <section class="panel">
            <div class="section-row"><h2>Role-based KPI scoreboard</h2><span class="status"><?= kpi_e($periodLabel) ?></span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Score</th><th>Band</th><th>Salary</th><th>Estimated bonus</th><th>Recommendation</th><th>Profile</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee) { kpi_employee_row($employee, $period); } ?>
                    <?php if (!$employees): ?><tr><td colspan="7">No employee KPI data available yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kpi-two-column">
            <article class="panel">
                <div class="section-row"><h2>Front desk / admin focus</h2><span class="status"><?= number_format(count($frontEmployees)) ?> people</span></div>
                <?php foreach ($frontEmployees as $employee): ?>
                    <div class="kpi-mini-row">
                        <strong><?= kpi_e($employee['name']) ?></strong>
                        <span><?= number_format((float) $employee['overall_score'], 1) ?>%</span>
                        <small>Cash missing <?= number_format((int) $employee['metrics']['cash_orders_missing']) ?> · Website uploads <?= number_format((int) $employee['metrics']['website_uploads']) ?></small>
                    </div>
                <?php endforeach; ?>
            </article>
            <article class="panel">
                <div class="section-row"><h2>Packer focus</h2><span class="status"><?= number_format(count($packerEmployees)) ?> people</span></div>
                <?php foreach ($packerEmployees as $employee): ?>
                    <div class="kpi-mini-row">
                        <strong><?= kpi_e($employee['name']) ?></strong>
                        <span><?= number_format((float) $employee['overall_score'], 1) ?>%</span>
                        <small>Packing rows <?= number_format((int) $employee['metrics']['packing_done']) ?>/<?= number_format((int) $employee['metrics']['packing_assigned']) ?> · Errors <?= number_format((int) $employee['metrics']['errors_total']) ?></small>
                    </div>
                <?php endforeach; ?>
            </article>
        </section>
    <?php endif; ?>

    <?php if (in_array($tab, ['front', 'packers', 'employees', 'bonus'], true)): ?>
        <?php
        $rows = $tab === 'front' ? $frontEmployees : ($tab === 'packers' ? $packerEmployees : $employees);
        if ($tab === 'bonus') {
            $rows = $employees;
        }
        ?>
        <section class="panel">
            <div class="section-row">
                <h2><?= $tab === 'front' ? 'Front Desk Performance' : ($tab === 'packers' ? 'Packer Performance' : ($tab === 'bonus' ? 'Bonus / Increment Score' : 'Individual Employee Profiles')) ?></h2>
                <span class="status"><?= kpi_e($periodLabel) ?></span>
            </div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead>
                    <tr>
                        <th>Employee</th><th>Score</th><th>Band</th><th>Orders</th><th>Packing</th><th>Tasks</th><th>Bookkeeping</th><th>Errors</th><th>Salary</th><th>Profile</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong><small><?= kpi_e($employee['role_name']) ?></small></td>
                            <td><span class="kpi-score-pill <?= kpi_e(kpi_score_class((float) $employee['overall_score'])) ?>"><?= number_format((float) $employee['overall_score'], 1) ?>%</span></td>
                            <td><?= kpi_e($employee['tier'][0]) ?></td>
                            <td><?= number_format((int) $employee['metrics']['orders_assigned_completed'] + (int) $employee['metrics']['orders_created_completed']) ?>/<?= number_format((int) $employee['metrics']['orders_assigned'] + (int) $employee['metrics']['orders_created']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_done']) ?>/<?= number_format((int) $employee['metrics']['packing_assigned']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_completed']) ?>/<?= number_format((int) $employee['metrics']['tasks_assigned']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['cash_orders_missing']) ?> missing</td>
                            <td><?= number_format((int) $employee['metrics']['errors_total']) ?></td>
                            <td><?= kpi_money((float) $employee['salary']) ?></td>
                            <td><a class="button small" href="reports.php?period=<?= kpi_e($period) ?>&tab=employees&employee=<?= (int) $employee['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="10">No employees match this role yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'orders'): ?>
        <section class="work-metric-grid">
            <?php
            kpi_metric_card('Orders Received', number_format((int) ($global['orders_total'] ?? 0)), 'Created in selected period', 'shopping-bag', 'metric-blue');
            kpi_metric_card('Orders Completed', number_format((int) ($global['orders_completed'] ?? 0)), 'Completed in the period', 'check-circle-2', 'metric-green');
            kpi_metric_card('Unassigned Orders', number_format((int) ($global['orders_unassigned'] ?? 0)), 'No packer assigned', 'user-x', 'metric-orange');
            kpi_metric_card('Courier Late', number_format((int) ($global['courier_late'] ?? 0)), 'Not packed before 14:00', 'truck', 'metric-red');
            ?>
        </section>
        <section class="panel">
            <div class="section-row"><h2>Orders KPI by employee</h2></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Created</th><th>Assigned</th><th>Completed</th><th>Avg start</th><th>Avg pack</th><th>Avg total</th><th>Overdue</th><th>Courier late</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong></td>
                            <td><?= number_format((int) $employee['metrics']['orders_created']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['orders_assigned']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['orders_created_completed'] + (int) $employee['metrics']['orders_assigned_completed']) ?></td>
                            <td><?= kpi_minutes_label(kpi_metric_avg($employee, 'order_start_minutes')) ?></td>
                            <td><?= kpi_minutes_label(kpi_metric_avg($employee, 'order_pack_minutes')) ?></td>
                            <td><?= kpi_minutes_label(kpi_metric_avg($employee, 'order_total_minutes')) ?></td>
                            <td><?= number_format((int) $employee['metrics']['overdue_orders']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['courier_late_orders']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'packing'): ?>
        <section class="work-metric-grid">
            <?php
            kpi_metric_card('Packing Rows', number_format((int) ($global['packing_total'] ?? 0)), 'Loaded in this period', 'package', 'metric-purple');
            kpi_metric_card('Unassigned Rows', number_format((int) ($global['packing_unassigned'] ?? 0)), 'Need packer assignment', 'user-x', 'metric-orange');
            kpi_metric_card('Website Missing', number_format((int) ($global['website_missing'] ?? 0)), 'Admin stock upload not ticked', 'upload-cloud', 'metric-red');
            kpi_metric_card('Status History', number_format((int) ($global['history_events'] ?? 0)), 'Tracked changes this month', 'history', 'metric-blue');
            ?>
        </section>
        <section class="panel">
            <div class="section-row"><h2>Packing List KPI</h2></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Assigned</th><th>Done</th><th>Workload</th><th>Not Started</th><th>Packing</th><th>Label Needed</th><th>Website</th><th>Avg Completion</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong></td>
                            <td><?= number_format((int) $employee['metrics']['packing_assigned']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_done']) ?></td>
                            <td><?= number_format((float) $employee['metrics']['packing_workload'], 1) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_not_started']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_in_progress']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_label_needed']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['packing_website']) ?></td>
                            <td><?= kpi_minutes_label(kpi_metric_avg($employee, 'packing_total_minutes')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'bookkeeping'): ?>
        <section class="panel">
            <div class="section-row"><h2>Bookkeeping KPI</h2><span class="status">Front desk/admin ownership</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Cash Detected</th><th>Recorded</th><th>Missing</th><th>Late Entries</th><th>Opening</th><th>Closing</th><th>Cash In</th><th>Cash Out</th><th>Variance</th></tr></thead>
                    <tbody>
                    <?php foreach ($frontEmployees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong></td>
                            <td><?= number_format((int) $employee['metrics']['cash_orders_detected']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['cash_orders_recorded']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['cash_orders_missing']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['late_bookkeeping']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['opening_count']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['closing_count']) ?></td>
                            <td><?= kpi_money((float) $employee['metrics']['cash_in']) ?></td>
                            <td><?= kpi_money((float) $employee['metrics']['cash_out']) ?></td>
                            <td><?= kpi_money((float) $employee['metrics']['cash_variance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$frontEmployees): ?><tr><td colspan="10">No front desk/admin users available.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'tasks'): ?>
        <section class="panel">
            <div class="section-row"><h2>Task KPI</h2><span class="status">Includes recurring cleaning and Saturday task compliance</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Assigned</th><th>Completed</th><th>Overdue</th><th>In Progress</th><th>Pending</th><th>Needs Review</th><th>No Note</th><th>Checklist Missing</th><th>Avg Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_assigned']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_completed']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_overdue']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_in_progress']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_pending']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_needs_review']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_without_notes']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['tasks_checklist_missing']) ?></td>
                            <td><?= kpi_minutes_label(kpi_metric_avg($employee, 'task_completion_minutes')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'errors'): ?>
        <section class="panel">
            <div class="section-row"><h2>Error KPI</h2><span class="status">Severity penalties affect score</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Total</th><th>Low</th><th>Medium</th><th>High</th><th>Critical</th><th>Repeat</th><th>Unresolved</th><th>Customer Impact</th><th>Financial</th><th>Penalty Points</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong></td>
                            <td><?= number_format((int) $employee['metrics']['errors_total']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_low']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_medium']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_high']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_critical']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_repeat']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_unresolved']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_customer']) ?></td>
                            <td><?= number_format((int) $employee['metrics']['errors_financial']) ?></td>
                            <td><?= number_format((float) $employee['metrics']['error_points'], 1) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'bonus'): ?>
        <section class="kpi-two-column">
            <form class="panel ops-form" method="post">
                <input type="hidden" name="action" value="save_kpi_settings">
                <div class="section-row"><h2>KPI targets</h2><span class="status">Admin adjustable</span></div>
                <div class="form-grid compact">
                    <?php foreach (kpi_default_settings() as $key => $fallback): ?>
                        <label><?= kpi_e(ucwords(str_replace(['kpi_', '_'], ['', ' '], $key))) ?><input type="number" step="0.01" name="<?= kpi_e($key) ?>" value="<?= kpi_e(kpi_setting($settings, $key, $fallback)) ?>"></label>
                    <?php endforeach; ?>
                </div>
                <div class="ops-form-actions"><button class="button primary" type="submit">Save targets</button></div>
            </form>
            <form class="panel ops-form" method="post">
                <input type="hidden" name="action" value="save_kpi_weights">
                <div class="section-row"><h2>Score weights</h2><span class="status">Must total near 100 per role</span></div>
                <?php foreach ($weights as $role => $components): ?>
                    <h3><?= $role === 'front' ? 'Front Desk/Admin' : 'Packer' ?></h3>
                    <div class="form-grid compact">
                        <?php foreach ($components as $key => $component): ?>
                            <label><?= kpi_e($component['label']) ?><input type="number" step="0.01" name="weights[<?= kpi_e($role) ?>][<?= kpi_e($key) ?>]" value="<?= kpi_e($component['weight']) ?>"></label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="ops-form-actions"><button class="button primary" type="submit">Save weights</button></div>
            </form>
        </section>

        <form class="panel ops-form" method="post">
            <input type="hidden" name="action" value="save_kpi_inputs">
            <input type="hidden" name="period_month" value="<?= kpi_e($period) ?>">
            <div class="section-row"><h2>Manual KPI inputs and salary override</h2><span class="status">Used only where data cannot be automated</span></div>
            <div class="table-scroll">
                <table class="data-table ops-table kpi-input-table">
                    <thead><tr><th>Employee</th><th>Salary Override</th><th>Attendance</th><th>Reliability</th><th>Communication</th><th>Team</th><th>Manual</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= kpi_e($employee['name']) ?></strong><small><?= kpi_e($employee['role_name']) ?></small></td>
                            <td><input type="number" step="0.01" name="inputs[<?= (int) $employee['id'] ?>][salary_override]" value="<?= $employee['salary'] > 0 ? kpi_e($employee['salary']) : '' ?>" placeholder="Use HR salary"></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="inputs[<?= (int) $employee['id'] ?>][attendance_score]" value="<?= kpi_e($employee['input']['attendance_score']) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="inputs[<?= (int) $employee['id'] ?>][reliability_score]" value="<?= kpi_e($employee['input']['reliability_score']) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="inputs[<?= (int) $employee['id'] ?>][communication_score]" value="<?= kpi_e($employee['input']['communication_score']) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="inputs[<?= (int) $employee['id'] ?>][team_score]" value="<?= kpi_e($employee['input']['team_score']) ?>"></td>
                            <td><input type="number" step="0.01" min="0" max="100" name="inputs[<?= (int) $employee['id'] ?>][manual_score]" value="<?= kpi_e($employee['input']['manual_score']) ?>"></td>
                            <td><input name="inputs[<?= (int) $employee['id'] ?>][notes]" value="<?= kpi_e($employee['input']['notes']) ?>" placeholder="Coaching or context notes"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ops-form-actions"><button class="button primary" type="submit">Save employee inputs</button></div>
        </form>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
