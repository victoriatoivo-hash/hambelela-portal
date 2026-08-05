<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('dee6041f8c5e0d827d77c417d14bebb3', (string)($_GET['token'] ?? ''))) { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/database.php';
try {
    $sql = (string)file_get_contents(__DIR__ . '/operations-epi-courier-performance-migration.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [])) as $statement) {
        if ($statement === '' || strpos(ltrim($statement), '--') === 0 && strpos($statement, "\n") === false) continue;
        db()->exec($statement);
    }
    foreach (['courier_module_enabled','courier_packer_close_weekday','courier_exception_grace_time','courier_front_desk_send_deadline','courier_front_desk_response_minutes'] as $key) {
        $stmt=db()->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$stmt->execute([$key]);
        echo $key . '=' . (string)$stmt->fetchColumn() . "\n";
    }
    $stmt=db()->query("SELECT grace_key,minutes FROM epi_employee_grace_periods WHERE module='Courier' ORDER BY grace_key");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['grace_key'].'='.$row['minutes']."\n";
    echo "MIGRATION_OK\n";
} catch (Throwable $e) { http_response_code(500); echo 'ERROR: '.$e->getMessage(); }
