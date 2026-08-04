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
    $employees=db()->query("SELECT id,full_name FROM ops_employees WHERE status='active' AND full_name IN ('Secilia Shiweda','Klaudia Averinus','Ndinelao Kalola') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $versionFind=db()->prepare('SELECT id FROM kpi_employee_schedule_versions WHERE employee_id=? AND effective_from=? LIMIT 1');
    $versionInsert=db()->prepare("INSERT INTO kpi_employee_schedule_versions (employee_id,effective_from,effective_to,timezone,lunch_start,lunch_end,grace_minutes,change_reason,created_by) VALUES (?,'2026-08-04',NULL,'Africa/Windhoek','12:00:00','13:00:00',10,'Initial verified business schedule; Saturday rotation pending individual confirmation',NULL)");
    $dayInsert=db()->prepare('INSERT INTO kpi_employee_schedule_days (schedule_version_id,weekday,is_working,shift_start,shift_end) VALUES (?,?,?,?,?)');
    foreach($employees as $employee){
      $versionFind->execute([(int)$employee['id'],'2026-08-04']);$versionId=(int)$versionFind->fetchColumn();
      if($versionId===0){$versionInsert->execute([(int)$employee['id']]);$versionId=(int)db()->lastInsertId();for($weekday=1;$weekday<=7;$weekday++)$dayInsert->execute([$versionId,$weekday,$weekday<=5?1:0,$weekday<=5?'08:00:00':null,$weekday<=5?'17:00:00':null]);}
      echo 'SCHEDULE_OK: '.$employee['full_name']." (Mon-Fri 08:00-17:00; Sat/Sun rest)\n";
    }
    if(count($employees)!==3)throw new RuntimeException('Expected all three active employees; found '.count($employees));
    echo "MIGRATION_OK\n";
} catch (Throwable $error) { http_response_code(500); echo 'MIGRATION_FAILED: '.$error->getMessage()."\n"; }
