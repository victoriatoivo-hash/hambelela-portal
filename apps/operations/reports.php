<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'supervisor_manager');

$pageTitle = 'KPI Reports | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$period = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? '')) ? (string) $_GET['period'] : date('Y-m');
$periodStart = $period . '-01 00:00:00';
$periodEnd = (new DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d H:i:s');
$previousPeriod = (new DateTimeImmutable($periodStart))->modify('-1 month')->format('Y-m');
$previousStart = $previousPeriod . '-01 00:00:00';
$previousEnd = $periodStart;
$activeTab = preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['tab'] ?? 'overview')) ?: 'overview';
$selectedEmployeeId = max(0, (int) ($_GET['employee_id'] ?? 0));

function kpi_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // KPI setup must not block the report if the database user lacks ALTER rights.
    }
}

function kpi_bootstrap(): void
{
    kpi_try_sql("ALTER TABLE ops_employees ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER status");

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_report_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_employee_inputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            period_month CHAR(7) NOT NULL,
            monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            attendance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            reliability_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            compliance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            team_contribution_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            admin_accuracy_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            dispatch_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            operational_accuracy_score DECIMAL(5,2) NOT NULL DEFAULT 85,
            notes TEXT,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_employee_period (employee_id, period_month),
            FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES ops_employees(id)
        )"
    );
    kpi_try_sql("ALTER TABLE ops_kpi_employee_inputs ADD COLUMN communication_score DECIMAL(5,2) NOT NULL DEFAULT 85 AFTER team_contribution_score");

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_status_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(80) NOT NULL,
            record_id BIGINT NOT NULL,
            old_status VARCHAR(120) NULL,
            new_status VARCHAR(120) NULL,
            changed_by INT NULL,
            assigned_employee_id INT NULL,
            metadata JSON NULL,
            occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kpi_history_module_record (module_key, record_id, occurred_at),
            INDEX idx_kpi_history_employee (assigned_employee_id, occurred_at),
            INDEX idx_kpi_history_changed_by (changed_by, occurred_at),
            FOREIGN KEY (changed_by) REFERENCES ops_employees(id),
            FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_role_weights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_group VARCHAR(40) NOT NULL,
            component_key VARCHAR(80) NOT NULL,
            component_label VARCHAR(160) NOT NULL,
            weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_role_component (role_group, component_key)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_kpi_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reward_name VARCHAR(160) NOT NULL,
            reward_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            reward_type VARCHAR(80) NOT NULL DEFAULT 'recognition',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_order_stage_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            stage_key VARCHAR(80) NOT NULL,
            employee_id INT NULL,
            metadata JSON NULL,
            occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stage_order (order_id, stage_key),
            INDEX idx_stage_employee (employee_id, occurred_at),
            FOREIGN KEY (order_id) REFERENCES ops_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
        )"
    );

    foreach ([
        ['Driving lesson sponsorship', 800.00, 'development'],
        ['Employee of the Month', 0.00, 'recognition'],
        ['Gift voucher', 0.00, 'voucher'],
        ['Additional cash reward', 0.00, 'cash'],
        ['Performance certificate', 0.00, 'recognition'],
    ] as [$name, $value, $type]) {
        try {
            $stmt = db()->prepare('INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = ?)');
            $stmt->execute([$name, $value, $type, $name]);
        } catch (Throwable $e) {
            // Defaults are helpful but not critical.
        }
    }

    foreach (kpi_default_weights() as $group => $weights) {
        foreach ($weights as $key => $row) {
            try {
                $stmt = db()->prepare(
                    "INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
                     SELECT ?, ?, ?, ?
                     WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = ? AND component_key = ?)"
                );
                $stmt->execute([$group, $key, $row['label'], $row['weight'], $group, $key]);
            } catch (Throwable $e) {
                // Defaults are helpful but not critical.
            }
        }
    }
}

