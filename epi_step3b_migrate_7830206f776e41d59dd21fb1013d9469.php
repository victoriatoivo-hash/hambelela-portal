<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('caf50c9e585947dd96fee412216d1f17', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit('Not found'); }
try {
    require_once __DIR__ . '/shared/database.php';
    $sql = (string) file_get_contents(__DIR__ . '/operations-epi-recovery-step3b-migration.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) db()->exec($statement);
    $tables = ['epi_role_required_sources','epi_monthly_source_completeness','epi_evidence_eligibility_audits','epi_score_superseding_corrections'];
    foreach ($tables as $table) { $stmt=db()->query('SHOW TABLES LIKE '.db()->quote($table));echo $table.': '.($stmt->fetchColumn()?'EXISTS':'MISSING').PHP_EOL; }
    foreach ([['epi_employee_evidence','eligibility_state'],['epi_scoring_monthly_scores','result_type'],['epi_scoring_monthly_scores','official_score_hundredths'],['epi_employee_monthly_category_scores','calculation_status']] as $column) {
        $stmt=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute($column);echo implode('.',$column).': '.((int)$stmt->fetchColumn()>0?'EXISTS':'MISSING').PHP_EOL;
    }
    echo 'role_source_mappings: '.(int)db()->query('SELECT COUNT(*) FROM epi_role_required_sources')->fetchColumn().PHP_EOL;
} catch (Throwable $error) { http_response_code(500); echo 'ERROR: '.$error->getMessage(); }
