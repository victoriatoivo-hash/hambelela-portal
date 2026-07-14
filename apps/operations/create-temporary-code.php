<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/login-security.php';
require_once BASE_PATH . '/shared/temporary-access-codes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function temporary_code_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    temporary_code_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_login();
if (!user_has_role('owner_admin')) {
    temporary_code_json(['success' => false, 'message' => 'Only Owner/Admin can create temporary access codes.'], 403);
}

try {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['settings_csrf_token'] ?? '');
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        temporary_code_json(['success' => false, 'message' => 'Security validation failed. Refresh the page and try again.'], 419);
    }

    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$employeeId) {
        temporary_code_json(['success' => false, 'message' => 'Choose a valid employee account.'], 422);
    }

    $rateKey = 'temporary_code_generated_' . (int) $employeeId;
    $lastGeneratedAt = (int) ($_SESSION[$rateKey] ?? 0);
    if ($lastGeneratedAt > time() - 30) {
        temporary_code_json(['success' => false, 'message' => 'Please wait before creating another temporary code for this employee.'], 429);
    }

    $database = db();
    ensure_temporary_access_schema($database);

    $stmt = $database->prepare('SELECT id, full_name FROM ops_employees WHERE id = ? AND status = \'active\' LIMIT 1');
    $stmt->execute([(int) $employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        temporary_code_json(['success' => false, 'message' => 'The active employee account could not be found.'], 404);
    }

    $temporaryCode = (string) random_int(1000, 9999);
    $temporaryHash = password_hash($temporaryCode, PASSWORD_DEFAULT);
    if ($temporaryHash === false) {
        throw new RuntimeException('Unable to hash temporary access code.');
    }
    $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

    $update = $database->prepare(
        'UPDATE ops_employees
         SET temporary_code_hash = ?, temporary_code_expires_at = ?, temporary_code_used_at = NULL,
             must_change_access_code = 1, failed_login_attempts = 0, locked_until = NULL,
             last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = \'active\''
    );
    $update->execute([$temporaryHash, $expiresAt, (int) $employeeId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Temporary access code update did not affect one active account.');
    }

    $_SESSION[$rateKey] = time();
    record_security_event('temporary_access_code_created', (int) $employeeId, [
        'performed_by' => (int) (current_user()['id'] ?? 0),
        'employee_name' => (string) $employee['full_name'],
        'expires_at' => $expiresAt,
    ]);

    temporary_code_json([
        'success' => true,
        'message' => 'Temporary access code created.',
        'data' => [
            'employee_account_id' => (int) $employeeId,
            'employee_name' => (string) $employee['full_name'],
            'temporary_code' => $temporaryCode,
            'expires_at' => $expiresAt,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Create temporary access code failed: ' . $error->getMessage());
    temporary_code_json(['success' => false, 'message' => 'Unable to create a temporary access code right now.'], 500);
}
