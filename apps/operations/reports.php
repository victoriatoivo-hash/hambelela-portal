<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/owner-dashboard-data.php';

require_login();

$pageTitle = 'KPI Reports | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$currentKpiUser = current_user();
$isKpiManager = user_has_role('owner_admin', 'supervisor_manager');
$currentKpiEmployeeId = max(0, (int) ($currentKpiUser['id'] ?? 0));
$requestedTab = preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['tab'] ?? 'overview')) ?: 'overview';
$allowedPeriodPresets = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_30_days', 'custom'];
$defaultPeriodPreset = $requestedTab === 'overview' && $isKpiManager ? 'today' : 'this_month';
$periodPreset = in_array((string) ($_GET['period_preset'] ?? ''), $allowedPeriodPresets, true)
    ? (string) $_GET['period_preset']
    : $defaultPeriodPreset;
$today = new DateTimeImmutable('today');
$presetRanges = [
    'today' => [$today, $today],
    'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
    'this_week' => [$today->modify('monday this week'), $today],
    'last_week' => [$today->modify('monday last week'), $today->modify('sunday last week')],
    'this_month' => [$today->modify('first day of this month'), $today],
    'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
    'last_30_days' => [$today->modify('-29 days'), $today],
];
$defaultStartDate = $presetRanges[$defaultPeriodPreset][0]->format('Y-m-d');
$defaultEndDate = $presetRanges[$defaultPeriodPreset][1]->format('Y-m-d');
if ($periodPreset === 'custom') {
    $filterStartDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['start_date'] ?? '')) ? (string) $_GET['start_date'] : $defaultStartDate;
    $filterEndDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['end_date'] ?? '')) ? (string) $_GET['end_date'] : $defaultEndDate;
} else {
    $filterStartDate = $presetRanges[$periodPreset][0]->format('Y-m-d');
    $filterEndDate = $presetRanges[$periodPreset][1]->format('Y-m-d');
}
if ($filterEndDate < $filterStartDate) {
    $filterEndDate = $filterStartDate;
}
$period = substr($filterStartDate, 0, 7);
$periodStart = $filterStartDate . ' 00:00:00';
$periodEnd = (new DateTimeImmutable($filterEndDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
$rangeDays = (int) (new DateTimeImmutable($filterStartDate))->diff(new DateTimeImmutable($filterEndDate))->days + 1;
$previousEndDate = (new DateTimeImmutable($filterStartDate))->modify('-1 day');
$previousStartDate = $previousEndDate->modify('-' . max(0, $rangeDays - 1) . ' days');
$previousPeriod = $previousStartDate->format('Y-m');
$previousStart = $previousStartDate->format('Y-m-d 00:00:00');
$previousEnd = $previousEndDate->modify('+1 day')->format('Y-m-d 00:00:00');
$activeTab = $isKpiManager ? $requestedTab : 'employees';
$selectedEmployeeId = $isKpiManager ? max(0, (int) ($_GET['employee_id'] ?? 0)) : $currentKpiEmployeeId;

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
        "CREATE TABLE IF NOT EXISTS employee_user_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            portal_user_id INT NOT NULL,
            hr_employee_id INT NOT NULL,
            role VARCHAR(120) NULL,
            linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            linked_by INT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_employee_user_link (portal_user_id),
            INDEX idx_hr_employee_link (hr_employee_id),
            FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (linked_by) REFERENCES ops_employees(id) ON DELETE SET NULL
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_status_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(80) NOT NULL,
            record_id BIGINT NOT NULL,
            old_status VARCHAR(120) NULL,
            new_status VARCHAR(120) NULL,
            changed_by_user_id INT NULL,
            linked_hr_employee_id INT NULL,
            assigned_employee_id INT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            business_time_elapsed DECIMAL(10,2) NULL,
            notes TEXT NULL,
            INDEX idx_kpi_status_module_record (module_key, record_id, timestamp),
            INDEX idx_kpi_status_user (changed_by_user_id, timestamp),
            INDEX idx_kpi_status_assigned (assigned_employee_id, timestamp),
            FOREIGN KEY (changed_by_user_id) REFERENCES ops_employees(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id) ON DELETE SET NULL
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_business_time_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(80) NOT NULL,
            record_id BIGINT NOT NULL,
            from_status VARCHAR(120) NULL,
            to_status VARCHAR(120) NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            real_minutes DECIMAL(10,2) NULL,
            business_minutes DECIMAL(10,2) NULL,
            employee_id INT NULL,
            hr_employee_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_time_module_record (module_key, record_id),
            INDEX idx_business_time_employee (employee_id, started_at)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_employee_scores (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            period_month CHAR(7) NOT NULL,
            portal_user_id INT NOT NULL,
            hr_employee_id INT NULL,
            role_group VARCHAR(40) NOT NULL,
            total_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            score_band VARCHAR(80) NULL,
            score_payload JSON NULL,
            calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_employee_score (period_month, portal_user_id),
            INDEX idx_kpi_employee_score_hr (hr_employee_id, period_month),
            FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_score_weights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_group VARCHAR(40) NOT NULL,
            component_key VARCHAR(80) NOT NULL,
            component_label VARCHAR(160) NOT NULL,
            weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_score_weight (role_group, component_key)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_module_metrics (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            period_month CHAR(7) NOT NULL,
            module_key VARCHAR(80) NOT NULL,
            portal_user_id INT NULL,
            hr_employee_id INT NULL,
            metric_key VARCHAR(120) NOT NULL,
            metric_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            metric_payload JSON NULL,
            calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kpi_module_metric (period_month, module_key, metric_key),
            INDEX idx_kpi_module_employee (portal_user_id, period_month)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS kpi_bonus_reviews (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            period_month CHAR(7) NOT NULL,
            portal_user_id INT NOT NULL,
            hr_employee_id INT NULL,
            salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            monthly_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            quarterly_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            bonus_eligibility VARCHAR(80) NULL,
            increment_recommendation VARCHAR(160) NULL,
            owner_decision VARCHAR(80) NULL,
            notes TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kpi_bonus_review (period_month, portal_user_id),
            FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES ops_employees(id) ON DELETE SET NULL
        )"
    );

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

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_login_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NULL,
            employee_name VARCHAR(160) NULL,
            role_key VARCHAR(60) NULL,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source VARCHAR(40) NOT NULL DEFAULT 'database',
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            INDEX idx_login_employee_time (employee_id, login_at),
            INDEX idx_login_time (login_at)
        )"
    );

    kpi_try_sql(
        "CREATE TABLE IF NOT EXISTS ops_employee_availability_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            availability_status VARCHAR(40) NOT NULL,
            unavailable_until DATETIME NULL,
            note VARCHAR(255) NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_availability_history_employee (employee_id, changed_at),
            INDEX idx_availability_history_status (availability_status, changed_at)
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
            try {
                $stmt = db()->prepare(
                    "INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
                     SELECT ?, ?, ?, ?
                     WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = ? AND component_key = ?)"
                );
                $stmt->execute([$group, $key, $row['label'], $row['weight'], $group, $key]);
            } catch (Throwable $e) {
                // Defaults are helpful but not critical.
            }
        }
    }

    ops_reconcile_core_staff();
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
    if (ops_table_exists('kpi_score_weights')) {
        foreach (ops_rows('SELECT role_group, component_key, component_label, weight_percent FROM kpi_score_weights WHERE active = 1') as $row) {
            $weights[(string) $row['role_group']][(string) $row['component_key']] = [
                'label' => (string) $row['component_label'],
                'weight' => (float) $row['weight_percent'],
            ];
        }
        return $weights;
    }
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

function kpi_employee_links(): array
{
    if (!ops_table_exists('employee_user_links')) {
        return [];
    }

    $links = [];
    foreach (ops_rows('SELECT * FROM employee_user_links WHERE active = 1') as $row) {
        $links[(int) $row['portal_user_id']] = $row;
    }

    return $links;
}

