<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('23e1ec8d18fb43c19facc4a3462b6d8e', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit('Not found'); }
try {
    require_once __DIR__ . '/shared/database.php';
    $sql = (string) file_get_contents(__DIR__ . '/operations-epi-quality-migration.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) db()->exec($statement);
    $expected = ['epi_quality_categories','epi_quality_severities','epi_quality_error_profiles','epi_quality_status_history','epi_quality_owner_reviews','epi_quality_responsibility_allocations','epi_quality_financial_impacts','epi_quality_corrective_actions','epi_quality_record_links','epi_quality_root_causes','epi_quality_repeat_reviews','epi_quality_exceptions'];
    foreach ($expected as $table) {
        $stmt = db()->query('SHOW TABLES LIKE ' . db()->quote($table));
        echo $table . ': ' . ($stmt->fetchColumn() ? 'EXISTS' : 'MISSING') . PHP_EOL;
    }
} catch (Throwable $error) { http_response_code(500); echo 'ERROR: ' . $error->getMessage(); }
