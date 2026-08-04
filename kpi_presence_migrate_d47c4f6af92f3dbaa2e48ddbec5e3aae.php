<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';
header('Content-Type: text/plain; charset=utf-8');
const KPI_PRESENCE_MIGRATION_TOKEN = 'd47c4f6af92f3dbaa2e48ddbec5e3aae';
if (!isset($_GET['token']) || !hash_equals(KPI_PRESENCE_MIGRATION_TOKEN, (string) $_GET['token'])) { http_response_code(404); exit("Not found\n"); }
try {
    $columns = db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kpi_sessions'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('explicit_logout_at', $columns, true)) db()->exec('ALTER TABLE kpi_sessions ADD COLUMN explicit_logout_at DATETIME NULL AFTER logout_at');
    if (!in_array('session_expired_at', $columns, true)) db()->exec('ALTER TABLE kpi_sessions ADD COLUMN session_expired_at DATETIME NULL AFTER explicit_logout_at');
    if (!in_array('end_reason', $columns, true)) db()->exec("ALTER TABLE kpi_sessions ADD COLUMN end_reason ENUM('explicit_logout','inactive_expiry') NULL AFTER session_expired_at");
    db()->exec("CREATE TABLE IF NOT EXISTS kpi_portal_presence_reviews (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id INT NOT NULL,
      evidence_date DATE NOT NULL,
      classification ENUM('confirmed_late_portal_start','confirmed_work_away','approved_break','approved_absence','rest_day','public_holiday','portal_outage','schedule_change','unexplained','no_performance_impact') NOT NULL,
      owner_note VARCHAR(1000) NOT NULL,
      score_effect ENUM('positive','negative','excluded','none') NOT NULL DEFAULT 'none',
      source_snapshot_json LONGTEXT NULL,
      reviewed_by INT NOT NULL,
      reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_presence_review_employee_date (employee_id,evidence_date,reviewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['kpi_sessions','kpi_portal_presence_reviews'] as $table) {
      $count=(int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".db()->quote($table))->fetchColumn();
      echo $table.': '.($count===1?'TABLE_OK':'TABLE_MISSING')."\n";
    }
    $coverage=db()->query("SELECT MIN(login_at) first_session,MAX(COALESCE(logout_at,last_seen_at)) last_session,COUNT(*) session_rows,COUNT(DISTINCT user_id) mapped_employees FROM kpi_sessions")->fetch(PDO::FETCH_ASSOC);
    echo 'COVERAGE: '.json_encode($coverage,JSON_UNESCAPED_SLASHES)."\n";
    $people=db()->query("SELECT s.user_id,e.full_name,COUNT(*) session_rows,MIN(s.login_at) first_session,MAX(COALESCE(s.logout_at,s.last_seen_at)) last_session FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id=s.user_id GROUP BY s.user_id,e.full_name ORDER BY s.user_id")->fetchAll(PDO::FETCH_ASSOC);
    echo 'IDENTITY_MAP: '.json_encode($people,JSON_UNESCAPED_SLASHES)."\n";
    echo "MIGRATION_OK\n";
} catch (Throwable $error) { http_response_code(500); echo 'MIGRATION_FAILED: '.$error->getMessage()."\n"; }
