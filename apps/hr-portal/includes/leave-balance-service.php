<?php
require_once __DIR__ . '/leave-reserve.php';

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

function hrProratedAnnualLeaveAccrued(float $annualRate, string $startDate, int $year, int $month, ?DateTimeImmutable $asOf = null): float {
    if ($annualRate <= 0 || $month < 1 || $month > 12 || trim($startDate) === '') {
        return 0.0;
    }
    $employmentStart = new DateTimeImmutable($startDate);
    $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
    $periodEnd = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month');
    $asOf = $asOf ?: new DateTimeImmutable('today');
    if ($periodEnd > $asOf) {
        $periodEnd = $asOf;
    }
    $accrualStart = $employmentStart > $yearStart ? $employmentStart : $yearStart;
    if ($accrualStart > $periodEnd) {
        return 0.0;
    }
    $daysEmployed = (int)$accrualStart->diff($periodEnd)->days + 1;
    $daysInYear = (int)(new DateTimeImmutable(sprintf('%04d-12-31', $year)))->format('z') + 1;
    return round(min($annualRate, ($daysEmployed / max(1, $daysInYear)) * $annualRate), 1);
}

function hrProbationAnnualAccrualStart(PDO $db, array $employee): string {
    $startDate = trim((string)($employee['start_date'] ?? ''));
    if (hrEmployeeIsOnProbation($employee)) {
        return $startDate;
    }

    $employeeId = (int)($employee['id'] ?? 0);
    if ($employeeId <= 0) {
        return '';
    }
    return trim(hrLeaveSettingValue($db, 'probation_annual_accrual_start_' . $employeeId, ''));
}

function hrAnnualAccruedForEmployee(PDO $db, array $employee, int $year, int $month): float {
    $defaultAccrued = hrAnnualAccruedTotal($db, $month);
    $startDate = hrProbationAnnualAccrualStart($db, $employee);
    if ($startDate === '') {
        return $defaultAccrued;
    }

    try {
        $annualRate = max(0.0, (float)hrLeaveSettingValue($db, 'leave_accrual_rate', '2')) * 12;
        return hrProratedAnnualLeaveAccrued($annualRate, $startDate, $year, $month);
    } catch (Throwable $error) {
        return $defaultAccrued;
    }
}

function hrReconcileProbationAnnualLeave(PDO $db, ?int $actorUserId = null): array {
    $year = (int)date('Y');
    $month = (int)date('n');
    $employees = $db->query("SELECT id,first_name,last_name,employment_type,start_date FROM employees WHERE status='active' AND LOWER(employment_type)='probation' ORDER BY id")->fetchAll();
    if (!$employees) {
        return ['employees_checked' => 0, 'balances_corrected' => 0];
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    $read = $db->prepare("SELECT balance_days,used_days FROM leave_balances WHERE employee_id=? AND leave_type='Annual Leave' AND year=? FOR UPDATE");
    $write = $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,'Annual Leave',?,?,?) ON DUPLICATE KEY UPDATE balance_days=VALUES(balance_days),used_days=VALUES(used_days)");
    $rememberStart = $db->prepare("INSERT INTO settings (setting_key,setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)");
    $audit = $db->prepare("INSERT INTO audit_log (user_id,action,description) VALUES (?,'probation_annual_leave_reconciled',?)");
    $corrected = 0;
    try {
        foreach ($employees as $employee) {
            $employeeId = (int)$employee['id'];
            $startDate = trim((string)($employee['start_date'] ?? ''));
            if ($startDate !== '') {
                // Keep the commencement-date accrual basis after the employee
                // moves from Probation to Permanent/Active employment.
                $rememberStart->execute(['probation_annual_accrual_start_' . $employeeId, $startDate]);
            }
            $expected = hrAnnualAccruedForEmployee($db, $employee, $year, $month);
            $used = hrApprovedLeaveUsed($db, $employeeId, 'Annual Leave', $year);
            $read->execute([$employeeId, $year]);
            $before = $read->fetch();
            $beforeBalance = $before ? (float)$before['balance_days'] : 0.0;
            $beforeUsed = $before ? (float)$before['used_days'] : 0.0;
            if (!$before || abs($beforeBalance - $expected) > 0.001 || abs($beforeUsed - $used) > 0.001) {
                $write->execute([$employeeId, $expected, $used, $year]);
                $audit->execute([
                    $actorUserId,
                    sprintf(
                        'Probation annual leave reconciled for %s: %.1f to %.1f accrued day(s); approved usage %.1f day(s) preserved.',
                        trim($employee['first_name'] . ' ' . $employee['last_name']),
                        $beforeBalance,
                        $expected,
                        $used
                    ),
                ]);
                $corrected++;
            }
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return ['employees_checked' => count($employees), 'balances_corrected' => $corrected];
}

function hrSyncAnnualLeaveAccrual(PDO $db, int $year, int $month, string $source, ?int $actorUserId = null): array {
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid accrual month.');
    }

    hrEnsureLeaveAccrualLedger($db);
    $source = preg_replace('/[^a-z0-9_-]/i', '', $source) ?: 'unknown';
    $accrued = hrAnnualAccruedTotal($db, $month);
    $employees = $db->query("SELECT id,first_name,last_name,employment_type,start_date FROM employees WHERE status='active' ORDER BY id")->fetchAll();
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
            $employeeAccrued = hrAnnualAccruedForEmployee($db, $employee, $year, $month);
            $used = hrApprovedLeaveUsed($db, $employeeId, 'Annual Leave', $year);
            $ledger->execute([$employeeId, $year, $month, $employeeAccrued, $source, $actorUserId]);
            if ($ledger->rowCount() === 1) {
                $applied++;
            }
            $balance->execute([$employeeId, $employeeAccrued, $used, $year]);
            $results[] = [
                'employee_id' => $employeeId,
                'employee_name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                'accrued_days' => $employeeAccrued,
                'used_days' => $used,
                'available_days' => max(0, $employeeAccrued - $used),
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
