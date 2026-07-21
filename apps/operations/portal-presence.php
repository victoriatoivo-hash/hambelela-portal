<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

header('Content-Type: application/json; charset=utf-8');

if (current_role_key() === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required.']);
    exit;
}

try {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_board_presence (
            employee_id INT NOT NULL PRIMARY KEY,
            page VARCHAR(160) NOT NULL DEFAULT 'Business Portal',
            path VARCHAR(255) NULL,
            last_seen_at DATETIME NOT NULL,
            INDEX idx_presence_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!ops_column_exists('ops_board_presence', 'path')) {
        db()->exec("ALTER TABLE ops_board_presence ADD COLUMN path VARCHAR(255) NULL AFTER page");
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : $_POST;
    $page = substr(trim((string) ($body['page'] ?? 'Business Portal')), 0, 160);
    $path = substr(trim((string) ($body['path'] ?? '')), 0, 255);
    $employeeId = ops_current_employee_id();

    if ($employeeId) {
        kpi_foundation_heartbeat($employeeId);
        $stmt = db()->prepare(
            "INSERT INTO ops_board_presence (employee_id, page, path, last_seen_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE page = VALUES(page), path = VALUES(path), last_seen_at = VALUES(last_seen_at)"
        );
        $stmt->execute([$employeeId, $page !== '' ? $page : 'Business Portal', $path]);
    }

    $where = ["e.status = 'active'", "bp.last_seen_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"];
    if (current_role_key() !== 'owner_admin') {
        $where[] = "r.role_key <> 'owner_admin'";
    }

    $rows = ops_rows(
        "SELECT e.id, e.full_name, r.name AS role_name, r.role_key,
                bp.page, bp.path, bp.last_seen_at,
                TIMESTAMPDIFF(SECOND, bp.last_seen_at, NOW()) AS seconds_since_activity
         FROM ops_board_presence bp
         JOIN ops_employees e ON e.id = bp.employee_id
         JOIN ops_roles r ON r.id = e.role_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY bp.last_seen_at DESC, e.full_name ASC"
    );

    $employees = array_map(static function (array $row): array {
        $seconds = max(0, (int) ($row['seconds_since_activity'] ?? 0));
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['full_name'],
            'role' => (string) $row['role_name'],
            'page' => (string) ($row['page'] ?: 'Business Portal'),
            'path' => (string) ($row['path'] ?? ''),
            'presence' => $seconds <= 120 ? 'online' : 'away',
            'seconds_since_activity' => $seconds,
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'employees' => $employees], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Presence is temporarily unavailable.']);
}
