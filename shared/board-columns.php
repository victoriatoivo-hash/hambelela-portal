<?php

declare(strict_types=1);

function board_columns_ensure_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_board_columns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            board_name VARCHAR(100) NOT NULL,
            col_key VARCHAR(100) NOT NULL,
            col_name VARCHAR(100) NOT NULL,
            col_type VARCHAR(50) NOT NULL,
            col_order INT DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_board_column_key (board_name, col_key),
            INDEX idx_board_columns_board (board_name, col_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function board_columns_list(string $boardName): array
{
    board_columns_ensure_table();
    $stmt = db()->prepare(
        "SELECT col_key, col_name, col_type, col_order
         FROM hambelela_board_columns
         WHERE board_name = ?
         ORDER BY col_order ASC, id ASC"
    );
    $stmt->execute([$boardName]);
    $rows = $stmt->fetchAll();
    $stmt->closeCursor();

    return $rows ?: [];
}

function board_columns_save(string $boardName, string $key, string $name, string $type, int $createdBy): array
{
    board_columns_ensure_table();
    $allowedTypes = ['text', 'number', 'status', 'date', 'person', 'checkbox'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new RuntimeException('Unsupported column type.');
    }
    $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key) ?: ('custom_' . time());
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Column name is required.');
    }
    if (strlen($name) > 100) {
        $name = substr($name, 0, 100);
    }

    $stmt = db()->prepare("SELECT COALESCE(MAX(col_order), 0) + 1 FROM hambelela_board_columns WHERE board_name = ?");
    $stmt->execute([$boardName]);
    $order = (int) $stmt->fetchColumn();
    $stmt->closeCursor();

    db()->prepare(
        "INSERT INTO hambelela_board_columns (board_name, col_key, col_name, col_type, col_order, created_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE col_name = VALUES(col_name), col_type = VALUES(col_type)"
    )->execute([$boardName, $key, $name, $type, $order, $createdBy > 0 ? $createdBy : null]);

    return [
        'col_key' => $key,
        'col_name' => $name,
        'col_type' => $type,
        'col_order' => $order,
    ];
}
