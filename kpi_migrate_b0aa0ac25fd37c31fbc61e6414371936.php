<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';
header('Content-Type: text/plain; charset=utf-8');
const KPI_SCHEDULE_MIGRATION_TOKEN = 'b0aa0ac25fd37c31fbc61e6414371936';
if (!isset($_GET['token']) || !hash_equals(KPI_SCHEDULE_MIGRATION_TOKEN, (string) $_GET['token'])) { http_response_code(404); exit("Not found\n"); }
try {
    db()->exec("CREATE TABLE IF NOT EXISTS kpi_employee_schedule_versions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id INT NOT NULL,
      effective_from DATE NOT NULL,
      effective_to DATE NULL,
      timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Windhoek',
      lunch_start TIME NULL,
      lunch_end TIME NULL,
      grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
      change_reason VARCHAR(255) NULL,
      created_by INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_schedule_version_employee_dates (employee_id,effective_from,effective_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS kpi_employee_schedule_days (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      schedule_version_id BIGINT UNSIGNED NOT NULL,
      weekday TINYINT UNSIGNED NOT NULL,
      is_working TINYINT(1) NOT NULL DEFAULT 0,
      shift_start TIME NULL,
      shift_end TIME NULL,
      PRIMARY KEY (id), UNIQUE KEY uq_schedule_version_weekday (schedule_version_id,weekday),
      KEY idx_schedule_day_version (schedule_version_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['kpi_employee_schedule_versions','kpi_employee_schedule_days'] as $table) {
      $count=(int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".db()->quote($table))->fetchColumn();
      echo $table.': '.($count===1?'TABLE_OK':'TABLE_MISSING')."\n";
    }
    echo "MIGRATION_OK\n";
} catch (Throwable $error) { http_response_code(500); echo 'MIGRATION_FAILED: '.$error->getMessage()."\n"; }