function kpi_default_weights(): array
{
    return [
        'front_desk' => [
            'orders' => ['label' => 'Order / walk-in completion', 'weight' => 20],
            'bookkeeping' => ['label' => 'Bookkeeping accuracy', 'weight' => 20],
            'website_stock' => ['label' => 'Website stock upload', 'weight' => 15],
            'tasks' => ['label' => 'Task completion', 'weight' => 15],
            'errors' => ['label' => 'Error score', 'weight' => 15],
            'communication' => ['label' => 'Communication / manual assessment', 'weight' => 10],
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

function kpi_role_group(string $roleKey): string
{
    return in_array($roleKey, ['packer', 'supervisor_manager'], true) ? 'packer' : 'front_desk';
}

function kpi_role_weights(): array
{
    $weights = kpi_default_weights();
    if (!ops_table_exists('ops_kpi_role_weights')) {
        return $weights;
    }
    foreach (ops_rows('SELECT role_group, component_key, component_label, weight_percent FROM ops_kpi_role_weights WHERE active = 1') as $row) {
        $weights[(string) $row['role_group']][(string) $row['component_key']] = [
            'label' => (string) $row['component_label'],
            'weight' => (float) $row['weight_percent'],
        ];
    }
    return $weights;
}

function kpi_setting(string $key, string $default): string
{
    if (!ops_table_exists('ops_report_settings')) {
        return $default;
    }

    $rows = ops_rows('SELECT setting_value FROM ops_report_settings WHERE setting_key = ? LIMIT 1', [$key]);
    return $rows ? (string) $rows[0]['setting_value'] : $default;
}

function kpi_save_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        "INSERT INTO ops_report_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

function kpi_money(float $amount): string
{
    return 'N$ ' . number_format($amount, 2);
}

function kpi_percent(float $value): string
{
    return number_format($value, 1) . '%';
}

function kpi_score(float $score): float
{
    return round(max(0, min(100, $score)), 1);
}

function kpi_ratio_score(float $actual, float $target): float
{
    if ($target <= 0) {
        return $actual > 0 ? 100.0 : 0.0;
    }

    return kpi_score(($actual / $target) * 100);
}

function kpi_speed_score(?float $minutes, float $targetMinutes): float
{
    if ($minutes === null || $minutes <= 0) {
        return 0.0;
    }
    if ($targetMinutes <= 0) {
        return 100.0;
    }

    return kpi_score(($targetMinutes / max(1.0, $minutes)) * 100);
}

function kpi_penalty_score(float $errorPoints, float $penalty): float
{
    return kpi_score(100 - ($errorPoints * $penalty));
}

function kpi_duration(?float $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        return '-';
    }

    $minutes = (int) round($minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    return $hours > 0 ? $hours . 'h ' . $remaining . 'm' : $remaining . 'm';
}

function kpi_business_window(DateTimeImmutable $date): ?array
{
    $day = (int) $date->format('N');
    if ($day >= 1 && $day <= 5) {
        return [$date->setTime(8, 0), $date->setTime(17, 0)];
    }
    if ($day === 6) {
        return [$date->setTime(9, 0), $date->setTime(13, 0)];
    }
    return null;
}

function kpi_next_business_open(DateTimeImmutable $date): DateTimeImmutable
{
    for ($i = 0; $i < 10; $i++) {
        $window = kpi_business_window($date);
        if ($window) {
            [$open, $close] = $window;
            if ($date < $open) {
                return $open;
            }
            if ($date >= $open && $date < $close) {
                return $date;
            }
        }
        $date = $date->modify('+1 day')->setTime(8, 0);
    }
    return $date;
}

function kpi_business_minutes(?string $from, ?string $to): ?float
{
    if (!$from || !$to) {
        return null;
    }
    try {
        $start = kpi_next_business_open(new DateTimeImmutable($from));
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return null;
    }
    if ($end <= $start) {
        return 0.0;
    }
    $minutes = 0.0;
    $cursor = $start;
    for ($i = 0; $i < 370 && $cursor < $end; $i++) {
        $window = kpi_business_window($cursor);
        if (!$window) {
            $cursor = $cursor->modify('+1 day')->setTime(8, 0);
            continue;
        }
        [$open, $close] = $window;
        $segmentStart = $cursor < $open ? $open : $cursor;
        $segmentEnd = $end < $close ? $end : $close;
        if ($segmentEnd > $segmentStart) {
            $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
        }
        $cursor = $cursor->modify('+1 day')->setTime(8, 0);
    }
    return round($minutes, 1);
}

function kpi_tier(float $score): array
{
    if ($score >= 90) {
        return ['tier' => 'Excellent', 'label' => 'Excellent', 'bonus_multiplier' => 1.0, 'bonus_label' => 'Bonus and increment candidate', 'recommendation' => 'Strong bonus / increment consideration', 'reward' => true, 'class' => 'exceptional'];
    }
    if ($score >= 80) {
        return ['tier' => 'Good', 'label' => 'Good', 'bonus_multiplier' => 0.75, 'bonus_label' => 'Good bonus candidate', 'recommendation' => 'Bonus eligible', 'reward' => false, 'class' => 'high'];
    }
    if ($score >= 70) {
        return ['tier' => 'Needs Improvement', 'label' => 'Needs Improvement', 'bonus_multiplier' => 0.35, 'bonus_label' => 'Small/conditional bonus', 'recommendation' => 'Coach and monitor', 'reward' => false, 'class' => 'satisfactory'];
    }

    return ['tier' => 'Performance Concern', 'label' => 'Performance Concern', 'bonus_multiplier' => 0.0, 'bonus_label' => 'No bonus', 'recommendation' => 'Performance conversation required', 'reward' => false, 'class' => 'loss'];
}

function kpi_float_setting(array $settings, string $key): float
{
    return (float) ($settings[$key] ?? 0);
}

function kpi_employee_inputs(string $period): array
{
    if (!ops_table_exists('ops_kpi_employee_inputs')) {
        return [];
    }

    $rows = ops_rows('SELECT * FROM ops_kpi_employee_inputs WHERE period_month = ?', [$period]);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['employee_id']] = $row;
    }

    return $map;
}

function kpi_default_input(): array
{
    return [
        'monthly_salary' => 0,
        'attendance_score' => 85,
        'reliability_score' => 85,
        'compliance_score' => 85,
        'team_contribution_score' => 85,
        'communication_score' => 85,
        'admin_accuracy_score' => 85,
        'dispatch_score' => 85,
        'operational_accuracy_score' => 85,
        'notes' => '',
    ];
}

function kpi_build_scores(string $period, string $start, string $end, array $settings): array
{
    $employees = ops_rows(
        "SELECT e.id, e.full_name, e.email, r.role_key, r.name AS role_name
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'packer', 'front_desk_admin', 'supervisor_manager', 'owner_admin'), e.full_name"
    );

    $inputs = kpi_employee_inputs($period);
    $errorRows = ops_table_exists('ops_error_logs') ? ops_rows(
        "SELECT employee_id,
                COUNT(*) AS error_count,
                SUM(CASE severity WHEN 'critical' THEN 3 WHEN 'high' THEN 2 WHEN 'medium' THEN 1 ELSE 0.5 END) AS error_points
         FROM ops_error_logs
         WHERE employee_id IS NOT NULL AND logged_at >= ? AND logged_at < ?
         GROUP BY employee_id",
        [$start, $end]
    ) : [];
    $errorsByEmployee = [];
    foreach ($errorRows as $row) {
        $errorsByEmployee[(int) $row['employee_id']] = $row;
    }

    $checkRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT assigned_employee_id,
                COUNT(*) AS checklist_total,
                SUM(CASE WHEN status IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS checklist_done,
                SUM(CASE WHEN deadline IS NOT NULL AND deadline < COALESCE(completed_at, NOW()) AND status NOT IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS missed_tasks
         FROM ops_checklist_tasks
         WHERE assigned_employee_id IS NOT NULL AND created_at >= ? AND created_at < ?
         GROUP BY assigned_employee_id",
        [$start, $end]
    ) : [];
    $checkByEmployee = [];
    foreach ($checkRows as $row) {
        $checkByEmployee[(int) $row['assigned_employee_id']] = $row;
    }

    $packerRows = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT assigned_packer_id,
                COUNT(*) AS handled_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                SUM(workload_score) AS workload_points,
                AVG(CASE WHEN assigned_at IS NOT NULL AND packing_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, assigned_at, packing_started_at) END) AS avg_start_minutes,
                AVG(CASE WHEN packing_started_at IS NOT NULL AND COALESCE(packed_at, completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, packing_started_at, COALESCE(packed_at, completed_at)) END) AS avg_pack_minutes,
                AVG(CASE WHEN created_at IS NOT NULL AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_completion_minutes
         FROM ops_orders
         WHERE assigned_packer_id IS NOT NULL AND created_at >= ? AND created_at < ?
         GROUP BY assigned_packer_id",
        [$start, $end]
    ) : [];
    $packerByEmployee = [];
    foreach ($packerRows as $row) {
        $packerByEmployee[(int) $row['assigned_packer_id']] = $row;
    }

    $itemRows = (ops_table_exists('ops_order_items') && ops_table_exists('ops_orders')) ? ops_rows(
        "SELECT COALESCE(oi.packed_by, o.assigned_packer_id) AS employee_id,
                SUM(CASE WHEN oi.packed_quantity > 0 THEN oi.packed_quantity ELSE oi.quantity END) AS items_packed
         FROM ops_order_items oi
         JOIN ops_orders o ON o.id = oi.order_id
         WHERE COALESCE(oi.packed_by, o.assigned_packer_id) IS NOT NULL
           AND o.created_at >= ? AND o.created_at < ?
         GROUP BY COALESCE(oi.packed_by, o.assigned_packer_id)",
        [$start, $end]
    ) : [];
    $itemsByEmployee = [];
    foreach ($itemRows as $row) {
        $itemsByEmployee[(int) $row['employee_id']] = (float) $row['items_packed'];
    }

    $frontRows = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT created_by,
                COUNT(*) AS orders_loaded,
                SUM(CASE WHEN assigned_packer_id IS NULL AND status NOT IN ('completed', 'packed', 'verified') THEN 1 ELSE 0 END) AS unassigned_orders,
                SUM(CASE WHEN status NOT IN ('completed', 'packed', 'verified') AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > ? THEN 1 ELSE 0 END) AS delayed_orders,
                SUM(CASE WHEN status = 'correction_required' THEN 1 ELSE 0 END) AS correction_orders,
                AVG(CASE WHEN assigned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, assigned_at) END) AS avg_assignment_minutes
         FROM ops_orders
         WHERE created_by IS NOT NULL AND created_at >= ? AND created_at < ?
         GROUP BY created_by",
        [(int) kpi_float_setting($settings, 'target_assignment_minutes'), $start, $end]
    ) : [];
    $frontByEmployee = [];
    foreach ($frontRows as $row) {
        $frontByEmployee[(int) $row['created_by']] = $row;
    }

    $scores = [];
    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id'];
        $roleKey = (string) $employee['role_key'];
        $input = array_merge(kpi_default_input(), $inputs[$employeeId] ?? []);
        $errors = $errorsByEmployee[$employeeId] ?? ['error_count' => 0, 'error_points' => 0];
        $check = $checkByEmployee[$employeeId] ?? ['checklist_total' => 0, 'checklist_done' => 0, 'missed_tasks' => 0];
        $checkTotal = (int) $check['checklist_total'];
        $checkDone = (int) $check['checklist_done'];
        $checkRate = $checkTotal > 0 ? kpi_score(($checkDone / max(1, $checkTotal)) * 100) : (float) $input['compliance_score'];
        $attendanceReliability = kpi_score(((float) $input['attendance_score'] + (float) $input['reliability_score']) / 2);
        $errorPoints = (float) ($errors['error_points'] ?? 0);
        $accuracyScore = kpi_penalty_score($errorPoints, kpi_float_setting($settings, 'error_penalty_points'));

        $roleGroup = kpi_role_group($roleKey);
        $roleWeights = kpi_role_weights();
        if ($roleGroup === 'packer') {
            $pack = $packerByEmployee[$employeeId] ?? [];
            $completedOrders = (int) ($pack['completed_orders'] ?? 0);
            $handledOrders = (int) ($pack['handled_orders'] ?? 0);
            $itemsPacked = (float) ($itemsByEmployee[$employeeId] ?? 0);
            $productivity = kpi_score((kpi_ratio_score((float) $completedOrders, kpi_float_setting($settings, 'target_orders_month')) * 0.65) + (kpi_ratio_score($itemsPacked, kpi_float_setting($settings, 'target_items_month')) * 0.35));
            $speed = kpi_score((kpi_speed_score(isset($pack['avg_start_minutes']) ? (float) $pack['avg_start_minutes'] : null, kpi_float_setting($settings, 'target_start_minutes')) * 0.45) + (kpi_speed_score(isset($pack['avg_pack_minutes']) ? (float) $pack['avg_pack_minutes'] : null, kpi_float_setting($settings, 'target_packing_minutes')) * 0.55));
            $compliance = kpi_score(($checkRate * 0.75) + ((float) $input['compliance_score'] * 0.25));
            $team = kpi_score((float) $input['team_contribution_score']);
            $rawComponents = [
                'order_speed' => ['score' => $speed, 'raw' => kpi_duration(isset($pack['avg_pack_minutes']) ? (float) $pack['avg_pack_minutes'] : null)],
                'packing_productivity' => ['score' => $productivity, 'raw' => number_format($itemsPacked, 1) . ' items'],
                'packing_accuracy' => ['score' => $accuracyScore, 'raw' => number_format((int) ($errors['error_count'] ?? 0)) . ' errors'],
                'tasks' => ['score' => $compliance, 'raw' => number_format($checkDone) . '/' . number_format($checkTotal) . ' tasks'],
                'errors' => ['score' => $accuracyScore, 'raw' => number_format($errorPoints, 1) . ' penalty pts'],
                'team' => ['score' => $team, 'raw' => 'Manual score'],
            ];
            $scorecard = 'Packer KPI Scorecard';
            $ordersHandled = $handledOrders;
            $avgCompletion = isset($pack['avg_completion_minutes']) ? (float) $pack['avg_completion_minutes'] : null;
        } else {
            $front = $frontByEmployee[$employeeId] ?? [];
            $ordersLoaded = (int) ($front['orders_loaded'] ?? 0);
            $unassigned = (int) ($front['unassigned_orders'] ?? 0);
            $delayed = (int) ($front['delayed_orders'] ?? 0);
            $corrections = (int) ($front['correction_orders'] ?? 0);
            $processingSpeed = kpi_speed_score(isset($front['avg_assignment_minutes']) ? (float) $front['avg_assignment_minutes'] : null, kpi_float_setting($settings, 'target_assignment_minutes'));
            $flow = kpi_score(100 - ($unassigned * 8) - ($delayed * 6) - ($corrections * 8));
            $adminAccuracy = kpi_score(((float) $input['admin_accuracy_score'] * 0.6) + ($accuracyScore * 0.4));
            $dispatch = kpi_score((float) $input['dispatch_score']);
            $operational = kpi_score(((float) $input['operational_accuracy_score'] * 0.6) + ($accuracyScore * 0.4));
            $bookkeepingScore = kpi_score(((float) $input['operational_accuracy_score'] * 0.5) + ($adminAccuracy * 0.5));
            $websiteStockScore = kpi_score(((float) $input['dispatch_score'] * 0.45) + ($flow * 0.35) + ($adminAccuracy * 0.20));
            $communicationScore = kpi_score((float) ($input['communication_score'] ?? 85));
            $rawComponents = [
                'orders' => ['score' => kpi_score(($processingSpeed * 0.55) + ($flow * 0.45)), 'raw' => number_format($ordersLoaded) . ' orders'],
                'bookkeeping' => ['score' => $bookkeepingScore, 'raw' => 'Admin/manual + error score'],
                'website_stock' => ['score' => $websiteStockScore, 'raw' => 'Dispatch/manual + flow score'],
                'tasks' => ['score' => $checkRate, 'raw' => number_format($checkDone) . '/' . number_format($checkTotal) . ' tasks'],
                'errors' => ['score' => $accuracyScore, 'raw' => number_format($errorPoints, 1) . ' penalty pts'],
                'communication' => ['score' => $communicationScore, 'raw' => 'Manual score'],
                'reliability' => ['score' => $attendanceReliability, 'raw' => 'Attendance + reliability'],
            ];
            $scorecard = 'Front Desk KPI Scorecard';
            $ordersHandled = $ordersLoaded;
            $itemsPacked = 0.0;
            $avgCompletion = isset($front['avg_assignment_minutes']) ? (float) $front['avg_assignment_minutes'] : null;
        }

        $overall = 0.0;
        $components = [];
        $totalWeight = 0.0;
        foreach (($roleWeights[$roleGroup] ?? []) as $key => $weightRow) {
            $score = (float) ($rawComponents[$key]['score'] ?? 0);
            $weight = (float) $weightRow['weight'];
            $components[(string) $weightRow['label']] = [
                'weight' => $weight,
                'score' => kpi_score($score),
                'raw' => (string) ($rawComponents[$key]['raw'] ?? ''),
            ];
            $overall += $score * ($weight / 100);
            $totalWeight += $weight;
        }
        if ($totalWeight > 0 && abs($totalWeight - 100) > 0.01) {
            $overall = ($overall / $totalWeight) * 100;
        }
        $overall = kpi_score($overall);
        $tier = kpi_tier($overall);
        $salary = (float) $input['monthly_salary'];
        $maxBonus = $salary * (kpi_float_setting($settings, 'bonus_percent') / 100);
        $bonusAmount = round($maxBonus * (float) $tier['bonus_multiplier'], 2);

        $scores[] = [
            'employee_id' => $employeeId,
            'name' => (string) $employee['full_name'],
            'email' => (string) ($employee['email'] ?? ''),
            'role_key' => $roleKey,
            'role_group' => $roleGroup,
            'role_name' => (string) $employee['role_name'],
            'scorecard' => $scorecard,
            'components' => $components,
            'score' => $overall,
            'tier' => $tier,
            'salary' => $salary,
            'max_bonus' => $maxBonus,
            'bonus_amount' => $bonusAmount,
            'orders_handled' => $ordersHandled,
            'items_packed' => $itemsPacked,
            'avg_completion_minutes' => $avgCompletion,
            'error_count' => (int) ($errors['error_count'] ?? 0),
            'attendance_score' => (float) $input['attendance_score'],
            'reliability_score' => (float) $input['reliability_score'],
            'checklist_rate' => $checkRate,
            'notes' => (string) ($input['notes'] ?? ''),
            'input' => $input,
        ];
    }

    usort($scores, function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });
    foreach ($scores as $index => $score) {
        $scores[$index]['rank'] = $index + 1;
    }

    return $scores;
}

