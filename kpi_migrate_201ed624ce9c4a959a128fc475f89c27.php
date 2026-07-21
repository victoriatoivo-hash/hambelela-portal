<?php

declare(strict_types=1);

$expectedToken = 'b254728b34474e299ccbd717e6f259be';
if (!hash_equals($expectedToken, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

function migration_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function migration_index_exists(string $table, string $index): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function migration_add_column(string $table, string $column, string $definition): void
{
    if (!migration_column_exists($table, $column)) {
        db()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

$errors = [];
$run = static function (string $label, string $sql) use (&$errors): void {
    try {
        db()->exec($sql);
        echo "OK: {$label}\n";
    } catch (Throwable $error) {
        $errors[] = $label . ': ' . $error->getMessage();
        echo "FAILED: {$label}: {$error->getMessage()}\n";
    }
};

echo "KPI V2 PHASE 0 MIGRATION\n";
$run('kpi_status_events table', "CREATE TABLE IF NOT EXISTS kpi_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    module ENUM('order','packing','waybill','task','bookkeeping','website_update') NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kpi_event_record (module, record_id),
    INDEX idx_kpi_event_actor_time (changed_by, changed_at),
    INDEX idx_kpi_event_status_time (module, new_status, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$run('kpi_sessions table', "CREATE TABLE IF NOT EXISTS kpi_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_token CHAR(64) NULL,
    user_id INT UNSIGNED NOT NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logout_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kpi_session_user_login (user_id, login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$run('kpi_settings table', "CREATE TABLE IF NOT EXISTS kpi_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$run('kpi_holidays table', "CREATE TABLE IF NOT EXISTS kpi_holidays (
    holiday_date DATE NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    migration_add_column('kpi_sessions', 'session_token', 'CHAR(64) NULL');
    migration_add_column('kpi_holidays', 'name', 'VARCHAR(100) NULL');
    migration_add_column('kpi_holidays', 'holiday_name', 'VARCHAR(160) NULL');
    migration_add_column('kpi_holidays', 'active', 'TINYINT(1) NOT NULL DEFAULT 1');
    migration_add_column('ops_packing_tasks', 'weight_class', "ENUM('S','M','L','XL') NOT NULL DEFAULT 'M'");
    migration_add_column('ops_packing_tasks', 'unit_weight_kg', 'DECIMAL(8,3) NULL');
    migration_add_column('ops_employees', 'hire_date', 'DATE NULL');
    migration_add_column('ops_employees', 'working_days', 'VARCHAR(30) NULL');
    migration_add_column('ops_employees', 'shift_start', 'TIME NULL');
    migration_add_column('ops_employees', 'shift_end', 'TIME NULL');
    migration_add_column('ops_employees', 'late_grace_minutes', 'INT NOT NULL DEFAULT 10');
    migration_add_column('ops_error_logs', 'cause', "ENUM('employee','process','system','supplier') NOT NULL DEFAULT 'process'");
    db()->exec("UPDATE kpi_holidays SET name = holiday_name WHERE (name IS NULL OR name = '') AND holiday_name IS NOT NULL");
    db()->exec("UPDATE kpi_holidays SET holiday_name = name WHERE (holiday_name IS NULL OR holiday_name = '') AND name IS NOT NULL");
    echo "OK: guarded column additions\n";
} catch (Throwable $error) {
    $errors[] = 'guarded column additions: ' . $error->getMessage();
    echo 'FAILED: guarded column additions: ' . $error->getMessage() . "\n";
}

$run('settings key/value types', 'ALTER TABLE kpi_settings MODIFY setting_key VARCHAR(64) NOT NULL, MODIFY setting_value TEXT NOT NULL');
if (!migration_index_exists('kpi_sessions', 'idx_kpi_session_user_login')) $run('session user/login index', 'CREATE INDEX idx_kpi_session_user_login ON kpi_sessions (user_id, login_at)');
if (!migration_index_exists('kpi_status_events', 'idx_kpi_event_record')) $run('event module/record index', 'CREATE INDEX idx_kpi_event_record ON kpi_status_events (module, record_id)');
if (!migration_index_exists('kpi_status_events', 'idx_kpi_event_actor_time')) $run('event actor/time index', 'CREATE INDEX idx_kpi_event_actor_time ON kpi_status_events (changed_by, changed_at)');
if (!migration_index_exists('kpi_status_events', 'idx_kpi_event_status_time')) $run('event status/time index', 'CREATE INDEX idx_kpi_event_status_time ON kpi_status_events (module, new_status, changed_at)');

$settings = [
    'data_start_date' => '2026-07-01', 'adoption_date' => '2026-07-14',
    'target_fulfilment_hours' => '6', 'on_time_dispatch_hours' => '6',
    'waybill_overdue_hours' => '24', 'website_update_lag_target_minutes' => '60',
    'stale_work_days' => '2', 'weight_points_s' => '1', 'weight_points_m' => '3',
    'weight_points_l' => '6', 'weight_points_xl' => '10', 'working_days_per_week' => '5',
    'default_shift_start' => '08:00', 'default_shift_end' => '17:00',
    'late_grace_minutes' => '10', 'composite_score_enabled' => '0',
];
$settingStmt = db()->prepare('INSERT INTO kpi_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
foreach ($settings as $key => $value) $settingStmt->execute([$key, $value]);
echo "OK: settings seeded\n";

$holidays = [
    '2026-01-01' => "New Year's Day", '2026-03-21' => 'Independence Day',
    '2026-04-03' => 'Good Friday', '2026-04-06' => 'Easter Monday',
    '2026-05-01' => "Workers' Day", '2026-05-04' => 'Cassinga Day observed',
    '2026-05-14' => 'Ascension Day', '2026-05-25' => 'Africa Day',
    '2026-08-26' => "Heroes' Day", '2026-09-10' => 'Genocide Remembrance Day',
    '2026-12-10' => 'Human Rights Day', '2026-12-25' => 'Christmas Day',
    '2026-12-26' => 'Family Day',
];
$holidayStmt = db()->prepare('INSERT INTO kpi_holidays (holiday_date, name, holiday_name, active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), holiday_name = VALUES(holiday_name), active = 1');
foreach ($holidays as $date => $name) $holidayStmt->execute([$date, $name, $name]);
echo "OK: holidays seeded\n\n";

$tables = ['kpi_status_events', 'kpi_sessions', 'kpi_settings', 'kpi_holidays'];
foreach ($tables as $table) {
    echo "=== DESCRIBE {$table} ===\n";
    foreach (db()->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo implode(' | ', [$row['Field'], $row['Type'], $row['Null'], $row['Key'], (string) $row['Default'], $row['Extra']]) . "\n";
    }
}

foreach (['ops_packing_tasks' => ['weight_class','unit_weight_kg'], 'ops_employees' => ['hire_date','working_days','shift_start','shift_end','late_grace_minutes'], 'ops_error_logs' => ['cause']] as $table => $columns) {
    echo "=== REQUIRED COLUMNS {$table} ===\n";
    foreach ($columns as $column) echo $column . ': ' . (migration_column_exists($table, $column) ? 'EXISTS' : 'MISSING') . "\n";
}

$existing = db()->query("SHOW TABLES LIKE 'kpi_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) echo $table . ': ' . (in_array($table, $existing, true) ? 'EXISTS' : 'MISSING') . "\n";
echo 'RESULT: ' . ($errors ? 'FAILED' : 'PASS') . "\n";
if ($errors) http_response_code(500);
