<?php

declare(strict_types=1);

/**
 * Phase 1 KPI data foundation. All helpers are deliberately fail-safe: reporting
 * telemetry must never interrupt an operational save or a login/logout flow.
 */
function kpi_foundation_try_sql(string $sql): void
{
    try {
        require_once __DIR__ . '/database.php';
        db()->exec($sql);
    } catch (Throwable $e) {
        // Telemetry schema upgrades are retried on the next eligible request.
    }
}

function kpi_foundation_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        require_once __DIR__ . '/database.php';
        $version = db()->query("SELECT setting_value FROM kpi_settings WHERE setting_key = '_foundation_schema_version' LIMIT 1")->fetchColumn();
        if ((string) $version === '1') return;
    } catch (Throwable $e) {
        // First run: the canonical settings table may not exist yet.
    }

    kpi_foundation_try_sql("CREATE TABLE IF NOT EXISTS kpi_status_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        module ENUM('order','packing','waybill','task','bookkeeping','website_update') NOT NULL,
        record_id BIGINT NOT NULL,
        old_status VARCHAR(50) NULL,
        new_status VARCHAR(50) NOT NULL,
        changed_by INT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_kpi_event_record (module, record_id),
        INDEX idx_kpi_event_actor_time (changed_by, changed_at),
        INDEX idx_kpi_event_status_time (module, new_status, changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    kpi_foundation_try_sql("CREATE TABLE IF NOT EXISTS kpi_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        session_token CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        logout_at DATETIME NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kpi_session_token (session_token),
        INDEX idx_kpi_session_user_login (user_id, login_at),
        INDEX idx_kpi_session_open (logout_at, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    kpi_foundation_try_sql("CREATE TABLE IF NOT EXISTS kpi_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    kpi_foundation_try_sql("CREATE TABLE IF NOT EXISTS kpi_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL,
        holiday_name VARCHAR(160) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_kpi_holiday_date (holiday_date),
        INDEX idx_kpi_holiday_active_date (active, holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'data_start_date' => '2026-07-01', 'adoption_date' => '2026-07-14',
        'target_fulfilment_hours' => '6', 'on_time_dispatch_target_hours' => '6',
        'waybill_overdue_threshold_hours' => '24', 'website_update_lag_target_minutes' => '60',
        'stale_work_threshold_days' => '2', 'weight_points_s' => '1',
        'weight_points_m' => '3', 'weight_points_l' => '6', 'weight_points_xl' => '10',
        'working_days' => '1,2,3,4,5', 'shift_start' => '08:00', 'shift_end' => '17:00',
        'late_grace_minutes' => '10', 'composite_score_enabled' => '0',
    ] as $key => $value) {
        try {
            $stmt = db()->prepare('INSERT IGNORE INTO kpi_settings (setting_key, setting_value) VALUES (?, ?)');
            $stmt->execute([$key, $value]);
        } catch (Throwable $e) {}
    }

    foreach ([
        '2026-01-01' => "New Year's Day", '2026-03-21' => 'Independence Day',
        '2026-04-03' => 'Good Friday', '2026-04-06' => 'Easter Monday',
        '2026-05-01' => "Workers' Day", '2026-05-04' => 'Cassinga Day observed',
        '2026-05-14' => 'Ascension Day', '2026-05-25' => 'Africa Day',
        '2026-08-26' => "Heroes' Day", '2026-09-10' => 'Genocide Remembrance Day',
        '2026-12-10' => 'Human Rights Day', '2026-12-25' => 'Christmas Day',
        '2026-12-26' => 'Family Day',
    ] as $date => $name) {
        try {
            $stmt = db()->prepare('INSERT IGNORE INTO kpi_holidays (holiday_date, holiday_name) VALUES (?, ?)');
            $stmt->execute([$date, $name]);
        } catch (Throwable $e) {}
    }

    foreach ([
        "ALTER TABLE ops_packing_tasks ADD COLUMN weight_class ENUM('S','M','L','XL') NOT NULL DEFAULT 'M'",
        "ALTER TABLE ops_packing_tasks ADD COLUMN unit_weight_kg DECIMAL(8,3) NULL",
        "ALTER TABLE ops_employees ADD COLUMN hire_date DATE NULL",
        "ALTER TABLE ops_employees ADD COLUMN working_days VARCHAR(30) NULL",
        "ALTER TABLE ops_employees ADD COLUMN shift_start TIME NULL",
        "ALTER TABLE ops_employees ADD COLUMN shift_end TIME NULL",
        "ALTER TABLE ops_employees ADD COLUMN late_grace_minutes INT NOT NULL DEFAULT 10",
        "ALTER TABLE ops_error_logs ADD COLUMN cause ENUM('employee','process','system','supplier') NOT NULL DEFAULT 'process'",
    ] as $sql) kpi_foundation_try_sql($sql);

    kpi_foundation_try_sql('CREATE INDEX idx_packing_kpi_status_time ON ops_packing_tasks (packing_status, date_loaded)');
    kpi_foundation_try_sql('CREATE INDEX idx_packing_kpi_employee_time ON ops_packing_tasks (assigned_employee_id, date_completed)');
    kpi_foundation_try_sql('CREATE INDEX idx_orders_kpi_status_time ON ops_orders (status, created_at)');
    kpi_foundation_try_sql('CREATE INDEX idx_tasks_kpi_status_time ON ops_checklist_tasks (status, created_at)');
    try {
        db()->exec("INSERT INTO kpi_settings (setting_key, setting_value) VALUES ('_foundation_schema_version', '1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    } catch (Throwable $e) {}
}

function kpi_foundation_actor_id(): ?int
{
    $user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? []);
    $id = (int) ($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function kpi_foundation_log_status(string $module, int $recordId, ?string $oldStatus, string $newStatus, ?int $actorId = null): void
{
    if (!in_array($module, ['order','packing','waybill','task','bookkeeping','website_update'], true) || $recordId <= 0) return;
    $oldStatus = $oldStatus === null ? null : substr(trim($oldStatus), 0, 50);
    $newStatus = substr(trim($newStatus), 0, 50);
    if ($newStatus === '' || ($oldStatus !== null && $oldStatus === $newStatus)) return;
    try {
        kpi_foundation_bootstrap();
        $stmt = db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([$module, $recordId, $oldStatus, $newStatus, $actorId ?: kpi_foundation_actor_id()]);
    } catch (Throwable $e) {}
}

function kpi_foundation_open_session(int $userId): void
{
    if ($userId <= 0) return;
    try {
        kpi_foundation_bootstrap();
        $token = hash('sha256', session_id() . '|' . $userId . '|' . bin2hex(random_bytes(16)));
        $stmt = db()->prepare('INSERT INTO kpi_sessions (session_token, user_id, login_at, last_seen_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([$token, $userId]);
        $_SESSION['kpi_session_token'] = $token;
    } catch (Throwable $e) {}
}

function kpi_foundation_heartbeat(?int $userId = null): void
{
    try {
        kpi_foundation_bootstrap();
        $token = (string) ($_SESSION['kpi_session_token'] ?? '');
        if ($token !== '') db()->prepare('UPDATE kpi_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE session_token = ? AND logout_at IS NULL')->execute([$token]);
        db()->exec("UPDATE kpi_sessions SET logout_at = DATE_ADD(last_seen_at, INTERVAL 2 MINUTE) WHERE logout_at IS NULL AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
    } catch (Throwable $e) {}
}

function kpi_foundation_close_session(): void
{
    try {
        $token = (string) ($_SESSION['kpi_session_token'] ?? '');
        if ($token !== '') db()->prepare('UPDATE kpi_sessions SET last_seen_at = UTC_TIMESTAMP(), logout_at = UTC_TIMESTAMP() WHERE session_token = ? AND logout_at IS NULL')->execute([$token]);
    } catch (Throwable $e) {}
}
