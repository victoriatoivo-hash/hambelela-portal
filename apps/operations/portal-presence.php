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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {http_response_code(405);header('Allow: POST');echo '{"ok":false}';exit;}
    $body=json_decode((string)file_get_contents('php://input'),true);
    $body=is_array($body)?$body:$_POST;
    $employeeId=ops_current_employee_id();
    if(!$employeeId)throw new RuntimeException('No authenticated employee');
    $now=time();
    portal_widget_touch(db(),$employeeId,portal_widget_key(),$now,(int)strtotime((string)($_SESSION['authenticated_at']??'')),(int)$_SESSION['absolute_expires_at']);
    // Background tabs confirm connectivity without claiming work or changing the dashboard-return clock.
    if(($body['background']??false)===true){echo '{"ok":true,"background":true}';exit;}
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

    $page = substr(trim((string) ($body['page'] ?? 'Business Portal')), 0, 160);
    $path = substr(trim((string) ($body['path'] ?? '')), 0, 255);

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
    $admin=current_role_key()==='owner_admin';
    echo json_encode(['ok'=>true,'employees'=>portal_widget_roster(db(),$now,$admin),'show_durations'=>$admin,
        'self_online'=>true,'resume_dashboard'=>!empty($_SESSION['portal_dashboard_pending']),
        'dashboard' => $dashboard], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Presence is temporarily unavailable.']);
}
