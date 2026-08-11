<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

function error_instructions_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS ops_error_instructions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            error_id INT NOT NULL,
            instruction_text TEXT NOT NULL,
            created_by_user_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            completed_by_user_id INT NULL,
            completion_note TEXT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_error_instruction_history (error_id, created_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $instructionColumns = [
            'status' => "ALTER TABLE ops_error_instructions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER created_by_user_id",
            'completed_by_user_id' => "ALTER TABLE ops_error_instructions ADD COLUMN completed_by_user_id INT NULL AFTER status",
            'completion_note' => "ALTER TABLE ops_error_instructions ADD COLUMN completion_note TEXT NULL AFTER completed_by_user_id",
            'completed_at' => "ALTER TABLE ops_error_instructions ADD COLUMN completed_at DATETIME NULL AFTER completion_note",
        ];
        foreach ($instructionColumns as $column => $alterSql) {
            $check = db()->prepare("SHOW COLUMNS FROM ops_error_instructions LIKE ?");
            $check->execute([$column]);
            $exists = (bool) $check->fetchColumn();
            $check->closeCursor();
            if (!$exists) db()->exec($alterSql);
        }
        db()->exec("CREATE TABLE IF NOT EXISTS ops_error_instruction_reads (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            instruction_id BIGINT NOT NULL,
            recipient_user_id INT NOT NULL,
            notification_id INT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_error_instruction_recipient (instruction_id, recipient_user_id),
            INDEX idx_error_instruction_unread (recipient_user_id, read_at, instruction_id),
            CONSTRAINT fk_error_instruction_read_instruction FOREIGN KEY (instruction_id)
                REFERENCES ops_error_instructions(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $error) {
        error_log('Error instruction schema unavailable: ' . $error->getMessage());
        $ready = false;
    }
    return $ready;
}

function error_instruction_error(int $errorId): ?array
{
    if ($errorId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$errorId]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    return is_array($row) ? $row : null;
}

function error_instruction_front_person_ids(array $error): array
{
    $candidateIds = [];
    $loggedBy = (int) ($error['logged_by'] ?? 0);
    if ($loggedBy > 0) $candidateIds[] = $loggedBy;
    $people = json_decode((string) ($error['people_involved'] ?? ''), true);
    if (is_array($people)) foreach ($people as $id) if ((int) $id > 0) $candidateIds[] = (int) $id;
    foreach (['employee_id', 'responsible_employee_id', 'attributed_employee_id'] as $field) {
        if ((int) ($error[$field] ?? 0) > 0) $candidateIds[] = (int) $error[$field];
    }
    $candidateIds = array_values(array_unique($candidateIds));
    if ($candidateIds) {
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $stmt = db()->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
            WHERE e.status='active' AND r.role_key IN ('front_desk_admin','front_desk_admin_employee')
              AND e.id IN ({$placeholders}) ORDER BY FIELD(e.id, {$placeholders})");
        $stmt->execute(array_merge($candidateIds, $candidateIds));
        $frontIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();
        // The front person who logged the error is the primary handler.
        if ($loggedBy > 0 && in_array($loggedBy, $frontIds, true)) return [$loggedBy];
        if ($frontIds) return $frontIds;
    }
    return notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee']);
}

function error_instruction_user_can_view(array $error, int $userId, string $roleKey): bool
{
    if ($userId <= 0) return false;
    if ($roleKey === 'owner_admin') return true;
    if (!in_array($roleKey, ['front_desk_admin', 'front_desk_admin_employee'], true)) return false;
    return in_array($userId, error_instruction_front_person_ids($error), true);
}

function error_instructions_for_errors(array $errorIds): array
{
    $result = [];
    $errorIds = array_values(array_unique(array_filter(array_map('intval', $errorIds))));
    if (!$errorIds || !error_instructions_schema_ready()) return $result;
    $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
    $rows = db()->prepare("SELECT i.*, creator.full_name created_by_name, completer.full_name completed_by_name
        FROM ops_error_instructions i
        LEFT JOIN ops_employees creator ON creator.id=i.created_by_user_id
        LEFT JOIN ops_employees completer ON completer.id=i.completed_by_user_id
        WHERE i.error_id IN ({$placeholders}) ORDER BY i.error_id, i.created_at, i.id");
    $rows->execute($errorIds);
    $instructions = $rows->fetchAll();
    $rows->closeCursor();
    if (!$instructions) return $result;
    $instructionIds = array_map(static fn(array $row): int => (int) $row['id'], $instructions);
    $readPlaceholders = implode(',', array_fill(0, count($instructionIds), '?'));
    $readStmt = db()->prepare("SELECT r.*, employee.full_name recipient_name
        FROM ops_error_instruction_reads r LEFT JOIN ops_employees employee ON employee.id=r.recipient_user_id
        WHERE r.instruction_id IN ({$readPlaceholders}) ORDER BY r.id");
    $readStmt->execute($instructionIds);
    $readsByInstruction = [];
    foreach ($readStmt->fetchAll() as $read) $readsByInstruction[(int) $read['instruction_id']][] = $read;
    $readStmt->closeCursor();
    foreach ($instructions as $instruction) {
        $instruction['recipients'] = $readsByInstruction[(int) $instruction['id']] ?? [];
        $result[(int) $instruction['error_id']][] = $instruction;
    }
    return $result;
}

function error_instruction_unread_counts(int $recipientId): array
{
    if ($recipientId <= 0 || !error_instructions_schema_ready()) return [];
    $stmt = db()->prepare("SELECT i.error_id, COUNT(*) unread_count
        FROM ops_error_instruction_reads r JOIN ops_error_instructions i ON i.id=r.instruction_id
        JOIN ops_error_logs e ON e.id=i.error_id AND e.deleted_at IS NULL
        WHERE r.recipient_user_id=? AND r.read_at IS NULL GROUP BY i.error_id");
    $stmt->execute([$recipientId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[(int) $row['error_id']] = (int) $row['unread_count'];
    $stmt->closeCursor();
    return $result;
}

