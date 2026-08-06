<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once dirname(__DIR__, 2) . '/shared/portal-presence-source.php';

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
        $stmt = db()->prepare(
            "INSERT INTO ops_board_presence (employee_id, page, path, last_seen_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE page = VALUES(page), path = VALUES(path), last_seen_at = VALUES(last_seen_at)"
        );
        $stmt->execute([$employeeId, $page !== '' ? $page : 'Business Portal', $path]);

        try {
            $kpiSessionToken = (string) ($_SESSION['kpi_session_token'] ?? '');
            if ($kpiSessionToken !== '') {
                db()->prepare('UPDATE kpi_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE session_token = ? AND user_id = ? AND logout_at IS NULL')->execute([$kpiSessionToken, $employeeId]);
            }
            db()->exec("UPDATE kpi_sessions SET logout_at = DATE_ADD(last_seen_at, INTERVAL 30 SECOND), session_expired_at = DATE_ADD(last_seen_at, INTERVAL 30 SECOND), end_reason = 'inactive_expiry' WHERE logout_at IS NULL AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)");
        } catch (Throwable $kpiError) {
            error_log(date(DATE_ATOM) . ' presence heartbeat: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
        }
    }

    $where = ["e.status = 'active'", "bp.last_seen_at >= DATE_SUB(NOW(), INTERVAL " . portal_presence_online_seconds() . " SECOND)"];
    if (current_role_key() !== 'owner_admin') {
        $where[] = "r.role_key <> 'owner_admin'";
    }

    $rows = ops_rows(
        "SELECT e.id, e.full_name, r.name AS role_name, r.role_key,
                bp.page, bp.path, bp.last_seen_at,
                DATE_ADD(s.login_at, INTERVAL 2 HOUR) AS session_started_at,
                TIMESTAMPDIFF(SECOND, s.login_at, UTC_TIMESTAMP()) AS session_duration_seconds,
                TIMESTAMPDIFF(SECOND, bp.last_seen_at, NOW()) AS seconds_since_activity
         FROM ops_board_presence bp
         JOIN ops_employees e ON e.id = bp.employee_id
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN kpi_sessions s ON s.id = (
             SELECT latest.id FROM kpi_sessions latest
             WHERE latest.user_id = bp.employee_id AND latest.logout_at IS NULL
             ORDER BY latest.login_at DESC, latest.id DESC LIMIT 1
         )
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
            'presence' => 'online',
            'seconds_since_activity' => $seconds,
            'session_started_at' => $row['session_started_at'] ?? null,
            'session_duration_seconds' => max(0, (int) ($row['session_duration_seconds'] ?? 0)),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'employees' => $employees], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Presence is temporarily unavailable.']);
}
