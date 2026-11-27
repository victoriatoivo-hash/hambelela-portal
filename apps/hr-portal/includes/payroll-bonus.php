<?php
// Gross performance awards are explicit, period-specific payroll snapshots.
function hrBonusEnsureSchema(PDO $db): bool {
    return hrAddColumnSafe($db, 'payslips', 'bonus_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00')
        && hrAddColumnSafe($db, 'payslips', 'bonus_paye', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
}
function hrBonusCsrf(): string {
    if (empty($_SESSION['hr_bonus_csrf'])) $_SESSION['hr_bonus_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['hr_bonus_csrf'];
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
