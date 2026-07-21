<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = '865d155c2b1b41498aac53025cb08b67';
$providedToken = (string) ($_GET['token'] ?? '');
if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

$statements = [
    "CREATE TABLE IF NOT EXISTS kpi_status_events (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS kpi_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        session_token CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        logout_at DATETIME NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kpi_session_token (session_token),
        INDEX idx_kpi_session_user_login (user_id, login_at),
        INDEX idx_kpi_session_open (logout_at, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS kpi_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS kpi_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL,
        holiday_name VARCHAR(160) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_kpi_holiday_date (holiday_date),
        INDEX idx_kpi_holiday_active_date (active, holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

echo "KPI PHASE 1 MIGRATION\n";
try {
    foreach ($statements as $statement) {
        db()->exec($statement);
    }
    $required = ['kpi_status_events', 'kpi_sessions', 'kpi_settings', 'kpi_holidays'];
    $query = db()->prepare(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN (?, ?, ?, ?)
         ORDER BY table_name"
    );
    $query->execute($required);
    $existing = $query->fetchAll(PDO::FETCH_COLUMN);
    foreach ($required as $table) {
        echo $table . ': ' . (in_array($table, $existing, true) ? 'EXISTS' : 'MISSING') . "\n";
    }
    echo 'Verified count: ' . count($existing) . '/4' . "\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo 'MIGRATION FAILED: ' . $error->getMessage() . "\n";
}
