<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/login-security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reset_code_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reset_code_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    reset_code_json(['success' => false, 'message' => 'Your session has expired. Please sign in again.'], 401);
}

refresh_logged_in_user();
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    reset_code_json(['success' => false, 'message' => 'Your session has expired. Please sign in again.'], 401);
}

if (!user_has_role('owner_admin')) {
    reset_code_json(['success' => false, 'message' => 'You do not have permission to reset employee codes.'], 403);
}

try {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['settings_csrf_token'] ?? '');
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        reset_code_json(['success' => false, 'message' => 'Security validation failed. Refresh the page and try again.'], 419);
    }

    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
    $code = trim((string) ($_POST['login_code'] ?? ''));
    $confirmCode = trim((string) ($_POST['confirm_login_code'] ?? ''));

    if (!$employeeId) {
        reset_code_json(['success' => false, 'message' => 'Invalid employee account.'], 422);
    }
    if (!preg_match('/^\d{4}$/', $code)) {
        reset_code_json(['success' => false, 'message' => 'Reset code must contain exactly 4 digits.'], 422);
    }
    if ($code !== $confirmCode) {
        reset_code_json(['success' => false, 'message' => 'The new reset code and confirmation do not match.'], 422);
    }

    $stmt = db()->prepare('SELECT id, full_name FROM ops_employees WHERE id = ? AND status = \'active\' LIMIT 1');
    $stmt->execute([(int) $employeeId]);
    $employeeAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employeeAccount) {
        reset_code_json(['success' => false, 'message' => 'The selected employee account could not be found.'], 404);
    }

    if (access_secret_is_shared($code, (int) $employeeId)) {
        reset_code_json(['success' => false, 'message' => 'That access code is already assigned to another account.'], 422);
    }

    $database = db();
    $database->beginTransaction();
    try {
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            throw new RuntimeException('Unable to hash employee access code.');
        }

        $stmt = $database->prepare(
            'UPDATE ops_employees
             SET password_hash = ?, requires_code_reset = 0, failed_login_attempts = 0,
                 locked_until = NULL, last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = \'active\''
        );
        $stmt->execute([$codeHash, (int) $employeeId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Employee access-code update did not affect exactly one active account.');
        }

        $check = $database->prepare(
            'SELECT id, password_hash, updated_at
             FROM ops_employees
             WHERE id = ? AND status = \'active\'
             LIMIT 1'
        );
        $check->execute([(int) $employeeId]);
        $updatedAccount = $check->fetch(PDO::FETCH_ASSOC);
        $savedHash = (string) ($updatedAccount['password_hash'] ?? '');
        if (!$updatedAccount || $savedHash === '' || !password_verify($code, $savedHash)) {
            throw new RuntimeException('The saved employee access-code hash could not be verified.');
        }

        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }

    record_security_event('access_code_reset', (int) $employeeId, [
        'performed_by' => (int) (current_user()['id'] ?? 0),
        'employee_name' => (string) ($employeeAccount['full_name'] ?? ''),
    ]);

    reset_code_json([
        'success' => true,
        'message' => 'Employee access code updated successfully.',
        'data' => [
            'employee_account_id' => (int) $employeeId,
            'code_changed' => true,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Reset employee code failed: ' . $error->getMessage());
    reset_code_json(['success' => false, 'message' => 'Unable to update the reset code.'], 500);
}
