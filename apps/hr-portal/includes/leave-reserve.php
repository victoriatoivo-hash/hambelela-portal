<?php

function leaveSetting($db, $key, $default = '') {
    $stmt = $db->prepare("SELECT setting_val FROM settings WHERE setting_key=?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function ensureLeaveShutdownSchema($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS `shutdown_leave_plans` (
      `employee_id` INT UNSIGNED NOT NULL,
      `year` INT NOT NULL,
      `handling` ENUM('unpaid','borrow','exception') DEFAULT NULL,
      `notes` TEXT NULL,
      `updated_by` INT UNSIGNED NULL,
      `updated_at` TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (`employee_id`,`year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $col = $db->query("SHOW COLUMNS FROM leave_requests LIKE 'reserve_warning'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE leave_requests ADD COLUMN reserve_warning TINYINT(1) DEFAULT 0 AFTER certificate");
        }
    } catch (Exception $e) {}
}

function shutdownSettings($db) {
    return [
        'reserve_days' => (float)leaveSetting($db, 'shutdown_reserve_days', '10'),
        'start_md' => leaveSetting($db, 'shutdown_start_md', '12-19'),
        'end_md' => leaveSetting($db, 'shutdown_end_md', '01-04'),
        'allow_borrow' => leaveSetting($db, 'shutdown_allow_borrow', '1') === '1',
        'reserve_start_month' => 8,
        'reserve_end_month' => 12,
        'monthly_accrual' => (float)leaveSetting($db, 'leave_accrual_rate', '2'),
    ];
}

function annualLeaveMetrics($db, $employeeId, $year = null) {
    $year = $year ?: (int)date('Y');
    $month = ((int)$year === (int)date('Y')) ? (int)date('n') : 12;
    $settings = shutdownSettings($db);
    $stmt = $db->prepare("SELECT balance_days,used_days FROM leave_balances WHERE employee_id=? AND leave_type='Annual Leave' AND year=?");
    $stmt->execute([(int)$employeeId, (int)$year]);
    $bal = $stmt->fetch();
    $accrued = $bal ? (float)$bal['balance_days'] : 0;
    $used = $bal ? (float)$bal['used_days'] : 0;
    $remaining = max(0, $accrued - $used);
    $projectedReserve = max(0, (float)$settings['reserve_days']);
    $monthlyAccrual = max(0, (float)$settings['monthly_accrual']);
    $reserveStart = (int)$settings['reserve_start_month'];
    $reserveEnd = (int)$settings['reserve_end_month'];
    $normalAccruedCutoff = max(0, ($reserveStart - 1) * $monthlyAccrual);
    $reserveMonthsElapsed = $month >= $reserveStart ? min($month, $reserveEnd) - $reserveStart + 1 : 0;
    $reserveAccumulated = min($projectedReserve, max(0, $reserveMonthsElapsed * $monthlyAccrual));
    $reserveActive = $reserveMonthsElapsed > 0;

    if ($reserveActive) {
        $available = max(0, min($accrued, $normalAccruedCutoff) - $used);
        $reserveRemaining = max(0, $remaining - $available);
        $shortfall = max(0, $reserveAccumulated - $reserveRemaining);
        $status = $shortfall > 0 ? 'red' : ($reserveRemaining <= $reserveAccumulated + 2 ? 'amber' : 'green');
    } else {
        $available = $remaining;
        $reserveRemaining = 0;
        $shortfall = 0;
        $status = 'green';
    }

    $decemberProjectedShortfall = $month >= 12 ? max(0, $projectedReserve - $reserveRemaining) : 0;

    return [
        'accrued' => $accrued,
        'used' => $used,
        'total' => $remaining,
        'current_accrued' => $accrued,
        'leave_taken' => $used,
        'available_now' => $available,
        'reserve' => $projectedReserve,
        'future_reserve' => $projectedReserve,
        'projected_reserve' => $projectedReserve,
        'reserve_accumulated' => $reserveAccumulated,
        'reserve_remaining' => $reserveRemaining,
        'reserve_active' => $reserveActive,
        'reserve_status_text' => $reserveActive ? 'Active - accumulating from August to December' : 'Not active yet',
        'reserve_period' => 'August to December',
        'available' => $available,
        'shortfall' => $shortfall,
        'december_shortfall' => $decemberProjectedShortfall,
        'status' => $status,
    ];
}

function shutdownHandlingLabel($handling) {
    $labels = [
        'unpaid' => 'Unpaid Leave',
        'borrow' => 'Borrow From Future Leave Balance',
        'exception' => 'Management Exception / Paid Leave',
    ];
    return $labels[$handling] ?? 'Not selected';
}

function shutdownStatusColor($status) {
    if ($status === 'red') return 'var(--red)';
    if ($status === 'amber') return 'var(--amber)';
    return 'var(--green)';
}
