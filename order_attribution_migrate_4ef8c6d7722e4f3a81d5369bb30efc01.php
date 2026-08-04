<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/shared/database.php';
header('Content-Type: text/plain; charset=utf-8');
const TOKEN='4ef8c6d7722e4f3a81d5369bb30efc01';
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(404);exit("Not found\n");}
try{
db()->exec("CREATE TABLE IF NOT EXISTS ops_order_attribution_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 order_id INT NOT NULL,
 classification ENUM('system_confirmed','staff_confirmation_required','unable_to_confirm','not_applicable') NOT NULL,
 possible_packer_id INT NULL,
 confirmed_packer_id INT NULL,
 confirmation_obtained_from INT NULL,
 supporting_note VARCHAR(1500) NULL,
 source_evidence_json LONGTEXT NULL,
 assignment_method VARCHAR(80) NULL,
 policy_applies TINYINT(1) NOT NULL DEFAULT 0,
 compliance_result ENUM('compliant','missed_attribution','excluded','pending') NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL,
 reviewed_at DATETIME NULL,
 restored_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uniq_order_attribution_review(order_id),
 KEY idx_attribution_classification(classification,created_at), KEY idx_attribution_packer(confirmed_packer_id,compliance_result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
db()->exec("INSERT INTO kpi_settings(setting_key,setting_value) VALUES ('packed_by_compliance_effective_date','2026-08-04') ON DUPLICATE KEY UPDATE setting_value=setting_value");
foreach(['ops_order_attribution_reviews','ops_orders','ops_order_stage_events','ops_activity_logs','kpi_status_events','kpi_activity_events']as$table){$ok=(int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".db()->quote($table))->fetchColumn();echo $table.': '.($ok?'TABLE_OK':'TABLE_MISSING')."\n";}
$summary=db()->query("SELECT COUNT(*) total,SUM(classification='system_confirmed') system_confirmed,SUM(classification='staff_confirmation_required') staff_confirmation_required,SUM(classification='unable_to_confirm') unable_to_confirm,SUM(classification='not_applicable') not_applicable,SUM(restored_at IS NOT NULL) restored,SUM(compliance_result='excluded') excluded FROM ops_order_attribution_reviews")->fetch(PDO::FETCH_ASSOC);echo 'SUMMARY: '.json_encode($summary,JSON_UNESCAPED_SLASHES)."\n";
foreach([__DIR__.'/error_log',__DIR__.'/apps/operations/error_log',__DIR__.'/includes/error_log']as$log){if(!is_readable($log))continue;$lines=file($log,FILE_IGNORE_NEW_LINES);echo "LOG {$log}:\n".implode("\n",array_slice($lines?:[],-30))."\n";}
echo "MIGRATION_OK\n";
}catch(Throwable $e){http_response_code(500);echo 'MIGRATION_FAILED: '.$e->getMessage()."\n";}
