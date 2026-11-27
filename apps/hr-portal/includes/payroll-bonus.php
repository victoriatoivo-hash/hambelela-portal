<?php
// Gross performance awards are explicit, period-specific payroll snapshots.
function hrBonusColumnExists(PDO $db, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payslips' AND COLUMN_NAME=?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() === 1;
}
function hrBonusEnsureSchema(PDO $db): bool {
    try {
        foreach (['bonus_amount', 'bonus_paye'] as $column) {
            if (!hrBonusColumnExists($db, $column)) {
                try {
                    $db->exec("ALTER TABLE payslips ADD COLUMN `{$column}` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
                } catch (PDOException $e) {
                    // Concurrent administrator requests can create the same column.
                    if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
                }
            }
            if (!hrBonusColumnExists($db, $column)) return false;
        }
        return true;
    } catch (Throwable $e) {
        $GLOBALS['hr_bonus_schema_error'] = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
        error_log('HR bonus schema initialization failed: database error code ' . $GLOBALS['hr_bonus_schema_error']);
        return false;
    }
}
// A host-only, Secure, HttpOnly cookie avoids relying on HR's bridged PHP
// session being writable/persistent. The form token is bound to the current
// authenticated admin ID; another origin cannot read or plant the __Host cookie.
const HR_BONUS_CSRF_COOKIE = '__Host-HRPayrollCSRF';
function hrBonusCsrfFromCookie($cookie, int $userId): ?string {
    if ($userId <= 0 || !is_string($cookie) || !preg_match('/^[a-f0-9]{64}$/D', $cookie)) return null;
    return hash_hmac('sha256', 'hambelela-hr-payroll-v1:' . $userId, $cookie);
}
function hrBonusCsrf(int $userId = 0): string {
    if ($userId <= 0 && function_exists('currentUser')) $userId = (int)(currentUser()['id'] ?? 0);
    $cookie = $_COOKIE[HR_BONUS_CSRF_COOKIE] ?? null;
    $token = hrBonusCsrfFromCookie($cookie, $userId);
    if ($token !== null) return $token;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || $userId <= 0) return bin2hex(random_bytes(32));
    $cookie = bin2hex(random_bytes(32));
    if (!setcookie(HR_BONUS_CSRF_COOKIE, $cookie, [
        'expires'=>0, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict'
    ])) return bin2hex(random_bytes(32));
    return hrBonusCsrfFromCookie($cookie, $userId);
}
function hrBonusCsrfValid(int $userId, $provided, $cookie): bool {
    $expected = hrBonusCsrfFromCookie($cookie, $userId);
    return $expected !== null && is_string($provided) && hash_equals($expected, $provided);
}
function hrBonusMoney($value): float {
    if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException('Invalid amount');
    $value = trim((string)$value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Invalid amount');
    return round((float)$value, 2);
}
function hrBonusValidate($amounts, $taxes, array $employees): array {
    if (!is_array($amounts) || !is_array($taxes)) throw new InvalidArgumentException('Invalid bonus input');
    $active = [];
    foreach ($employees as $employee) $active[(int)$employee['id']] = true;
    $result = [];
    foreach ($amounts + $taxes as $id => $ignored) {
        if (!isset($active[$id])) throw new InvalidArgumentException('Unknown employee');
        $raw = $amounts[$id] ?? '';
        $tax = $taxes[$id] ?? '';
        $amount = $raw === '' ? 0.0 : hrBonusMoney($raw);
        if ($amount === 0.0) {
            if ($tax !== '' && hrBonusMoney($tax) !== 0.0) throw new InvalidArgumentException('Tax without bonus');
            continue;
        }
        $paye = hrBonusMoney($tax);
        if ($paye > $amount) throw new InvalidArgumentException('Additional PAYE exceeds bonus');
        $result[(int)$id] = ['amount'=>$amount,'paye'=>$paye];
    }
    return $result;
}
