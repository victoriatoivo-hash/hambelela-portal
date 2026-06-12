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

function kpi_tier(float $score): array
{
    if ($score < 50) {
        return ['tier' => 'Tier 1', 'label' => 'Needs Improvement', 'bonus_multiplier' => 0.0, 'bonus_label' => 'No bonus', 'reward' => false, 'class' => 'loss'];
    }
    if ($score < 60) {
        return ['tier' => 'Tier 2', 'label' => 'Developing', 'bonus_multiplier' => 0.25, 'bonus_label' => '25% bonus', 'reward' => false, 'class' => 'developing'];
    }
    if ($score < 70) {
        return ['tier' => 'Tier 3', 'label' => 'Satisfactory', 'bonus_multiplier' => 0.50, 'bonus_label' => '50% bonus', 'reward' => false, 'class' => 'satisfactory'];
    }
    if ($score < 75) {
        return ['tier' => 'Tier 4', 'label' => 'Good Performer', 'bonus_multiplier' => 0.75, 'bonus_label' => '75% bonus', 'reward' => false, 'class' => 'good'];
    }
    if ($score < 90) {
        return ['tier' => 'Tier 5', 'label' => 'High Performer', 'bonus_multiplier' => 1.0, 'bonus_label' => '100% bonus', 'reward' => false, 'class' => 'high'];
    }

    return ['tier' => 'Tier 6', 'label' => 'Exceptional Performer', 'bonus_multiplier' => 1.0, 'bonus_label' => '100% bonus + reward eligible', 'reward' => true, 'class' => 'exceptional'];
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

        if (in_array($roleKey, ['packer', 'supervisor_manager'], true)) {
            $pack = $packerByEmployee[$employeeId] ?? [];
            $completedOrders = (int) ($pack['completed_orders'] ?? 0);
            $handledOrders = (int) ($pack['handled_orders'] ?? 0);
            $itemsPacked = (float) ($itemsByEmployee[$employeeId] ?? 0);
            $productivity = kpi_score((kpi_ratio_score((float) $completedOrders, kpi_float_setting($settings, 'target_orders_month')) * 0.65) + (kpi_ratio_score($itemsPacked, kpi_float_setting($settings, 'target_items_month')) * 0.35));
            $speed = kpi_score((kpi_speed_score(isset($pack['avg_start_minutes']) ? (float) $pack['avg_start_minutes'] : null, kpi_float_setting($settings, 'target_start_minutes')) * 0.45) + (kpi_speed_score(isset($pack['avg_pack_minutes']) ? (float) $pack['avg_pack_minutes'] : null, kpi_float_setting($settings, 'target_packing_minutes')) * 0.55));
            $compliance = kpi_score(($checkRate * 0.75) + ((float) $input['compliance_score'] * 0.25));
            $team = kpi_score((float) $input['team_contribution_score']);
            $components = [
                'Productivity' => ['weight' => 30, 'score' => $productivity],
                'Packing Accuracy' => ['weight' => 25, 'score' => $accuracyScore],
                'Packing Speed' => ['weight' => 15, 'score' => $speed],
                'Attendance & Reliability' => ['weight' => 10, 'score' => $attendanceReliability],
                'Compliance' => ['weight' => 10, 'score' => $compliance],
                'Team Contribution' => ['weight' => 10, 'score' => $team],
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
            $components = [
                'Order Processing Speed' => ['weight' => 25, 'score' => $processingSpeed],
                'Order Flow Management' => ['weight' => 20, 'score' => $flow],
                'Customer Admin Accuracy' => ['weight' => 15, 'score' => $adminAccuracy],
                'Dispatch Management' => ['weight' => 15, 'score' => $dispatch],
                'Operational Accuracy' => ['weight' => 15, 'score' => $operational],
                'Attendance & Reliability' => ['weight' => 10, 'score' => $attendanceReliability],
            ];
            $scorecard = 'Front Desk KPI Scorecard';
            $ordersHandled = $ordersLoaded;
            $itemsPacked = 0.0;
            $avgCompletion = isset($front['avg_assignment_minutes']) ? (float) $front['avg_assignment_minutes'] : null;
        }

        $overall = 0.0;
        foreach ($components as $component) {
            $overall += ((float) $component['score']) * ((float) $component['weight'] / 100);
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
                'kpi_error_penalty_points',
            ];
            foreach ($keys as $key) {
                kpi_save_setting($key, number_format(max(0, (float) ($_POST[$key] ?? 0)), 2, '.', ''));
            }
            $message = 'KPI bonus and target settings saved.';
        } elseif ($action === 'save_employee_input') {
            $employeeId = max(0, (int) ($_POST['employee_id'] ?? 0));
            $inputPeriod = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period_month'] ?? '')) ? (string) $_POST['period_month'] : $period;
            if ($employeeId <= 0) {
                throw new RuntimeException('Please choose an employee.');
            }
            $stmt = db()->prepare(
                "INSERT INTO ops_kpi_employee_inputs
                    (employee_id, period_month, monthly_salary, attendance_score, reliability_score, compliance_score, team_contribution_score, admin_accuracy_score, dispatch_score, operational_accuracy_score, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    monthly_salary = VALUES(monthly_salary),
                    attendance_score = VALUES(attendance_score),
                    reliability_score = VALUES(reliability_score),
                    compliance_score = VALUES(compliance_score),
                    team_contribution_score = VALUES(team_contribution_score),
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
                kpi_score((float) ($_POST['admin_accuracy_score'] ?? 85)),
                kpi_score((float) ($_POST['dispatch_score'] ?? 85)),
                kpi_score((float) ($_POST['operational_accuracy_score'] ?? 85)),
                ops_post_string('notes', 1000),
                ops_current_employee_id(),
            ]);
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
    </section>

    <section class="panel">
        <div class="section-row"><div><h2>Graduated bonus framework</h2><p>Bonus payouts are calculated from performance score, salary and the monthly bonus percentage.</p></div></div>
        <div class="kpi-tier-grid">
            <?php foreach ([
                ['0-49%', 'Needs Improvement', 'No bonus'],
                ['50-59%', 'Developing', '25% of entitlement'],
                ['60-69%', 'Satisfactory', '50% of entitlement'],
                ['70-74%', 'Good Performer', '75% of entitlement'],
                ['75-89%', 'High Performer', '100% of entitlement'],
                ['90-100%', 'Exceptional Performer', '100% + reward eligible'],
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
