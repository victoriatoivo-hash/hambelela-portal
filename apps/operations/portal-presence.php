<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once dirname(__DIR__, 2) . '/shared/portal-presence-source.php';

header('Content-Type: application/json; charset=utf-8');

if (current_role_key() === 'guest' || !portal_validate_authenticated_session()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required.',
        'resume_dashboard' => portal_device_key() !== null, 'dashboard' => BASE_URL.'/login.php']);
    exit;
}
require_login();

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
        $db = db();
        $db->exec("CREATE TABLE IF NOT EXISTS portal_employee_presence_intervals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
            returned_at BIGINT NOT NULL, last_seen BIGINT NOT NULL, INDEX employee_intervals(employee_id,id)
        ) ENGINE=InnoDB");
        $db->beginTransaction();
        // The employee PK serialises heartbeats across tabs and devices.
        $stmt = db()->prepare(
            "INSERT INTO ops_board_presence (employee_id, page, path, last_seen_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE page = VALUES(page), path = VALUES(path), last_seen_at = VALUES(last_seen_at)"
        );
        $stmt->execute([$employeeId, $page !== '' ? $page : 'Business Portal', $path]);
        $seen = (int)$db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        portal_record_browser_contact($db,$employeeId,$seen);
        $db->commit();
        // Existing KPI attendance is separate from browser connectivity.
        try {
            $kpiSessionToken = (string) ($_SESSION['kpi_session_token'] ?? '');
            if ($kpiSessionToken !== '') {
                db()->prepare('UPDATE kpi_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE session_token = ? AND user_id = ? AND logout_at IS NULL AND last_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)')->execute([$kpiSessionToken, $employeeId]);
            }
            db()->exec("UPDATE kpi_sessions SET logout_at = DATE_ADD(last_seen_at, INTERVAL 30 SECOND), session_expired_at = DATE_ADD(last_seen_at, INTERVAL 30 SECOND), end_reason = 'inactive_expiry' WHERE logout_at IS NULL AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)");
            // Reconnects already have a presence interval. They must not create
            // a KPI login or attendance row just because a browser returned.
        } catch (Throwable $kpiError) {
            error_log(date(DATE_ATOM) . ' presence heartbeat: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
        }
    }

    $now = time();
    if (isset($_SESSION['portal_device_id']) && $employeeId) {
        db()->prepare('UPDATE portal_employee_devices SET last_contact=? WHERE device_id=? AND employee_id=?')->execute([$now,$_SESSION['portal_device_id'],$employeeId]);
    }
    if (portal_presence_dashboard_due($now)) $_SESSION['portal_dashboard_pending'] = true;
    $dashboard = portal_post_login_destination(current_user());
    $dashboardPath = parse_url($dashboard, PHP_URL_PATH);
    if ($path === $dashboardPath || ($dashboardPath === BASE_URL.'/index.php' && $path === BASE_URL.'/')) {
        // A visible dashboard heartbeat already fulfils the return. Do not reload it.
        unset($_SESSION['portal_dashboard_pending']);
    }
    $_SESSION['portal_last_contact'] = $now;
    $where = ["e.status = 'active'"];
    if (current_role_key() !== 'owner_admin') {
        $where[] = "bp.last_seen_at > DATE_SUB(NOW(), INTERVAL " . portal_presence_online_seconds() . " SECOND)";
        $where[] = "r.role_key <> 'owner_admin'";
    }

    $rows = ops_rows(
        "SELECT e.id, e.full_name, r.name AS role_name, r.role_key,
                bp.page, bp.path, bp.last_seen_at,
                s.returned_at, s.last_seen, UNIX_TIMESTAMP()-s.returned_at AS session_duration_seconds,
                (SELECT prior.last_seen FROM portal_employee_presence_intervals prior WHERE prior.employee_id=bp.employee_id AND prior.id<s.id ORDER BY prior.id DESC LIMIT 1) AS prior_last_seen,
                TIMESTAMPDIFF(SECOND, bp.last_seen_at, NOW()) AS seconds_since_activity
         FROM ops_board_presence bp
         JOIN ops_employees e ON e.id = bp.employee_id
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN portal_employee_presence_intervals s ON s.id = (
             SELECT latest.id FROM portal_employee_presence_intervals latest
             WHERE latest.employee_id = bp.employee_id ORDER BY latest.id DESC LIMIT 1
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
            'presence' => $seconds < portal_presence_online_seconds() ? 'online' : 'offline',
            'last_seen_at' => isset($row['last_seen']) ? portal_presence_local_time((int)$row['last_seen']) : $row['last_seen_at'],
            'prior_last_seen_at' => isset($row['prior_last_seen']) ? portal_presence_local_time((int)$row['prior_last_seen']) : null,
            'seconds_since_activity' => $seconds,
            'session_started_at' => isset($row['returned_at']) ? portal_presence_local_time((int)$row['returned_at']) : null,
            'session_duration_seconds' => max(0, (int) ($row['session_duration_seconds'] ?? 0)),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'employees' => $employees, 'resume_dashboard' => !empty($_SESSION['portal_dashboard_pending']),
        'dashboard' => $dashboard], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Presence is temporarily unavailable.']);
}
