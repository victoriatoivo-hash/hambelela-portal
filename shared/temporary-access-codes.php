<?php

declare(strict_types=1);

function temporary_access_column_exists(PDO $database, string $column): bool
{
    try {
        $stmt = $database->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute(['ops_employees', $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        error_log('Temporary access schema inspection failed: ' . $error->getMessage());
        return false;
    }
}
function ensure_temporary_access_schema(PDO $database): void
{
    $columns = [
        'temporary_code_hash' => 'VARCHAR(255) NULL',
        'temporary_code_expires_at' => 'DATETIME NULL',
        'temporary_code_used_at' => 'DATETIME NULL',
        'must_change_access_code' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'failed_login_attempts' => 'INT NOT NULL DEFAULT 0',
        'locked_until' => 'DATETIME NULL',
        'last_failed_login_at' => 'DATETIME NULL',
        'code_changed_at' => 'DATETIME NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!temporary_access_column_exists($database, $column)) {
            $database->exec("ALTER TABLE ops_employees ADD COLUMN {$column} {$definition}");
        }
    }
}

function temporary_access_schema_ready(PDO $database): bool
{
    foreach (['temporary_code_hash', 'temporary_code_expires_at', 'temporary_code_used_at', 'must_change_access_code'] as $column) {
        if (!temporary_access_column_exists($database, $column)) {
            return false;
        }
    }
    return true;
}

function temporary_access_account(PDO $database, int $employeeId): ?array
{
    if ($employeeId < 1 || !temporary_access_schema_ready($database)) {
        return null;
    }

    $stmt = $database->prepare(
        'SELECT temporary_code_hash, temporary_code_expires_at, temporary_code_used_at, must_change_access_code
         FROM ops_employees
         WHERE id = ? AND status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([$employeeId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    return $account ?: null;
}
