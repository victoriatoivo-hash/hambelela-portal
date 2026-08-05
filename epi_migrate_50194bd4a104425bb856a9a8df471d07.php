<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!hash_equals('1a32142181dd455a922af4bad9eac65d', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

require_once __DIR__ . '/shared/database.php';

try {
    $sql = file_get_contents(__DIR__ . '/operations-epi-foundation-migration.sql');
    if ($sql === false) {
        throw new RuntimeException('Migration file is unavailable.');
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        db()->exec($statement);
        $executed++;
    }
    $tables = db()->query("SHOW TABLES LIKE 'epi_%'")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    $flag = db()->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key = 'epi_enabled' LIMIT 1")->fetchColumn();
    echo json_encode(['ok' => true, 'executed' => $executed, 'tables' => $tables, 'epi_enabled' => (string) $flag], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