function kpi_hr_leave_map(string $periodStart, string $periodEnd): array
{
    $rows = ops_hr_rows(
        "SELECT employee_id, leave_type, start_date, end_date, status
         FROM leave_requests
         WHERE status = 'approved'
           AND start_date <= DATE(?)
           AND end_date >= DATE(?)",
        [$periodEnd, $periodStart]
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['employee_id']][] = $row;
    }

    return $map;
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
    $currentUserId = (int) (current_user()['id'] ?? 0);
    $employees = ops_rows(
        "SELECT e.id, e.full_name, e.email, r.role_key, r.name AS role_name
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE (
                LOWER(TRIM(e.status)) = 'active'
                OR LOWER(e.email) = 'shiwedasecilia3@gmail.com'
                OR LOWER(e.full_name) LIKE '%secilia%'
                OR LOWER(e.full_name) LIKE '%cecilia%'
           )
           AND NOT (
                r.role_key = 'owner_admin'
                AND (
                    e.id = ?
                    OR LOWER(e.full_name) LIKE '%victoria%'
                    OR LOWER(e.email) LIKE '%victoria%'
                )
           )
         ORDER BY FIELD(r.role_key, 'front_desk_admin', 'packer', 'supervisor_manager', 'owner_admin'), e.full_name",
        [$currentUserId]
    );
    $employeeAliasMap = ops_employee_alias_map($employees);
    $employees = ops_canonical_employee_rows($employees);

    $employeeLinks = kpi_employee_links();
    $hrEmployees = ops_hr_employee_options();
    $hrLeave = kpi_hr_leave_map($start, $end);
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
        $employeeId = ops_canonical_employee_id((int) $row['employee_id'], $employeeAliasMap);
        if (!isset($errorsByEmployee[$employeeId])) {
            $errorsByEmployee[$employeeId] = ['error_count' => 0, 'error_points' => 0];
        }
        $errorsByEmployee[$employeeId]['error_count'] += (int) ($row['error_count'] ?? 0);
        $errorsByEmployee[$employeeId]['error_points'] += (float) ($row['error_points'] ?? 0);
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
        $employeeId = ops_canonical_employee_id((int) $row['assigned_employee_id'], $employeeAliasMap);
        if (!isset($checkByEmployee[$employeeId])) {
            $checkByEmployee[$employeeId] = ['checklist_total' => 0, 'checklist_done' => 0, 'missed_tasks' => 0];
        }
        $checkByEmployee[$employeeId]['checklist_total'] += (int) ($row['checklist_total'] ?? 0);
        $checkByEmployee[$employeeId]['checklist_done'] += (int) ($row['checklist_done'] ?? 0);
        $checkByEmployee[$employeeId]['missed_tasks'] += (int) ($row['missed_tasks'] ?? 0);
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
        $employeeId = ops_canonical_employee_id((int) $row['assigned_packer_id'], $employeeAliasMap);
        if (!isset($packerByEmployee[$employeeId])) {
            $packerByEmployee[$employeeId] = ['handled_orders' => 0, 'completed_orders' => 0, 'workload_points' => 0, 'avg_start_minutes' => null, 'avg_pack_minutes' => null, 'avg_completion_minutes' => null];
        }
        $packerByEmployee[$employeeId]['handled_orders'] += (int) ($row['handled_orders'] ?? 0);
        $packerByEmployee[$employeeId]['completed_orders'] += (int) ($row['completed_orders'] ?? 0);
        $packerByEmployee[$employeeId]['workload_points'] += (float) ($row['workload_points'] ?? 0);
        foreach (['avg_start_minutes', 'avg_pack_minutes', 'avg_completion_minutes'] as $avgKey) {
            if ($packerByEmployee[$employeeId][$avgKey] === null && $row[$avgKey] !== null) {
                $packerByEmployee[$employeeId][$avgKey] = $row[$avgKey];
            }
        }
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
        $employeeId = ops_canonical_employee_id((int) $row['employee_id'], $employeeAliasMap);
        $itemsByEmployee[$employeeId] = (float) ($itemsByEmployee[$employeeId] ?? 0) + (float) $row['items_packed'];
    }

    $canReadPackingTaskTiming = ops_table_exists('ops_packing_tasks')
        && ops_column_exists('ops_packing_tasks', 'date_loaded')
        && ops_column_exists('ops_packing_tasks', 'date_completed');
    $packingTaskRows = $canReadPackingTaskTiming ? ops_rows(
        "SELECT assigned_employee_id,
                COUNT(*) AS packing_rows,
                SUM(CASE WHEN packing_status IN ('done', 'done_needs_label', 'label_created', 'website') THEN 1 ELSE 0 END) AS packing_done_rows,
                COALESCE(SUM(workload_points), 0) AS packing_workload,
                AVG(CASE WHEN date_completed IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, date_loaded, date_completed) END) AS avg_packing_task_minutes
         FROM ops_packing_tasks
         WHERE assigned_employee_id IS NOT NULL
           AND date_loaded >= ? AND date_loaded < ?
         GROUP BY assigned_employee_id",
        [$start, $end]
    ) : [];
    $packingTasksByEmployee = [];
    foreach ($packingTaskRows as $row) {
        $employeeId = ops_canonical_employee_id((int) $row['assigned_employee_id'], $employeeAliasMap);
        if (!isset($packingTasksByEmployee[$employeeId])) {
            $packingTasksByEmployee[$employeeId] = ['packing_rows' => 0, 'packing_done_rows' => 0, 'packing_workload' => 0, 'avg_packing_task_minutes' => null];
        }
        $packingTasksByEmployee[$employeeId]['packing_rows'] += (int) ($row['packing_rows'] ?? 0);
        $packingTasksByEmployee[$employeeId]['packing_done_rows'] += (int) ($row['packing_done_rows'] ?? 0);
        $packingTasksByEmployee[$employeeId]['packing_workload'] += (float) ($row['packing_workload'] ?? 0);
        if ($packingTasksByEmployee[$employeeId]['avg_packing_task_minutes'] === null && $row['avg_packing_task_minutes'] !== null) {
            $packingTasksByEmployee[$employeeId]['avg_packing_task_minutes'] = $row['avg_packing_task_minutes'];
        }
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
        $employeeId = ops_canonical_employee_id((int) $row['created_by'], $employeeAliasMap);
        if (!isset($frontByEmployee[$employeeId])) {
            $frontByEmployee[$employeeId] = ['orders_loaded' => 0, 'unassigned_orders' => 0, 'delayed_orders' => 0, 'correction_orders' => 0, 'avg_assignment_minutes' => null];
        }
        $frontByEmployee[$employeeId]['orders_loaded'] += (int) ($row['orders_loaded'] ?? 0);
        $frontByEmployee[$employeeId]['unassigned_orders'] += (int) ($row['unassigned_orders'] ?? 0);
        $frontByEmployee[$employeeId]['delayed_orders'] += (int) ($row['delayed_orders'] ?? 0);
        $frontByEmployee[$employeeId]['correction_orders'] += (int) ($row['correction_orders'] ?? 0);
        if ($frontByEmployee[$employeeId]['avg_assignment_minutes'] === null && $row['avg_assignment_minutes'] !== null) {
            $frontByEmployee[$employeeId]['avg_assignment_minutes'] = $row['avg_assignment_minutes'];
        }
    }

    $scores = [];
    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id'];
        $roleKey = (string) $employee['role_key'];
        $link = $employeeLinks[$employeeId] ?? null;
        $hrEmployeeId = $link ? (int) ($link['hr_employee_id'] ?? 0) : 0;
        $hrEmployee = $hrEmployeeId && isset($hrEmployees[$hrEmployeeId]) ? $hrEmployees[$hrEmployeeId] : null;
        $onLeave = $hrEmployeeId && !empty($hrLeave[$hrEmployeeId]);
        $input = array_merge(kpi_default_input(), $inputs[$employeeId] ?? []);
        $errors = $errorsByEmployee[$employeeId] ?? ['error_count' => 0, 'error_points' => 0];
        $check = $checkByEmployee[$employeeId] ?? ['checklist_total' => 0, 'checklist_done' => 0, 'missed_tasks' => 0];
        $checkTotal = (int) $check['checklist_total'];
        $checkDone = (int) $check['checklist_done'];
        $checkRate = $checkTotal > 0 ? kpi_score(($checkDone / max(1, $checkTotal)) * 100) : (float) $input['compliance_score'];
        $attendanceReliability = kpi_score(((float) $input['attendance_score'] + (float) $input['reliability_score']) / 2);
        $errorPoints = (float) ($errors['error_points'] ?? 0);
        $accuracyScore = kpi_penalty_score($errorPoints, kpi_float_setting($settings, 'error_penalty_points'));

        if ($hrEmployee && (float) ($hrEmployee['basic_salary'] ?? 0) > 0 && empty($inputs[$employeeId]['monthly_salary'])) {
            $input['monthly_salary'] = (float) $hrEmployee['basic_salary'];
        }

        $roleGroup = kpi_role_group($roleKey);
        $roleWeights = kpi_role_weights();
        if ($roleGroup === 'packer') {
            $pack = $packerByEmployee[$employeeId] ?? [];
            $packingTask = $packingTasksByEmployee[$employeeId] ?? [];
            $completedOrders = (int) ($pack['completed_orders'] ?? 0);
            $packingRows = (int) ($packingTask['packing_rows'] ?? 0);
            $packingDoneRows = (int) ($packingTask['packing_done_rows'] ?? 0);
            $handledOrders = (int) ($pack['handled_orders'] ?? 0) + $packingRows;
            $itemsPacked = (float) ($itemsByEmployee[$employeeId] ?? 0);
            $packingWorkload = (float) ($packingTask['packing_workload'] ?? 0);
            $workVolume = max($itemsPacked, $packingWorkload, (float) $packingDoneRows);
            $itemsPacked = $workVolume;
            $productivity = kpi_score((kpi_ratio_score((float) ($completedOrders + $packingDoneRows), kpi_float_setting($settings, 'target_orders_month')) * 0.45) + (kpi_ratio_score($workVolume, kpi_float_setting($settings, 'target_items_month')) * 0.55));
            $startSpeed = isset($pack['avg_start_minutes']) && $pack['avg_start_minutes'] !== null ? kpi_speed_score((float) $pack['avg_start_minutes'], kpi_float_setting($settings, 'target_start_minutes')) : null;
            $packSpeed = isset($pack['avg_pack_minutes']) && $pack['avg_pack_minutes'] !== null ? kpi_speed_score((float) $pack['avg_pack_minutes'], kpi_float_setting($settings, 'target_packing_minutes')) : null;
            if ($packSpeed === null && isset($packingTask['avg_packing_task_minutes']) && $packingTask['avg_packing_task_minutes'] !== null) {
                $packSpeed = kpi_speed_score((float) $packingTask['avg_packing_task_minutes'], kpi_float_setting($settings, 'target_packing_minutes'));
            }
            $speedParts = array_values(array_filter([$startSpeed, $packSpeed], static fn ($value): bool => $value !== null));
            $speed = $speedParts ? kpi_score(array_sum($speedParts) / count($speedParts)) : 75.0;
            $compliance = kpi_score(($checkRate * 0.75) + ((float) $input['compliance_score'] * 0.25));
            $team = kpi_score((float) $input['team_contribution_score']);
            $rawComponents = [
                'order_speed' => ['score' => $speed, 'raw' => $speedParts ? kpi_duration(isset($pack['avg_pack_minutes']) ? (float) $pack['avg_pack_minutes'] : ((isset($packingTask['avg_packing_task_minutes']) && $packingTask['avg_packing_task_minutes'] !== null) ? (float) $packingTask['avg_packing_task_minutes'] : null)) : 'Timing data incomplete'],
                'packing_productivity' => ['score' => $productivity, 'raw' => number_format($workVolume, 1) . ' work pts / items'],
                'packing_accuracy' => ['score' => $accuracyScore, 'raw' => number_format((int) ($errors['error_count'] ?? 0)) . ' errors'],
                'tasks' => ['score' => $compliance, 'raw' => number_format($checkDone) . '/' . number_format($checkTotal) . ' tasks'],
                'errors' => ['score' => $accuracyScore, 'raw' => number_format($errorPoints, 1) . ' penalty pts'],
                'team' => ['score' => $team, 'raw' => 'Manual score'],
            ];
            $scorecard = 'Packer KPI Scorecard';
            $ordersHandled = $handledOrders;
            $avgCompletion = isset($pack['avg_completion_minutes']) && $pack['avg_completion_minutes'] !== null ? (float) $pack['avg_completion_minutes'] : ((isset($packingTask['avg_packing_task_minutes']) && $packingTask['avg_packing_task_minutes'] !== null) ? (float) $packingTask['avg_packing_task_minutes'] : null);
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

        if ($onLeave) {
            foreach ($rawComponents as $key => $component) {
                if ((float) ($component['score'] ?? 0) < 80) {
                    $rawComponents[$key]['score'] = 80.0;
                    $rawComponents[$key]['raw'] = trim((string) ($component['raw'] ?? '') . ' | approved leave considered', ' |');
                }
            }
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
            'hr_employee_id' => $hrEmployeeId ?: null,
            'hr_linked' => (bool) $hrEmployee,
            'hr_status' => $hrEmployee ? (string) ($hrEmployee['status'] ?? '') : '',
            'hr_department' => $hrEmployee ? (string) ($hrEmployee['department'] ?? '') : '',
            'hr_job_title' => $hrEmployee ? (string) ($hrEmployee['job_title'] ?? '') : '',
            'on_leave' => (bool) $onLeave,
            'name' => $hrEmployee ? (string) ($hrEmployee['full_name'] ?: $employee['full_name']) : (string) $employee['full_name'],
            'portal_name' => (string) $employee['full_name'],
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
        $statusKey = kpi_status_key($status);
        $created = (string) ($order['created_at'] ?? '');
        $assignedAt = (string) ($order['assigned_at'] ?? '');
        $startedAt = (string) ($order['packing_started_at'] ?? '');
        $packedAt = (string) (($order['packed_at'] ?? '') ?: ($order['completed_at'] ?? ''));
        $completedAt = (string) ($order['completed_at'] ?? '');
        $assignmentEnd = $assignedAt ?: ($startedAt ?: ($completedAt ?: null));
        $assign[] = kpi_business_minutes($created, $assignmentEnd);
        $startTimes[] = kpi_business_minutes($assignedAt ?: $created, $startedAt ?: ($completedAt ?: null));
        $packing[] = kpi_business_minutes($startedAt ?: $assignedAt, $packedAt ?: null);
        $completion[] = kpi_business_minutes($created, $completedAt ?: null);
        $isDone = kpi_is_done_status($status);
        $activeAge = kpi_business_minutes($created, date('Y-m-d H:i:s')) ?? 0;
        $isOverTarget = $activeAge > (float) ($settings['target_order_total_minutes'] ?? 360);
        if (!$isDone && kpi_is_active_status($status) && $isOverTarget) {
            $summary['overdue']++;
        }
        if (empty($order['assigned_packer_id']) && !$isDone) {
            $summary['unassigned']++;
        }
        if (in_array($statusKey, ['new_order', 'new', 'assigned'], true) && $isOverTarget) {
            $summary['stuck_new']++;
        }
        if (in_array($statusKey, ['in_progress', 'progress', 'packing'], true) && $isOverTarget) {
            $summary['stuck_progress']++;
        }
        if (kpi_status_key((string) ($order['order_type'] ?? '')) === 'courier' && (!$isDone || ($completedAt && substr($completedAt, 11, 8) > '14:00:00'))) {
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

function kpi_empty_detail_bucket(): array
{
    return [
        'orders' => ['title' => 'Order Board / My Work', 'icon' => 'package-check', 'accent' => 'blue', 'metrics' => []],
        'tasks' => ['title' => 'Task Management', 'icon' => 'list-checks', 'accent' => 'teal', 'metrics' => []],
        'packing' => ['title' => 'Packing List / Consignments', 'icon' => 'package-open', 'accent' => 'purple', 'metrics' => []],
        'bookkeeping' => ['title' => 'Bookkeeping', 'icon' => 'wallet-cards', 'accent' => 'green', 'metrics' => []],
        'courier' => ['title' => 'Courier Waybills', 'icon' => 'truck', 'accent' => 'orange', 'metrics' => []],
        'errors' => ['title' => 'Errors & Corrections', 'icon' => 'triangle-alert', 'accent' => 'red', 'metrics' => []],
        'hr' => ['title' => 'HR / Leave', 'icon' => 'calendar-days', 'accent' => 'pink', 'metrics' => []],
    ];
}

function kpi_add_detail_metric(array &$details, int $employeeId, string $bucket, string $label, $value, ?string $hint = null): void
{
    if ($employeeId <= 0 || !isset($details[$employeeId][$bucket])) {
        return;
    }
    $details[$employeeId][$bucket]['metrics'][] = [
        'label' => $label,
        'value' => (string) $value,
        'hint' => $hint,
    ];
}

function kpi_done_status_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . "status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery')";
}

function kpi_active_status_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . "status NOT IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed')";
}

function kpi_status_key(?string $status): string
{
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', (string) $status), '_'));
}

function kpi_is_done_status(?string $status): bool
{
    return in_array(kpi_status_key($status), ['completed', 'complete', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true);
}

function kpi_is_active_status(?string $status): bool
{
    return !in_array(kpi_status_key($status), ['completed', 'complete', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed'], true);
}

function kpi_employee_performance_details(array $scores, string $start, string $end, array $settings): array
{
    $details = [];
    $hrToEmployee = [];
    foreach ($scores as $score) {
        $employeeId = (int) ($score['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }
        $details[$employeeId] = kpi_empty_detail_bucket();
        if (!empty($score['hr_employee_id'])) {
            $hrToEmployee[(int) $score['hr_employee_id']] = $employeeId;
        }
    }

    if (!$details) {
        return [];
    }

    $targetOrderMinutes = (int) ($settings['target_order_total_minutes'] ?? 360);

    if (ops_table_exists('ops_orders')) {
        $doneSql = kpi_done_status_sql();
        $activeSql = kpi_active_status_sql();
        foreach (ops_rows(
            "SELECT assigned_packer_id AS employee_id,
                    COUNT(*) AS total_orders,
                    SUM(CASE WHEN status IN ('new_order', 'assigned') THEN 1 ELSE 0 END) AS waiting_orders,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_orders,
                    SUM(CASE WHEN {$doneSql} THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN {$activeSql} THEN 1 ELSE 0 END) AS active_orders,
                    SUM(CASE WHEN {$activeSql} AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > ? THEN 1 ELSE 0 END) AS overdue_orders,
                    SUM(CASE WHEN order_type = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders,
                    SUM(CASE WHEN order_type = 'collection' THEN 1 ELSE 0 END) AS collection_orders,
                    SUM(CASE WHEN order_type = 'courier' THEN 1 ELSE 0 END) AS courier_orders,
                    COALESCE(SUM(workload_score), 0) AS workload_points,
                    AVG(CASE WHEN packing_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), packing_started_at) END) AS avg_wait_to_start,
                    AVG(CASE WHEN COALESCE(packed_at, completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(packing_started_at, assigned_at, created_at), COALESCE(packed_at, completed_at)) END) AS avg_pack_time,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_total_time
             FROM ops_orders
             WHERE assigned_packer_id IS NOT NULL AND created_at >= ? AND created_at < ?
             GROUP BY assigned_packer_id",
            [$targetOrderMinutes, $start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Assigned orders', number_format((int) ($row['total_orders'] ?? 0)), 'Orders assigned to this picker.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Waiting / New Order', number_format((int) ($row['waiting_orders'] ?? 0)), 'Work waiting to be picked.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'In Progress', number_format((int) ($row['in_progress_orders'] ?? 0)), 'Orders currently being packed.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Completed', number_format((int) ($row['completed_orders'] ?? 0)), 'Orders finished in this period.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Overdue', number_format((int) ($row['overdue_orders'] ?? 0)), 'Active orders beyond the target time.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Delivery / Collection / Courier', number_format((int) ($row['delivery_orders'] ?? 0)) . ' / ' . number_format((int) ($row['collection_orders'] ?? 0)) . ' / ' . number_format((int) ($row['courier_orders'] ?? 0)), 'Mode split for assigned work.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Avg wait to start', kpi_duration(isset($row['avg_wait_to_start']) ? (float) $row['avg_wait_to_start'] : null), 'Assigned/New Order to In Progress.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Avg packing time', kpi_duration(isset($row['avg_pack_time']) ? (float) $row['avg_pack_time'] : null), 'In Progress to packed/completed.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Avg total order time', kpi_duration(isset($row['avg_total_time']) ? (float) $row['avg_total_time'] : null), 'Order loaded to completed.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Workload points', number_format((float) ($row['workload_points'] ?? 0), 1), 'Fairness signal based on order complexity.');
        }

        foreach (ops_rows(
            "SELECT created_by AS employee_id,
                    COUNT(*) AS orders_loaded,
                    SUM(CASE WHEN LOWER(COALESCE(customer_contact, '')) LIKE '%walk%' OR LOWER(COALESCE(customer_name, '')) LIKE '%walk%' THEN 1 ELSE 0 END) AS walk_in_orders,
                    SUM(CASE WHEN {$doneSql} THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
                    SUM(CASE WHEN payment_status <> 'paid' OR payment_status IS NULL THEN 1 ELSE 0 END) AS unpaid_orders,
                    SUM(CASE WHEN assigned_packer_id IS NULL AND {$activeSql} THEN 1 ELSE 0 END) AS unassigned_orders,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_complete_minutes
             FROM ops_orders
             WHERE created_by IS NOT NULL AND created_at >= ? AND created_at < ?
             GROUP BY created_by",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Orders loaded', number_format((int) ($row['orders_loaded'] ?? 0)), 'Orders created or imported by this front-office user.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Walk-in customers assisted', number_format((int) ($row['walk_in_orders'] ?? 0)), 'Walk-in customer records usually handled at front desk.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Completed orders', number_format((int) ($row['completed_orders'] ?? 0)), 'Front desk completion flow.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Paid / unpaid', number_format((int) ($row['paid_orders'] ?? 0)) . ' / ' . number_format((int) ($row['unpaid_orders'] ?? 0)), 'Payment follow-up signal.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Unassigned follow-up', number_format((int) ($row['unassigned_orders'] ?? 0)), 'Orders still needing assignment.');
            kpi_add_detail_metric($details, $employeeId, 'orders', 'Avg completion follow-up', kpi_duration(isset($row['avg_complete_minutes']) ? (float) $row['avg_complete_minutes'] : null), 'Loaded to completed.');
        }
    }

    if (ops_table_exists('ops_checklist_tasks')) {
        foreach (ops_rows(
            "SELECT assigned_employee_id AS employee_id,
                    COUNT(*) AS total_tasks,
                    SUM(CASE WHEN status IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS done_tasks,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                    SUM(CASE WHEN status IN ('not_started', 'pending', 'todo') THEN 1 ELSE 0 END) AS pending_tasks,
                    SUM(CASE WHEN status = 'needs_review' THEN 1 ELSE 0 END) AS review_tasks,
                    SUM(CASE WHEN status NOT IN ('done', 'completed', 'approved') AND deadline IS NOT NULL AND deadline < NOW() THEN 1 ELSE 0 END) AS overdue_tasks,
                    SUM(CASE WHEN checklist_type IN ('cleaning', 'saturday', 'stock_refill') OR recurrence_key IS NOT NULL THEN 1 ELSE 0 END) AS recurring_tasks,
                    AVG(CASE WHEN COALESCE(date_completed, completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(date_assigned, created_at), COALESCE(date_completed, completed_at)) END) AS avg_complete_minutes
             FROM ops_checklist_tasks
             WHERE assigned_employee_id IS NOT NULL AND created_at >= ? AND created_at < ?
             GROUP BY assigned_employee_id",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Total tasks', number_format((int) ($row['total_tasks'] ?? 0)), 'All assigned checklist/task items.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Done / Pending', number_format((int) ($row['done_tasks'] ?? 0)) . ' / ' . number_format((int) ($row['pending_tasks'] ?? 0)), 'Completion accountability.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'In progress', number_format((int) ($row['in_progress_tasks'] ?? 0)), 'Currently active tasks.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Needs review', number_format((int) ($row['review_tasks'] ?? 0)), 'Tasks waiting for approval/review.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Overdue tasks', number_format((int) ($row['overdue_tasks'] ?? 0)), 'Deadline missed or currently late.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Recurring / cleaning tasks', number_format((int) ($row['recurring_tasks'] ?? 0)), 'Shelf stocking, cleaning and recurring duties.');
            kpi_add_detail_metric($details, $employeeId, 'tasks', 'Avg task completion', kpi_duration(isset($row['avg_complete_minutes']) ? (float) $row['avg_complete_minutes'] : null), 'Assigned to completed.');
        }
    }

    if (ops_table_exists('ops_packing_tasks')) {
        $websiteTimeColumn = ops_column_exists('ops_packing_tasks', 'website_uploaded_at') ? 'website_uploaded_at' : (ops_column_exists('ops_packing_tasks', 'updated_at') ? 'updated_at' : '');
        $websiteTimeSql = $websiteTimeColumn !== ''
            ? "AVG(CASE WHEN website_uploaded = 1 AND {$websiteTimeColumn} IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, date_loaded, {$websiteTimeColumn}) END) AS avg_website_minutes,"
            : 'NULL AS avg_website_minutes,';
        $receivedSql = ops_column_exists('ops_packing_tasks', 'received_weight')
            ? 'COALESCE(SUM(CAST(received_weight AS DECIMAL(12,2))), 0) AS received_weight_total,'
            : '0 AS received_weight_total,';
        foreach (ops_rows(
            "SELECT assigned_employee_id AS employee_id,
                    COUNT(*) AS total_rows,
                    SUM(CASE WHEN packing_status = 'not_started' THEN 1 ELSE 0 END) AS not_started_rows,
                    SUM(CASE WHEN packing_status = 'packing' THEN 1 ELSE 0 END) AS packing_rows,
                    SUM(CASE WHEN packing_status IN ('done', 'website', 'label_created') THEN 1 ELSE 0 END) AS done_rows,
                    SUM(CASE WHEN packing_status IN ('done_needs_label', 'packed_label_needed') THEN 1 ELSE 0 END) AS label_wait_rows,
                    SUM(CASE WHEN website_uploaded = 1 THEN 1 ELSE 0 END) AS website_uploaded_rows,
                    SUM(CASE WHEN website_uploaded = 0 THEN 1 ELSE 0 END) AS website_pending_rows,
                    COALESCE(SUM(workload_points), 0) AS workload_points,
                    {$receivedSql}
                    {$websiteTimeSql}
                    AVG(CASE WHEN date_completed IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, date_loaded, date_completed) END) AS avg_pack_minutes,
                    MAX(CASE WHEN packing_status IN ('packing', 'done_needs_label', 'packed_label_needed') THEN TIMESTAMPDIFF(MINUTE, date_loaded, NOW()) END) AS oldest_active_minutes
             FROM ops_packing_tasks
             WHERE assigned_employee_id IS NOT NULL AND date_loaded >= ? AND date_loaded < ?
             GROUP BY assigned_employee_id",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Packing rows assigned', number_format((int) ($row['total_rows'] ?? 0)), 'Product lines assigned on the packing list.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Not started / Packing / Done', number_format((int) ($row['not_started_rows'] ?? 0)) . ' / ' . number_format((int) ($row['packing_rows'] ?? 0)) . ' / ' . number_format((int) ($row['done_rows'] ?? 0)), 'Status progress per person.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Waiting for label', number_format((int) ($row['label_wait_rows'] ?? 0)), 'Rows done but blocked by labels.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Website uploaded / pending', number_format((int) ($row['website_uploaded_rows'] ?? 0)) . ' / ' . number_format((int) ($row['website_pending_rows'] ?? 0)), 'Website quantity update responsibility.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Workload points', number_format((float) ($row['workload_points'] ?? 0), 1), 'Fairness signal across packers.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Recorded received weight', number_format((float) ($row['received_weight_total'] ?? 0), 1), 'Parsed numeric weight from received-weight field where available.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Avg packing completion', kpi_duration(isset($row['avg_pack_minutes']) ? (float) $row['avg_pack_minutes'] : null), 'Loaded to completed.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Oldest active wait', kpi_duration(isset($row['oldest_active_minutes']) ? (float) $row['oldest_active_minutes'] : null), 'Longest item still packing or waiting label.');
            kpi_add_detail_metric($details, $employeeId, 'packing', 'Avg website update time', kpi_duration(isset($row['avg_website_minutes']) ? (float) $row['avg_website_minutes'] : null), 'Loaded to website checkbox updated.');
        }
    }

    if (ops_table_exists('ops_error_logs')) {
        $repeatSql = ops_column_exists('ops_error_logs', 'repeat_issue') ? 'repeat_issue = 1' : '0';
        $statusSql = ops_column_exists('ops_error_logs', 'status') ? "status <> 'resolved'" : '0';
        foreach (ops_rows(
            "SELECT employee_id,
                    COUNT(*) AS total_errors,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical_errors,
                    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS high_errors,
                    SUM(CASE WHEN {$repeatSql} THEN 1 ELSE 0 END) AS repeat_errors,
                    SUM(CASE WHEN {$statusSql} THEN 1 ELSE 0 END) AS unresolved_errors,
                    COALESCE(SUM(financial_impact), 0) AS financial_impact
             FROM ops_error_logs
             WHERE employee_id IS NOT NULL AND logged_at >= ? AND logged_at < ?
             GROUP BY employee_id",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'errors', 'Errors assigned', number_format((int) ($row['total_errors'] ?? 0)), 'Errors linked to this employee.');
            kpi_add_detail_metric($details, $employeeId, 'errors', 'Critical / High', number_format((int) ($row['critical_errors'] ?? 0)) . ' / ' . number_format((int) ($row['high_errors'] ?? 0)), 'Serious error severity count.');
            kpi_add_detail_metric($details, $employeeId, 'errors', 'Repeat errors', number_format((int) ($row['repeat_errors'] ?? 0)), 'Repeat issue signal.');
            kpi_add_detail_metric($details, $employeeId, 'errors', 'Unresolved', number_format((int) ($row['unresolved_errors'] ?? 0)), 'Still open or in review.');
            kpi_add_detail_metric($details, $employeeId, 'errors', 'Financial impact', kpi_money((float) ($row['financial_impact'] ?? 0)), 'Recorded error cost.');
        }
        foreach (ops_rows(
            "SELECT logged_by AS employee_id, COUNT(*) AS errors_logged
             FROM ops_error_logs
             WHERE logged_by IS NOT NULL AND logged_at >= ? AND logged_at < ?
             GROUP BY logged_by",
            [$start, $end]
        ) as $row) {
            kpi_add_detail_metric($details, (int) ($row['employee_id'] ?? 0), 'errors', 'Errors logged by employee', number_format((int) ($row['errors_logged'] ?? 0)), 'Front desk/admin logging consistency.');
        }
    }

    if (ops_table_exists('ops_cash_book_entries')) {
        $cashArchiveWhere = ops_column_exists('ops_cash_book_entries', 'archived_at')
            ? "AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00')"
            : '';
        foreach (ops_rows(
            "SELECT recorded_by AS employee_id,
                    COUNT(*) AS entries_logged,
                    COALESCE(SUM(cash_in), 0) AS total_cash_in,
                    COALESCE(SUM(cash_out), 0) AS total_cash_out,
                    SUM(CASE WHEN related_order_id IS NULL AND cash_in > 0 THEN 1 ELSE 0 END) AS unlinked_cash_entries,
                    AVG(CASE WHEN related_order_id IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, transaction_date, created_at) END) AS avg_record_minutes
             FROM ops_cash_book_entries
             WHERE recorded_by IS NOT NULL
               AND transaction_date >= ? AND transaction_date < ?
               {$cashArchiveWhere}
             GROUP BY recorded_by",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'bookkeeping', 'Cash entries logged', number_format((int) ($row['entries_logged'] ?? 0)), 'Bookkeeping transactions created.');
            kpi_add_detail_metric($details, $employeeId, 'bookkeeping', 'Cash in / out', kpi_money((float) ($row['total_cash_in'] ?? 0)) . ' / ' . kpi_money((float) ($row['total_cash_out'] ?? 0)), 'Money movement captured.');
            kpi_add_detail_metric($details, $employeeId, 'bookkeeping', 'Unlinked cash entries', number_format((int) ($row['unlinked_cash_entries'] ?? 0)), 'Cash entries not tied to a specific order.');
            kpi_add_detail_metric($details, $employeeId, 'bookkeeping', 'Avg recording delay', kpi_duration(isset($row['avg_record_minutes']) ? (float) $row['avg_record_minutes'] : null), 'Transaction date to record creation.');
        }
    }

    if (ops_table_exists('ops_courier_waybills')) {
        foreach (ops_rows(
            "SELECT uploaded_by AS employee_id,
                    COUNT(*) AS uploaded_count,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS uploaded_and_sent,
                    AVG(CASE WHEN sent_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, uploaded_at, sent_at) END) AS avg_sent_minutes
             FROM ops_courier_waybills
             WHERE uploaded_by IS NOT NULL AND uploaded_at >= ? AND uploaded_at < ?
             GROUP BY uploaded_by",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'courier', 'Waybills uploaded', number_format((int) ($row['uploaded_count'] ?? 0)), 'Courier labels scanned/uploaded.');
            kpi_add_detail_metric($details, $employeeId, 'courier', 'Uploaded then sent', number_format((int) ($row['uploaded_and_sent'] ?? 0)), 'Uploaded labels later sent to customers.');
            kpi_add_detail_metric($details, $employeeId, 'courier', 'Avg sent-to-customer time', kpi_duration(isset($row['avg_sent_minutes']) ? (float) $row['avg_sent_minutes'] : null), 'Upload to sent status.');
        }
        foreach (ops_rows(
            "SELECT sent_by AS employee_id,
                    COUNT(*) AS sent_count,
                    AVG(TIMESTAMPDIFF(MINUTE, uploaded_at, sent_at)) AS avg_sent_minutes
             FROM ops_courier_waybills
             WHERE sent_by IS NOT NULL AND sent_at >= ? AND sent_at < ?
             GROUP BY sent_by",
            [$start, $end]
        ) as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            kpi_add_detail_metric($details, $employeeId, 'courier', 'Waybills sent to customer', number_format((int) ($row['sent_count'] ?? 0)), 'Front desk completion of courier communication.');
            kpi_add_detail_metric($details, $employeeId, 'courier', 'Avg customer-send delay', kpi_duration(isset($row['avg_sent_minutes']) ? (float) $row['avg_sent_minutes'] : null), 'Uploaded to sent by this employee.');
        }
    }

    if ($hrToEmployee) {
        $placeholders = implode(',', array_fill(0, count($hrToEmployee), '?'));
        foreach (ops_hr_rows(
            "SELECT employee_id,
                    COUNT(*) AS leave_requests,
                    COALESCE(SUM(days), 0) AS leave_days,
                    GROUP_CONCAT(DISTINCT leave_type ORDER BY leave_type SEPARATOR ', ') AS leave_types,
                    GROUP_CONCAT(DISTINCT DATE_FORMAT(start_date, '%a') ORDER BY DATE_FORMAT(start_date, '%w') SEPARATOR ', ') AS usual_days
             FROM leave_requests
             WHERE employee_id IN ({$placeholders})
               AND start_date < ?
               AND end_date >= ?
               AND status IN ('approved', 'pending')
             GROUP BY employee_id",
            array_merge(array_keys($hrToEmployee), [$end, substr($start, 0, 10)])
        ) as $row) {
            $employeeId = $hrToEmployee[(int) ($row['employee_id'] ?? 0)] ?? 0;
            kpi_add_detail_metric($details, $employeeId, 'hr', 'Leave requests in range', number_format((int) ($row['leave_requests'] ?? 0)), 'Approved or pending leave overlapping this period.');
            kpi_add_detail_metric($details, $employeeId, 'hr', 'Leave days', number_format((float) ($row['leave_days'] ?? 0), 1), 'Days requested/taken.');
            kpi_add_detail_metric($details, $employeeId, 'hr', 'Leave types', (string) ($row['leave_types'] ?: '-'), 'Annual, sick, unpaid, etc.');
            kpi_add_detail_metric($details, $employeeId, 'hr', 'Usual leave days', (string) ($row['usual_days'] ?: '-'), 'Weekdays leave starts on in this period.');
        }
    }

    foreach ($scores as $score) {
        $employeeId = (int) ($score['employee_id'] ?? 0);
        if ($employeeId <= 0 || !isset($details[$employeeId])) {
            continue;
        }
        kpi_add_detail_metric($details, $employeeId, 'hr', 'Attendance score', kpi_percent((float) ($score['attendance_score'] ?? 0)), 'Manual/HR attendance input.');
        kpi_add_detail_metric($details, $employeeId, 'hr', 'Reliability score', kpi_percent((float) ($score['reliability_score'] ?? 0)), 'Manual/HR reliability input.');
        kpi_add_detail_metric($details, $employeeId, 'hr', 'Current leave flag', !empty($score['on_leave']) ? 'On leave' : 'Working / no leave flag', 'Approved leave is considered in KPI scoring.');
    }

    return $details;
}

function kpi_render_employee_detail_grid(array $detail): void
{
    echo '<div class="kpi-evidence-grid">';
    foreach ($detail as $bucket) {
        $metrics = $bucket['metrics'] ?? [];
        if (!$metrics) {
            continue;
        }
        echo '<article class="kpi-evidence-card kpi-evidence-' . htmlspecialchars((string) ($bucket['accent'] ?? 'blue'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="kpi-evidence-head"><span><i data-lucide="' . htmlspecialchars((string) ($bucket['icon'] ?? 'activity'), ENT_QUOTES, 'UTF-8') . '"></i></span><h3>' . htmlspecialchars((string) ($bucket['title'] ?? 'Module'), ENT_QUOTES, 'UTF-8') . '</h3></div>';
        echo '<div class="kpi-evidence-metrics">';
        foreach ($metrics as $metric) {
            echo '<div><small>' . htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</small><strong>' . htmlspecialchars((string) ($metric['value'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</strong>';
            if (!empty($metric['hint'])) {
                echo '<em>' . htmlspecialchars((string) $metric['hint'], ENT_QUOTES, 'UTF-8') . '</em>';
            }
            echo '</div>';
        }
        echo '</div></article>';
    }
    echo '</div>';
}

function kpi_front_date_label(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('d M H:i', $time) : '-';
}

function kpi_hr_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts ?: [] as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'H';
}

function kpi_hr_duration_between(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return 'Ongoing';
    }
    $startTime = strtotime($start);
    $endTime = strtotime($end);
    if (!$startTime || !$endTime || $endTime < $startTime) {
        return '-';
    }
    $minutes = (int) floor(($endTime - $startTime) / 60);
    return kpi_duration((float) $minutes);
}

function kpi_hr_minutes_between(?string $start, ?string $end): ?float
{
    if (!$start || !$end) {
        return null;
    }
    $startTime = strtotime($start);
    $endTime = strtotime($end);
    if (!$startTime || !$endTime || $endTime < $startTime) {
        return null;
    }

    return ($endTime - $startTime) / 60;
}

function kpi_hr_avg(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($value): bool => $value !== null && is_numeric($value)));
    if (!$values) {
        return null;
    }

    return array_sum($values) / count($values);
}

function kpi_hr_duration_value(?float $minutes): string
{
    return $minutes === null ? '-' : kpi_duration($minutes);
}

function kpi_front_is_completed_status(string $status): bool
{
    return in_array(strtolower($status), ['completed', 'complete', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true);
}

function kpi_front_is_closed_status(string $status): bool
{
    return in_array(strtolower($status), ['completed', 'complete', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed'], true);
}

function kpi_front_is_paid(array $row): bool
{
    $paymentStatus = strtolower((string) ($row['payment_status'] ?? ''));
    return in_array($paymentStatus, ['paid', 'complete', 'completed'], true);
}

function kpi_front_is_walk_in(array $row): bool
{
    $contact = strtolower((string) ($row['customer_contact'] ?? ''));
    return strpos($contact, 'walk-in') !== false || strpos($contact, 'walk in') !== false || strpos($contact, 'walkin') !== false;
}

function kpi_front_sla_due(?string $uploadedAt): ?int
{
    if (!$uploadedAt) {
        return null;
    }
    $uploaded = strtotime($uploadedAt);
    if (!$uploaded) {
        return null;
    }
    $sameDayDeadline = strtotime(date('Y-m-d 17:00:00', $uploaded));
    if ($uploaded <= $sameDayDeadline) {
        return $sameDayDeadline;
    }
    $next = strtotime('+1 day', strtotime(date('Y-m-d 09:00:00', $uploaded)));
    while ((int) date('N', $next) >= 7) {
        $next = strtotime('+1 day', $next);
    }

    return $next;
}

function kpi_front_workdays(string $start, string $end): array
{
    $days = [];
    try {
        $cursor = new DateTimeImmutable(substr($start, 0, 10) . ' 00:00:00');
        $limit = new DateTimeImmutable(substr($end, 0, 10) . ' 00:00:00');
        $today = new DateTimeImmutable('today 23:59:59');
    } catch (Throwable $e) {
        return [];
    }
    for ($day = $cursor; $day < $limit && $day <= $today; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') <= 6) {
            $days[] = $day->format('Y-m-d');
        }
    }

    return $days;
}

function kpi_hr_tag(string $text, string $tone = 'neutral'): string
{
    $class = [
        'good' => 'tg',
        'warn' => 'tw',
        'danger' => 'tr',
    ][$tone] ?? '';

    return '<span class="hr-tag ' . $class . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

function kpi_hr_render_stats(array $cards, int $columns = 5): void
{
    echo '<div class="hr-stats-row hr-cols-' . max(3, min(6, $columns)) . '">';
    foreach ($cards as $card) {
        echo '<article class="hr-stat-card"><div class="hr-stat-label">' . htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div><div class="hr-stat-value">' . htmlspecialchars((string) ($card['value'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</div>';
        if (!empty($card['badge'])) {
            echo kpi_hr_tag((string) $card['badge'], (string) ($card['tone'] ?? 'neutral'));
        } elseif (!empty($card['sub'])) {
            echo '<div class="hr-stat-sub">' . htmlspecialchars((string) $card['sub'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</article>';
    }
    echo '</div>';
}

function kpi_hr_render_table_card(string $title, array $columns, array $rows, string $action = 'View all', bool $alert = false): void
{
    echo '<article class="hr-card' . ($alert ? ' hr-alert-outline' : '') . '">';
    echo '<div class="hr-card-header' . ($alert ? ' hr-alert-header' : '') . '"><h3 class="hr-card-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3><span class="hr-card-action">' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '</span></div>';
    echo '<div class="hr-card-body hr-card-table-body"><table class="hr-data-table"><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . (string) $value . '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td class="hr-empty-state" colspan="' . count($columns) . '">No records found for this period.</td></tr>';
    }
    echo '</tbody></table></div></article>';
}

function kpi_render_front_person_live_dashboard(array $employee, array $detail, string $start, string $end): void
{
    $employeeId = (int) ($employee['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        return;
    }

    $employeeName = (string) ($employee['name'] ?? 'Front Person');
    $employeeRole = (string) ($employee['role_name'] ?? 'Customer Service & Operations');
    $score = isset($employee['score']) ? kpi_percent((float) $employee['score']) : '-';
    $monthLabel = date('F Y', strtotime($start));
    $monthStart = new DateTimeImmutable(substr($start, 0, 10) . ' 00:00:00');
    $previousMonth = $monthStart->modify('first day of previous month');
    $nextMonth = $monthStart->modify('first day of next month');
    $monthLink = static function (DateTimeImmutable $month): string {
        $first = $month->format('Y-m-01');
        $last = $month->format('Y-m-t');
        return BASE_URL . '/apps/operations/reports.php?tab=front-desk&start_date=' . rawurlencode($first) . '&end_date=' . rawurlencode($last);
    };
    $monthNav = '<div class="hr-month-nav"><a href="' . htmlspecialchars($monthLink($previousMonth), ENT_QUOTES, 'UTF-8') . '">&lsaquo;</a><strong>' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '</strong><a href="' . htmlspecialchars($monthLink($nextMonth), ENT_QUOTES, 'UTF-8') . '">&rsaquo;</a></div>';

    $paidAtExpr = ops_table_exists('ops_orders') && ops_column_exists('ops_orders', 'paid_at')
        ? 'o.paid_at'
        : "CASE WHEN o.payment_status = 'paid' THEN COALESCE(o.updated_at, o.completed_at) ELSE NULL END";
    $orderScope = ['o.created_at >= ? AND o.created_at < ?'];
    $orderParams = [$start, $end];
    if (ops_table_exists('ops_orders') && ops_column_exists('ops_orders', 'created_by')) {
        $orderScope[] = "(o.created_by = ? OR LOWER(COALESCE(o.customer_contact, '')) LIKE '%walk-in%' OR LOWER(COALESCE(o.customer_contact, '')) LIKE '%walk in%' OR LOWER(COALESCE(o.customer_contact, '')) LIKE '%walkin%')";
        $orderParams[] = $employeeId;
    }
    $orders = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT o.*, {$paidAtExpr} AS paid_at_value
         FROM ops_orders o
         WHERE " . implode(' AND ', $orderScope) . "
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT 500",
        $orderParams
    ) : [];

    $walkInOrders = array_values(array_filter($orders, static fn(array $row): bool => kpi_front_is_walk_in($row)));
    $completedOrders = array_values(array_filter($orders, static fn(array $row): bool => kpi_front_is_completed_status((string) ($row['status'] ?? ''))));
    $activeOrders = array_values(array_filter($orders, static fn(array $row): bool => !kpi_front_is_closed_status((string) ($row['status'] ?? ''))));
    $unpaidOrders = array_values(array_filter($orders, static fn(array $row): bool => !kpi_front_is_paid($row)));
    $walkDurations = [];
    foreach ($walkInOrders as $row) {
        $doneAt = $row['completed_at'] ?: (kpi_front_is_completed_status((string) ($row['status'] ?? '')) ? ($row['updated_at'] ?? null) : null);
        $walkDurations[] = kpi_hr_minutes_between((string) ($row['created_at'] ?? ''), $doneAt ? (string) $doneAt : null);
    }

    $cashArchiveClause = ops_table_exists('ops_cash_book_entries') && ops_column_exists('ops_cash_book_entries', 'archived_at') ? 'AND c.archived_at IS NULL' : '';
    $cashRows = ops_table_exists('ops_cash_book_entries') ? ops_rows(
        "SELECT c.*, e.full_name AS recorded_by_name
         FROM ops_cash_book_entries c
         LEFT JOIN ops_employees e ON e.id = c.recorded_by
         WHERE c.transaction_date >= ? AND c.transaction_date < ? {$cashArchiveClause}
         ORDER BY c.transaction_date DESC, c.id DESC
         LIMIT 500",
        [$start, $end]
    ) : [];
    $bookkeepingJoined = (ops_table_exists('ops_cash_book_entries') && ops_table_exists('ops_orders')) ? ops_rows(
        "SELECT o.order_number, o.customer_name, o.order_type, o.payment_method, o.total_amount, o.created_at, c.transaction_date,
                TIMESTAMPDIFF(MINUTE, o.created_at, c.transaction_date) AS delay_minutes
         FROM ops_orders o
         LEFT JOIN ops_cash_book_entries c ON (c.related_order_id = o.id OR c.related_order_number = o.order_number) {$cashArchiveClause}
         WHERE o.created_at >= ? AND o.created_at < ? AND o.order_type IN ('delivery', 'courier')
         ORDER BY o.created_at DESC
         LIMIT 500",
        [$start, $end]
    ) : [];
    $missingCashOrders = (ops_table_exists('ops_cash_book_entries') && ops_table_exists('ops_orders')) ? ops_rows(
        "SELECT o.*
         FROM ops_orders o
         LEFT JOIN ops_cash_book_entries c ON (c.related_order_id = o.id OR c.related_order_number = o.order_number) {$cashArchiveClause}
         WHERE o.created_at >= ? AND o.created_at < ?
           AND LOWER(COALESCE(o.payment_method, '')) LIKE '%cash%'
           AND o.payment_status = 'paid'
           AND c.id IS NULL
         ORDER BY o.created_at DESC
         LIMIT 100",
        [$start, $end]
    ) : [];

    $courierRows = ops_table_exists('ops_courier_waybills') ? ops_rows(
        "SELECT w.*, up.full_name AS uploaded_by_name, sp.full_name AS sent_by_name
         FROM ops_courier_waybills w
         LEFT JOIN ops_employees up ON up.id = w.uploaded_by
         LEFT JOIN ops_employees sp ON sp.id = w.sent_by
         WHERE w.uploaded_at >= ? AND w.uploaded_at < ?
         ORDER BY w.uploaded_at DESC, w.id DESC
         LIMIT 500",
        [$start, $end]
    ) : [];
    $waybillSent = 0;
    $waybillBreaches = 0;
    $waybillPending = 0;
    $waybillDurations = [];
    foreach ($courierRows as $row) {
        $due = kpi_front_sla_due((string) ($row['uploaded_at'] ?? ''));
        $sentAt = $row['sent_at'] ?? null;
        if ($sentAt) {
            $waybillSent++;
            $waybillDurations[] = kpi_hr_minutes_between((string) ($row['uploaded_at'] ?? ''), (string) $sentAt);
            if ($due && strtotime((string) $sentAt) > $due) {
                $waybillBreaches++;
            }
        } else {
            $waybillPending++;
            if ($due && time() > $due) {
                $waybillBreaches++;
            }
        }
    }

    $taskAssignedExpr = ops_table_exists('ops_checklist_tasks') && ops_column_exists('ops_checklist_tasks', 'date_assigned') ? 't.date_assigned' : 't.created_at';
    $taskCompletedBySelect = ops_table_exists('ops_checklist_tasks') && ops_column_exists('ops_checklist_tasks', 'completed_by') ? ', c.full_name AS completed_by_name' : ", NULL AS completed_by_name";
    $taskCompletedByJoin = ops_table_exists('ops_checklist_tasks') && ops_column_exists('ops_checklist_tasks', 'completed_by') ? 'LEFT JOIN ops_employees c ON c.id = t.completed_by' : '';
    $taskRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT t.*, e.full_name AS assigned_name {$taskCompletedBySelect}
         FROM ops_checklist_tasks t
         LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
         {$taskCompletedByJoin}
         WHERE t.assigned_employee_id = ?
           AND COALESCE({$taskAssignedExpr}, t.created_at, t.deadline) >= ?
           AND COALESCE({$taskAssignedExpr}, t.created_at, t.deadline) < ?
         ORDER BY COALESCE(t.deadline, t.created_at) DESC, t.id DESC
         LIMIT 500",
        [$employeeId, $start, $end]
    ) : [];

    $errorRows = ops_table_exists('ops_error_logs') ? ops_rows(
        "SELECT l.*, r.full_name AS responsible_name, by_emp.full_name AS logged_by_name
         FROM ops_error_logs l
         LEFT JOIN ops_employees r ON r.id = l.employee_id
         LEFT JOIN ops_employees by_emp ON by_emp.id = l.logged_by
         WHERE l.logged_at >= ? AND l.logged_at < ? AND (l.logged_by = ? OR l.employee_id = ?)
         ORDER BY l.logged_at DESC, l.id DESC
         LIMIT 500",
        [$start, $end, $employeeId, $employeeId]
    ) : [];

    $packingRows = ops_table_exists('ops_packing_tasks') ? ops_rows(
        "SELECT p.*, e.full_name AS assigned_name
         FROM ops_packing_tasks p
         LEFT JOIN ops_employees e ON e.id = p.assigned_employee_id
         WHERE COALESCE(p.date_loaded, p.created_at) >= ? AND COALESCE(p.date_loaded, p.created_at) < ?
         ORDER BY COALESCE(p.date_loaded, p.created_at) DESC, p.id DESC
         LIMIT 500",
        [$start, $end]
    ) : [];

    $loginRows = ops_table_exists('ops_login_events') ? ops_rows(
        "SELECT *
         FROM ops_login_events
         WHERE employee_id = ? AND login_at >= ? AND login_at < ?
         ORDER BY login_at DESC",
        [$employeeId, $start, $end]
    ) : [];

    $firstLoginByDay = [];
    foreach ($loginRows as $row) {
        $day = substr((string) ($row['login_at'] ?? ''), 0, 10);
        if ($day !== '' && (!isset($firstLoginByDay[$day]) || strtotime((string) $row['login_at']) < strtotime((string) $firstLoginByDay[$day]['login_at']))) {
            $firstLoginByDay[$day] = $row;
        }
    }
    $workdays = kpi_front_workdays($start, $end);
    $lateLogins = 0;
    $earlyLogins = 0;
    $loginMinutes = [];
    foreach ($firstLoginByDay as $row) {
        $time = substr((string) ($row['login_at'] ?? ''), 11, 5);
        if ($time !== '') {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $loginMinutes[] = ($hour * 60) + $minute;
            if ($time <= '08:00') {
                $earlyLogins++;
            }
            if ($time > '08:15') {
                $lateLogins++;
            }
        }
    }
    $avgLoginMins = kpi_hr_avg($loginMinutes);
    $averageLogin = $avgLoginMins === null ? '-' : sprintf('%02d:%02d', (int) floor($avgLoginMins / 60), (int) $avgLoginMins % 60);
    $daysPresent = count($firstLoginByDay);
    $absentDays = max(0, count($workdays) - $daysPresent);
    $portalLogins = number_format($daysPresent) . '/' . number_format(count($workdays));

    $completedOrderCount = 0;
    $inProgressOrderCount = 0;
    $unpaidOrderCount = 0;
    $walkInRows = [];
    $paymentRows = [];
    $progressRows = [];
    foreach ($orders as $row) {
        $status = strtolower((string) ($row['status'] ?? ''));
        $isPaid = kpi_front_is_paid($row);
        if (kpi_front_is_completed_status($status)) {
            $completedOrderCount++;
        } elseif (!kpi_front_is_closed_status($status)) {
            $inProgressOrderCount++;
        }
        if (!$isPaid) {
            $unpaidOrderCount++;
        }
        if (kpi_front_is_walk_in($row)) {
            $doneAt = $row['completed_at'] ?: (kpi_front_is_completed_status($status) ? ($row['updated_at'] ?? null) : null);
            $walkInRows[] = [
                '<div class="hr-tname">' . htmlspecialchars((string) ($row['order_number'] ?: 'Walk-in order'), ENT_QUOTES, 'UTF-8') . '</div>',
                '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
                '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label($doneAt ? (string) $doneAt : ''), ENT_QUOTES, 'UTF-8') . '</span>',
                '<span class="hr-tmono">' . htmlspecialchars($doneAt ? kpi_hr_duration_between((string) ($row['created_at'] ?? ''), (string) $doneAt) : 'Ongoing', ENT_QUOTES, 'UTF-8') . '</span>',
                kpi_hr_tag(kpi_front_is_completed_status($status) ? 'Complete' : ucwords(str_replace('_', ' ', $status ?: 'Open')), kpi_front_is_completed_status($status) ? 'good' : 'warn'),
            ];
        }
        $paidAt = $row['paid_at_value'] ?? null;
        $paymentRows[] = [
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars($isPaid ? kpi_front_date_label((string) $paidAt) : '-', ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars($isPaid ? kpi_hr_duration_value(kpi_hr_minutes_between((string) ($row['created_at'] ?? ''), (string) $paidAt)) : 'Pending', ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag($isPaid ? 'Paid' : 'Unpaid', $isPaid ? 'good' : 'danger'),
        ];
        if (!kpi_front_is_closed_status($status)) {
            $progressRows[] = [
                '<div class="hr-tname">' . htmlspecialchars((string) ($row['order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
                kpi_hr_tag(ucwords((string) ($row['order_type'] ?: 'Order'))),
                '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
                '<span class="hr-tmono">' . htmlspecialchars(kpi_hr_duration_between((string) ($row['created_at'] ?? ''), date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8') . '</span>',
                kpi_hr_tag($isPaid ? 'Yes' : 'No', $isPaid ? 'good' : 'danger'),
                '<button class="hr-btn hr-btn-outline" type="button">Review</button>',
            ];
        }
    }

    $taskDone = 0;
    $taskProgress = 0;
    $taskPending = 0;
    $taskOverdue = 0;
    $taskDurations = [];
    foreach ($taskRows as $row) {
        $status = strtolower(str_replace(' ', '_', (string) ($row['status'] ?? '')));
        $deadline = strtotime((string) ($row['deadline'] ?? ''));
        if (in_array($status, ['done', 'completed', 'approved'], true)) {
            $taskDone++;
            $assignedAt = (string) ($row['date_assigned'] ?? $row['created_at'] ?? '');
            $doneAt = (string) ($row['date_completed'] ?? $row['completed_at'] ?? '');
            $minutes = kpi_hr_minutes_between($assignedAt, $doneAt);
            if ($minutes !== null) {
                $taskDurations[] = $minutes / 1440;
            }
        } elseif ($status === 'in_progress') {
            $taskProgress++;
        } else {
            $taskPending++;
            if ($deadline && $deadline < time()) {
                $taskOverdue++;
            }
        }
    }

    $websiteDone = 0;
    $websitePending = 0;
    $websiteLate = 0;
    $websiteOnTime = 0;
    $websiteDurations = [];
    foreach ($packingRows as $row) {
        $loadedAt = (string) ($row['date_loaded'] ?? $row['created_at'] ?? '');
        $uploadedAt = (string) ($row['website_uploaded_at'] ?? (!empty($row['website_uploaded']) ? ($row['updated_at'] ?? '') : ''));
        if (!empty($row['website_uploaded'])) {
            $websiteDone++;
            $minutes = kpi_hr_minutes_between($loadedAt, $uploadedAt);
            $websiteDurations[] = $minutes;
            if ($minutes !== null && $minutes <= 1440) {
                $websiteOnTime++;
            } else {
                $websiteLate++;
            }
        } else {
            $websitePending++;
        }
    }

    echo '<section class="hr-performance-shell hr-front-performance">';
    echo '<div class="hr-profile-strip"><div class="hr-avatar">' . htmlspecialchars(kpi_hr_initials($employeeName), ENT_QUOTES, 'UTF-8') . '</div><div class="hr-profile-info"><div class="hr-profile-name">' . htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') . '</div><div class="hr-profile-role">' . htmlspecialchars($employeeRole, ENT_QUOTES, 'UTF-8') . '</div><div class="hr-profile-meta"><div><strong>Period</strong> ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '</div><div><strong>Score</strong> ' . htmlspecialchars($score, ENT_QUOTES, 'UTF-8') . '</div><div><strong>Portal logins this month</strong> ' . htmlspecialchars($portalLogins, ENT_QUOTES, 'UTF-8') . '</div><div><strong>Avg login time</strong> ' . htmlspecialchars($averageLogin, ENT_QUOTES, 'UTF-8') . '</div></div></div><div class="hr-profile-actions"><a class="hr-btn hr-btn-outline" href="' . htmlspecialchars(BASE_URL . '/apps/hr-portal/cecilia_performance.php', ENT_QUOTES, 'UTF-8') . '">View Contract</a><button class="hr-btn hr-btn-primary" type="button">Issue Notice</button></div></div>';
    echo '<div class="hr-section-tabs" data-hr-tabs><button class="hr-section-tab active" type="button" data-hr-target="orders">Orders</button><button class="hr-section-tab" type="button" data-hr-target="bookkeeping">Bookkeeping</button><button class="hr-section-tab" type="button" data-hr-target="courier">Courier</button><button class="hr-section-tab" type="button" data-hr-target="tasks">Tasks</button><button class="hr-section-tab" type="button" data-hr-target="errors">Errors</button><button class="hr-section-tab" type="button" data-hr-target="picking">Packing List</button><button class="hr-section-tab" type="button" data-hr-target="attendance">Attendance</button></div>';

    echo '<div class="hr-section active" id="hr-sec-orders"><div class="hr-section-heading"><h2>Order Board</h2><p>Tracking order completion time, walk-in fulfilment, payment status, and processing speed.</p></div>' . $monthNav;
    kpi_hr_render_stats([
        ['label' => 'Total Orders', 'value' => number_format(count($orders)), 'sub' => 'this period'],
        ['label' => 'Completed', 'value' => number_format($completedOrderCount), 'badge' => $orders ? number_format(($completedOrderCount / max(1, count($orders))) * 100, 0) . '% completion' : 'No orders', 'tone' => 'good'],
        ['label' => 'Still In Progress', 'value' => number_format($inProgressOrderCount), 'badge' => 'Not yet marked done', 'tone' => $inProgressOrderCount ? 'warn' : 'good'],
        ['label' => 'Avg Order to Complete', 'value' => kpi_hr_duration_value(kpi_hr_avg($walkDurations)), 'sub' => 'walk-in orders'],
        ['label' => 'Unpaid Orders', 'value' => number_format($unpaidOrderCount), 'badge' => 'Payment not ticked', 'tone' => $unpaidOrderCount ? 'warn' : 'good'],
    ]);
    echo '<div class="hr-two-col">';
    kpi_hr_render_table_card('Walk-in Orders - Completion Time', ['Order', 'Loaded', 'Completed', 'Duration', 'Status'], $walkInRows);
    kpi_hr_render_table_card('Payment Status Tracking', ['Order', 'Loaded', 'Paid Ticked', 'Delay', 'Status'], $paymentRows);
    echo '</div>';
    kpi_hr_render_table_card('Orders Still In Progress (Not Marked Complete)', ['Order ID', 'Type', 'Date Loaded', 'Time Open', 'Paid', 'Action'], $progressRows, 'Flag all', $progressRows !== []);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-bookkeeping"><div class="hr-section-heading"><h2>Bookkeeping</h2><p>Tracking how quickly delivery orders and cash transactions are logged onto the bookkeeping sheet.</p></div>' . $monthNav;
    $cashInTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['cash_in'] ?? 0), $cashRows));
    $cashOutTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['cash_out'] ?? 0), $cashRows));
    kpi_hr_render_stats([
        ['label' => 'Delivery Orders', 'value' => number_format(count($bookkeepingJoined)), 'sub' => 'delivery/courier to log'],
        ['label' => 'Cash In', 'value' => kpi_money((float) $cashInTotal), 'badge' => 'Logged', 'tone' => 'good'],
        ['label' => 'Cash Out', 'value' => kpi_money((float) $cashOutTotal), 'sub' => 'expenses / payouts'],
        ['label' => 'Missing Cash Orders', 'value' => number_format(count($missingCashOrders)), 'badge' => 'Needs checking', 'tone' => count($missingCashOrders) ? 'danger' : 'good'],
        ['label' => 'Avg Log Delay', 'value' => kpi_hr_duration_value(kpi_hr_avg(array_column(array_filter($bookkeepingJoined, static fn(array $row): bool => !empty($row['transaction_date'])), 'delay_minutes'))), 'sub' => 'order placed to logged'],
    ]);
    $cashTableRows = array_map(static function (array $row): array {
        return [
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['transaction_date'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag(ucwords(str_replace('_', ' ', (string) ($row['transaction_type'] ?: '-')))),
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['description'] ?: $row['customer_name'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars((string) ($row['related_order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_money((float) ($row['cash_in'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_money((float) ($row['cash_out'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</span>',
        ];
    }, $cashRows);
    kpi_hr_render_table_card('Delivery Orders to Bookkeeping Log Time', ['Date', 'Type', 'Description', 'Order', 'In', 'Out'], $cashTableRows);
    $missingRows = array_map(static function (array $row): array {
        return [
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['order_number'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            htmlspecialchars((string) ($row['customer_name'] ?? '-'), ENT_QUOTES, 'UTF-8'),
            '<span class="hr-tmono">' . htmlspecialchars(kpi_money((float) ($row['total_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag('Never logged', 'danger'),
        ];
    }, array_slice($missingCashOrders, 0, 10));
    kpi_hr_render_table_card('Cash Orders Not Uploaded', ['Order', 'Date', 'Customer', 'Amount', 'Status'], $missingRows, 'Missing cash total: ' . kpi_money((float) array_sum(array_map('floatval', array_column($missingCashOrders, 'total_amount')))), $missingRows !== []);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-courier"><div class="hr-section-heading"><h2>Courier Waybills</h2><p>Tracking time from waybill upload to customer notification. SLA: same day before 5pm, or next day before 9am.</p></div>' . $monthNav;
    kpi_hr_render_stats([
        ['label' => 'Waybills Uploaded', 'value' => number_format(count($courierRows)), 'sub' => 'in this period'],
        ['label' => 'Sent to Customer', 'value' => number_format($waybillSent), 'badge' => 'Complete', 'tone' => 'good'],
        ['label' => 'SLA Breaches', 'value' => number_format($waybillBreaches), 'badge' => 'After deadline', 'tone' => $waybillBreaches ? 'danger' : 'good'],
        ['label' => 'Not Yet Sent', 'value' => number_format($waybillPending), 'badge' => 'Needs action', 'tone' => $waybillPending ? 'danger' : 'good'],
        ['label' => 'Avg Send Time', 'value' => kpi_hr_duration_value(kpi_hr_avg($waybillDurations)), 'sub' => 'upload to sent'],
    ]);
    $courierTableRows = array_map(static function (array $row): array {
        $sent = !empty($row['sent_at']);
        $due = kpi_front_sla_due((string) ($row['uploaded_at'] ?? ''));
        $breach = $due && (($sent && strtotime((string) ($row['sent_at'] ?? '')) > $due) || (!$sent && time() > $due));
        return [
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['waybill_reference'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['uploaded_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['sent_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_hr_duration_between((string) ($row['uploaded_at'] ?? ''), (string) ($row['sent_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag($breach ? 'SLA breach' : ($sent ? 'On time' : 'Open'), $breach ? 'danger' : ($sent ? 'good' : 'warn')),
            kpi_hr_tag(ucwords((string) ($row['status'] ?: ($sent ? 'Complete' : 'Not Sent'))), $sent ? 'good' : 'danger'),
        ];
    }, $courierRows);
    kpi_hr_render_table_card('Waybill Log - Upload to Customer Sent', ['Waybill', 'Uploaded', 'Sent to Customer', 'Duration', 'SLA', 'Status'], $courierTableRows);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-tasks"><div class="hr-section-heading"><h2>Task Management</h2><p>Tracking task completion rate, speed, and overdue items assigned to Secilia.</p></div>' . $monthNav;
    kpi_hr_render_stats([
        ['label' => 'Total Tasks', 'value' => number_format(count($taskRows)), 'sub' => 'this period'],
        ['label' => 'Completed', 'value' => number_format($taskDone), 'badge' => 'Done', 'tone' => 'good'],
        ['label' => 'In Progress', 'value' => number_format($taskProgress), 'badge' => 'Ongoing', 'tone' => 'warn'],
        ['label' => 'Not Started', 'value' => number_format($taskPending), 'badge' => 'Pending', 'tone' => 'warn'],
        ['label' => 'Overdue', 'value' => number_format($taskOverdue), 'badge' => 'Past due date', 'tone' => $taskOverdue ? 'danger' : 'good'],
    ]);
    echo '<div class="hr-task-pills"><span class="hr-task-pill done">Completed: ' . number_format($taskDone) . '</span><span class="hr-task-pill progress">In Progress: ' . number_format($taskProgress) . '</span><span class="hr-task-pill pending">Not Started: ' . number_format($taskPending) . '</span><span class="hr-task-pill overdue">Overdue: ' . number_format($taskOverdue) . '</span></div>';
    $taskTableRows = array_map(static function (array $row): array {
        $status = strtolower((string) ($row['status'] ?? ''));
        $tone = in_array($status, ['done', 'completed', 'approved'], true) ? 'good' : ($status === 'in_progress' ? 'warn' : 'danger');
        $assignedAt = (string) ($row['date_assigned'] ?? $row['created_at'] ?? '');
        $doneAt = (string) ($row['date_completed'] ?? $row['completed_at'] ?? '');
        return [
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['task_name'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label($assignedAt), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['deadline'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label($doneAt), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars($doneAt ? kpi_hr_duration_between($assignedAt, $doneAt) : 'Ongoing', ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag(ucwords(str_replace('_', ' ', $status ?: 'Open')), $tone),
        ];
    }, $taskRows);
    kpi_hr_render_table_card('Task Log', ['Task', 'Assigned', 'Due Date', 'Completed', 'Duration', 'Status'], $taskTableRows);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-errors"><div class="hr-section-heading"><h2>Error Log</h2><p>Errors Secilia has logged with notes and tagging vs errors logged against her.</p></div>' . $monthNav;
    $loggedByEmployee = 0;
    $againstEmployee = 0;
    $completeErrorLogs = 0;
    foreach ($errorRows as $row) {
        if ((int) ($row['logged_by'] ?? 0) === $employeeId) {
            $loggedByEmployee++;
            if (trim((string) ($row['description'] ?? '')) !== '' && (int) ($row['employee_id'] ?? 0) > 0) {
                $completeErrorLogs++;
            }
        }
        if ((int) ($row['employee_id'] ?? 0) === $employeeId) {
            $againstEmployee++;
        }
    }
    kpi_hr_render_stats([
        ['label' => 'Errors She Logged', 'value' => number_format($loggedByEmployee), 'badge' => 'This period', 'tone' => 'good'],
        ['label' => 'Properly Completed', 'value' => number_format($completeErrorLogs), 'sub' => 'notes + responsible person'],
        ['label' => 'Incomplete Logs', 'value' => number_format(max(0, $loggedByEmployee - $completeErrorLogs)), 'badge' => 'Missing info', 'tone' => $loggedByEmployee > $completeErrorLogs ? 'warn' : 'good'],
        ['label' => 'Errors Against Her', 'value' => number_format($againstEmployee), 'badge' => 'Logged by others', 'tone' => $againstEmployee ? 'danger' : 'good'],
    ], 4);
    $errorTableRows = array_map(static function (array $row) use ($employeeId): array {
        $against = (int) ($row['employee_id'] ?? 0) === $employeeId;
        return [
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label((string) ($row['logged_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</span>',
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['category'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            kpi_hr_tag(ucwords((string) ($row['severity'] ?: '-')), strtolower((string) ($row['severity'] ?? '')) === 'critical' ? 'danger' : 'warn'),
            kpi_hr_tag($against ? 'Against Secilia' : 'Logged by Secilia', $against ? 'danger' : 'good'),
            '<span class="hr-tmono">' . htmlspecialchars((string) ($row['resolution'] ?: 'Open'), ENT_QUOTES, 'UTF-8') . '</span>',
        ];
    }, $errorRows);
    kpi_hr_render_table_card('Error Log Records', ['Date', 'Error', 'Severity', 'Type', 'Status'], $errorTableRows);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-picking"><div class="hr-section-heading"><h2>Packing List</h2><p>Products loaded onto the system. Secilia must update stock quantities on the website within 24 hours of loading.</p></div>' . $monthNav;
    kpi_hr_render_stats([
        ['label' => 'Products Loaded', 'value' => number_format(count($packingRows)), 'sub' => 'this period'],
        ['label' => 'Website Updated', 'value' => number_format($websiteOnTime), 'badge' => 'Within 24h', 'tone' => 'good'],
        ['label' => 'Updated Late', 'value' => number_format($websiteLate), 'badge' => 'After 24h', 'tone' => $websiteLate ? 'warn' : 'good'],
        ['label' => 'Not Updated', 'value' => number_format($websitePending), 'badge' => 'Still outstanding', 'tone' => $websitePending ? 'danger' : 'good'],
        ['label' => 'Avg Update Time', 'value' => kpi_hr_duration_value(kpi_hr_avg($websiteDurations)), 'sub' => 'loaded to website updated'],
    ]);
    $packingTableRows = array_map(static function (array $row): array {
        $done = !empty($row['website_uploaded']);
        $loadedAt = (string) ($row['date_loaded'] ?? $row['created_at'] ?? '');
        $uploadedAt = (string) ($row['website_uploaded_at'] ?? ($done ? ($row['updated_at'] ?? '') : ''));
        $minutes = kpi_hr_minutes_between($loadedAt, $uploadedAt ?: date('Y-m-d H:i:s'));
        $late = $minutes !== null && $minutes > 1440;
        return [
            '<div class="hr-tname">' . htmlspecialchars((string) ($row['item_name'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</div>',
            '<span class="hr-tmono">' . htmlspecialchars((string) ($row['quantity_planned'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label($loadedAt), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_front_date_label($uploadedAt), ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars(kpi_hr_duration_value($minutes) . (!$done && $minutes !== null ? ' ongoing' : ''), ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag($done && !$late ? 'Yes' : ($done ? 'No - Late' : ($late ? 'No - Overdue' : 'Open')), $done && !$late ? 'good' : ($late ? 'danger' : 'warn')),
            kpi_hr_tag($done ? 'Complete' : 'Not Done', $done ? 'good' : 'danger'),
        ];
    }, $packingRows);
    kpi_hr_render_table_card('Packing List - Website Inventory Update Tracker', ['Product', 'Qty Loaded', 'Date Loaded', 'Website Updated', 'Time Taken', 'Within 24h', 'Inventory Update'], $packingTableRows, 'Export', $websitePending > 0);
    echo '</div>';

    echo '<div class="hr-section" id="hr-sec-attendance"><div class="hr-section-heading"><h2>Attendance & Punctuality</h2><p>Portal login times, physical attendance, punctuality patterns, and overtime averages.</p></div>' . $monthNav;
    kpi_hr_render_stats([
        ['label' => 'Days Present', 'value' => $portalLogins, 'badge' => 'Portal logins', 'tone' => 'good'],
        ['label' => 'Late Arrivals', 'value' => number_format($lateLogins), 'badge' => 'This period', 'tone' => $lateLogins ? 'warn' : 'good'],
        ['label' => 'Portal Logins', 'value' => number_format(count($loginRows)), 'sub' => 'login events'],
        ['label' => 'Avg Login Time', 'value' => $averageLogin, 'badge' => 'Punctuality record', 'tone' => 'good'],
        ['label' => 'Early Logins', 'value' => number_format($earlyLogins), 'sub' => 'before opening'],
        ['label' => 'Unplanned Absences', 'value' => number_format($absentDays), 'sub' => 'based on login record'],
    ], 6);
    $loginTableRows = array_map(static function (array $row): array {
        $login = (string) ($row['login_at'] ?? '');
        $late = $login && strtotime($login) && date('H:i:s', strtotime($login)) > '08:30:00';
        return [
            '<span class="hr-tmono">' . htmlspecialchars($login ? date('d M (D)', strtotime($login)) : '-', ENT_QUOTES, 'UTF-8') . '</span>',
            '<span class="hr-tmono">' . htmlspecialchars($login ? date('H:i', strtotime($login)) : '-', ENT_QUOTES, 'UTF-8') . '</span>',
            kpi_hr_tag($late ? 'Late' : 'On time', $late ? 'warn' : 'good'),
            htmlspecialchars((string) ($row['role_key'] ?: '-'), ENT_QUOTES, 'UTF-8'),
        ];
    }, $loginRows);
    kpi_hr_render_table_card('Portal Login Time Log', ['Date', 'Login Time', 'On Time', 'Role'], $loginTableRows, 'Full backlog');
    echo '</div>';

    echo '<script>document.addEventListener("click",function(event){var tab=event.target.closest(".hr-front-performance [data-hr-target]");if(!tab){return;}var shell=tab.closest(".hr-front-performance");var name=tab.getAttribute("data-hr-target");shell.querySelectorAll(".hr-section-tab").forEach(function(item){item.classList.remove("active");});tab.classList.add("active");shell.querySelectorAll(".hr-section").forEach(function(item){item.classList.remove("active");});var section=shell.querySelector("#hr-sec-"+name);if(section){section.classList.add("active");section.scrollIntoView({behavior:"smooth",block:"start"});}});</script>';
    echo '</section>';
}

function kpi_weight_to_grams(string $value): float
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return 0.0;
    }
    if (!preg_match('/([\d]+(?:[.,]\d+)?)\s*(kg|g|ml|l|litre|liter|litres|liters)?/', $value, $match)) {
        return 0.0;
    }
    $amount = (float) str_replace(',', '.', $match[1]);
    $unit = $match[2] ?? 'g';
    if (in_array($unit, ['kg'], true)) {
        return $amount * 1000;
    }
    if (in_array($unit, ['l', 'litre', 'liter', 'litres', 'liters'], true)) {
        return $amount * 1000;
    }
    return $amount;
}

function kpi_weight_unit_breakdown(string $value): array
{
    $breakdown = ['kg' => 0.0, 'g' => 0.0, 'ml' => 0.0, 'l' => 0.0];
    $value = strtolower(trim($value));
    if ($value === '') {
        return $breakdown;
    }

    if (!preg_match_all('/([\d]+(?:[.,]\d+)?)\s*(kg|g|ml|l|litre|liter|litres|liters)?/', $value, $matches, PREG_SET_ORDER)) {
        return $breakdown;
    }

    foreach ($matches as $match) {
        $amount = (float) str_replace(',', '.', $match[1]);
        $unit = $match[2] ?? 'g';
        if ($unit === 'kg') {
            $breakdown['kg'] += $amount;
        } elseif (in_array($unit, ['l', 'litre', 'liter', 'litres', 'liters'], true)) {
            $breakdown['l'] += $amount;
        } elseif ($unit === 'ml') {
            $breakdown['ml'] += $amount;
        } else {
            $breakdown['g'] += $amount;
        }
    }

    return $breakdown;
}

function kpi_packer_tag(string $label, string $tone = ''): string
{
    $class = 'hr-tag';
    if ($tone !== '') {
        $class .= ' ' . $tone;
    }

    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function kpi_packer_table(array $columns, array $rows, string $empty = 'No records found for this period.'): string
{
    ob_start();
    ?>
    <div class="hr-table-scroll">
        <table class="hr-data-table">
            <thead><tr><?php foreach ($columns as $column): ?><th><?= htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $value): ?><td><?= is_string($value) && (strpos($value, '<span class="hr-tag') !== false || strpos($value, '<button ') !== false || strpos($value, '<div class="hr-action-inline') !== false) ? $value : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td class="hr-empty-state" colspan="<?= count($columns) ?>"><?= htmlspecialchars($empty, ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

function kpi_packer_status_tone(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['completed', 'packed', 'verified', 'done', 'website', 'label_created', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'], true)) {
        return 'tg';
    }
    if (in_array($status, ['in_progress', 'packing', 'pending', 'assigned', 'new_order', 'done_needs_label', 'packed_label_needed'], true)) {
        return 'tw';
    }
    if (in_array($status, ['cancelled', 'failed', 'correction_required', 'overdue'], true)) {
        return 'tr';
    }
    return '';
}

function kpi_render_packer_live_dashboard(array $employee, array $detail, string $start, string $end): void
{
    $employeeId = (int) ($employee['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        return;
    }

    $orderRows = ops_table_exists('ops_orders') ? ops_rows(
        "SELECT id, order_number, customer_name, customer_contact, order_type, payment_status, status, created_at, assigned_at, packing_started_at, completed_at
         FROM ops_orders
         WHERE assigned_packer_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY created_at DESC
         LIMIT 18",
        [$employeeId, $start, $end]
    ) : [];

    $orderSummary = ops_table_exists('ops_orders') ? (ops_rows(
        "SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'new_order' THEN 1 ELSE 0 END) AS new_orders,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_orders,
            SUM(CASE WHEN status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN order_type = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders,
            SUM(CASE WHEN order_type = 'collection' THEN 1 ELSE 0 END) AS collection_orders,
            SUM(CASE WHEN order_type = 'courier' THEN 1 ELSE 0 END) AS courier_orders,
            SUM(CASE WHEN LOWER(customer_contact) LIKE '%walk-in%' OR LOWER(customer_contact) LIKE '%walk in%' THEN 1 ELSE 0 END) AS walkin_orders,
            AVG(CASE WHEN packing_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, packing_started_at) END) AS avg_start_minutes,
            AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(packing_started_at, assigned_at, created_at), completed_at) END) AS avg_pack_minutes
         FROM ops_orders
         WHERE assigned_packer_id = ? AND created_at >= ? AND created_at < ?",
        [$employeeId, $start, $end]
    )[0] ?? []) : [];

    $courierRows = ops_table_exists('ops_courier_waybills') ? ops_rows(
        "SELECT waybill_reference, customer_name, uploaded_at, sent_at, status,
                CASE WHEN TIME(uploaded_at) > '14:00:00' THEN 1 ELSE 0 END AS late_upload
         FROM ops_courier_waybills
         WHERE uploaded_by = ? AND uploaded_at >= ? AND uploaded_at < ?
         ORDER BY uploaded_at DESC
         LIMIT 8",
        [$employeeId, $start, $end]
    ) : [];
    $lateCourier = 0;
    foreach ($courierRows as $row) {
        $lateCourier += (int) ($row['late_upload'] ?? 0);
    }

    $taskRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT task_name, checklist_type, priority, status, deadline, date_assigned, COALESCE(date_completed, completed_at) AS completed_at, completed_by, recurrence_key, checked_items
         FROM ops_checklist_tasks
         WHERE assigned_employee_id = ? AND COALESCE(date_assigned, created_at, deadline) >= ? AND COALESCE(date_assigned, created_at, deadline) < ?
         ORDER BY COALESCE(deadline, created_at) DESC
         LIMIT 18",
        [$employeeId, $start, $end]
    ) : [];
    $recurringTaskRows = ops_table_exists('ops_checklist_tasks') ? ops_rows(
        "SELECT task_name, checklist_type, status, deadline, COALESCE(date_completed, completed_at) AS completed_at, completed_by, recurrence_key
         FROM ops_checklist_tasks
         WHERE (assigned_employee_id = ? OR completed_by = ?)
           AND recurrence_key IS NOT NULL AND recurrence_key <> ''
           AND COALESCE(date_assigned, created_at, deadline) >= ? AND COALESCE(date_assigned, created_at, deadline) < ?
         ORDER BY COALESCE(deadline, created_at) DESC
         LIMIT 12",
        [$employeeId, $employeeId, $start, $end]
    ) : [];

    $errorRows = ops_table_exists('ops_error_logs') ? ops_rows(
        "SELECT el.logged_at, o.order_number, el.category, el.severity, el.description, el.resolution
         FROM ops_error_logs el
         LEFT JOIN ops_orders o ON o.id = el.order_id
         WHERE el.employee_id = ? AND el.logged_at >= ? AND el.logged_at < ?
         ORDER BY el.logged_at DESC
         LIMIT 8",
        [$employeeId, $start, $end]
    ) : [];
    $errorTypes = [];
    foreach ($errorRows as $row) {
        $category = strtolower(trim((string) ($row['category'] ?? 'other'))) ?: 'other';
        $errorTypes[$category] = ($errorTypes[$category] ?? 0) + 1;
    }

    $packingArchiveSql = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'archived_at')
        ? "AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00')"
        : '';
    $receivedWeightSql = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'received_weight')
        ? 'received_weight'
        : "'' AS received_weight";
    $dateStartedSql = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'date_started')
        ? 'date_started'
        : 'NULL AS date_started';
    $websiteUploadedAtSql = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'website_uploaded_at')
        ? 'website_uploaded_at'
        : 'NULL AS website_uploaded_at';
    $packingWebsiteConfirmedSql = ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
        ? 'packing_website_confirmed'
        : '0 AS packing_website_confirmed';
    $packingRows = ops_table_exists('ops_packing_tasks') ? ops_rows(
        "SELECT item_name, {$receivedWeightSql}, priority, date_loaded, {$dateStartedSql}, quantity_planned, quantity_packed, date_completed, website_uploaded, {$websiteUploadedAtSql}, {$packingWebsiteConfirmedSql}, packing_status, workload_points, notes
         FROM ops_packing_tasks
         WHERE assigned_employee_id = ? AND COALESCE(date_loaded, created_at) >= ? AND COALESCE(date_loaded, created_at) < ?
           {$packingArchiveSql}
         ORDER BY COALESCE(date_loaded, created_at) DESC
         LIMIT 18",
        [$employeeId, $start, $end]
    ) : [];
    $weightGrams = 0.0;
    $weightUnits = ['kg' => 0.0, 'g' => 0.0, 'ml' => 0.0, 'l' => 0.0];
    $packingDone = 0;
    $packingActive = 0;
    $packingNotStarted = 0;
    $websitePending = 0;
    $websiteNeeded = 0;
    $highPriorityStale = 0;
    $startDurations = [];
    $completeDurations = [];
    foreach ($packingRows as $row) {
        $weightValue = (string) ($row['received_weight'] ?? '');
        $weightGrams += kpi_weight_to_grams($weightValue);
        $unitRows = kpi_weight_unit_breakdown($weightValue);
        foreach ($unitRows as $unit => $amount) {
            $weightUnits[$unit] += $amount;
        }
        $status = (string) ($row['packing_status'] ?? '');
        if (in_array($status, ['done', 'website', 'label_created', 'done_needs_label', 'packed_label_needed'], true)) {
            $packingDone++;
        } elseif ($status === 'packing') {
            $packingActive++;
        } else {
            $packingNotStarted++;
        }
        if (empty($row['website_uploaded'])) {
            $websitePending++;
        }
        $planned = trim((string) ($row['quantity_planned'] ?? ''));
        $packed = trim((string) ($row['quantity_packed'] ?? ''));
        if ($planned !== '' && $packed !== '' && strcasecmp(preg_replace('/\s+/', '', $planned), preg_replace('/\s+/', '', $packed)) !== 0) {
            $websiteNeeded++;
        }
        $loadedAt = (string) ($row['date_loaded'] ?? '');
        $startedAt = (string) ($row['date_started'] ?? '');
        $completedAt = (string) ($row['date_completed'] ?? '');
        if ($loadedAt !== '' && $startedAt !== '') {
            $minutes = kpi_hr_minutes_between($loadedAt, $startedAt);
            if ($minutes !== null) {
                $startDurations[] = $minutes;
            }
        }
        if ($loadedAt !== '' && $completedAt !== '') {
            $minutes = kpi_hr_minutes_between($loadedAt, $completedAt);
            if ($minutes !== null) {
                $completeDurations[] = $minutes;
            }
        }
        $priority = strtolower((string) ($row['priority'] ?? ''));
        if (($priority === 'high' || $priority === 'top_critical' || $priority === 'top critical') && in_array($status, ['', 'not_started'], true) && $loadedAt !== '' && strtotime($loadedAt) < strtotime('-2 days')) {
            $highPriorityStale++;
        }
    }
    $weightDisplay = $weightGrams >= 1000 ? number_format($weightGrams / 1000, 1) . ' kg' : number_format($weightGrams, 0) . ' g';

    $loginRows = ops_table_exists('ops_login_events') ? ops_rows(
        "SELECT login_at, role_key
         FROM ops_login_events
         WHERE employee_id = ? AND login_at >= ? AND login_at < ?
         ORDER BY login_at DESC
         LIMIT 8",
        [$employeeId, $start, $end]
    ) : [];
    $loginSummary = ops_table_exists('ops_login_events') ? (ops_rows(
        "SELECT COUNT(DISTINCT DATE(login_at)) AS present_days,
                SUM(CASE WHEN TIME(login_at) > '08:00:00' THEN 1 ELSE 0 END) AS late_logins,
                AVG(TIME_TO_SEC(TIME(login_at))) AS avg_login_seconds
         FROM ops_login_events
         WHERE employee_id = ? AND login_at >= ? AND login_at < ?",
        [$employeeId, $start, $end]
    )[0] ?? []) : [];
    $availabilityRows = ops_table_exists('ops_employee_availability_history') ? ops_rows(
        "SELECT availability_status, changed_at
         FROM ops_employee_availability_history
         WHERE employee_id = ? AND changed_at >= ? AND changed_at < ?
         ORDER BY changed_at DESC
         LIMIT 20",
        [$employeeId, $start, $end]
    ) : [];
    $lunchOutCount = 0;
    $lunchDurations = [];
    $lastLunchOut = null;
    foreach (array_reverse($availabilityRows) as $row) {
        $status = strtolower((string) ($row['availability_status'] ?? ''));
        $changedAt = (string) ($row['changed_at'] ?? '');
        if ($status === 'on_lunch') {
            $lunchOutCount++;
            $lastLunchOut = $changedAt;
        } elseif ($lastLunchOut && in_array($status, ['available', 'online'], true)) {
            $minutes = kpi_hr_minutes_between($lastLunchOut, $changedAt);
            if ($minutes !== null) {
                $lunchDurations[] = $minutes;
            }
            $lastLunchOut = null;
        }
    }
    $avgLunchDuration = $lunchDurations ? array_sum($lunchDurations) / count($lunchDurations) : null;

    $employeeName = (string) ($employee['name'] ?? 'Packer');
    $employeeRole = (string) ($employee['role_name'] ?? 'Packing Operative');
    $averageLogin = isset($loginSummary['avg_login_seconds']) && $loginSummary['avg_login_seconds'] !== null ? gmdate('H:i', (int) $loginSummary['avg_login_seconds']) : '-';
    $score = isset($employee['score']) ? kpi_percent((float) $employee['score']) : '-';
    $periodLabel = date('d M', strtotime($start)) . ' - ' . date('d M Y', strtotime($end . ' -1 second'));
    $componentRows = [];
    foreach (($employee['components'] ?? []) as $label => $component) {
        $componentRows[] = [
            'label' => (string) $label,
            'score' => (float) ($component['score'] ?? 0),
            'raw' => (string) ($component['raw'] ?? ''),
        ];
    }
    $accuracyScore = 100.0;
    foreach ($componentRows as $component) {
        if (stripos($component['label'], 'accuracy') !== false || stripos($component['label'], 'error') !== false) {
            $accuracyScore = (float) $component['score'];
            break;
        }
    }
    $taskTotal = count($taskRows);
    $taskDone = 0;
    $taskProgress = 0;
    $taskOverdue = 0;
    $taskCompletionDurations = [];
    foreach ($taskRows as $row) {
        $status = strtolower((string) ($row['status'] ?? ''));
        if (in_array($status, ['done', 'completed', 'approved'], true)) {
            $taskDone++;
        } elseif (in_array($status, ['in_progress', 'progress', 'review', 'needs_review'], true)) {
            $taskProgress++;
        }
        if (!empty($row['deadline']) && !in_array($status, ['done', 'completed', 'approved'], true) && strtotime((string) $row['deadline']) < time()) {
            $taskOverdue++;
        }
        $assignedAt = (string) ($row['date_assigned'] ?? '');
        $completedAt = (string) ($row['completed_at'] ?? '');
        if ($assignedAt !== '' && $completedAt !== '') {
            $minutes = kpi_hr_minutes_between($assignedAt, $completedAt);
            if ($minutes !== null) {
                $taskCompletionDurations[] = $minutes;
            }
        }
    }
    $recurringClaimed = 0;
    $recurringMissed = 0;
    foreach ($recurringTaskRows as $row) {
        $status = strtolower((string) ($row['status'] ?? ''));
        if (!empty($row['completed_by']) || in_array($status, ['done', 'completed', 'needs_review'], true)) {
            $recurringClaimed++;
        } elseif (!empty($row['deadline']) && strtotime((string) $row['deadline']) < time()) {
            $recurringMissed++;
        }
    }
    $orderTableRows = array_map(static function (array $row): array {
        $status = (string) ($row['status'] ?: '-');
        $statusKey = strtolower($status);
        $doneStatuses = ['completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery'];
        $action = in_array($statusKey, ['in_progress'], true) || in_array($statusKey, $doneStatuses, true)
            ? kpi_packer_tag('Tracked', 'tg')
            : '<button type="button" class="hr-action-btn hr-packer-set-progress" data-order-id="' . (int) ($row['id'] ?? 0) . '" data-order-title="' . htmlspecialchars((string) ($row['order_number'] ?: 'Order'), ENT_QUOTES, 'UTF-8') . '">Set In Progress</button>';
        return [
            (string) ($row['order_number'] ?: '-'),
            (string) ($row['customer_name'] ?: $row['customer_contact'] ?: '-'),
            ucwords((string) ($row['order_type'] ?: '-')),
            kpi_packer_tag(ucwords(str_replace('_', ' ', $status)), kpi_packer_status_tone($status)),
            kpi_front_date_label((string) ($row['created_at'] ?? '')),
            kpi_front_date_label((string) ($row['packing_started_at'] ?? '')),
            kpi_front_date_label((string) ($row['completed_at'] ?? '')),
            $action,
        ];
    }, $orderRows);
    $courierTableRows = array_map(static function (array $row): array {
        return [
            (string) ($row['waybill_reference'] ?: '-'),
            (string) ($row['customer_name'] ?: '-'),
            kpi_front_date_label((string) ($row['uploaded_at'] ?? '')),
            !empty($row['late_upload']) ? kpi_packer_tag('Late', 'tr') : kpi_packer_tag('On time', 'tg'),
            kpi_front_date_label((string) ($row['sent_at'] ?? '')),
            kpi_packer_tag(ucwords((string) ($row['status'] ?: 'Pending')), kpi_packer_status_tone((string) ($row['status'] ?? ''))),
        ];
    }, $courierRows);
    $taskTableRows = array_map(static function (array $row): array {
        $status = (string) ($row['status'] ?: '-');
        $checked = trim((string) ($row['checked_items'] ?? ''));
        return [
            (string) ($row['task_name'] ?: '-'),
            ucwords(str_replace('_', ' ', (string) ($row['checklist_type'] ?: '-'))),
            kpi_packer_tag((string) ($row['priority'] ?: 'Normal'), strtolower((string) ($row['priority'] ?? '')) === 'high' ? 'tr' : 'tw'),
            kpi_front_date_label((string) ($row['deadline'] ?? '')),
            kpi_front_date_label((string) ($row['completed_at'] ?? '')),
            $checked !== '' ? kpi_packer_tag('Checklist ticked', 'tg') : kpi_packer_tag('Checklist open', 'tw'),
            kpi_packer_tag(ucwords(str_replace('_', ' ', $status)), kpi_packer_status_tone($status)),
        ];
    }, $taskRows);
    $errorTableRows = array_map(static function (array $row): array {
        $severity = strtolower((string) ($row['severity'] ?: ''));
        return [
            kpi_front_date_label((string) ($row['logged_at'] ?? '')),
            (string) ($row['order_number'] ?: '-'),
            kpi_packer_tag((string) ($row['category'] ?: '-'), $severity === 'critical' || $severity === 'high' ? 'tr' : 'tw'),
            ucwords((string) ($row['severity'] ?: '-')),
            (string) ($row['resolution'] ?: 'Open'),
        ];
    }, $errorRows);
    $packingTableRows = array_map(static function (array $row): array {
        $status = (string) ($row['packing_status'] ?: '-');
        $planned = trim((string) ($row['quantity_planned'] ?? ''));
        $packed = trim((string) ($row['quantity_packed'] ?? ''));
        $updateNeeded = $planned !== '' && $packed !== '' && strcasecmp(preg_replace('/\s+/', '', $planned), preg_replace('/\s+/', '', $packed)) !== 0;
        return [
            (string) ($row['item_name'] ?: '-'),
            (string) ($row['received_weight'] ?: '-'),
            (string) ($row['quantity_planned'] ?: '-'),
            (string) ($row['quantity_packed'] ?: '-'),
            kpi_front_date_label((string) ($row['date_loaded'] ?? '')),
            kpi_front_date_label((string) ($row['date_started'] ?? '')),
            kpi_front_date_label((string) ($row['date_completed'] ?? '')),
            kpi_packer_tag(ucwords(str_replace('_', ' ', $status)), kpi_packer_status_tone($status)),
            $updateNeeded ? kpi_packer_tag('Website update needed', 'tr') : kpi_packer_tag('Not needed', 'tg'),
            !empty($row['website_uploaded']) ? kpi_packer_tag('Complete', 'tg') : kpi_packer_tag('Pending', 'tw'),
        ];
    }, $packingRows);
    $attendanceTableRows = array_map(static function (array $row): array {
        return [
            kpi_front_date_label((string) ($row['login_at'] ?? '')),
            (string) ($row['role_key'] ?: '-'),
        ];
    }, $loginRows);
    $availabilityTableRows = array_map(static function (array $row): array {
        return [
            kpi_front_date_label((string) ($row['changed_at'] ?? '')),
            ucwords(str_replace('_', ' ', (string) ($row['availability_status'] ?? '-'))),
        ];
    }, $availabilityRows);
    $sectionId = 'packerDash' . $employeeId;
    ?>
    <section class="hr-performance-shell hr-packer-performance hr-packer-exact" id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>">
        <div class="hr-profile-strip">
            <div class="hr-avatar hr-avatar-purple"><?= htmlspecialchars(kpi_hr_initials($employeeName), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="hr-profile-info">
                <div class="hr-profile-name"><?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="hr-profile-role"><?= htmlspecialchars($employeeRole ?: 'Packing Operative', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="hr-profile-meta">
                    <div><strong>Salary</strong> <?= kpi_money((float) ($employee['salary'] ?? 0)) ?>/mo</div>
                    <div><strong>Portal logins</strong> <?= number_format((int) ($loginSummary['present_days'] ?? 0)) ?> days</div>
                    <div><strong>Avg login time</strong> <?= htmlspecialchars($averageLogin, ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Period</strong> <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Status</strong> <?= !empty($employee['on_leave']) ? kpi_packer_tag('On leave', 'tw') : kpi_packer_tag('Active', 'tg') ?></div>
                </div>
            </div>
            <div class="hr-profile-actions">
                <a class="button small" href="reports.php?tab=employees&employee_id=<?= (int) $employeeId ?>">View Profile</a>
                <a class="button small primary" href="my-account.php#section-employees">Employee Link</a>
            </div>
        </div>

        <div class="hr-section-tabs hr-packer-tabs" data-packer-tabs="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>">
            <button class="hr-section-tab active" type="button" data-packer-section="orders">Orders</button>
            <button class="hr-section-tab" type="button" data-packer-section="courier">Courier</button>
            <button class="hr-section-tab" type="button" data-packer-section="tasks">Tasks</button>
            <button class="hr-section-tab" type="button" data-packer-section="errors">Errors</button>
            <button class="hr-section-tab" type="button" data-packer-section="packing">Packing</button>
            <button class="hr-section-tab" type="button" data-packer-section="attendance">Attendance</button>
        </div>

        <div class="hr-modal-backdrop" data-packer-modal>
            <form class="hr-packer-modal" data-packer-progress-form>
                <button type="button" class="hr-modal-close" data-packer-modal-close aria-label="Close">&times;</button>
                <p class="hr-modal-eyebrow">Order Board</p>
                <h3>Set In Progress</h3>
                <p class="hr-modal-copy">Choose who is starting this order. The system will assign the order, move it to In Progress and record the timestamp for KPI tracking.</p>
                <input type="hidden" name="action" value="set_in_progress">
                <input type="hidden" name="order_id" value="">
                <label>Order<input type="text" data-packer-order-title readonly></label>
                <label>Packer
                    <select name="packer_name" required>
                        <option value="Klaudia">Klaudia</option>
                        <option value="Ndinelao">Ndinelao</option>
                        <option value="Secilia">Secilia</option>
                        <option value="Victoria">Victoria</option>
                    </select>
                </label>
                <div class="hr-modal-error" data-packer-progress-error hidden></div>
                <div class="hr-modal-actions"><button type="button" class="button small" data-packer-modal-close>Cancel</button><button type="submit" class="button small primary">Confirm start</button></div>
            </form>
        </div>

        <div class="hr-packer-section active" data-packer-panel="orders">
            <div class="hr-section-heading"><h2>Order Board</h2><p>Orders packed by <?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?>, timing, customer modes and completion movement.</p></div>
            <div class="hr-month-nav"><span><?= htmlspecialchars(date('F Y', strtotime($start)), ENT_QUOTES, 'UTF-8') ?></span><em><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></em></div>
            <div class="hr-stat-grid cols-5">
                <article class="hr-stat-card"><div class="hr-stat-label">Orders Packed</div><div class="hr-stat-value"><?= number_format((int) ($orderSummary['total_orders'] ?? 0)) ?></div><div class="hr-stat-sub">assigned order rows</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Avg New To Progress</div><div class="hr-stat-value"><?= htmlspecialchars(kpi_duration(isset($orderSummary['avg_start_minutes']) ? (float) $orderSummary['avg_start_minutes'] : null), ENT_QUOTES, 'UTF-8') ?></div><div class="hr-stat-sub">assigned to started</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Completed</div><div class="hr-stat-value"><?= number_format((int) ($orderSummary['completed_orders'] ?? 0)) ?></div><div class="hr-stat-sub">completed/packed/verified</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Avg Pack Time</div><div class="hr-stat-value"><?= htmlspecialchars(kpi_duration(isset($orderSummary['avg_pack_minutes']) ? (float) $orderSummary['avg_pack_minutes'] : null), ENT_QUOTES, 'UTF-8') ?></div><div class="hr-stat-sub">started to completed</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Performance Score</div><div class="hr-stat-value"><?= htmlspecialchars($score, ENT_QUOTES, 'UTF-8') ?></div><div class="hr-stat-sub">weighted KPI score</div></article>
            </div>
            <div class="hr-three-col">
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Order Board - Status & Time</h3><span class="hr-card-action">Live data</span></div><?= kpi_packer_table(['Order', 'Customer', 'Mode', 'Status', 'Loaded', 'Started', 'Completed', 'Action'], $orderTableRows) ?></article>
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">KPI Components</h3></div><div class="hr-kpi-list"><?php foreach ($componentRows as $component): ?><div><div class="hr-kpi-row"><span><?= htmlspecialchars($component['label'], ENT_QUOTES, 'UTF-8') ?></span><b><?= kpi_percent($component['score']) ?></b></div><i><em style="width:<?= min(100, max(0, $component['score'])) ?>%"></em></i><small><?= htmlspecialchars($component['raw'], ENT_QUOTES, 'UTF-8') ?></small></div><?php endforeach; ?></div></article>
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Mode Breakdown</h3></div><div class="hr-split-metrics compact"><article><span>Delivery</span><strong><?= number_format((int) ($orderSummary['delivery_orders'] ?? 0)) ?></strong></article><article><span>Collection</span><strong><?= number_format((int) ($orderSummary['collection_orders'] ?? 0)) ?></strong></article><article><span>Courier</span><strong><?= number_format((int) ($orderSummary['courier_orders'] ?? 0)) ?></strong></article><article><span>Walk-in</span><strong><?= number_format((int) ($orderSummary['walkin_orders'] ?? 0)) ?></strong></article></div></article>
            </div>
        </div>

        <div class="hr-packer-section" data-packer-panel="courier">
            <div class="hr-section-heading"><h2>Courier</h2><p>Waybill upload and customer-send timing. Late uploads after the 14:00 cutoff are flagged.</p></div>
            <div class="hr-stat-grid cols-4">
                <article class="hr-stat-card"><div class="hr-stat-label">Waybills Uploaded</div><div class="hr-stat-value"><?= number_format(count($courierRows)) ?></div><div class="hr-stat-sub">in selected period</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Late Uploads</div><div class="hr-stat-value"><?= number_format($lateCourier) ?></div><div class="<?= $lateCourier > 0 ? 'badge bg-danger' : 'badge bg-good' ?>"><?= $lateCourier > 0 ? 'Cutoff issue' : 'On track' ?></div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Sent To Customer</div><div class="hr-stat-value"><?= number_format(count(array_filter($courierRows, static fn(array $row): bool => !empty($row['sent_at'])))) ?></div><div class="hr-stat-sub">sent status captured</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Pending Sends</div><div class="hr-stat-value"><?= number_format(count(array_filter($courierRows, static fn(array $row): bool => empty($row['sent_at'])))) ?></div><div class="hr-stat-sub">needs follow-up</div></article>
            </div>
            <?php if ($lateCourier >= 3): ?><section class="hr-alert-card"><strong>Courier pattern alert.</strong> <?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?> has <?= number_format($lateCourier) ?> courier uploads after the 14:00 cutoff.</section><?php endif; ?>
            <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Courier Uploads</h3><span class="hr-card-action">View all</span></div><?= kpi_packer_table(['Waybill', 'Customer', 'Uploaded', 'Cutoff', 'Sent', 'Status'], $courierTableRows) ?></article>
        </div>

        <div class="hr-packer-section" data-packer-panel="tasks">
            <div class="hr-section-heading"><h2>Task Management</h2><p>Recurring, cleaning and assigned digital task board work.</p></div>
            <div class="hr-stat-grid cols-4">
                <article class="hr-stat-card"><div class="hr-stat-label">Total Tasks</div><div class="hr-stat-value"><?= number_format($taskTotal) ?></div><div class="hr-stat-sub">assigned tasks</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Completed</div><div class="hr-stat-value"><?= number_format($taskDone) ?></div><div class="badge bg-good">Done</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">In Progress</div><div class="hr-stat-value"><?= number_format($taskProgress) ?></div><div class="badge bg-warn">Active</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Overdue</div><div class="hr-stat-value"><?= number_format($taskOverdue) ?></div><div class="badge bg-danger">Past due</div></article>
            </div>
            <div class="task-pills"><span class="task-pill pill-done">Done: <?= number_format($taskDone) ?></span><span class="task-pill pill-progress">In Progress: <?= number_format($taskProgress) ?></span><span class="task-pill pill-pending">Pending: <?= number_format(max(0, $taskTotal - $taskDone - $taskProgress)) ?></span><span class="task-pill pill-overdue">Overdue: <?= number_format($taskOverdue) ?></span><span class="task-pill pill-done">Recurring claimed: <?= number_format($recurringClaimed) ?></span><span class="task-pill pill-overdue">Missed recurring: <?= number_format($recurringMissed) ?></span><span class="task-pill pill-progress">Avg complete: <?= htmlspecialchars(kpi_duration($taskCompletionDurations ? array_sum($taskCompletionDurations) / count($taskCompletionDurations) : null), ENT_QUOTES, 'UTF-8') ?></span></div>
            <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Assigned Tasks</h3><span class="hr-card-action">Digital task board</span></div><?= kpi_packer_table(['Task', 'Type', 'Priority', 'Due', 'Completed', 'Checklist', 'Status'], $taskTableRows) ?></article>
        </div>

        <div class="hr-packer-section" data-packer-panel="errors">
            <div class="hr-section-heading"><h2>Error Log</h2><p>Errors logged against <?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?>, frequency and recurring patterns.</p></div>
            <div class="hr-stat-grid cols-4">
                <article class="hr-stat-card"><div class="hr-stat-label">Total Errors</div><div class="hr-stat-value"><?= number_format(count($errorRows)) ?></div><div class="<?= count($errorRows) > 0 ? 'badge bg-danger' : 'badge bg-good' ?>"><?= count($errorRows) > 0 ? 'Review needed' : 'Clean period' ?></div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Accuracy Score</div><div class="hr-stat-value"><?= kpi_percent($accuracyScore) ?></div><div class="hr-stat-sub">error-weighted</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Recurring Types</div><div class="hr-stat-value"><?= number_format(count(array_filter($errorTypes, static fn(int $count): bool => $count >= 2))) ?></div><div class="hr-stat-sub">same category repeated</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Open Resolutions</div><div class="hr-stat-value"><?= number_format(count(array_filter($errorRows, static fn(array $row): bool => trim((string) ($row['resolution'] ?? '')) === ''))) ?></div><div class="hr-stat-sub">still open</div></article>
            </div>
            <?php foreach ($errorTypes as $category => $count): ?><?php if ($count >= 2): ?><section class="hr-alert-card"><strong>Recurring error pattern.</strong> <?= htmlspecialchars(ucwords($category), ENT_QUOTES, 'UTF-8') ?> appears <?= number_format($count) ?> times in this period.</section><?php break; ?><?php endif; ?><?php endforeach; ?>
            <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Error Log</h3><span class="hr-card-action">Live data</span></div><?= kpi_packer_table(['Date', 'Order', 'Category', 'Severity', 'Resolution'], $errorTableRows) ?></article>
        </div>

        <div class="hr-packer-section" data-packer-panel="packing">
            <div class="hr-section-heading"><h2>Packing Performance</h2><p>Weight-based performance tracking, quantity packed, website update status and packing-list timing.</p></div>
            <div class="hr-stat-grid cols-5">
                <article class="hr-stat-card"><div class="hr-stat-label">Total Weight Packed</div><div class="hr-stat-value"><?= htmlspecialchars($weightDisplay, ENT_QUOTES, 'UTF-8') ?></div><div class="hr-stat-sub">received weight total</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Items Complete</div><div class="hr-stat-value"><?= number_format($packingDone) ?></div><div class="hr-stat-sub">packing rows done</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Items In Progress</div><div class="hr-stat-value"><?= number_format($packingActive) ?></div><div class="badge bg-warn">Ongoing</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Not Yet Started</div><div class="hr-stat-value"><?= number_format($packingNotStarted) ?></div><div class="badge bg-danger">On list</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Website Pending</div><div class="hr-stat-value"><?= number_format($websitePending) ?></div><div class="hr-stat-sub">needs inventory update</div></article>
            </div>
            <?php if ($highPriorityStale > 0): ?><section class="hr-alert-card"><strong>High-priority delay.</strong> <?= number_format($highPriorityStale) ?> high-priority packing item(s) have not started within 2 days.</section><?php endif; ?>
            <div class="unit-grid">
                <div class="unit-card"><div class="unit-icon">kg</div><div class="unit-label">Kilograms</div><div class="unit-val"><?= number_format($weightUnits['kg'], 1) ?> kg</div></div>
                <div class="unit-card"><div class="unit-icon">g</div><div class="unit-label">Grams</div><div class="unit-val"><?= number_format($weightUnits['g'], 0) ?> g</div></div>
                <div class="unit-card"><div class="unit-icon">ml</div><div class="unit-label">Millilitres</div><div class="unit-val"><?= number_format($weightUnits['ml'], 0) ?> ml</div></div>
                <div class="unit-card"><div class="unit-icon">L</div><div class="unit-label">Litres</div><div class="unit-val"><?= number_format($weightUnits['l'], 1) ?> L</div></div>
            </div>
            <div class="hr-two-col">
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Packing List - Status & Time</h3><span class="hr-card-action">View all</span></div><?= kpi_packer_table(['Item', 'Weight', 'Qty plan', 'Qty packed', 'Loaded', 'Started', 'Completed', 'Status', 'Website Need', 'Website Done'], $packingTableRows) ?></article>
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Accuracy & Weight Performance</h3></div><div class="hr-kpi-list"><div><div class="hr-kpi-row"><span>Packing accuracy</span><b><?= kpi_percent($accuracyScore) ?></b></div><i><em style="width:<?= min(100, max(0, $accuracyScore)) ?>%"></em></i><small>Error score and quantity signals</small></div><div><div class="hr-kpi-row"><span>Website updates completed</span><b><?= count($packingRows) ? kpi_percent(((count($packingRows) - $websitePending) / max(1, count($packingRows))) * 100) : '-' ?></b></div><i><em style="width:<?= count($packingRows) ? min(100, max(0, ((count($packingRows) - $websitePending) / max(1, count($packingRows))) * 100)) : 0 ?>%"></em></i><small><?= number_format($websitePending) ?> pending, <?= number_format($websiteNeeded) ?> needed because quantity differs</small></div><div><div class="hr-kpi-row"><span>Avg loaded to start</span><b><?= htmlspecialchars(kpi_duration($startDurations ? array_sum($startDurations) / count($startDurations) : null), ENT_QUOTES, 'UTF-8') ?></b></div><i><em style="width:<?= $startDurations ? 80 : 0 ?>%"></em></i><small>time before packing begins</small></div><div><div class="hr-kpi-row"><span>Avg loaded to done</span><b><?= htmlspecialchars(kpi_duration($completeDurations ? array_sum($completeDurations) / count($completeDurations) : null), ENT_QUOTES, 'UTF-8') ?></b></div><i><em style="width:<?= $completeDurations ? 80 : 0 ?>%"></em></i><small>time from list load to completion</small></div></div></article>
            </div>
        </div>

        <div class="hr-packer-section" data-packer-panel="attendance">
            <div class="hr-section-heading"><h2>Attendance & Punctuality</h2><p>Portal login times and current availability evidence.</p></div>
            <div class="hr-stat-grid cols-4">
                <article class="hr-stat-card"><div class="hr-stat-label">Days Present</div><div class="hr-stat-value"><?= number_format((int) ($loginSummary['present_days'] ?? 0)) ?></div><div class="hr-stat-sub">login days</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Late Arrivals</div><div class="hr-stat-value"><?= number_format((int) ($loginSummary['late_logins'] ?? 0)) ?></div><div class="badge bg-warn">After 08:00</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Avg Login Time</div><div class="hr-stat-value"><?= htmlspecialchars($averageLogin, ENT_QUOTES, 'UTF-8') ?></div><div class="hr-stat-sub">selected period</div></article>
                <article class="hr-stat-card"><div class="hr-stat-label">Lunch Toggles</div><div class="hr-stat-value"><?= number_format($lunchOutCount) ?></div><div class="hr-stat-sub">avg <?= htmlspecialchars(kpi_duration($avgLunchDuration), ENT_QUOTES, 'UTF-8') ?></div></article>
            </div>
            <div class="hr-two-col">
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Login Log</h3><span class="hr-card-action">Full backlog</span></div><?= kpi_packer_table(['Login', 'Role'], $attendanceTableRows) ?></article>
                <article class="hr-perf-card"><div class="hr-card-header"><h3 class="hr-card-title">Lunch & Availability</h3><span class="hr-card-action">Status toggles</span></div><?= kpi_packer_table(['Changed', 'Status'], $availabilityTableRows) ?></article>
            </div>
        </div>
    </section>
    <script>
    (function(){
      var shell=document.getElementById(<?= json_encode($sectionId) ?>);
      if(!shell||shell.dataset.packerBound==="1"){return;}
      shell.dataset.packerBound="1";
      shell.addEventListener("click",function(event){
        var tab=event.target.closest("[data-packer-section]");
        if(tab){
          var name=tab.getAttribute("data-packer-section");
          shell.querySelectorAll("[data-packer-section]").forEach(function(item){item.classList.toggle("active",item===tab);});
          shell.querySelectorAll("[data-packer-panel]").forEach(function(item){item.classList.toggle("active",item.getAttribute("data-packer-panel")===name);});
          return;
        }
        var open=event.target.closest(".hr-packer-set-progress");
        if(open){
          var modal=shell.querySelector("[data-packer-modal]");
          modal.querySelector("[name=order_id]").value=open.getAttribute("data-order-id")||"";
          modal.querySelector("[data-packer-order-title]").value=open.getAttribute("data-order-title")||"Order";
          modal.classList.add("open");
          return;
        }
        if(event.target.closest("[data-packer-modal-close]")){
          shell.querySelector("[data-packer-modal]").classList.remove("open");
        }
      });
      var form=shell.querySelector("[data-packer-progress-form]");
      if(form){
        form.addEventListener("submit",function(event){
          event.preventDefault();
          var error=form.querySelector("[data-packer-progress-error]");
          var submit=form.querySelector("[type=submit]");
          error.hidden=true;
          submit.disabled=true;
          fetch("orders-board-action.php",{method:"POST",body:new FormData(form),credentials:"same-origin"})
            .then(function(response){return response.json();})
            .then(function(data){
              if(!data.ok){throw new Error(data.message||"Could not update order.");}
              window.location.reload();
            })
            .catch(function(err){error.textContent=err.message;error.hidden=false;})
            .finally(function(){submit.disabled=false;});
        });
      }
    })();
    </script>
    <?php
}

function kpi_detail_metric_value(array $details, int $employeeId, string $bucket, string $label, string $default = '-'): string
{
    foreach (($details[$employeeId][$bucket]['metrics'] ?? []) as $metric) {
        if ((string) ($metric['label'] ?? '') === $label) {
            return (string) ($metric['value'] ?? $default);
        }
    }

    return $default;
}

function kpi_module_report_config(string $module): array
{
    $configs = [
        'packing' => [
            'title' => 'Packing List KPI',
            'description' => 'Packing-list productivity, completion timing, website upload follow-up and workload fairness by employee.',
            'columns' => ['Employee', 'Role', 'Rows Assigned', 'Status Split', 'Website Uploaded / Pending', 'Workload Points', 'Received Weight', 'Avg Packing Time', 'Oldest Active Wait'],
            'bucket' => 'packing',
            'metrics' => ['Packing rows assigned', 'Not started / Packing / Done', 'Website uploaded / pending', 'Workload points', 'Recorded received weight', 'Avg packing completion', 'Oldest active wait'],
        ],
        'bookkeeping' => [
            'title' => 'Bookkeeping KPI',
            'description' => 'Cash entry logging, cash-in/out capture and front-office recording delay for physical cash tracking.',
            'columns' => ['Employee', 'Role', 'Entries Logged', 'Cash In / Out', 'Unlinked Cash', 'Avg Recording Delay', 'Scorecard Signals'],
            'bucket' => 'bookkeeping',
            'metrics' => ['Cash entries logged', 'Cash in / out', 'Unlinked cash entries', 'Avg recording delay'],
        ],
        'tasks' => [
            'title' => 'Task Management KPI',
            'description' => 'Checklist, cleaning, recurring task and deadline performance grouped by assigned employee.',
            'columns' => ['Employee', 'Role', 'Total Tasks', 'Done / Pending', 'In Progress', 'Needs Review', 'Overdue', 'Recurring / Cleaning', 'Avg Completion'],
            'bucket' => 'tasks',
            'metrics' => ['Total tasks', 'Done / Pending', 'In progress', 'Needs review', 'Overdue tasks', 'Recurring / cleaning tasks', 'Avg task completion'],
        ],
        'errors' => [
            'title' => 'Error Log KPI',
            'description' => 'Errors assigned, logged, severity, repeat issues, unresolved work and financial impact.',
            'columns' => ['Employee', 'Role', 'Errors Assigned', 'Critical / High', 'Repeat Errors', 'Unresolved', 'Financial Impact', 'Errors Logged By Employee'],
            'bucket' => 'errors',
            'metrics' => ['Errors assigned', 'Critical / High', 'Repeat errors', 'Unresolved', 'Financial impact', 'Errors logged by employee'],
        ],
        'courier' => [
            'title' => 'Courier KPI',
            'description' => 'Courier waybill upload and customer-send timing, including front-office courier communication follow-up.',
            'columns' => ['Employee', 'Role', 'Waybills Uploaded', 'Uploaded Then Sent', 'Avg Sent Time', 'Waybills Sent To Customer', 'Avg Customer-Send Delay'],
            'bucket' => 'courier',
            'metrics' => ['Waybills uploaded', 'Uploaded then sent', 'Avg sent-to-customer time', 'Waybills sent to customer', 'Avg customer-send delay'],
        ],
    ];

    return $configs[$module] ?? $configs['packing'];
}

function kpi_module_report_rows(array $scores, array $details, string $module): array
{
    $config = kpi_module_report_config($module);
    $rows = [];
    foreach ($scores as $score) {
        $employeeId = (int) ($score['employee_id'] ?? 0);
        $row = [
            htmlspecialchars((string) ($score['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($score['role_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
        foreach ($config['metrics'] as $metricLabel) {
            $row[] = htmlspecialchars(kpi_detail_metric_value($details, $employeeId, (string) $config['bucket'], $metricLabel), ENT_QUOTES, 'UTF-8');
        }
        if ($module === 'bookkeeping') {
            $signals = [];
            foreach (($score['components'] ?? []) as $label => $component) {
                if (stripos((string) $label, 'bookkeeping') !== false || stripos((string) $label, 'order') !== false || stripos((string) $label, 'website') !== false) {
                    $signals[] = htmlspecialchars((string) $label . ': ' . kpi_percent((float) ($component['score'] ?? 0)), ENT_QUOTES, 'UTF-8');
                }
            }
            $row[] = $signals ? implode('<br>', $signals) : '-';
        }
        $rows[] = $row;
    }

    return $rows;
}

function kpi_render_module_report(string $module, array $scores, array $details): void
{
    $config = kpi_module_report_config($module);
    $rows = kpi_module_report_rows($scores, $details, $module);
    echo '<section class="panel kpi-module-report-panel">';
    echo '<div class="section-row"><div><h2>' . htmlspecialchars((string) $config['title'], ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars((string) $config['description'], ENT_QUOTES, 'UTF-8') . '</p></div></div>';
    echo '<div class="table-scroll kpi-table-scroll"><table class="data-table ops-table kpi-module-table"><thead><tr>';
    foreach ($config['columns'] as $column) {
        echo '<th>' . htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $index => $value) {
            echo '<td>' . ($index === 0 ? '<strong>' . $value . '</strong>' : $value) . '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="' . count($config['columns']) . '">No KPI data available yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
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

function kpi_store_score_snapshots(string $period, array $scores): void
{
    if (!ops_table_exists('kpi_employee_scores')) {
        return;
    }

    foreach ($scores as $row) {
        try {
            $stmt = db()->prepare(
                "INSERT INTO kpi_employee_scores
                    (period_month, portal_user_id, hr_employee_id, role_group, total_score, score_band, score_payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    hr_employee_id = VALUES(hr_employee_id),
                    role_group = VALUES(role_group),
                    total_score = VALUES(total_score),
                    score_band = VALUES(score_band),
                    score_payload = VALUES(score_payload),
                    calculated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                $period,
                (int) $row['employee_id'],
                $row['hr_employee_id'] ?? null,
                (string) $row['role_group'],
                (float) $row['score'],
                (string) $row['tier']['label'],
                json_encode([
                    'components' => $row['components'],
                    'orders_handled' => $row['orders_handled'],
                    'items_packed' => $row['items_packed'],
                    'error_count' => $row['error_count'],
                    'on_leave' => $row['on_leave'] ?? false,
                ], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // Snapshots are useful for audit trails but should not block the report.
        }
    }
}

if ($ready && $isKpiManager) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isKpiManager) {
    http_response_code(403);
    exit('You do not have permission to change KPI settings.');
}

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
                    if (ops_table_exists('kpi_score_weights')) {
                        $stmt = db()->prepare(
                            "INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE component_label = VALUES(component_label), weight_percent = VALUES(weight_percent), active = 1"
                        );
                        $stmt->execute([$group, $key, $row['label'], $value]);
                    }
                }
            }
            $message = 'Role-based KPI weights saved.';
        } elseif ($action === 'save_picker_slots') {
            kpi_save_setting('kpi_picker_1_employee_id', (string) max(0, (int) ($_POST['picker_1_employee_id'] ?? 0)));
            kpi_save_setting('kpi_picker_2_employee_id', (string) max(0, (int) ($_POST['picker_2_employee_id'] ?? 0)));
            $message = 'Packer performance slots saved.';
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
if ($ready && $isKpiManager) {
    kpi_store_score_snapshots($period, $employeeScores);
}
$previousScores = $ready ? kpi_build_scores($previousPeriod, $previousStart, $previousEnd, $settings) : [];
if (!$isKpiManager) {
    $employeeScores = array_values(array_filter($employeeScores, static function (array $row) use ($currentKpiEmployeeId): bool {
        return (int) ($row['employee_id'] ?? 0) === $currentKpiEmployeeId;
    }));
    $previousScores = array_values(array_filter($previousScores, static function (array $row) use ($currentKpiEmployeeId): bool {
        return (int) ($row['employee_id'] ?? 0) === $currentKpiEmployeeId;
    }));
}
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
$totalOrdersInRange = $ready && ops_table_exists('ops_orders') ? ops_count('ops_orders', "created_at >= '" . str_replace("'", "''", $periodStart) . "' AND created_at < '" . str_replace("'", "''", $periodEnd) . "' AND status NOT IN ('cancelled', 'canceled', 'refunded', 'failed')") : 0;
$completedOrdersInRange = $ready && ops_table_exists('ops_orders') ? ops_count('ops_orders', "completed_at >= '" . str_replace("'", "''", $periodStart) . "' AND completed_at < '" . str_replace("'", "''", $periodEnd) . "' AND status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery')") : 0;
$overallBusinessHealth = $employeeScores ? kpi_score(($averageScore * 0.7) + (max(0, 100 - ((int) ($systemMetricRow['overdue_orders'] ?? 0) * 6) - ((int) ($systemMetricRow['correction_orders'] ?? 0) * 5)) * 0.3)) : 0;
$trendMonths = [];
$trendValues = [];
$trendEnd = new DateTimeImmutable($filterEndDate . ' 00:00:00');
for ($i = 7; $i >= 0; $i--) {
    $monthDate = $trendEnd->modify('first day of this month')->modify("-{$i} months");
    $monthKey = $monthDate->format('Y-m');
    $trendMonths[] = $monthDate->format('M');
    $trendValues[$monthKey] = 0.0;
}
if ($ready && ops_table_exists('kpi_employee_scores')) {
    $snapshotRows = ops_rows(
        "SELECT period_month, AVG(total_score) AS avg_score
         FROM kpi_employee_scores
         WHERE period_month IN (" . implode(',', array_fill(0, count($trendValues), '?')) . ")
         GROUP BY period_month",
        array_keys($trendValues)
    );
    foreach ($snapshotRows as $row) {
        $trendValues[(string) $row['period_month']] = (float) ($row['avg_score'] ?? 0);
    }
}
if (isset($trendValues[$period])) {
    $trendValues[$period] = max((float) $trendValues[$period], (float) $averageScore);
}
$trendSeries = array_values($trendValues);
$trendNonZero = array_values(array_filter($trendSeries, static fn (float $value): bool => $value > 0));
if (!$trendNonZero && $averageScore > 0) {
    $trendSeries = array_fill(0, 8, (float) $averageScore);
}
$trendPoints = [];
$chartWidth = 360;
$chartHeight = 140;
$chartTop = 14;
$chartBottom = 122;
$maxTrend = max(100.0, max($trendSeries ?: [100]));
$minTrend = 0.0;
foreach ($trendSeries as $index => $value) {
    $x = count($trendSeries) > 1 ? ($index * ($chartWidth / (count($trendSeries) - 1))) : 0;
    $ratio = ($value - $minTrend) / max(1.0, ($maxTrend - $minTrend));
    $y = $chartBottom - ($ratio * ($chartBottom - $chartTop));
    $trendPoints[] = round($x, 1) . ',' . round($y, 1);
}
$trendAreaPoints = $trendPoints ? '0,' . $chartBottom . ' ' . implode(' ', $trendPoints) . ' ' . $chartWidth . ',' . $chartBottom : '';
$trendChange = null;
if (count($trendNonZero) >= 2) {
    $trendChange = end($trendNonZero) - $trendNonZero[0];
}
$orderCompletionRate = $totalOrdersInRange > 0
    ? kpi_score(($completedOrdersInRange / max(1, $totalOrdersInRange)) * 100)
    : 0.0;
$averageWorkPerEmployee = $employeeScores ? ($completedOrdersInRange / max(1, count($employeeScores))) : 0.0;
$taskEfficiency = kpi_speed_score(isset($systemMetricRow['avg_completion_minutes']) ? (float) $systemMetricRow['avg_completion_minutes'] : null, (float) $settings['target_order_total_minutes']);
$productivityDayMinutes = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0];
if ($ready && ops_table_exists('ops_orders')) {
    $productivityOrders = ops_rows(
        "SELECT created_at, packing_started_at, completed_at
         FROM ops_orders
         WHERE completed_at IS NOT NULL
           AND completed_at >= ? AND completed_at < ?
           AND status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery')",
        [$periodStart, $periodEnd]
    );
    foreach ($productivityOrders as $row) {
        $completedAt = (string) ($row['completed_at'] ?? '');
        $day = $completedAt ? (int) (new DateTimeImmutable($completedAt))->format('N') : 0;
        if ($day < 1 || $day > 7) continue;
        $minutes = kpi_business_minutes((string) (($row['packing_started_at'] ?? '') ?: ($row['created_at'] ?? '')), $completedAt) ?? 0.0;
        $productivityDayMinutes[$day] += $minutes;
    }
}
if ($ready && ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'date_loaded') && ops_column_exists('ops_packing_tasks', 'date_completed')) {
    $productivityPackingRows = ops_rows(
        "SELECT assigned_employee_id, date_loaded, date_completed
         FROM ops_packing_tasks
         WHERE date_completed IS NOT NULL
           AND date_completed >= ? AND date_completed < ?",
        [$periodStart, $periodEnd]
    );
    foreach ($productivityPackingRows as $row) {
        $completedAt = (string) ($row['date_completed'] ?? '');
        $day = $completedAt ? (int) (new DateTimeImmutable($completedAt))->format('N') : 0;
        if ($day < 1 || $day > 7) continue;
        $productivityDayMinutes[$day] += kpi_business_minutes((string) ($row['date_loaded'] ?? ''), $completedAt) ?? 0.0;
    }
}
$productivityHours = array_map(static fn (float $minutes): float => round($minutes / 60, 1), $productivityDayMinutes);
$totalProductivityHours = array_sum($productivityHours);
$maxProductivityHours = max(1.0, max($productivityHours));
$employeeHourRows = $ready && ops_table_exists('ops_orders') ? ops_rows(
    "SELECT COALESCE(assigned_packer_id, created_by) AS employee_id,
            SUM(TIMESTAMPDIFF(MINUTE, COALESCE(packing_started_at, assigned_at, created_at), completed_at)) AS real_minutes
     FROM ops_orders
     WHERE completed_at IS NOT NULL
       AND completed_at >= ? AND completed_at < ?
       AND COALESCE(assigned_packer_id, created_by) IS NOT NULL
     GROUP BY COALESCE(assigned_packer_id, created_by)",
    [$periodStart, $periodEnd]
) : [];
$employeeHours = [];
foreach ($employeeHourRows as $row) {
    $employeeHours[(int) ($row['employee_id'] ?? 0)] = round(((float) ($row['real_minutes'] ?? 0)) / 60, 1);
}
if ($ready && ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'date_loaded') && ops_column_exists('ops_packing_tasks', 'date_completed')) {
    $packingHourRows = ops_rows(
        "SELECT assigned_employee_id, date_loaded, date_completed
         FROM ops_packing_tasks
         WHERE assigned_employee_id IS NOT NULL
           AND date_completed IS NOT NULL
           AND date_completed >= ? AND date_completed < ?",
        [$periodStart, $periodEnd]
    );
    foreach ($packingHourRows as $row) {
        $id = (int) ($row['assigned_employee_id'] ?? 0);
        if ($id <= 0) continue;
        $minutes = kpi_business_minutes((string) ($row['date_loaded'] ?? ''), (string) ($row['date_completed'] ?? '')) ?? 0.0;
        $employeeHours[$id] = round(($employeeHours[$id] ?? 0.0) + ($minutes / 60), 1);
    }
}
$employeeImpactRows = $employeeScores;
$maxEmployeeWork = max(1.0, max(array_map(static fn (array $row): float => (float) ($row['orders_handled'] ?? 0) + (float) ($row['items_packed'] ?? 0), $employeeImpactRows ?: [['orders_handled' => 0, 'items_packed' => 0]])));
usort($employeeImpactRows, static function (array $a, array $b) use ($maxEmployeeWork): int {
    $aWork = (float) ($a['orders_handled'] ?? 0) + (float) ($a['items_packed'] ?? 0);
    $bWork = (float) ($b['orders_handled'] ?? 0) + (float) ($b['items_packed'] ?? 0);
    $aImpact = ((float) ($a['score'] ?? 0) * 0.55) + (($aWork / $maxEmployeeWork) * 45) - ((int) ($a['error_count'] ?? 0) * 2);
    $bImpact = ((float) ($b['score'] ?? 0) * 0.55) + (($bWork / $maxEmployeeWork) * 45) - ((int) ($b['error_count'] ?? 0) * 2);
    return $bImpact <=> $aImpact;
});
$topEmployeeRows = array_slice($employeeImpactRows, 0, 4);
$hasErrorRepeatIssue = $ready && ops_table_exists('ops_error_logs') && ops_column_exists('ops_error_logs', 'repeat_issue');
$hasErrorStatus = $ready && ops_table_exists('ops_error_logs') && ops_column_exists('ops_error_logs', 'status');
$errorSummaryRow = $ready && ops_table_exists('ops_error_logs') ? (ops_rows(
    "SELECT
        COUNT(*) AS total_errors,
        SUM(CASE WHEN " . ($hasErrorRepeatIssue ? 'repeat_issue = 1' : '0') . " THEN 1 ELSE 0 END) AS repeat_errors,
        SUM(CASE WHEN " . ($hasErrorStatus ? "status <> 'resolved'" : '0') . " THEN 1 ELSE 0 END) AS open_errors
     FROM ops_error_logs
     WHERE logged_at >= ? AND logged_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$activeQueueCount = (int) ($systemMetricRow['active_queue'] ?? 0);
$ordersMatrix = [
    'Average time to assign' => kpi_duration(isset($systemMetricRow['avg_assignment_minutes']) ? (float) $systemMetricRow['avg_assignment_minutes'] : null),
    'Average time to start packing' => kpi_duration(isset($systemMetricRow['avg_start_minutes']) ? (float) $systemMetricRow['avg_start_minutes'] : null),
    'Average packing duration' => kpi_duration(isset($systemMetricRow['avg_packing_minutes']) ? (float) $systemMetricRow['avg_packing_minutes'] : null),
    'Average time to complete order' => kpi_duration(isset($systemMetricRow['avg_completion_minutes']) ? (float) $systemMetricRow['avg_completion_minutes'] : null),
    'Overdue orders' => number_format((int) ($systemMetricRow['overdue_orders'] ?? 0)),
    'Orders with errors' => number_format((int) ($errorSummaryRow['total_errors'] ?? 0)),
    'Recurring errors' => number_format((int) ($errorSummaryRow['repeat_errors'] ?? 0)),
    'Active queue' => number_format($activeQueueCount),
];
$canReadPackingTotals = $ready
    && ops_table_exists('ops_order_items')
    && ops_table_exists('ops_orders')
    && ops_column_exists('ops_order_items', 'packed_quantity')
    && ops_column_exists('ops_order_items', 'quantity')
    && ops_column_exists('ops_order_items', 'packed_by');
$packingTotalsRows = $canReadPackingTotals ? ops_rows(
    "SELECT COALESCE(oi.packed_by, o.assigned_packer_id) AS employee_id,
            e.full_name,
            SUM(CASE WHEN oi.packed_quantity > 0 THEN oi.packed_quantity ELSE oi.quantity END) AS total_items_packed,
            COUNT(DISTINCT o.id) AS orders_packed,
            COUNT(*) AS packed_rows
     FROM ops_order_items oi
     JOIN ops_orders o ON o.id = oi.order_id
     LEFT JOIN ops_employees e ON e.id = COALESCE(oi.packed_by, o.assigned_packer_id)
     WHERE COALESCE(oi.packed_by, o.assigned_packer_id) IS NOT NULL
       AND o.created_at >= ? AND o.created_at < ?
     GROUP BY COALESCE(oi.packed_by, o.assigned_packer_id), e.full_name
     ORDER BY total_items_packed DESC",
    [$periodStart, $periodEnd]
) : [];
$mostItemsPacked = $packingTotalsRows[0] ?? null;
$secondMostItemsPacked = $packingTotalsRows[1] ?? null;
$totalPackedItems = array_sum(array_map(static fn (array $row): float => (float) ($row['total_items_packed'] ?? 0), $packingTotalsRows));
$packingMatrix = [
    'Average picked/order start time' => kpi_duration($businessSummary['avg_start'] ?? null),
    'Average time under New Order' => kpi_duration($businessSummary['avg_assignment'] ?? null),
    'Average time before In Progress' => kpi_duration($businessSummary['avg_start'] ?? null),
    'Average order completion time' => kpi_duration($businessSummary['avg_completion'] ?? null),
    'Packing notes created' => $ready && ops_table_exists('ops_orders') ? number_format(ops_count('ops_orders', "notes IS NOT NULL AND notes <> '' AND created_at >= '" . str_replace("'", "''", $periodStart) . "' AND created_at < '" . str_replace("'", "''", $periodEnd) . "'")) : '0',
    'Most items packed' => $mostItemsPacked ? (string) ($mostItemsPacked['full_name'] ?: 'Unassigned') . ' (' . number_format((float) $mostItemsPacked['total_items_packed'], 1) . ' items)' : '-',
    'Second most items packed' => $secondMostItemsPacked ? (string) ($secondMostItemsPacked['full_name'] ?: 'Unassigned') . ' (' . number_format((float) $secondMostItemsPacked['total_items_packed'], 1) . ' items)' : '-',
    'Total packed items' => number_format($totalPackedItems, 1),
];
$hasCashBook = $ready
    && ops_table_exists('ops_cash_book_entries')
    && ops_column_exists('ops_cash_book_entries', 'related_order_id')
    && ops_column_exists('ops_cash_book_entries', 'cash_in')
    && ops_column_exists('ops_cash_book_entries', 'created_at');
$cashBookArchiveWhere = ($hasCashBook && ops_column_exists('ops_cash_book_entries', 'archived_at')) ? 'AND c.archived_at IS NULL' : '';
$cashOrderAmountExpr = ($ready && ops_table_exists('ops_orders') && ops_column_exists('ops_orders', 'total_amount')) ? 'COALESCE(o.total_amount, 0)' : '0';
$cashOrderBaseWhere = "LOWER(COALESCE(o.payment_method, '')) LIKE '%cash%' AND o.status NOT IN ('cancelled', 'canceled', 'refunded', 'failed')";
$cashBookJoin = $hasCashBook
    ? "LEFT JOIN ops_cash_book_entries c ON c.related_order_id = o.id AND c.cash_in > 0 {$cashBookArchiveWhere}"
    : "LEFT JOIN (SELECT NULL AS related_order_id, NULL AS created_at) c ON 1 = 0";
$bookkeepingRow = $ready && ops_table_exists('ops_orders') ? (ops_rows(
    "SELECT
        COUNT(*) AS cash_orders,
        SUM(CASE WHEN o.status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') THEN 1 ELSE 0 END) AS completed_cash_orders,
        SUM(CASE WHEN c.related_order_id IS NOT NULL THEN 1 ELSE 0 END) AS logged_cash_orders,
        SUM(CASE WHEN o.status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') AND c.related_order_id IS NULL THEN 1 ELSE 0 END) AS completed_unlogged_cash_orders,
        AVG(CASE WHEN c.related_order_id IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, o.created_at, c.created_at) END) AS avg_cash_log_minutes,
        MAX(CASE WHEN c.related_order_id IS NULL THEN TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) END) AS oldest_unlogged_minutes,
        COALESCE(SUM(CASE WHEN c.related_order_id IS NULL THEN {$cashOrderAmountExpr} ELSE 0 END), 0) AS unlogged_cash_value
     FROM ops_orders o
     {$cashBookJoin}
     WHERE {$cashOrderBaseWhere}
       AND o.created_at >= ? AND o.created_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$bookkeepingMatrix = [
    'Cash orders in order list' => number_format((int) ($bookkeepingRow['cash_orders'] ?? 0)),
    'Completed cash orders' => number_format((int) ($bookkeepingRow['completed_cash_orders'] ?? 0)),
    'Cash orders logged' => number_format((int) ($bookkeepingRow['logged_cash_orders'] ?? 0)),
    'Completed cash orders not logged' => number_format((int) ($bookkeepingRow['completed_unlogged_cash_orders'] ?? 0)),
    'Average time to log cash order' => kpi_duration(isset($bookkeepingRow['avg_cash_log_minutes']) ? (float) $bookkeepingRow['avg_cash_log_minutes'] : null),
    'Oldest unlogged cash order wait' => kpi_duration(isset($bookkeepingRow['oldest_unlogged_minutes']) ? (float) $bookkeepingRow['oldest_unlogged_minutes'] : null),
    'Unlogged cash value' => kpi_money((float) ($bookkeepingRow['unlogged_cash_value'] ?? 0)),
    'Bookkeeping source' => $hasCashBook ? 'Cash book linked to orders' : 'Cash book not active',
];
$hasPackingWebsiteFields = $ready
    && ops_table_exists('ops_packing_tasks')
    && ops_column_exists('ops_packing_tasks', 'website_uploaded')
    && ops_column_exists('ops_packing_tasks', 'date_loaded');
$websiteUploadTimeColumn = $hasPackingWebsiteFields && ops_column_exists('ops_packing_tasks', 'website_uploaded_at')
    ? 'website_uploaded_at'
    : (($hasPackingWebsiteFields && ops_column_exists('ops_packing_tasks', 'updated_at')) ? 'updated_at' : 'NULL');
$packingArchiveWhere = ($ready && ops_table_exists('ops_packing_tasks') && ops_column_exists('ops_packing_tasks', 'archived_at'))
    ? "AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00')"
    : '';
$websiteUploadRow = $hasPackingWebsiteFields ? (ops_rows(
    "SELECT
        COUNT(*) AS packing_rows,
        SUM(CASE WHEN website_uploaded = 1 THEN 1 ELSE 0 END) AS website_uploaded_rows,
        SUM(CASE WHEN website_uploaded = 0 THEN 1 ELSE 0 END) AS website_pending_rows,
        AVG(CASE WHEN website_uploaded = 1 AND {$websiteUploadTimeColumn} IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, date_loaded, {$websiteUploadTimeColumn}) END) AS avg_website_minutes,
        MAX(CASE WHEN website_uploaded = 0 THEN TIMESTAMPDIFF(MINUTE, date_loaded, NOW()) END) AS oldest_pending_minutes
     FROM ops_packing_tasks
     WHERE date_loaded >= ? AND date_loaded < ?
       {$packingArchiveWhere}",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$orderListRow = $ready && ops_table_exists('ops_orders') ? (ops_rows(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status NOT IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed') THEN 1 ELSE 0 END) AS incomplete_orders,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
        SUM(CASE WHEN payment_status <> 'paid' OR payment_status IS NULL THEN 1 ELSE 0 END) AS unpaid_orders,
        AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_order_complete_minutes
     FROM ops_orders
     WHERE created_at >= ? AND created_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$frontDeskMatrix = [
    'Website quantities uploaded' => number_format((int) ($websiteUploadRow['website_uploaded_rows'] ?? 0)),
    'Website quantities still pending' => number_format((int) ($websiteUploadRow['website_pending_rows'] ?? 0)),
    'Average website upload time' => kpi_duration(isset($websiteUploadRow['avg_website_minutes']) ? (float) $websiteUploadRow['avg_website_minutes'] : null),
    'Oldest pending website update' => kpi_duration(isset($websiteUploadRow['oldest_pending_minutes']) ? (float) $websiteUploadRow['oldest_pending_minutes'] : null),
    'Orders completed' => number_format((int) ($orderListRow['completed_orders'] ?? 0)),
    'Orders still incomplete' => number_format((int) ($orderListRow['incomplete_orders'] ?? 0)),
    'Average order completion time' => kpi_duration(isset($orderListRow['avg_order_complete_minutes']) ? (float) $orderListRow['avg_order_complete_minutes'] : null),
    'Orders marked paid' => number_format((int) ($orderListRow['paid_orders'] ?? 0)),
];
$loginRow = $ready && ops_table_exists('ops_login_events') ? (ops_rows(
    "SELECT
        COUNT(*) AS login_count,
        AVG(TIME_TO_SEC(TIME(login_at))) AS avg_login_seconds,
        MIN(TIME(login_at)) AS earliest_login_time,
        MAX(login_at) AS last_login_at
     FROM ops_login_events
     WHERE login_at >= ? AND login_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$availabilityRows = $ready && ops_table_exists('ops_employee_availability') ? ops_rows(
    "SELECT e.full_name, ea.availability_status, ea.unavailable_until, ea.updated_at
     FROM ops_employee_availability ea
     JOIN ops_employees e ON e.id = ea.employee_id
     WHERE ea.availability_status IN ('on_lunch', 'offline')
     ORDER BY FIELD(ea.availability_status, 'on_lunch', 'offline'), e.full_name"
) : [];
$currentLunchNames = [];
foreach ($availabilityRows as $row) {
    if ((string) ($row['availability_status'] ?? '') === 'on_lunch') {
        $until = !empty($row['unavailable_until']) ? ' until ' . date('H:i', strtotime((string) $row['unavailable_until'])) : '';
        $currentLunchNames[] = (string) $row['full_name'] . $until;
    }
}
$lunchRow = $ready && ops_table_exists('ops_employee_availability_history') ? (ops_rows(
    "SELECT
        COUNT(*) AS lunch_events,
        AVG(CASE WHEN unavailable_until IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, changed_at, unavailable_until) END) AS avg_lunch_minutes
     FROM ops_employee_availability_history
     WHERE availability_status = 'on_lunch'
       AND changed_at >= ? AND changed_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$averageLoginTime = isset($loginRow['avg_login_seconds']) && $loginRow['avg_login_seconds'] !== null
    ? gmdate('H:i', (int) $loginRow['avg_login_seconds'])
    : '-';
$availabilityMatrix = [
    'Portal logins recorded' => number_format((int) ($loginRow['login_count'] ?? 0)),
    'Average login time' => $averageLoginTime,
    'Earliest login time' => !empty($loginRow['earliest_login_time']) ? substr((string) $loginRow['earliest_login_time'], 0, 5) : '-',
    'Last login recorded' => !empty($loginRow['last_login_at']) ? date('Y-m-d H:i', strtotime((string) $loginRow['last_login_at'])) : '-',
    'Currently on lunch' => $currentLunchNames ? implode(', ', $currentLunchNames) : 'No one',
    'Lunch events recorded' => number_format((int) ($lunchRow['lunch_events'] ?? 0)),
    'Average planned lunch time' => kpi_duration(isset($lunchRow['avg_lunch_minutes']) ? (float) $lunchRow['avg_lunch_minutes'] : null),
    'Unavailable employees now' => number_format(count($availabilityRows)),
];
$taskDashboardRow = $ready && ops_table_exists('ops_checklist_tasks') ? (ops_rows(
    "SELECT
        COUNT(*) AS total_tasks,
        SUM(CASE WHEN status IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS completed_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
        SUM(CASE WHEN status IN ('not_started', 'todo') THEN 1 ELSE 0 END) AS not_started_tasks,
        SUM(CASE WHEN status = 'needs_review' THEN 1 ELSE 0 END) AS needs_review_tasks,
        SUM(CASE WHEN status NOT IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS active_tasks,
        AVG(CASE WHEN COALESCE(date_completed, completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(date_assigned, created_at), COALESCE(date_completed, completed_at)) END) AS avg_complete_minutes,
        AVG(CASE WHEN status = 'in_progress' THEN TIMESTAMPDIFF(MINUTE, COALESCE(date_assigned, created_at), NOW()) END) AS avg_in_progress_minutes,
        AVG(CASE WHEN status = 'needs_review' THEN TIMESTAMPDIFF(MINUTE, COALESCE(date_assigned, created_at), COALESCE(date_completed, completed_at, NOW())) END) AS avg_review_minutes
     FROM ops_checklist_tasks
     WHERE created_at >= ? AND created_at < ?",
    [$periodStart, $periodEnd]
)[0] ?? []) : [];
$taskDailyRows = $ready && ops_table_exists('ops_checklist_tasks') ? ops_rows(
    "SELECT DATE(COALESCE(date_completed, completed_at)) AS task_day, COUNT(*) AS total
     FROM ops_checklist_tasks
     WHERE COALESCE(date_completed, completed_at) IS NOT NULL
       AND COALESCE(date_completed, completed_at) >= ? AND COALESCE(date_completed, completed_at) < ?
       AND status IN ('done', 'completed', 'approved')
     GROUP BY DATE(COALESCE(date_completed, completed_at))
     ORDER BY task_day ASC",
    [$periodStart, $periodEnd]
) : [];
$taskCompletedByDay = [];
foreach ($taskDailyRows as $row) {
    $taskCompletedByDay[(string) ($row['task_day'] ?? '')] = (int) ($row['total'] ?? 0);
}
$taskEmployeeRows = $ready && ops_table_exists('ops_checklist_tasks') ? ops_rows(
    "SELECT COALESCE(t.assigned_employee_id, t.completed_by) AS employee_id,
            e.full_name,
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN t.status IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status IN ('not_started', 'todo') THEN 1 ELSE 0 END) AS not_started_tasks,
            SUM(CASE WHEN t.status = 'needs_review' THEN 1 ELSE 0 END) AS needs_review_tasks,
            SUM(CASE WHEN t.status NOT IN ('done', 'completed', 'approved') THEN 1 ELSE 0 END) AS active_tasks,
            AVG(CASE WHEN COALESCE(t.date_completed, t.completed_at) IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, COALESCE(t.date_assigned, t.created_at), COALESCE(t.date_completed, t.completed_at)) END) AS avg_complete_minutes
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = COALESCE(t.assigned_employee_id, t.completed_by)
     WHERE COALESCE(t.assigned_employee_id, t.completed_by) IS NOT NULL
       AND t.created_at >= ? AND t.created_at < ?
     GROUP BY COALESCE(t.assigned_employee_id, t.completed_by), e.full_name
     ORDER BY completed_tasks DESC, active_tasks DESC, e.full_name",
    [$periodStart, $periodEnd]
) : [];
$taskMatrix = [
    'Total active tasks' => number_format((int) ($taskDashboardRow['active_tasks'] ?? 0)),
    'Completed tasks' => number_format((int) ($taskDashboardRow['completed_tasks'] ?? 0)),
    'In progress' => number_format((int) ($taskDashboardRow['in_progress_tasks'] ?? 0)),
    'Need review' => number_format((int) ($taskDashboardRow['needs_review_tasks'] ?? 0)),
    'Not yet started' => number_format((int) ($taskDashboardRow['not_started_tasks'] ?? 0)),
    'Average completion time' => kpi_duration(isset($taskDashboardRow['avg_complete_minutes']) ? (float) $taskDashboardRow['avg_complete_minutes'] : null),
    'Average in-progress age' => kpi_duration(isset($taskDashboardRow['avg_in_progress_minutes']) ? (float) $taskDashboardRow['avg_in_progress_minutes'] : null),
    'Average review wait' => kpi_duration(isset($taskDashboardRow['avg_review_minutes']) ? (float) $taskDashboardRow['avg_review_minutes'] : null),
];
$employeeSummarySignals = [];
foreach ($employeeScores as $row) {
    $employeeSummarySignals[(int) $row['employee_id']] = [
        'completed' => (int) ($row['orders_handled'] ?? 0),
        'active' => 0,
        'pending_admin' => 0,
        'errors' => (int) ($row['error_count'] ?? 0),
    ];
}
if ($ready && ops_table_exists('ops_orders')) {
    $packerWorkRows = ops_rows(
        "SELECT assigned_packer_id AS employee_id,
                SUM(CASE WHEN status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN status NOT IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed') THEN 1 ELSE 0 END) AS active_orders
         FROM ops_orders
         WHERE assigned_packer_id IS NOT NULL
           AND created_at >= ? AND created_at < ?
         GROUP BY assigned_packer_id",
        [$periodStart, $periodEnd]
    );
    foreach ($packerWorkRows as $row) {
        $id = (int) ($row['employee_id'] ?? 0);
        if (!isset($employeeSummarySignals[$id])) continue;
        $employeeSummarySignals[$id]['completed'] = max($employeeSummarySignals[$id]['completed'], (int) ($row['completed_orders'] ?? 0));
        $employeeSummarySignals[$id]['active'] += (int) ($row['active_orders'] ?? 0);
    }

    $frontWorkRows = ops_rows(
        "SELECT created_by AS employee_id,
                SUM(CASE WHEN status IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery') THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN status NOT IN ('completed', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'cancelled', 'canceled', 'refunded', 'failed') THEN 1 ELSE 0 END) AS active_orders
         FROM ops_orders
         WHERE created_by IS NOT NULL
           AND created_at >= ? AND created_at < ?
         GROUP BY created_by",
        [$periodStart, $periodEnd]
    );
    foreach ($frontWorkRows as $row) {
        $id = (int) ($row['employee_id'] ?? 0);
        if (!isset($employeeSummarySignals[$id])) continue;
        $employeeSummarySignals[$id]['completed'] = max($employeeSummarySignals[$id]['completed'], (int) ($row['completed_orders'] ?? 0));
        $employeeSummarySignals[$id]['active'] += (int) ($row['active_orders'] ?? 0);
    }
}
$frontDeskPendingAdmin = (int) ($bookkeepingRow['completed_unlogged_cash_orders'] ?? 0) + (int) ($websiteUploadRow['website_pending_rows'] ?? 0) + (int) ($orderListRow['unpaid_orders'] ?? 0);
foreach ($employeeScores as $row) {
    $id = (int) $row['employee_id'];
    if (($row['role_group'] ?? '') === 'front_desk' && isset($employeeSummarySignals[$id])) {
        $employeeSummarySignals[$id]['pending_admin'] += $frontDeskPendingAdmin;
    }
}
$employeeOwnerRows = [];
foreach ($employeeScores as $row) {
    $id = (int) $row['employee_id'];
    $signals = $employeeSummarySignals[$id] ?? ['completed' => 0, 'active' => 0, 'pending_admin' => 0, 'errors' => 0];
    $completed = max(0, (int) $signals['completed']);
    $active = max(0, (int) $signals['active']);
    $pendingAdmin = max(0, (int) $signals['pending_admin']);
    $errors = max(0, (int) $signals['errors']);
    $totalSignals = max(1, $completed + $active + $pendingAdmin + $errors);
    $employeeOwnerRows[] = [
        'name' => (string) $row['name'],
        'role' => (string) $row['role_name'],
        'score' => (float) $row['score'],
        'on_leave' => !empty($row['on_leave']),
        'completed' => $completed,
        'active' => $active,
        'pending_admin' => $pendingAdmin,
        'errors' => $errors,
        'completed_pct' => ($completed / $totalSignals) * 100,
        'active_pct' => ($active / $totalSignals) * 100,
        'pending_pct' => ($pendingAdmin / $totalSignals) * 100,
        'error_pct' => ($errors / $totalSignals) * 100,
    ];
}
$errorCategoryRows = ($ready && ops_table_exists('ops_error_logs')) ? ops_rows(
    "SELECT category, COUNT(*) AS total
     FROM ops_error_logs
     WHERE logged_at >= ? AND logged_at < ?
     GROUP BY category
     ORDER BY total DESC
     LIMIT 6",
    [$periodStart, $periodEnd]
) : [];
$topErrorEmployeeRows = ($ready && ops_table_exists('ops_error_logs')) ? ops_rows(
    "SELECT e.full_name, COUNT(*) AS total
     FROM ops_error_logs el
     LEFT JOIN ops_employees e ON e.id = el.employee_id
     WHERE el.logged_at >= ? AND el.logged_at < ?
       AND el.employee_id IS NOT NULL
     GROUP BY el.employee_id, e.full_name
     ORDER BY total DESC
     LIMIT 3",
    [$periodStart, $periodEnd]
) : [];
$resolvedErrors = $ready && ops_table_exists('ops_error_logs') && $hasErrorStatus ? (int) (ops_rows(
    "SELECT COUNT(*) AS total
     FROM ops_error_logs
     WHERE status = 'resolved'
       AND logged_at >= ? AND logged_at < ?",
    [$periodStart, $periodEnd]
)[0]['total'] ?? 0) : 0;
$highRiskErrors = $ready && ops_table_exists('ops_error_logs') ? (int) (ops_rows(
    "SELECT COUNT(*) AS total
     FROM ops_error_logs
     WHERE severity IN ('high', 'critical')
       AND logged_at >= ? AND logged_at < ?",
    [$periodStart, $periodEnd]
)[0]['total'] ?? 0) : 0;
$totalErrorsForBars = max(1, array_sum(array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $errorCategoryRows)));
$topErrorEmployee = $topErrorEmployeeRows[0] ?? null;
$errorOwnerSummary = [
    'Total errors logged' => number_format((int) ($errorSummaryRow['total_errors'] ?? 0)),
    'Resolved errors' => number_format($resolvedErrors),
    'Open errors' => number_format((int) ($errorSummaryRow['open_errors'] ?? 0)),
    'Repeat errors' => number_format((int) ($errorSummaryRow['repeat_errors'] ?? 0)),
    'High/Critical errors' => number_format($highRiskErrors),
    'Most error-linked person' => $topErrorEmployee ? (string) ($topErrorEmployee['full_name'] ?: 'Unassigned') . ' (' . number_format((int) $topErrorEmployee['total']) . ')' : '-',
];
$tabs = [
    'overview' => 'Overview Dashboard',
    'employees' => 'Employee Profiles',
    'front-desk' => 'Front Desk Performance',
    'picker-1' => 'Packer Performance 1',
    'picker-2' => 'Packer Performance 2',
    'orders' => 'Order Board',
    'packing' => 'Packing List',
    'tasks' => 'Task Management',
    'bookkeeping' => 'Bookkeeping',
    'courier' => 'Courier',
    'errors' => 'Errors',
    'bonus' => 'Bonus Incentive Score',
];
if (!$isKpiManager) {
    $tabs = ['employees' => 'My Performance'];
}
$scoresById = [];
foreach ($employeeScores as $row) {
    $scoresById[(int) $row['employee_id']] = $row;
}
$employeeKpiDetails = $ready ? kpi_employee_performance_details($employeeScores, $periodStart, $periodEnd, $settings) : [];
$selectedEmployee = $selectedEmployeeId && isset($scoresById[$selectedEmployeeId]) ? $scoresById[$selectedEmployeeId] : ($employeeScores[0] ?? null);
$pickerOneId = (int) kpi_setting('kpi_picker_1_employee_id', '0');
$pickerTwoId = (int) kpi_setting('kpi_picker_2_employee_id', '0');
$pickerEmployees = array_values(array_filter($employeeScores, static function (array $row): bool {
    return ($row['role_group'] ?? '') === 'packer';
}));
$unlinkedEmployees = array_values(array_filter($employeeScores, static function (array $row): bool {
    return empty($row['hr_linked']);
}));
$dateRangeQuery = http_build_query([
    'period_preset' => $periodPreset,
    'start_date' => $filterStartDate,
    'end_date' => $filterEndDate,
]);
$ownerDashboardData = $isKpiManager
    ? ($ready ? owner_dashboard_build($filterStartDate, $filterEndDate) : owner_dashboard_build(date('Y-m-d'), date('Y-m-d')))
    : [];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module kpi-performance-page">
    <section class="module-header">
        <div>
            <h1><?= $isKpiManager ? 'Performance Dashboard' : 'My Performance' ?></h1>
        </div>
        <form class="kpi-period-form" method="get">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            <label>Period
                <select name="period_preset">
                    <?php foreach ([
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'this_week' => 'This week',
                        'last_week' => 'Last week',
                        'this_month' => 'This month',
                        'last_month' => 'Last month',
                        'last_30_days' => 'Last 30 days',
                        'custom' => 'Custom range',
                    ] as $presetKey => $presetLabel): ?>
                        <option value="<?= $presetKey ?>" <?= $periodPreset === $presetKey ? 'selected' : '' ?>><?= $presetLabel ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>From<input type="date" name="start_date" value="<?= htmlspecialchars($filterStartDate, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>To<input type="date" name="end_date" value="<?= htmlspecialchars($filterEndDate, ENT_QUOTES, 'UTF-8') ?>"></label>
            <button class="button primary" type="submit"><i data-lucide="calendar-range"></i> View</button>
        </form>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>
    <?php if ($ready && $isKpiManager && $unlinkedEmployees): ?>
        <section class="ops-alert kpi-link-alert">
            <strong>HR employee links needed.</strong>
            <?= count($unlinkedEmployees) ?> active portal user<?= count($unlinkedEmployees) === 1 ? '' : 's' ?> are not linked to HR employee profiles. KPI salary, leave and availability tracking may be inaccurate.
            <a class="button small" href="my-account.php#section-employees">Link employees</a>
        </section>
    <?php endif; ?>
    <nav class="kpi-report-tabs" aria-label="KPI report sections">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <a class="<?= $activeTab === $tabKey ? 'active' : '' ?>" href="reports.php?tab=<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>&<?= htmlspecialchars($dateRangeQuery, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($activeTab === 'overview'): ?>
        <section class="owner-performance-dashboard" data-owner-dashboard data-dashboard-endpoint="<?= htmlspecialchars(BASE_URL . '/apps/operations/api-dashboard.php', ENT_QUOTES, 'UTF-8') ?>">
            <div class="owner-dashboard-head">
                <div>
                    <span class="owner-eyebrow"><span class="owner-live-dot"></span> Hambelela Organic</span>
                    <h2>Owner Performance Dashboard</h2>
                    <p>Live operations view for orders, packing, checklists, staff and owner action items.</p>
                </div>
                <div class="owner-dashboard-controls">
                    <label>From<input type="date" data-owner-from value="<?= htmlspecialchars($filterStartDate, ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>To<input type="date" data-owner-to value="<?= htmlspecialchars($filterEndDate, ENT_QUOTES, 'UTF-8') ?>"></label>
                    <button class="owner-refresh" type="button" data-owner-refresh><i data-lucide="refresh-cw"></i> Refresh</button>
                    <small data-owner-updated>Updated <?= htmlspecialchars(date('H:i:s'), ENT_QUOTES, 'UTF-8') ?></small>
                </div>
            </div>

            <div class="owner-card-grid owner-health-grid" data-owner-health>
                <?php foreach (($ownerDashboardData['metrics'] ?? []) as $metric): ?>
                    <article class="owner-metric-card">
                        <div class="owner-card-top">
                            <span class="owner-card-icon"><i data-lucide="activity"></i></span>
                            <h3><?= htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <strong><?= htmlspecialchars((string) ($metric['value'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars((string) ($metric['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                        <div class="owner-progress"><span style="width: <?= max(0, min(100, (int) ($metric['progress'] ?? 0))) ?>%"></span></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="owner-section-grid">
                <section class="owner-panel owner-span-2">
                    <div class="owner-section-title">
                        <div>
                            <h3>Board Status</h3>
                            <p>One row per employee summarising live board workload.</p>
                        </div>
                    </div>
                    <div class="owner-table-wrap">
                        <table class="owner-staff-table owner-board-status-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Order Board</th>
                                    <th>Packing List</th>
                                    <th>Checklist</th>
                                    <th>Mode</th>
                                    <th>Avg Time</th>
                                    <th>Stale</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody data-owner-boards>
                                <?php foreach (($ownerDashboardData['board_status'] ?? []) as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string) ($row['name'] ?? 'Employee'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars((string) ($row['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format((int) ($row['order_board'] ?? 0)) ?></td>
                                        <td><?= number_format((int) ($row['packing_list'] ?? 0)) ?></td>
                                        <td><?= number_format((int) ($row['checklist'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['mode'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['avg_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format((int) ($row['stale'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="owner-mode-panel">
                        <div class="owner-section-title owner-section-title-compact">
                            <div>
                                <h3>Mode</h3>
                                <p>Courier, delivery and collection work handled by each employee.</p>
                            </div>
                        </div>
                        <div class="owner-table-wrap">
                            <table class="owner-mode-table">
                                <thead><tr><th>Employee</th><th>Courier / Delivery</th><th>Collections</th><th>Total handled</th></tr></thead>
                                <tbody data-owner-mode>
                                    <?php foreach (($ownerDashboardData['mode']['rows'] ?? []) as $row): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars((string) ($row['name'] ?? 'Employee'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td><?= number_format((int) ($row['courier'] ?? 0)) ?></td>
                                            <td><?= number_format((int) ($row['collection'] ?? 0)) ?></td>
                                            <td><?= number_format((int) ($row['total'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="owner-panel">
                    <div class="owner-section-title">
                        <div>
                            <h3>Owner Error Log</h3>
                            <p>Unresolved issues assigned to the owner.</p>
                        </div>
                    </div>
                    <div class="owner-error-list" data-owner-errors>
                        <?php if (empty($ownerDashboardData['owner_errors'])): ?>
                            <p class="owner-empty">No unresolved owner issues in this period.</p>
                        <?php else: ?>
                            <?php foreach ($ownerDashboardData['owner_errors'] as $error): ?>
                                <article class="owner-error-item">
                                    <strong><?= htmlspecialchars((string) ($error['description'] ?? 'Issue'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars((string) ($error['logged_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) ($error['logged_by'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></small>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <section class="owner-panel">
                <div class="owner-section-title">
                    <div>
                        <h3>Staff Overview</h3>
                        <p>One row per employee with workload, speed, stale work and availability.</p>
                    </div>
                </div>
                <div class="owner-table-wrap">
                    <table class="owner-staff-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Orders</th>
                                <th>Packing</th>
                                <th>Tasks</th>
                                <th>Avg Time</th>
                                <th>Stale</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody data-owner-staff>
                            <?php foreach (($ownerDashboardData['staff'] ?? []) as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string) ($row['name'] ?? 'Employee'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><?= htmlspecialchars((string) ($row['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((int) ($row['orders'] ?? 0)) ?></td>
                                    <td><?= number_format((int) ($row['packing'] ?? 0)) ?></td>
                                    <td><?= number_format((int) ($row['tasks'] ?? 0)) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['avg_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((int) ($row['stale_items'] ?? 0)) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    <?php elseif ($activeTab === 'orders'): ?>
        <section class="panel kpi-system-panel">
            <div class="section-row"><div><h2>Orders KPI</h2><p>Business-hour timing uses Mon-Fri 08:00-17:00, Saturday 09:00-13:00, and excludes Sundays. After-hours orders start counting at the next opening time.</p></div></div>
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
    <?php elseif ($activeTab === 'front-desk' || $activeTab === 'picker-1' || $activeTab === 'picker-2'): ?>
        <?php
        $roleRows = [];
        if ($activeTab === 'front-desk') {
            $roleRows = array_values(array_filter($employeeScores, static function (array $row): bool {
                return ($row['role_group'] ?? '') === 'front_desk';
            }));
            if (!$roleRows) {
                $roleRows = array_values(array_filter($employeeScores, static function (array $row): bool {
                    $name = (string) ($row['name'] ?? '');
                    $email = (string) ($row['email'] ?? '');
                    return stripos($name, 'cecil') !== false
                        || stripos($name, 'secil') !== false
                        || strtolower($email) === 'shiwedasecilia3@gmail.com';
                }));
            }
        } else {
            $slotId = $activeTab === 'picker-1' ? $pickerOneId : $pickerTwoId;
            if ($slotId > 0 && isset($scoresById[$slotId])) {
                $roleRows = [$scoresById[$slotId]];
            }
        }
        ?>
        <?php if (!$roleRows): ?>
            <section class="panel">
                <p><?= $activeTab === 'front-desk' ? 'No front desk employees found.' : 'No picker assigned to this slot yet. Open Dashboard and choose a picker for this performance slot.' ?></p>
            </section>
        <?php endif; ?>
        <?php foreach ($roleRows as $row): ?>
            <?php $detail = $employeeKpiDetails[(int) $row['employee_id']] ?? []; ?>
            <?php if ($activeTab === 'front-desk'): ?>
                <?php kpi_render_front_person_live_dashboard($row, $detail, $periodStart, $periodEnd); ?>
            <?php else: ?>
                <?php kpi_render_packer_live_dashboard($row, $detail, $periodStart, $periodEnd); ?>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php elseif ($activeTab === 'employees'): ?>
        <section class="panel kpi-employee-picker">
            <div class="section-row"><div><h2><?= $isKpiManager ? 'Individual Employee Profiles' : 'My Performance Profile' ?></h2><p><?= $isKpiManager ? 'Open one employee at a time inside KPI Reports.' : 'Your operational score, supporting evidence and development signals for the selected period.' ?></p></div></div>
            <?php if ($isKpiManager): ?>
                <div class="kpi-employee-tabs">
                    <?php foreach ($employeeScores as $row): ?>
                        <a class="<?= $selectedEmployee && (int) $selectedEmployee['employee_id'] === (int) $row['employee_id'] ? 'active' : '' ?>" href="reports.php?tab=employees&employee_id=<?= (int) $row['employee_id'] ?>&<?= htmlspecialchars($dateRangeQuery, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!$employeeScores): ?>
                <p class="kpi-warning-text">No employee performance records are available for the selected period. Try a wider date range or confirm that active portal users are linked to HR employee profiles.</p>
            <?php endif; ?>
        </section>
        <?php if ($selectedEmployee): ?>
            <section class="panel kpi-profile">
                <div class="section-row"><div><h2><?= htmlspecialchars($selectedEmployee['name'], ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($selectedEmployee['role_name'], ENT_QUOTES, 'UTF-8') ?> | Salary <?= kpi_money((float) $selectedEmployee['salary']) ?> | <?= htmlspecialchars((string) $selectedEmployee['tier']['recommendation'], ENT_QUOTES, 'UTF-8') ?></p><?php if (empty($selectedEmployee['hr_linked'])): ?><p class="kpi-warning-text">This user is not linked to an HR employee profile. KPI and leave tracking may be inaccurate.</p><?php else: ?><p class="kpi-hr-context">HR: <?= htmlspecialchars((string) ($selectedEmployee['hr_job_title'] ?: 'Employee'), ENT_QUOTES, 'UTF-8') ?><?= !empty($selectedEmployee['hr_department']) ? ' | ' . htmlspecialchars((string) $selectedEmployee['hr_department'], ENT_QUOTES, 'UTF-8') : '' ?><?= !empty($selectedEmployee['on_leave']) ? ' | Approved leave considered' : '' ?></p><?php endif; ?></div><span class="kpi-score"><?= kpi_percent((float) $selectedEmployee['score']) ?></span></div>
                <div class="kpi-profile-grid">
                    <?php foreach ($selectedEmployee['components'] as $label => $component): ?>
                        <article><h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3><p><span>Score</span><strong><?= kpi_percent((float) $component['score']) ?></strong></p><p><span>Weight</span><strong><?= number_format((float) $component['weight']) ?>%</strong></p><?php if (!empty($component['raw'])): ?><p><span>Signal</span><strong><?= htmlspecialchars((string) $component['raw'], ENT_QUOTES, 'UTF-8') ?></strong></p><?php endif; ?></article>
                    <?php endforeach; ?>
                    <article><h3>Bonus / increment</h3><p><span>Suggested bonus</span><strong><?= kpi_money((float) $selectedEmployee['bonus_amount']) ?></strong></p><p><span>Status</span><strong><?= htmlspecialchars((string) $selectedEmployee['tier']['label'], ENT_QUOTES, 'UTF-8') ?></strong></p><p><span>Recommendation</span><strong><?= htmlspecialchars((string) $selectedEmployee['tier']['recommendation'], ENT_QUOTES, 'UTF-8') ?></strong></p></article>
                </div>
            </section>
            <?php $selectedDetail = $employeeKpiDetails[(int) $selectedEmployee['employee_id']] ?? []; ?>
            <?php if ($selectedDetail): ?>
                <section class="panel kpi-evidence-panel">
                    <div class="section-row">
                        <div>
                            <h2>Detailed Performance Tracking</h2>
                            <p>Operational evidence for bonus, coaching and salary increment decisions.</p>
                        </div>
                    </div>
                    <?php kpi_render_employee_detail_grid($selectedDetail); ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif (in_array($activeTab, ['packing', 'bookkeeping', 'tasks', 'courier', 'errors'], true)): ?>
        <?php kpi_render_module_report($activeTab, $employeeScores, $employeeKpiDetails); ?>
    <?php endif; ?>

    <?php if (false && $activeTab === 'overview'): ?>
    <section class="work-metric-grid kpi-overview-grid">
        <?php foreach ([
            ['Average Performance', kpi_percent($averageScore), 'All active employees', 'gauge', 'metric-blue'],
            ['Overall Business Health', kpi_percent($overallBusinessHealth), 'Performance, overdue work and correction signals', 'activity', 'metric-green'],
            ['Orders Completed', number_format($completedOrdersInRange), $filterStartDate === $defaultStartDate && $filterEndDate === $defaultEndDate ? 'Completed this month' : 'Completed in selected period', 'package-check', 'metric-blue'],
            ['Reward Candidates', number_format(count($rewardCandidates)), '90% and above', 'gift', 'metric-pink'],
        ] as [$title, $value, $desc, $icon, $class]): ?>
            <article class="work-metric-card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) $desc, ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="kpi-visual-dashboard-grid">
        <article class="panel kpi-visual-card kpi-performance-visual">
            <div class="kpi-visual-head">
                <div>
                    <h2>KPI Performance</h2>
                    <p><strong class="<?= $trendChange !== null && $trendChange < 0 ? 'is-down' : 'is-up' ?>"><?= $trendChange === null ? 'Live' : (($trendChange >= 0 ? '+' : '') . number_format($trendChange, 1) . '%') ?></strong> overall employee efficiency</p>
                </div>
                <span><?= htmlspecialchars(date('M Y', strtotime($filterStartDate)), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <svg class="kpi-line-chart" viewBox="0 0 360 160" role="img" aria-label="KPI performance trend">
                <defs>
                    <linearGradient id="kpiTrendFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#89b746" stop-opacity="0.38" />
                        <stop offset="100%" stop-color="#f4a64a" stop-opacity="0.05" />
                    </linearGradient>
                </defs>
                <g class="kpi-chart-grid">
                    <line x1="0" y1="24" x2="360" y2="24"></line>
                    <line x1="0" y1="56" x2="360" y2="56"></line>
                    <line x1="0" y1="88" x2="360" y2="88"></line>
                    <line x1="0" y1="120" x2="360" y2="120"></line>
                </g>
                <?php if ($trendAreaPoints): ?><polygon points="<?= htmlspecialchars($trendAreaPoints, ENT_QUOTES, 'UTF-8') ?>" fill="url(#kpiTrendFill)"></polygon><?php endif; ?>
                <polyline points="<?= htmlspecialchars(implode(' ', $trendPoints), ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="#89b746" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></polyline>
            </svg>
            <div class="kpi-chart-labels">
                <?php foreach ($trendMonths as $monthLabel): ?><span><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
            </div>
        </article>

        <article class="panel kpi-visual-card kpi-employee-visual">
            <div class="kpi-visual-head">
                <div><h2>Employee Performance</h2><p>Selected period</p></div>
                <a href="reports.php?tab=employees&<?= htmlspecialchars($dateRangeQuery, ENT_QUOTES, 'UTF-8') ?>">See Details</a>
            </div>
            <div class="kpi-donut-layout">
                <div class="kpi-donut" style="--value: <?= min(100, max(0, $orderCompletionRate)) ?>;">
                    <strong><?= kpi_percent($orderCompletionRate) ?></strong>
                    <span>Orders completion rate</span>
                </div>
                <div class="kpi-mini-bars">
                    <div><span>Orders Completed<small><?= number_format($completedOrdersInRange) ?></small></span><i><b style="width: <?= min(100, max(3, $orderCompletionRate)) ?>%"></b></i></div>
                    <div><span>Average Work<small>per employee</small></span><i><b class="blue" style="width: <?= min(100, max(3, kpi_ratio_score($averageWorkPerEmployee, 20))) ?>%"></b></i></div>
                    <div><span>Work Efficiency<small>time-to-completion</small></span><i><b class="green" style="width: <?= min(100, max(3, $taskEfficiency)) ?>%"></b></i></div>
                </div>
            </div>
        </article>

        <article class="panel kpi-visual-card kpi-productivity-visual">
            <div class="kpi-visual-head">
                <div>
                    <h2>Productivity Hours</h2>
                    <strong><?= number_format($totalProductivityHours, 1) ?></strong>
                </div>
                <nav class="kpi-period-switch" aria-label="Productivity period shortcuts">
                    <a href="reports.php?tab=overview&start_date=<?= date('Y-m-d') ?>&end_date=<?= date('Y-m-d') ?>">Day</a>
                    <a href="reports.php?tab=overview&start_date=<?= date('Y-m-d', strtotime('monday this week')) ?>&end_date=<?= date('Y-m-d', strtotime('saturday this week')) ?>">Week</a>
                    <a class="active" href="reports.php?tab=overview&start_date=<?= date('Y-m-01', strtotime($filterStartDate)) ?>&end_date=<?= date('Y-m-t', strtotime($filterStartDate)) ?>">Month</a>
                </nav>
            </div>
            <div class="kpi-week-bars">
                <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label): ?>
                    <?php $height = (($productivityHours[$day] ?? 0) / $maxProductivityHours) * 100; ?>
                    <div class="<?= $day === (int) date('N') ? 'is-today' : '' ?>"><span style="height: <?= min(100, max(8, $height)) ?>%"></span><small><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></small></div>
                <?php endforeach; ?>
            </div>
            <a class="kpi-soft-link" href="reports.php?tab=employees&<?= htmlspecialchars($dateRangeQuery, ENT_QUOTES, 'UTF-8') ?>">See Report</a>
        </article>

        <article class="panel kpi-visual-card kpi-top-employees-visual">
            <div class="kpi-visual-head">
                <div><h2>Top Employees</h2><p>Ranked by performance impact</p></div>
                <span>Sort: Impact</span>
            </div>
            <div class="kpi-top-employee-table">
                <div class="head"><span>Employee name</span><span>Work Done</span><span>Hours</span><span>Performance</span></div>
                <?php foreach ($topEmployeeRows as $row): ?>
                    <?php
                        $initials = implode('', array_map(static fn (string $part): string => strtoupper(substr($part, 0, 1)), array_slice(array_filter(explode(' ', (string) $row['name'])), 0, 2)));
                        $hours = $employeeHours[(int) $row['employee_id']] ?? 0.0;
                        $workDone = (int) $row['orders_handled'] + (float) $row['items_packed'];
                        $band = (float) $row['score'] >= 80 ? 'High' : ((float) $row['score'] >= 65 ? 'Medium' : 'Low');
                    ?>
                    <div>
                        <span><i><?= htmlspecialchars($initials ?: 'HO', ENT_QUOTES, 'UTF-8') ?></i><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= number_format($workDone, 1) ?></span>
                        <span><?= number_format($hours, 1) ?> hrs</span>
                        <span><b class="<?= strtolower($band) ?>"><?= htmlspecialchars($band, ENT_QUOTES, 'UTF-8') ?></b></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$topEmployeeRows): ?><p class="empty-state">No employee KPI data available yet.</p><?php endif; ?>
            </div>
            <a class="kpi-soft-link" href="reports.php?tab=employees&<?= htmlspecialchars($dateRangeQuery, ENT_QUOTES, 'UTF-8') ?>">View All Employees</a>
        </article>
    </section>

    <section class="panel kpi-system-panel kpi-panel-orders">
        <div class="section-row">
            <div>
                <h2>Orders Matrix</h2>
                <p>Order flow, completion timing, overdue work, active queue and error signals for the selected period.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($ordersMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel kpi-system-panel kpi-panel-packing">
        <div class="section-row">
            <div>
                <h2>Packing Matrix</h2>
                <p>Packing speed, status movement, notes and item volume signals from order and item history.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($packingMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel kpi-system-panel kpi-panel-bookkeeping">
        <div class="section-row">
            <div>
                <h2>Bookkeeping Matrix</h2>
                <p>Cash order reconciliation between completed orders and the cash-in/cash-out bookkeeping list.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($bookkeepingMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel kpi-system-panel kpi-panel-frontdesk">
        <div class="section-row">
            <div>
                <h2>Front Desk Website & Orders Matrix</h2>
                <p>Website quantity upload follow-up, order completion timing and paid-order status from the operations list.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($frontDeskMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel kpi-system-panel kpi-panel-availability">
        <div class="section-row">
            <div>
                <h2>Login & Availability Matrix</h2>
                <p>Portal login timing plus current lunch/offline status from the Operations board availability controls.</p>
            </div>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($availabilityMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel kpi-system-panel kpi-panel-tasks">
        <div class="section-row">
            <div>
                <h2>Task Management Matrix</h2>
                <p>Digital task board performance: active tasks, completed tasks, review queue and completion timing by employee.</p>
            </div>
            <a class="kpi-soft-link" href="checklists.php">Open Task Board</a>
        </div>
        <div class="kpi-system-metric-grid">
            <?php foreach ($taskMatrix as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="kpi-task-dashboard-grid">
            <div>
                <h3>Employee task load</h3>
                <div class="kpi-task-employee-bars">
                    <?php foreach ($taskEmployeeRows as $row): ?>
                        <?php
                            $completed = (int) ($row['completed_tasks'] ?? 0);
                            $inProgress = (int) ($row['in_progress_tasks'] ?? 0);
                            $review = (int) ($row['needs_review_tasks'] ?? 0);
                            $notStarted = (int) ($row['not_started_tasks'] ?? 0);
                            $total = max(1, $completed + $inProgress + $review + $notStarted);
                        ?>
                        <article>
                            <div><strong><?= htmlspecialchars((string) ($row['full_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></strong><span><?= number_format((int) ($row['total_tasks'] ?? 0)) ?> tasks | avg <?= kpi_duration(isset($row['avg_complete_minutes']) ? (float) $row['avg_complete_minutes'] : null) ?></span></div>
                            <div class="kpi-task-stack">
                                <i class="done" style="width: <?= max($completed > 0 ? 5 : 0, ($completed / $total) * 100) ?>%"></i>
                                <i class="progress" style="width: <?= max($inProgress > 0 ? 5 : 0, ($inProgress / $total) * 100) ?>%"></i>
                                <i class="review" style="width: <?= max($review > 0 ? 5 : 0, ($review / $total) * 100) ?>%"></i>
                                <i class="todo" style="width: <?= max($notStarted > 0 ? 5 : 0, ($notStarted / $total) * 100) ?>%"></i>
                            </div>
                            <small><?= number_format($completed) ?> done | <?= number_format($inProgress) ?> in progress | <?= number_format($review) ?> review | <?= number_format($notStarted) ?> not started</small>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$taskEmployeeRows): ?><p class="empty-state">No task board data for this period.</p><?php endif; ?>
                </div>
            </div>
            <div>
                <h3>Daily completed tasks</h3>
                <div class="kpi-task-day-bars">
                    <?php for ($cursor = new DateTimeImmutable($filterStartDate); $cursor <= new DateTimeImmutable($filterEndDate); $cursor = $cursor->modify('+1 day')): ?>
                        <?php
                            $dayKey = $cursor->format('Y-m-d');
                            $count = (int) ($taskCompletedByDay[$dayKey] ?? 0);
                            $maxDaily = max(1, max($taskCompletedByDay ?: [0]));
                        ?>
                        <span title="<?= htmlspecialchars($dayKey . ': ' . $count . ' tasks', ENT_QUOTES, 'UTF-8') ?>"><i style="height: <?= max($count > 0 ? 8 : 2, ($count / $maxDaily) * 100) ?>%"></i><small><?= htmlspecialchars($cursor->format('D'), ENT_QUOTES, 'UTF-8') ?></small></span>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="panel kpi-owner-snapshot-panel">
        <div class="section-row">
            <div>
                <h2>Employee Daily Snapshot</h2>
                <p>Owner view of each active employee: completed work, active queue, front-desk follow-ups, errors and HR leave status for the selected period.</p>
            </div>
        </div>
        <div class="kpi-owner-bars">
            <?php foreach ($employeeOwnerRows as $row): ?>
                <?php
                    $completedWidth = max(((int) $row['completed'] > 0 ? 4 : 0), (float) $row['completed_pct']);
                    $activeWidth = max(((int) $row['active'] > 0 ? 4 : 0), (float) $row['active_pct']);
                    $pendingWidth = max(((int) $row['pending_admin'] > 0 ? 4 : 0), (float) $row['pending_pct']);
                    $errorWidth = max(((int) $row['errors'] > 0 ? 4 : 0), (float) $row['error_pct']);
                ?>
                <article class="kpi-owner-bar-row <?= !empty($row['on_leave']) ? 'is-on-leave' : '' ?>">
                    <div class="kpi-owner-person">
                        <strong><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars((string) $row['role'], ENT_QUOTES, 'UTF-8') ?><?= !empty($row['on_leave']) ? ' | On approved leave' : '' ?></span>
                    </div>
                    <div class="kpi-owner-bar" aria-label="Employee work mix">
                        <span class="done" style="width: <?= min(100, $completedWidth) ?>%"></span>
                        <span class="active" style="width: <?= min(100, $activeWidth) ?>%"></span>
                        <span class="pending" style="width: <?= min(100, $pendingWidth) ?>%"></span>
                        <span class="error" style="width: <?= min(100, $errorWidth) ?>%"></span>
                    </div>
                    <div class="kpi-owner-stats">
                        <span><b><?= number_format((int) $row['completed']) ?></b> completed</span>
                        <span><b><?= number_format((int) $row['active']) ?></b> active</span>
                        <span><b><?= number_format((int) $row['pending_admin']) ?></b> admin follow-up</span>
                        <span><b><?= number_format((int) $row['errors']) ?></b> errors</span>
                        <span><b><?= kpi_percent((float) $row['score']) ?></b> score</span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="kpi-owner-legend">
            <span><i class="done"></i> Completed</span>
            <span><i class="active"></i> Active queue</span>
            <span><i class="pending"></i> Admin follow-up</span>
            <span><i class="error"></i> Errors</span>
        </div>
    </section>

    <section class="panel kpi-error-owner-panel">
        <div class="section-row">
            <div>
                <h2>Error Log Summary</h2>
                <p>Management view of error volume, unresolved issues, repeat mistakes and the most common error categories.</p>
            </div>
        </div>
        <div class="kpi-error-summary-grid">
            <?php foreach ($errorOwnerSummary as $label => $value): ?>
                <article>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="kpi-error-insight-grid">
            <div>
                <h3>Most logged error types</h3>
                <div class="kpi-error-bars">
                    <?php if ($errorCategoryRows): ?>
                        <?php foreach ($errorCategoryRows as $row): ?>
                            <?php $width = ((int) ($row['total'] ?? 0) / $totalErrorsForBars) * 100; ?>
                            <div class="kpi-error-bar-row">
                                <span><?= htmlspecialchars(OPS_ERROR_CATEGORIES[(string) ($row['category'] ?? '')] ?? (string) ($row['category'] ?? 'Uncategorised'), ENT_QUOTES, 'UTF-8') ?></span>
                                <div><i style="width: <?= min(100, max(4, $width)) ?>%"></i></div>
                                <strong><?= number_format((int) ($row['total'] ?? 0)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-state">No errors logged for this period.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <h3>People linked to errors</h3>
                <div class="kpi-error-people">
                    <?php if ($topErrorEmployeeRows): ?>
                        <?php foreach ($topErrorEmployeeRows as $row): ?>
                            <span><strong><?= htmlspecialchars((string) ($row['full_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= number_format((int) ($row['total'] ?? 0)) ?> logged error<?= (int) ($row['total'] ?? 0) === 1 ? '' : 's' ?></small></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-state">No employee-linked errors for this period.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($activeTab === 'bonus'): ?>
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
                        <th>Rank</th><th>Employee</th><th>HR Link</th><th>Role</th><th>Performance</th><th>Tier</th><th>Bonus %</th><th>Bonus Amount</th><th>Orders</th><th>Items Packed</th><th>Avg Completion</th><th>Errors</th><th>Attendance</th><th>Reliability</th><th>Monthly Trend</th><th>Reward</th><th>Bonus</th>
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
                        <td><?= !empty($row['hr_linked']) ? '<span class="status">linked</span>' : '<span class="status kpi-status-warning">missing</span>' ?><?= !empty($row['on_leave']) ? '<br><small>On approved leave</small>' : '' ?></td>
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
                <?php if (!$employeeScores): ?><tr><td colspan="17">No KPI data recorded yet.</td></tr><?php endif; ?>
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
    <?php endif; ?>
</main>
<?php if ($activeTab === 'overview'): ?>
<style>
.owner-performance-dashboard {
    --owner-pink: #D4537E;
    --owner-ink: #1f1f29;
    --owner-muted: #6f7282;
    --owner-line: #e6e8ef;
    --owner-soft: #faf7f8;
    color: var(--owner-ink);
    display: grid;
    gap: 16px;
}
.owner-dashboard-head,
.owner-panel,
.owner-metric-card {
    background: #fff;
    border: 1px solid var(--owner-line);
    border-radius: 14px;
}
.owner-dashboard-head {
    align-items: center;
    display: flex;
    gap: 18px;
    justify-content: space-between;
    padding: 18px 20px;
}
.owner-eyebrow {
    align-items: center;
    color: var(--owner-pink);
    display: inline-flex;
    font-size: 11px;
    font-weight: 800;
    gap: 8px;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.owner-live-dot {
    animation: ownerPulse 1.7s ease-in-out infinite;
    background: #19bf6b;
    border-radius: 999px;
    display: inline-block;
    height: 9px;
    width: 9px;
}
.owner-dashboard-head h2 {
    font-size: 22px;
    line-height: 1.1;
    margin: 7px 0 5px;
}
.owner-dashboard-head p,
.owner-section-title p {
    color: var(--owner-muted);
    font-size: 13px;
    margin: 0;
}
.owner-dashboard-controls {
    align-items: end;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}
.owner-dashboard-controls label {
    color: var(--owner-muted);
    display: grid;
    font-size: 11px;
    font-weight: 800;
    gap: 4px;
    text-transform: uppercase;
}
.owner-dashboard-controls input {
    border: 1px solid #cfd4e4;
    border-radius: 9px;
    color: var(--owner-ink);
    font: inherit;
    min-height: 38px;
    padding: 7px 10px;
}
.owner-refresh {
    align-items: center;
    background: var(--owner-pink);
    border: 0;
    border-radius: 9px;
    color: #fff;
    cursor: pointer;
    display: inline-flex;
    font-size: 13px;
    font-weight: 800;
    gap: 7px;
    min-height: 38px;
    padding: 8px 13px;
}
.owner-refresh.is-refreshing svg {
    animation: ownerSpin .8s linear infinite;
}
.owner-dashboard-controls small {
    color: var(--owner-muted);
    font-size: 11px;
    padding-bottom: 11px;
}
.owner-card-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(6, minmax(145px, 1fr));
}
.owner-metric-card {
    min-height: 118px;
    padding: 14px;
    transition: transform .18s ease, border-color .18s ease;
}
.owner-metric-card:hover {
    border-color: rgba(212, 83, 126, .38);
    transform: translateY(-2px);
}
.owner-card-top {
    align-items: center;
    display: flex;
    gap: 10px;
}
.owner-card-icon {
    align-items: center;
    background: rgba(212, 83, 126, .12);
    border-radius: 50%;
    color: var(--owner-pink);
    display: inline-flex;
    height: 34px;
    justify-content: center;
    width: 34px;
}
.owner-metric-card h3 {
    font-size: 11px;
    margin: 0;
    text-transform: uppercase;
}
.owner-metric-card strong {
    display: block;
    font-size: 22px;
    line-height: 1.1;
    margin-top: 14px;
}
.owner-metric-card small {
    color: var(--owner-muted);
    display: block;
    font-size: 12px;
    margin-top: 3px;
}
.owner-progress {
    background: #f0eef2;
    border-radius: 999px;
    height: 6px;
    margin-top: 12px;
    overflow: hidden;
}
.owner-progress span {
    background: var(--owner-pink);
    border-radius: inherit;
    display: block;
    height: 100%;
    transition: width .25s ease;
}
.owner-section-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
}
.owner-panel {
    overflow: hidden;
    padding: 16px;
}
.owner-section-title {
    align-items: start;
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
}
.owner-section-title h3 {
    font-size: 15px;
    margin: 0 0 4px;
}
.owner-board-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(280px, 1fr));
}
.owner-board-card {
    border: 1px solid var(--owner-line);
    border-radius: 12px;
    display: grid;
    gap: 10px;
    padding: 14px;
}
.owner-board-card h4 {
    font-size: 14px;
    margin: 0;
}
.owner-board-status-table {
    min-width: 980px;
}
.owner-person-stack {
    display: grid;
    gap: 2px;
}
.owner-person-stack small {
    color: var(--owner-muted);
    font-size: 11px;
    font-weight: 700;
}
.owner-board-summary {
    color: var(--owner-muted);
    font-size: 12px;
    margin: -6px 0 0;
}
.owner-board-row {
    align-items: center;
    border-top: 1px solid var(--owner-line);
    display: grid;
    gap: 10px;
    grid-template-columns: minmax(105px, 1fr) minmax(0, 2fr);
    padding-top: 9px;
}
.owner-board-person {
    display: grid;
    gap: 2px;
}
.owner-board-person strong {
    font-size: 12px;
}
.owner-board-person small,
.owner-no-activity {
    color: var(--owner-muted);
    font-size: 11px;
}
.owner-badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-end;
}
.owner-badge {
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    padding: 5px 8px;
}
.owner-badge-new { background: #E6F1FB; color: #185FA5; }
.owner-badge-progress { background: #FAEEDA; color: #854F0B; }
.owner-badge-done { background: #EAF3DE; color: #3B6D11; }
.owner-badge-hold { background: #FBEAF0; color: #993556; }
.owner-badge-neutral { background: #f3f4f8; color: #5f6575; }
.owner-mode-panel {
    border-top: 1px solid var(--owner-line);
    margin-top: 16px;
    padding-top: 14px;
}
.owner-section-title-compact {
    margin-bottom: 10px;
}
.owner-table-wrap {
    overflow-x: auto;
}
.owner-staff-table,
.owner-mode-table {
    border-collapse: collapse;
    min-width: 900px;
    width: 100%;
}
.owner-mode-table {
    min-width: 560px;
}
.owner-staff-table th,
.owner-staff-table td,
.owner-mode-table th,
.owner-mode-table td {
    border-bottom: 1px solid var(--owner-line);
    font-size: 12px;
    padding: 11px 10px;
    text-align: left;
}
.owner-staff-table th,
.owner-mode-table th {
    color: var(--owner-muted);
    font-size: 11px;
    text-transform: uppercase;
}
.owner-person {
    align-items: center;
    display: flex;
    gap: 10px;
    min-width: 190px;
}
.owner-avatar {
    align-items: center;
    border-radius: 50%;
    color: #fff;
    display: inline-flex;
    font-size: 12px;
    font-weight: 900;
    height: 34px;
    justify-content: center;
    width: 34px;
}
.owner-avatar.cecilia { background: #D4537E; }
.owner-avatar.klaudia { background: #5287E8; }
.owner-avatar.ndinelao { background: #1EB980; }
.owner-avatar.default { background: #777a88; }
.owner-error-list {
    display: grid;
    gap: 8px;
    max-height: 310px;
    overflow: auto;
}
.owner-error-item {
    border: 1px solid var(--owner-line);
    border-radius: 10px;
    padding: 10px;
}
.owner-error-item strong {
    display: block;
    font-size: 12px;
    margin-bottom: 4px;
}
.owner-error-item small {
    color: var(--owner-muted);
    display: block;
    font-size: 11px;
}
.owner-error-item button {
    background: transparent;
    border: 1px solid #d7dce8;
    border-radius: 8px;
    color: var(--owner-pink);
    cursor: pointer;
    font-size: 11px;
    font-weight: 800;
    margin-top: 8px;
    padding: 6px 8px;
}
.owner-empty {
    color: var(--owner-muted);
    font-size: 13px;
    margin: 0;
}
@keyframes ownerSpin { to { transform: rotate(360deg); } }
@keyframes ownerPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(25, 191, 107, .35); }
    50% { box-shadow: 0 0 0 7px rgba(25, 191, 107, 0); }
}
@media (max-width: 1260px) {
    .owner-card-grid { grid-template-columns: repeat(3, minmax(180px, 1fr)); }
    .owner-section-grid { grid-template-columns: 1fr; }
    .owner-board-grid { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
}
@media (max-width: 760px) {
    .owner-dashboard-head { align-items: stretch; flex-direction: column; }
    .owner-dashboard-controls { justify-content: stretch; }
    .owner-dashboard-controls label,
    .owner-dashboard-controls input,
    .owner-refresh { width: 100%; }
    .owner-card-grid,
    .owner-board-grid { grid-template-columns: 1fr; }
}
body.dark-mode .owner-dashboard-head,
body.dark-mode .owner-panel,
body.dark-mode .owner-metric-card,
body.dark-mode .owner-board-card,
body.dark-mode .owner-error-item {
    background: #171821;
    border-color: #2a2c38;
    color: #f6f7fb;
}
body.dark-mode .owner-dashboard-head p,
body.dark-mode .owner-section-title p,
body.dark-mode .owner-metric-card small,
body.dark-mode .owner-dashboard-controls small,
body.dark-mode .owner-empty,
body.dark-mode .owner-error-item small,
body.dark-mode .owner-staff-table th {
    color: #a9adbc;
}
body.dark-mode .owner-dashboard-controls input {
    background: #11131b;
    border-color: #343746;
    color: #f6f7fb;
}
</style>
<script>
window.HambelelaOwnerDashboard = <?= json_encode($ownerDashboardData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
(function () {
    const root = document.querySelector('[data-owner-dashboard]');
    if (!root) return;

    const endpoint = root.dataset.dashboardEndpoint;
    const fromInput = root.querySelector('[data-owner-from]');
    const toInput = root.querySelector('[data-owner-to]');
    const refreshButton = root.querySelector('[data-owner-refresh]');
    const updatedLabel = root.querySelector('[data-owner-updated]');
    const healthNode = root.querySelector('[data-owner-health]');
    const boardsNode = root.querySelector('[data-owner-boards]');
    const modeNode = root.querySelector('[data-owner-mode]');
    const staffNode = root.querySelector('[data-owner-staff]');
    const errorsNode = root.querySelector('[data-owner-errors]');

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));

    const iconFor = (key) => ({
        orders_today: 'shopping-bag',
        avg_fulfillment_time: 'timer',
        on_time_dispatch: 'send',
        walk_ins_today: 'users',
        avg_pack_speed: 'package-check',
        revenue_today: 'banknote'
    }[key] || 'activity');

    const metricBadge = (value, activeClass) => {
        const count = Number(value || 0);
        return `<span class="owner-badge ${count > 0 ? activeClass : 'owner-badge-neutral'}">${escapeHtml(count)}</span>`;
    };

    function render(data) {
        const metrics = data?.metrics || [];
        healthNode.innerHTML = metrics.map((metric) => `
            <article class="owner-metric-card">
                <div class="owner-card-top">
                    <span class="owner-card-icon"><i data-lucide="${escapeHtml(iconFor(metric.key))}"></i></span>
                    <h3>${escapeHtml(metric.label)}</h3>
                </div>
                <strong>${escapeHtml(metric.value)}</strong>
                <small>${escapeHtml(metric.subtitle)}</small>
                <div class="owner-progress"><span style="width:${Number(metric.progress || 0)}%"></span></div>
            </article>
        `).join('');

        const boardRows = data?.board_status || [];
        boardsNode.innerHTML = boardRows.length ? boardRows.map((person) => `
            <tr>
                <td>
                    <span class="owner-person">
                        <span class="owner-avatar ${escapeHtml(person.avatar || 'default')}">${escapeHtml(person.initials || 'HO')}</span>
                        <span class="owner-person-stack"><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.role)}</small></span>
                    </span>
                </td>
                <td>${escapeHtml(person.role)}</td>
                <td>${metricBadge(person.order_board, 'owner-badge-new')}</td>
                <td>${metricBadge(person.packing_list, 'owner-badge-progress')}</td>
                <td>${metricBadge(person.checklist, 'owner-badge-done')}</td>
                <td>${Number(person.mode || 0) > 0 ? escapeHtml(person.mode) : '-'}</td>
                <td>${escapeHtml(person.avg_time || '-')}</td>
                <td>${metricBadge(person.stale, 'owner-badge-hold')}</td>
                <td>${escapeHtml(person.status || 'Available')}</td>
            </tr>
        `).join('') : '<tr><td colspan="9">No board status records available.</td></tr>';

        const modeRows = data?.mode?.rows || [];
        const modeSummary = data?.mode?.summary || {};
        modeNode.innerHTML = modeRows.length ? modeRows.map((row) => `
            <tr>
                <td><strong>${escapeHtml(row.name || 'Employee')}</strong></td>
                <td><span class="owner-badge owner-badge-progress">${escapeHtml(row.courier ?? 0)}</span></td>
                <td><span class="owner-badge owner-badge-new">${escapeHtml(row.collection ?? 0)}</span></td>
                <td><span class="owner-badge owner-badge-done">${escapeHtml(row.total ?? 0)}</span></td>
            </tr>
        `).join('') + `
            <tr>
                <td><strong>Total</strong></td>
                <td><strong>${escapeHtml(modeSummary.courier ?? 0)}</strong></td>
                <td><strong>${escapeHtml(modeSummary.collection ?? 0)}</strong></td>
                <td><strong>${escapeHtml(modeSummary.total ?? 0)}</strong></td>
            </tr>
        ` : '<tr><td colspan="4">No order fulfilment activity for this date range.</td></tr>';

        const staff = data?.staff || [];
        staffNode.innerHTML = staff.length ? staff.map((person) => `
            <tr>
                <td><span class="owner-person"><span class="owner-avatar ${escapeHtml(person.avatar || 'default')}">${escapeHtml(person.initials || 'HO')}</span><strong>${escapeHtml(person.name)}</strong></span></td>
                <td>${escapeHtml(person.role)}</td>
                <td><span class="owner-badge owner-badge-new">${escapeHtml(person.orders ?? 0)}</span></td>
                <td><span class="owner-badge owner-badge-progress">${escapeHtml(person.packing ?? 0)}</span></td>
                <td><span class="owner-badge owner-badge-done">${escapeHtml(person.tasks ?? 0)}</span></td>
                <td>${escapeHtml(person.avg_time || '-')}</td>
                <td><span class="owner-badge owner-badge-hold">${escapeHtml(person.stale_items ?? 0)}</span></td>
                <td>${escapeHtml(person.status || 'Available')}</td>
            </tr>
        `).join('') : '<tr><td colspan="8">No staff records available.</td></tr>';

        const errors = data?.owner_errors || [];
        errorsNode.innerHTML = errors.length ? errors.map((error) => `
            <article class="owner-error-item">
                <strong>${escapeHtml(error.description)}</strong>
                <small>${escapeHtml(error.logged_at || '')}${error.reference_type ? ' | ' + escapeHtml(error.reference_type) : ''}${error.reference_id ? ' #' + escapeHtml(error.reference_id) : ''}</small>
                <button type="button" data-resolve-owner-error="${escapeHtml(error.id)}">Mark resolved</button>
            </article>
        `).join('') : '<p class="owner-empty">No unresolved owner errors.</p>';

        if (updatedLabel) {
            updatedLabel.textContent = `Updated ${new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'})}`;
        }
        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    async function loadDashboard() {
        if (!endpoint) return;
        refreshButton?.classList.add('is-refreshing');
        refreshButton && (refreshButton.disabled = true);
        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('from', fromInput?.value || '');
            url.searchParams.set('to', toInput?.value || '');
            const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
            const payload = await response.json();
            if (!payload.ok) throw new Error(payload.error || 'Dashboard refresh failed');
            render(payload.data);
        } catch (error) {
            if (updatedLabel) updatedLabel.textContent = `Refresh issue: ${error.message}`;
        } finally {
            refreshButton?.classList.remove('is-refreshing');
            refreshButton && (refreshButton.disabled = false);
        }
    }

    root.addEventListener('click', async (event) => {
        const resolveButton = event.target.closest('[data-resolve-owner-error]');
        if (!resolveButton) return;
        resolveButton.disabled = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'resolve_owner_error', id: resolveButton.dataset.resolveOwnerError})
            });
            const payload = await response.json();
            if (!payload.ok) throw new Error(payload.error || 'Could not resolve error');
            await loadDashboard();
        } catch (error) {
            resolveButton.textContent = error.message;
        }
    });

    refreshButton?.addEventListener('click', loadDashboard);
    fromInput?.addEventListener('change', loadDashboard);
    toInput?.addEventListener('change', loadDashboard);
    render(window.HambelelaOwnerDashboard || {});
    window.setInterval(loadDashboard, 300000);
})();
</script>
<?php endif; ?>
<?php include BASE_PATH . '/shared/footer.php'; ?>
