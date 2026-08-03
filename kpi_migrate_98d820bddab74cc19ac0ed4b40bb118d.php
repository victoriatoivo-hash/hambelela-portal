<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

header('Content-Type: text/plain; charset=utf-8');

const KPI_MIGRATION_TOKEN = '98d820bddab74cc19ac0ed4b40bb118d';

if (!isset($_GET['token']) || !hash_equals(KPI_MIGRATION_TOKEN, (string) $_GET['token'])) {
    http_response_code(404);
    exit("Not found\n");
}

function kpi_add_column(string $table, string $column, string $definition): void
{
    $exists = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute([$table, $column]);
    if ((int) $exists->fetchColumn() === 0) {
        db()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

$columns = [
    'ops_packing_tasks' => [
        'workload_package_count' => 'DECIMAL(12,2) NULL AFTER workload_points',
        'workload_weight_grams' => 'DECIMAL(14,2) NULL AFTER workload_package_count',
        'workload_volume_ml' => 'DECIMAL(14,2) NULL AFTER workload_weight_grams',
        'workload_unit_count' => 'DECIMAL(12,2) NULL AFTER workload_volume_ml',
        'workload_parse_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending_review' AFTER workload_unit_count",
        'workload_breakdown_json' => 'TEXT NULL AFTER workload_parse_status',
        'workload_points_override' => 'DECIMAL(10,2) NULL AFTER workload_breakdown_json',
        'workload_override_reason' => 'VARCHAR(500) NULL AFTER workload_points_override',
        'workload_override_by' => 'INT NULL AFTER workload_override_reason',
        'workload_override_at' => 'DATETIME NULL AFTER workload_override_by',
    ],
    'ops_error_logs' => [
        'responsible_employee_id' => 'INT NULL AFTER employee_id',
        'packing_task_id' => 'INT NULL AFTER order_id',
        'affects_kpi_accuracy' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER packing_task_id',
        'accuracy_verified_by' => 'INT NULL AFTER affects_kpi_accuracy',
        'accuracy_verified_at' => 'DATETIME NULL AFTER accuracy_verified_by',
    ],
];

try {
    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $definition) {
            kpi_add_column($table, $column, $definition);
        }
    }

    foreach ($columns as $table => $tableColumns) {
        echo $table . "\n";
        $verify = db()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('
            . implode(',', array_fill(0, count($tableColumns), '?')) . ') ORDER BY ORDINAL_POSITION'
        );
        $verify->execute(array_merge([$table], array_keys($tableColumns)));
        foreach ($verify->fetchAll(PDO::FETCH_COLUMN) as $column) {
            echo '  OK ' . $column . "\n";
        }
    }
    echo "MIGRATION_OK\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo 'MIGRATION_FAILED: ' . $error->getMessage() . "\n";
}
