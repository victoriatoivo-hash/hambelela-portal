<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

header('Content-Type: text/plain; charset=utf-8');

const KPI_MIGRATION_TOKEN = '41f62355b0724cbfa9a8163c5d4e9f22';

if (!isset($_GET['token']) || !hash_equals(KPI_MIGRATION_TOKEN, (string) $_GET['token'])) {
    http_response_code(404);
    exit("Not found\n");
}

try {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS kpi_employee_schedules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id INT NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL,
            is_working TINYINT(1) NOT NULL DEFAULT 1,
            shift_start TIME NULL,
            shift_end TIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_kpi_employee_weekday (employee_id, weekday),
            KEY idx_kpi_schedule_employee (employee_id),
            CONSTRAINT chk_kpi_schedule_weekday CHECK (weekday BETWEEN 1 AND 7)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $verify = db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_employee_schedules'"
    );
    $columns = db()->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_employee_schedules'
         ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_COLUMN);
    echo ((int) $verify->fetchColumn() === 1 ? "TABLE_OK\n" : "TABLE_MISSING\n");
    foreach ($columns as $column) {
        echo '  OK ' . $column . "\n";
    }
    echo count($columns) === 8 ? "MIGRATION_OK\n" : "MIGRATION_INCOMPLETE\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo 'MIGRATION_FAILED: ' . $error->getMessage() . "\n";
}