function kpi_order_business_summary(string $start, string $end, array $settings): array
{
    $summary = [
        'avg_assignment' => null,
        'avg_start' => null,
        'avg_packing' => null,
        'avg_completion' => null,
        'overdue' => 0,
        'unassigned' => 0,
        'stuck_new' => 0,
        'stuck_progress' => 0,
        'courier_late' => 0,
    ];
    if (!ops_table_exists('ops_orders')) {
        return $summary;
    }
    $assign = [];
    $startTimes = [];
    $packing = [];
    $completion = [];
    foreach (ops_rows('SELECT * FROM ops_orders WHERE created_at >= ? AND created_at < ?', [$start, $end]) as $order) {
        $status = (string) ($order['status'] ?? '');
        $created = (string) ($order['created_at'] ?? '');
        $assignedAt = (string) ($order['assigned_at'] ?? '');
        $startedAt = (string) ($order['packing_started_at'] ?? '');
        $packedAt = (string) (($order['packed_at'] ?? '') ?: ($order['completed_at'] ?? ''));
        $completedAt = (string) ($order['completed_at'] ?? '');
        $assign[] = kpi_business_minutes($created, $assignedAt ?: null);
        $startTimes[] = kpi_business_minutes($assignedAt ?: $created, $startedAt ?: null);
        $packing[] = kpi_business_minutes($startedAt ?: $assignedAt, $packedAt ?: null);
        $completion[] = kpi_business_minutes($created, $completedAt ?: null);
        $isDone = in_array($status, ['completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true);
        if (!$isDone && (kpi_business_minutes($created, date('Y-m-d H:i:s')) ?? 0) > (float) ($settings['target_order_total_minutes'] ?? 360)) {
            $summary['overdue']++;
        }
        if (empty($order['assigned_packer_id']) && !$isDone) {
            $summary['unassigned']++;
        }
        if ($status === 'new_order') {
            $summary['stuck_new']++;
        }
        if ($status === 'in_progress') {
            $summary['stuck_progress']++;
        }
        if ((string) ($order['order_type'] ?? '') === 'courier' && (!$isDone || ($completedAt && substr($completedAt, 11, 8) > '14:00:00'))) {
            $summary['courier_late']++;
        }
    }
    $summary['avg_assignment'] = kpi_avg_values($assign);
    $summary['avg_start'] = kpi_avg_values($startTimes);
    $summary['avg_packing'] = kpi_avg_values($packing);
    $summary['avg_completion'] = kpi_avg_values($completion);
    return $summary;
}

function kpi_avg_values(array $values): ?float
{
    $values = array_values(array_filter($values, static function ($value): bool {
        return $value !== null;
    }));
    return $values ? array_sum($values) / count($values) : null;
}

function kpi_employee_module_rows(array $scores, string $module): array
{
    $rows = [];
    foreach ($scores as $score) {
        $components = $score['components'];
        $rows[] = [
            'employee' => (string) $score['name'],
            'role' => (string) $score['role_name'],
            'score' => (float) $score['score'],
            'orders' => (int) $score['orders_handled'],
            'items' => (float) $score['items_packed'],
            'avg_time' => $score['avg_completion_minutes'],
            'errors' => (int) $score['error_count'],
            'components' => $components,
            'module' => $module,
        ];
    }
    return $rows;
}

function kpi_metric_text($value, bool $duration = false): string
{
    if ($duration) {
        return kpi_duration($value !== null ? (float) $value : null);
    }
    if (is_float($value)) {
        return number_format($value, 1);
    }
    if (is_int($value)) {
        return number_format($value);
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($ready) {
    kpi_bootstrap();
}

$settings = [
    'bonus_percent' => (float) kpi_setting('kpi_bonus_percent', '10'),
    'target_orders_month' => (float) kpi_setting('kpi_target_orders_month', '50'),
    'target_items_month' => (float) kpi_setting('kpi_target_items_month', '300'),
    'target_start_minutes' => (float) kpi_setting('kpi_target_start_minutes', '60'),
    'target_packing_minutes' => (float) kpi_setting('kpi_target_packing_minutes', '120'),
    'target_assignment_minutes' => (float) kpi_setting('kpi_target_assignment_minutes', '45'),
    'target_order_total_minutes' => (float) kpi_setting('kpi_target_order_total_minutes', '360'),
    'error_penalty_points' => (float) kpi_setting('kpi_error_penalty_points', '8'),
];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('kpi_action', 60);
        if ($action === 'save_settings') {
            $keys = [
                'kpi_bonus_percent',
                'kpi_target_orders_month',
                'kpi_target_items_month',
                'kpi_target_start_minutes',
                'kpi_target_packing_minutes',
                'kpi_target_assignment_minutes',
                'kpi_target_order_total_minutes',
                'kpi_error_penalty_points',
            ];
            foreach ($keys as $key) {
                kpi_save_setting($key, number_format(max(0, (float) ($_POST[$key] ?? 0)), 2, '.', ''));
            }
            $message = 'KPI bonus and target settings saved.';
        } elseif ($action === 'save_weights') {
            foreach (kpi_default_weights() as $group => $weights) {
                foreach ($weights as $key => $row) {
                    $value = max(0, (float) ($_POST['weight_' . $group . '_' . $key] ?? $row['weight']));
                    $stmt = db()->prepare(
                        "INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE component_label = VALUES(component_label), weight_percent = VALUES(weight_percent), active = 1"
                    );
                    $stmt->execute([$group, $key, $row['label'], $value]);
                }
            }
            $message = 'Role-based KPI weights saved.';
        } elseif ($action === 'save_employee_input') {
            $employeeId = max(0, (int) ($_POST['employee_id'] ?? 0));
            $inputPeriod = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period_month'] ?? '')) ? (string) $_POST['period_month'] : $period;
            if ($employeeId <= 0) {
                throw new RuntimeException('Please choose an employee.');
            }
            $stmt = db()->prepare(
                "INSERT INTO ops_kpi_employee_inputs
                    (employee_id, period_month, monthly_salary, attendance_score, reliability_score, compliance_score, team_contribution_score, communication_score, admin_accuracy_score, dispatch_score, operational_accuracy_score, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    monthly_salary = VALUES(monthly_salary),
                    attendance_score = VALUES(attendance_score),
                    reliability_score = VALUES(reliability_score),
                    compliance_score = VALUES(compliance_score),
                    team_contribution_score = VALUES(team_contribution_score),
                    communication_score = VALUES(communication_score),
                    admin_accuracy_score = VALUES(admin_accuracy_score),
                    dispatch_score = VALUES(dispatch_score),
                    operational_accuracy_score = VALUES(operational_accuracy_score),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by)"
            );
            $stmt->execute([
                $employeeId,
                $inputPeriod,
                max(0, (float) ($_POST['monthly_salary'] ?? 0)),
                kpi_score((float) ($_POST['attendance_score'] ?? 85)),
                kpi_score((float) ($_POST['reliability_score'] ?? 85)),
                kpi_score((float) ($_POST['compliance_score'] ?? 85)),
                kpi_score((float) ($_POST['team_contribution_score'] ?? 85)),
                kpi_score((float) ($_POST['communication_score'] ?? 85)),
                kpi_score((float) ($_POST['admin_accuracy_score'] ?? 85)),
                kpi_score((float) ($_POST['dispatch_score'] ?? 85)),
                kpi_score((float) ($_POST['operational_accuracy_score'] ?? 85)),
                ops_post_string('notes', 1000),
                ops_current_employee_id(),
            ]);
            if (ops_column_exists('ops_employees', 'monthly_salary')) {
                db()->prepare('UPDATE ops_employees SET monthly_salary = ? WHERE id = ?')->execute([max(0, (float) ($_POST['monthly_salary'] ?? 0)), $employeeId]);
            }
            $message = 'Employee KPI salary and manual scores saved.';
        } elseif ($action === 'add_reward') {
            $rewardName = ops_post_string('reward_name', 160);
            if ($rewardName === '') {
                throw new RuntimeException('Reward name is required.');
            }
            $stmt = db()->prepare('INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type, active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$rewardName, max(0, (float) ($_POST['reward_value'] ?? 0)), ops_post_string('reward_type', 80) ?: 'recognition']);
            $message = 'Reward option added.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$settings = [
    'bonus_percent' => (float) kpi_setting('kpi_bonus_percent', '10'),
    'target_orders_month' => (float) kpi_setting('kpi_target_orders_month', '50'),
    'target_items_month' => (float) kpi_setting('kpi_target_items_month', '300'),
    'target_start_minutes' => (float) kpi_setting('kpi_target_start_minutes', '60'),
    'target_packing_minutes' => (float) kpi_setting('kpi_target_packing_minutes', '120'),
    'target_assignment_minutes' => (float) kpi_setting('kpi_target_assignment_minutes', '45'),
    'target_order_total_minutes' => (float) kpi_setting('kpi_target_order_total_minutes', '360'),
    'error_penalty_points' => (float) kpi_setting('kpi_error_penalty_points', '8'),
];

$employeeScores = $ready ? kpi_build_scores($period, $periodStart, $periodEnd, $settings) : [];
$previousScores = $ready ? kpi_build_scores($previousPeriod, $previousStart, $previousEnd, $settings) : [];
$previousByEmployee = [];
foreach ($previousScores as $row) {
    $previousByEmployee[(int) $row['employee_id']] = $row;
}
$topPerformer = $employeeScores[0] ?? null;
$bottomPerformer = $employeeScores ? $employeeScores[count($employeeScores) - 1] : null;
$averageScore = $employeeScores ? array_sum(array_column($employeeScores, 'score')) / count($employeeScores) : 0;
$bonusForecast = array_sum(array_column($employeeScores, 'bonus_amount'));
$rewardCandidates = array_values(array_filter($employeeScores, function (array $row): bool {
    return (bool) $row['tier']['reward'];
}));
$trainingCandidates = array_values(array_filter($employeeScores, function (array $row): bool {
    return (float) $row['score'] < 60;
}));
$departmentScores = [];
foreach ($employeeScores as $row) {
    $key = (string) $row['scorecard'];
    $departmentScores[$key]['total'] = ($departmentScores[$key]['total'] ?? 0) + (float) $row['score'];
    $departmentScores[$key]['count'] = ($departmentScores[$key]['count'] ?? 0) + 1;
}
$rewards = $ready && ops_table_exists('ops_kpi_rewards') ? ops_rows('SELECT * FROM ops_kpi_rewards WHERE active = 1 ORDER BY reward_type, reward_name') : [];
$activeInputs = $ready ? kpi_employee_inputs($period) : [];
$systemMetricRow = $ready && ops_table_exists('ops_orders') ? (ops_rows(
    "SELECT
        AVG(CASE WHEN assigned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, assigned_at) END) AS avg_assignment_minutes,
        AVG(CASE WHEN assigned_at IS NOT NULL AND packing_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, assigned_at, packing_started_at) END) AS avg_start_minutes,
        AVG(CASE WHEN packing_started_at IS NOT NULL AND COALESCE(packed_at, completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, packing_started_at, COALESCE(packed_at, completed_at)) END) AS avg_packing_minutes,
        AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_completion_minutes,
        SUM(CASE WHEN status NOT IN ('completed', 'packed', 'verified') AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > ? THEN 1 ELSE 0 END) AS overdue_orders,
        SUM(CASE WHEN status = 'correction_required' THEN 1 ELSE 0 END) AS correction_orders,
        SUM(CASE WHEN status = 'error_logged' THEN 1 ELSE 0 END) AS intervention_orders,
        SUM(CASE WHEN status IN ('new_order', 'assigned', 'in_progress') THEN 1 ELSE 0 END) AS active_queue
     FROM ops_orders
     WHERE created_at >= ? AND created_at < ?",
    [(int) $settings['target_assignment_minutes'], $periodStart, $periodEnd]
)[0] ?? []) : [];
$systemMetrics = [
    'Average assignment time' => kpi_duration(isset($systemMetricRow['avg_assignment_minutes']) ? (float) $systemMetricRow['avg_assignment_minutes'] : null),
    'Average packing start time' => kpi_duration(isset($systemMetricRow['avg_start_minutes']) ? (float) $systemMetricRow['avg_start_minutes'] : null),
    'Average packing duration' => kpi_duration(isset($systemMetricRow['avg_packing_minutes']) ? (float) $systemMetricRow['avg_packing_minutes'] : null),
    'Average completion time' => kpi_duration(isset($systemMetricRow['avg_completion_minutes']) ? (float) $systemMetricRow['avg_completion_minutes'] : null),
    'Overdue orders' => number_format((int) ($systemMetricRow['overdue_orders'] ?? 0)),
    'Orders requiring correction' => number_format((int) ($systemMetricRow['correction_orders'] ?? 0)),
    'Manager intervention signals' => number_format((int) ($systemMetricRow['intervention_orders'] ?? 0)),
    'Active queue' => number_format((int) ($systemMetricRow['active_queue'] ?? 0)),
];
$businessSummary = $ready ? kpi_order_business_summary($periodStart, $periodEnd, $settings) : [];
$tabs = [
    'overview' => 'Overview Dashboard',
    'front-desk' => 'Front Desk Performance',
    'packers' => 'Packer Performance',
    'employees' => 'Individual Profiles',
    'orders' => 'Orders KPI',
    'packing' => 'Packing KPI',
    'bookkeeping' => 'Bookkeeping KPI',
    'tasks' => 'Task KPI',
    'errors' => 'Error KPI',
    'bonus' => 'Bonus / Increment Score',
];
$scoresById = [];
foreach ($employeeScores as $row) {
    $scoresById[(int) $row['employee_id']] = $row;
}
$selectedEmployee = $selectedEmployeeId && isset($scoresById[$selectedEmployeeId]) ? $scoresById[$selectedEmployeeId] : ($employeeScores[0] ?? null);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-performance-page">
    <section class="module-header">
        <div>
            <p class="eyebrow">KPI Performance & Bonus Framework</p>
            <h1>Performance Dashboard</h1>
            <p>Objective employee scoring, graduated bonus calculations, reward eligibility, training signals and operational KPI tracking.</p>
        </div>
        <form class="kpi-period-form" method="get">
            <label>Month<input type="month" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>"></label>
            <button class="button primary" type="submit"><i data-lucide="calendar-range"></i> View</button>
        </form>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <nav class="kpi-tab-nav" aria-label="KPI report sections">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="<?= $activeTab === $key ? 'active' : '' ?>" href="reports.php?period=<?= urlencode($period) ?>&tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($activeTab === 'orders'): ?>
        <section class="panel kpi-system-panel">
            <div class="section-row"><div><h2>Orders KPI</h2><p>Business-hour timing ignores nights and Sundays. After-hours orders start counting at the next opening time.</p></div></div>
            <div class="kpi-system-metric-grid">
                <?php foreach ([
                    'New Order to In Progress / assignment' => kpi_duration($businessSummary['avg_assignment'] ?? null),
                    'Packing start time' => kpi_duration($businessSummary['avg_start'] ?? null),
                    'Packing to complete' => kpi_duration($businessSummary['avg_packing'] ?? null),
                    'Total order processing time' => kpi_duration($businessSummary['avg_completion'] ?? null),
                    'Overdue orders' => number_format((int) ($businessSummary['overdue'] ?? 0)),
                    'Unassigned orders' => number_format((int) ($businessSummary['unassigned'] ?? 0)),
                    'Stuck on New Order' => number_format((int) ($businessSummary['stuck_new'] ?? 0)),
                    'Stuck In Progress' => number_format((int) ($businessSummary['stuck_progress'] ?? 0)),
                    'Courier late after 14:00' => number_format((int) ($businessSummary['courier_late'] ?? 0)),
                ] as $label => $value): ?><article><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong></article><?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'front-desk' || $activeTab === 'packers'): ?>
        <?php $roleRows = array_values(array_filter($employeeScores, function (array $row) use ($activeTab): bool { return $activeTab === 'packers' ? ($row['role_group'] ?? '') === 'packer' : ($row['role_group'] ?? '') === 'front_desk'; })); ?>
        <section class="dashboard-grid kpi-scorecard-grid">
            <?php foreach ($roleRows as $row): ?>
                <article class="panel kpi-scorecard">
                    <div class="section-row"><div><h2><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($row['scorecard'], ENT_QUOTES, 'UTF-8') ?></p></div><span class="kpi-score"><?= kpi_percent((float) $row['score']) ?></span></div>
                    <?php foreach ($row['components'] as $label => $component): ?>
                        <div class="kpi-component-row">
                            <div><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><small><?= number_format((float) $component['weight']) ?>% weight<?= !empty($component['raw']) ? ' | ' . htmlspecialchars((string) $component['raw'], ENT_QUOTES, 'UTF-8') : '' ?></small></div>
                            <span><?= kpi_percent((float) $component['score']) ?></span>
                            <div class="kpi-bar"><span><i style="width: <?= min(100, (float) $component['score']) ?>%"></i></span></div>
                        </div>
                    <?php endforeach; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$roleRows): ?><section class="panel"><p>No employees found for this role group.</p></section><?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'employees'): ?>
        <section class="panel kpi-employee-picker">
            <div class="section-row"><div><h2>Individual Employee Profiles</h2><p>Open one employee at a time inside KPI Reports.</p></div></div>
            <div class="kpi-employee-tabs">
                <?php foreach ($employeeScores as $row): ?>
                    <a class="<?= $selectedEmployee && (int) $selectedEmployee['employee_id'] === (int) $row['employee_id'] ? 'active' : '' ?>" href="reports.php?period=<?= urlencode($period) ?>&tab=employees&employee_id=<?= (int) $row['employee_id'] ?>"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php if ($selectedEmployee): ?>
            <section class="panel kpi-profile">
                <div class="section-row"><div><h2><?= htmlspecialchars($selectedEmployee['name'], ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($selectedEmployee['role_name'], ENT_QUOTES, 'UTF-8') ?> | Salary <?= kpi_money((float) $selectedEmployee['salary']) ?> | <?= htmlspecialchars((string) $selectedEmployee['tier']['recommendation'], ENT_QUOTES, 'UTF-8') ?></p></div><span class="kpi-score"><?= kpi_percent((float) $selectedEmployee['score']) ?></span></div>
                <div class="kpi-profile-grid">
                    <?php foreach ($selectedEmployee['components'] as $label => $component): ?>
                        <article><h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3><p><span>Score</span><strong><?= kpi_percent((float) $component['score']) ?></strong></p><p><span>Weight</span><strong><?= number_format((float) $component['weight']) ?>%</strong></p><?php if (!empty($component['raw'])): ?><p><span>Signal</span><strong><?= htmlspecialchars((string) $component['raw'], ENT_QUOTES, 'UTF-8') ?></strong></p><?php endif; ?></article>
                    <?php endforeach; ?>
                    <article><h3>Bonus / increment</h3><p><span>Suggested bonus</span><strong><?= kpi_money((float) $selectedEmployee['bonus_amount']) ?></strong></p><p><span>Status</span><strong><?= htmlspecialchars((string) $selectedEmployee['tier']['label'], ENT_QUOTES, 'UTF-8') ?></strong></p><p><span>Recommendation</span><strong><?= htmlspecialchars((string) $selectedEmployee['tier']['recommendation'], ENT_QUOTES, 'UTF-8') ?></strong></p></article>
                </div>
            </section>
        <?php endif; ?>
    <?php elseif (in_array($activeTab, ['packing', 'bookkeeping', 'tasks', 'errors'], true)): ?>
        <section class="panel">
            <div class="section-row"><div><h2><?= htmlspecialchars($tabs[$activeTab], ENT_QUOTES, 'UTF-8') ?></h2><p>Module view for management review. Values are pulled from the connected operations tables where fields exist.</p></div></div>
            <div class="table-scroll">
                <table class="data-table ops-table">
                    <thead><tr><th>Employee</th><th>Role</th><th>Score</th><th>Orders</th><th>Items Packed</th><th>Avg Time</th><th>Errors</th><th>Related Scorecard Signals</th></tr></thead>
                    <tbody>
                    <?php foreach ($employeeScores as $row): ?>
                        <tr><td><strong><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></strong></td><td><?= htmlspecialchars($row['role_name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= kpi_percent((float) $row['score']) ?></td><td><?= number_format((int) $row['orders_handled']) ?></td><td><?= number_format((float) $row['items_packed'], 1) ?></td><td><?= kpi_duration($row['avg_completion_minutes'] !== null ? (float) $row['avg_completion_minutes'] : null) ?></td><td><?= number_format((int) $row['error_count']) ?></td><td><?php foreach ($row['components'] as $label => $component): ?><span class="kpi-signal-pill"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>: <?= kpi_percent((float) $component['score']) ?></span><?php endforeach; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$employeeScores): ?><tr><td colspan="8">No KPI data available yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="work-metric-grid kpi-overview-grid">
        <?php foreach ([
            ['Average Performance', kpi_percent($averageScore), 'All active employees', 'gauge', 'metric-blue'],
            ['Top Performer', $topPerformer ? $topPerformer['name'] : '-', $topPerformer ? kpi_percent((float) $topPerformer['score']) : 'No score yet', 'trophy', 'metric-green'],
            ['Bottom Performer', $bottomPerformer ? $bottomPerformer['name'] : '-', $bottomPerformer ? kpi_percent((float) $bottomPerformer['score']) : 'No score yet', 'badge-alert', 'metric-orange'],
            ['Bonus Forecast', kpi_money((float) $bonusForecast), 'Estimated payout for ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8'), 'banknote', 'metric-purple'],
            ['Reward Candidates', number_format(count($rewardCandidates)), '90% and above', 'gift', 'metric-pink'],
            ['Training Needed', number_format(count($trainingCandidates)), 'Below 60%', 'graduation-cap', 'metric-red'],
        ] as [$title, $value, $desc, $icon, $class]): ?>
            <article class="work-metric-card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) $desc, ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel kpi-system-panel">
        <div class="section-row">
            <div>
                <h2>Operational timing metrics</h2>
                <p>System-calculated order flow, packing speed, overdue work and correction signals for the selected month.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($systemMetrics as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-grid kpi-management-grid">
        <article class="panel">
            <div class="section-row"><div><h2>Bonus settings</h2><p>Management controls the monthly bonus percentage and KPI targets.</p></div></div>
            <form class="kpi-settings-grid" method="post">
                <input type="hidden" name="kpi_action" value="save_settings">
                <label>Bonus %<input name="kpi_bonus_percent" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) $settings['bonus_percent'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Monthly order target<input name="kpi_target_orders_month" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_orders_month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Monthly item target<input name="kpi_target_items_month" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_items_month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Assign target minutes<input name="kpi_target_assignment_minutes" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_assignment_minutes'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Packing start target<input name="kpi_target_start_minutes" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_start_minutes'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Packing duration target<input name="kpi_target_packing_minutes" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_packing_minutes'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Total order target<input name="kpi_target_order_total_minutes" type="number" min="0" step="1" value="<?= htmlspecialchars((string) $settings['target_order_total_minutes'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Error penalty<input name="kpi_error_penalty_points" type="number" min="0" step="0.5" value="<?= htmlspecialchars((string) $settings['error_penalty_points'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <div><button class="button primary" type="submit"><i data-lucide="save"></i> Save settings</button></div>
            </form>
        </article>

        <article class="panel">
            <div class="section-row"><div><h2>Department performance</h2><p>Average score by scorecard type.</p></div></div>
            <div class="kpi-department-list">
                <?php foreach ($departmentScores as $label => $data): ?>
                    <?php $avg = (float) $data['total'] / max(1, (int) $data['count']); ?>
                    <div>
                        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= kpi_percent($avg) ?></strong>
                        <div class="kpi-bar"><span><i style="width: <?= min(100, $avg) ?>%"></i></span></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$departmentScores): ?><p>No department KPI data yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <div class="section-row"><div><h2>Role score weights</h2><p>Owner/Admin can adjust the KPI weights without changing code.</p></div></div>
            <form class="kpi-settings-grid" method="post">
                <input type="hidden" name="kpi_action" value="save_weights">
                <?php foreach (kpi_role_weights() as $group => $weights): ?>
                    <h3 class="span-2"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $group)), ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php foreach ($weights as $key => $row): ?>
                        <label><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?><input name="weight_<?= htmlspecialchars($group . '_' . $key, ENT_QUOTES, 'UTF-8') ?>" type="number" min="0" step="0.5" value="<?= htmlspecialchars((string) $row['weight'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div><button class="button primary" type="submit"><i data-lucide="sliders-horizontal"></i> Save weights</button></div>
            </form>
        </article>
    </section>

    <section class="panel">
        <div class="section-row"><div><h2>Graduated bonus framework</h2><p>Bonus payouts are calculated from performance score, salary and the monthly bonus percentage.</p></div></div>
        <div class="kpi-tier-grid">
            <?php foreach ([
                ['90-100%', 'Excellent', 'Strong bonus / increment consideration'],
                ['80-89%', 'Good', 'Bonus eligible'],
                ['70-79%', 'Needs Improvement', 'Coach and monitor'],
                ['Below 70%', 'Performance Concern', 'No bonus; performance conversation required'],
            ] as [$range, $label, $bonus]): ?>
                <article><strong><?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($bonus, ENT_QUOTES, 'UTF-8') ?></small></article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="section-row"><div><h2>Employee KPI dashboard</h2><p>Leaderboard, scorecard, bonus amount, reward eligibility and training signals for the selected month.</p></div></div>
        <div class="table-scroll">
            <table class="data-table ops-table kpi-employee-table">
                <thead>
                    <tr>
                        <th>Rank</th><th>Employee</th><th>Role</th><th>Performance</th><th>Tier</th><th>Bonus %</th><th>Bonus Amount</th><th>Orders</th><th>Items Packed</th><th>Avg Completion</th><th>Errors</th><th>Attendance</th><th>Reliability</th><th>Monthly Trend</th><th>Reward</th><th>Bonus</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employeeScores as $row): ?>
                    <?php
                    $previous = $previousByEmployee[(int) $row['employee_id']]['score'] ?? null;
                    $trend = $previous === null ? 'No prior month' : (($row['score'] - (float) $previous) >= 0 ? '+' : '') . kpi_percent((float) $row['score'] - (float) $previous);
                    ?>
                    <tr>
                        <td>#<?= number_format((int) $row['rank']) ?></td>
                        <td><strong><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars($row['scorecard'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><?= htmlspecialchars($row['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><strong><?= kpi_percent((float) $row['score']) ?></strong><div class="kpi-bar"><span><i style="width: <?= min(100, (float) $row['score']) ?>%"></i></span></div></td>
                        <td><span class="kpi-tier-badge kpi-tier-<?= htmlspecialchars((string) $row['tier']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $row['tier']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= kpi_percent((float) $settings['bonus_percent'] * (float) $row['tier']['bonus_multiplier']) ?></td>
                        <td><?= kpi_money((float) $row['bonus_amount']) ?><br><small>Max <?= kpi_money((float) $row['max_bonus']) ?></small></td>
                        <td><?= number_format((int) $row['orders_handled']) ?></td>
                        <td><?= number_format((float) $row['items_packed'], 1) ?></td>
                        <td><?= kpi_duration($row['avg_completion_minutes'] !== null ? (float) $row['avg_completion_minutes'] : null) ?></td>
                        <td><?= number_format((int) $row['error_count']) ?></td>
                        <td><?= kpi_percent((float) $row['attendance_score']) ?></td>
                        <td><?= kpi_percent((float) $row['reliability_score']) ?></td>
                        <td><?= htmlspecialchars($trend, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $row['tier']['reward'] ? 'Eligible' : '-' ?></td>
                        <td><?= htmlspecialchars((string) $row['tier']['bonus_label'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$employeeScores): ?><tr><td colspan="16">No KPI data recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="dashboard-grid kpi-scorecard-grid">
        <?php foreach ($employeeScores as $row): ?>
            <article class="panel kpi-scorecard">
                <div class="section-row">
                    <div><h2><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($row['scorecard'], ENT_QUOTES, 'UTF-8') ?></p></div>
                    <span class="kpi-score"><?= kpi_percent((float) $row['score']) ?></span>
                </div>
                <?php foreach ($row['components'] as $label => $component): ?>
                    <div class="kpi-component-row">
                        <div><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><small><?= number_format((float) $component['weight']) ?>% weight</small></div>
                        <span><?= kpi_percent((float) $component['score']) ?></span>
                        <div class="kpi-bar"><span><i style="width: <?= min(100, (float) $component['score']) ?>%"></i></span></div>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="dashboard-grid kpi-input-grid">
        <article class="panel">
            <div class="section-row"><div><h2>Salary and manual score inputs</h2><p>Attendance, reliability and management assessments fill the gaps that are not yet automatically measured.</p></div></div>
            <form class="kpi-manual-form" method="post">
                <input type="hidden" name="kpi_action" value="save_employee_input">
                <input type="hidden" name="period_month" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">
                <label>Employee<select name="employee_id" required><option value="">Choose employee</option><?php foreach ($employeeScores as $row): ?><option value="<?= (int) $row['employee_id'] ?>"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label>Monthly salary<input name="monthly_salary" type="number" min="0" step="0.01" placeholder="5000"></label>
                <label>Attendance %<input name="attendance_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Reliability %<input name="reliability_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Compliance %<input name="compliance_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Team contribution %<input name="team_contribution_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Communication %<input name="communication_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Admin accuracy %<input name="admin_accuracy_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Dispatch %<input name="dispatch_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label>Operational accuracy %<input name="operational_accuracy_score" type="number" min="0" max="100" step="1" value="85"></label>
                <label class="span-2">Notes<textarea name="notes" placeholder="Training notes, recognition, attendance context"></textarea></label>
                <div><button class="button primary" type="submit"><i data-lucide="save"></i> Save employee scores</button></div>
            </form>
        </article>

        <article class="panel">
            <div class="section-row"><div><h2>Reward configuration</h2><p>Employees at 90% and above become eligible for these rewards.</p></div></div>
            <form class="kpi-reward-form" method="post">
                <input type="hidden" name="kpi_action" value="add_reward">
                <label>Reward name<input name="reward_name" placeholder="Driving lesson sponsorship"></label>
                <label>Value<input name="reward_value" type="number" min="0" step="0.01" placeholder="800"></label>
                <label>Type<select name="reward_type"><option value="development">Development</option><option value="recognition">Recognition</option><option value="voucher">Voucher</option><option value="cash">Cash</option></select></label>
                <button class="button" type="submit"><i data-lucide="plus"></i> Add reward</button>
            </form>
            <div class="kpi-reward-list">
                <?php foreach ($rewards as $reward): ?>
                    <span><strong><?= htmlspecialchars((string) $reward['reward_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) $reward['reward_type'], ENT_QUOTES, 'UTF-8') ?><?= (float) $reward['reward_value'] > 0 ? ' | ' . kpi_money((float) $reward['reward_value']) : '' ?></small></span>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
