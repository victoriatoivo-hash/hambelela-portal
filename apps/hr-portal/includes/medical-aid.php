<?php

function ensureMedicalAidSchema(PDO $db): void {
    hrAddColumnIfMissing($db, 'employees', 'medical_aid_fund', "VARCHAR(120) NOT NULL DEFAULT 'Khomas Loyalty Fund'");
    hrAddColumnIfMissing($db, 'employees', 'medical_aid_active', "TINYINT(1) NOT NULL DEFAULT 0");
    hrAddColumnIfMissing($db, 'employees', 'medical_aid_total', "DECIMAL(10,2) NOT NULL DEFAULT 275.00");
    hrAddColumnIfMissing($db, 'employees', 'medical_aid_company', "DECIMAL(10,2) NOT NULL DEFAULT 110.00");
    hrAddColumnIfMissing($db, 'employees', 'medical_aid_employee', "DECIMAL(10,2) NOT NULL DEFAULT 165.00");

    hrAddColumnIfMissing($db, 'payslips', 'medical_aid_fund', "VARCHAR(120) NULL");
    hrAddColumnIfMissing($db, 'payslips', 'medical_aid_total', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    hrAddColumnIfMissing($db, 'payslips', 'medical_aid_company', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    hrAddColumnIfMissing($db, 'payslips', 'medical_aid_employee', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");

    $db->exec("CREATE TABLE IF NOT EXISTS medical_aid_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_month TINYINT NOT NULL,
        period_year YEAR NOT NULL,
        active_employee_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_payable DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        company_contribution DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        employee_contribution DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_status TINYINT(1) NOT NULL DEFAULT 0,
        paid_date DATETIME NULL,
        paid_by INT UNSIGNED NULL,
        notes_reference TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY month_year (period_month, period_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hrAddColumnIfMissing(PDO $db, string $table, string $column, string $definition): void {
    $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function medicalAidDefaults(): array {
    return [
        'fund' => 'Khomas Loyalty Fund',
        'total' => 275.00,
        'company' => 110.00,
        'employee' => 165.00,
    ];
}

function employeeMedicalAidAmounts(array $employee): array {
    $defaults = medicalAidDefaults();
    $active = !empty($employee['medical_aid_active']);
    $fund = trim((string)($employee['medical_aid_fund'] ?? ''));

    $total = (float)($employee['medical_aid_total'] ?? $defaults['total']);
    $company = (float)($employee['medical_aid_company'] ?? $defaults['company']);
    $employeeShare = (float)($employee['medical_aid_employee'] ?? $defaults['employee']);

    if ($total <= 0) $total = $defaults['total'];
    if ($company <= 0) $company = $defaults['company'];
    if ($employeeShare <= 0) $employeeShare = $defaults['employee'];

    return [
        'active' => $active,
        'fund' => $fund !== '' ? $fund : $defaults['fund'],
        'total' => $active ? round($total, 2) : 0.00,
        'company' => $active ? round($company, 2) : 0.00,
        'employee' => $active ? round($employeeShare, 2) : 0.00,
    ];
}

function medicalAidPaymentForPeriod(PDO $db, int $month, int $year): array {
    $stmt = $db->prepare("SELECT * FROM medical_aid_payments WHERE period_month=? AND period_year=?");
    $stmt->execute([$month, $year]);
    $row = $stmt->fetch();

    return $row ?: [
        'paid_status' => 0,
        'paid_date' => null,
        'paid_by' => null,
        'notes_reference' => '',
    ];
}
