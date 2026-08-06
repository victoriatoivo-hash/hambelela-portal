<?php

function hrLeaveSettingValue(PDO $db, string $key, string $default = ''): string {
    $stmt = $db->prepare('SELECT setting_val FROM settings WHERE setting_key=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function hrLeaveRecoveryLocked(PDO $db): bool {
    $environmentLock = strtolower(trim((string)getenv('HAMBELELA_HR_RECOVERY_LOCK')));
    if (in_array($environmentLock, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    return hrLeaveSettingValue($db, 'hr_leave_recovery_lock', '0') === '1';
}

function hrSetLeaveRecoveryLock(PDO $db, bool $locked): void {
    $value = $locked ? '1' : '0';
    $db->prepare("INSERT INTO settings (setting_key,setting_val) VALUES ('hr_leave_recovery_lock',?) ON DUPLICATE KEY UPDATE setting_val=?")
       ->execute([$value, $value]);
}

function hrEnsureLeaveAccrualLedger(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS leave_accrual_ledger (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id INT UNSIGNED NOT NULL,
        leave_type VARCHAR(50) NOT NULL DEFAULT 'Annual Leave',
        accrual_year INT NOT NULL,
        accrual_month TINYINT UNSIGNED NOT NULL,
        accrued_total DECIMAL(5,1) NOT NULL,
        source VARCHAR(40) NOT NULL,
        actor_user_id INT UNSIGNED NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY employee_type_period (employee_id, leave_type, accrual_year, accrual_month),
        KEY accrual_period (accrual_year, accrual_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hrApprovedLeaveUsed(PDO $db, int $employeeId, string $leaveType, int $year): float {
    $stmt = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE employee_id=? AND leave_type=? AND YEAR(start_date)=? AND status='approved'");
    $stmt->execute([$employeeId, $leaveType, $year]);
    return (float)$stmt->fetchColumn();
}

function hrRefreshUsedLeave(PDO $db, int $employeeId, string $leaveType, int $year): float {
    $used = hrApprovedLeaveUsed($db, $employeeId, $leaveType, $year);
    $stmt = $db->prepare('UPDATE leave_balances SET used_days=? WHERE employee_id=? AND leave_type=? AND year=?');
    $stmt->execute([$used, $employeeId, $leaveType, $year]);
    return $used;
}

function hrAnnualAccruedTotal(PDO $db, int $month): float {
    $rate = max(0.0, (float)hrLeaveSettingValue($db, 'leave_accrual_rate', '2'));
    return min($month * $rate, 24.0);
}

function hrSyncAnnualLeaveAccrual(PDO $db, int $year, int $month, string $source, ?int $actorUserId = null): array {
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid accrual month.');
    }

    hrEnsureLeaveAccrualLedger($db);
    $source = preg_replace('/[^a-z0-9_-]/i', '', $source) ?: 'unknown';
    $accrued = hrAnnualAccruedTotal($db, $month);
    $employees = $db->query("SELECT id,first_name,last_name FROM employees WHERE status='active' ORDER BY id")->fetchAll();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    $applied = 0;
    $results = [];
    try {
        $ledger = $db->prepare("INSERT IGNORE INTO leave_accrual_ledger (employee_id,leave_type,accrual_year,accrual_month,accrued_total,source,actor_user_id) VALUES (?,'Annual Leave',?,?,?,?,?)");
        $balance = $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,'Annual Leave',?,?,?) ON DUPLICATE KEY UPDATE balance_days=VALUES(balance_days),used_days=VALUES(used_days)");

        foreach ($employees as $employee) {
            $employeeId = (int)$employee['id'];
            $used = hrApprovedLeaveUsed($db, $employeeId, 'Annual Leave', $year);
            $ledger->execute([$employeeId, $year, $month, $accrued, $source, $actorUserId]);
            if ($ledger->rowCount() === 1) {
                $applied++;
            }
            $balance->execute([$employeeId, $accrued, $used, $year]);
            $results[] = [
                'employee_id' => $employeeId,
                'employee_name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                'accrued_days' => $accrued,
                'used_days' => $used,
                'available_days' => max(0, $accrued - $used),
            ];
        }

        if ($applied > 0) {
            $audit = $db->prepare("INSERT INTO audit_log (user_id,action,description) VALUES (?,'leave_accrual',?)");
            $audit->execute([$actorUserId, sprintf('Annual leave accrual synchronized for %04d-%02d: %.1f days; %d employee period(s) recorded.', $year, $month, $accrued, $applied)]);
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'year' => $year,
        'month' => $month,
        'accrued_days' => $accrued,
        'employee_count' => count($employees),
        'periods_applied' => $applied,
        'employees' => $results,
    ];
}
