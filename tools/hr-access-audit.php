<?php
declare(strict_types=1);
// Fail closed after thirty minutes even if cleanup is interrupted.
if (time() - (int) filemtime(__FILE__) > 1800) {
    http_response_code(410);
    exit('Temporary audit expired.');
}
require_once dirname(__DIR__) . '/apps/operations/operations.php';
require_role('owner_admin');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
function audit_rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
}
try {
    $portal = db();
    $hr = ops_hr_db();
    if (!$hr instanceof PDO) { throw new RuntimeException('HR connection unavailable'); }
    $portal->exec('SET TRANSACTION READ ONLY');
    $portal->beginTransaction();
    $hr->exec('SET TRANSACTION READ ONLY');
    $hr->beginTransaction();
    $staff = audit_rows($portal, "SELECT e.id, e.full_name, e.email, e.status, r.role_key,
        l.portal_user_id, l.hr_employee_id, l.active AS link_active
        FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
        LEFT JOIN employee_user_links l ON l.portal_user_id=e.id
        WHERE e.id=15 OR (e.status='active' AND l.active=1) ORDER BY e.id");
    $result = [];
    foreach ($staff as $person) {
        $id = (int) ($person['hr_employee_id'] ?? 0);
        $profiles = audit_rows($hr, 'SELECT id,emp_number,first_name,last_name,email,status FROM employees WHERE id=?', [$id]);
        $email = (string) ($profiles[0]['email'] ?? $person['email']);
        $name = trim((string) ($profiles[0]['first_name'] ?? '') . ' ' . (string) ($profiles[0]['last_name'] ?? ''));
        $accounts = audit_rows($hr, 'SELECT id,employee_id,name,email,role,active FROM users WHERE employee_id=? OR LOWER(TRIM(email))=LOWER(TRIM(?)) OR LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id', [$id,$email,$name]);
        $result[] = ['portal'=>$person,'hr_employee'=>$profiles,'matching_hr_users'=>$accounts];
    }
    $hr->rollBack();
    $portal->rollBack();
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>HR access audit</title></head><body><h1>Read-only HR access audit</h1><pre>';
    echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    echo '</pre></body></html>';
} catch (Throwable $error) {
    if (isset($hr) && $hr instanceof PDO && $hr->inTransaction()) { $hr->rollBack(); }
    if (isset($portal) && $portal->inTransaction()) { $portal->rollBack(); }
    http_response_code(503);
    error_log('HR read-only audit failed: ' . $error->getMessage());
    echo 'HR audit unavailable. No account records were changed.';
}
