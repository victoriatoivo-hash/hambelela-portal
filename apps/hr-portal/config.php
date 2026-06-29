<?php
// ============================================================
//  Hambelela Organic — HR Portal
//  Database Configuration
// ============================================================

$businessPortalRoot = dirname(__DIR__, 2);
$businessLocalConfig = $businessPortalRoot . '/config.local.php';
$businessLocalSecrets = [];
if (is_file($businessLocalConfig)) {
    $loadedBusinessLocalConfig = require $businessLocalConfig;
    if (is_array($loadedBusinessLocalConfig)) {
        $businessLocalSecrets = $loadedBusinessLocalConfig;
    }
}

function hrConfigValue($value): string {
    $value = trim((string)$value);
    if ($value === '' || substr($value, 0, 5) === 'your_' || substr($value, 0, 6) === 'paste-') {
        return '';
    }
    return $value;
}

function readHrConfigDefines(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $source = file_get_contents($path);
    if ($source === false) {
        return [];
    }

    $values = [];
    if (preg_match_all("/define\\(\\s*['\"]([A-Z_]+)['\"]\\s*,\\s*(['\"])(.*?)\\2\\s*\\)/", $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $values[$match[1]] = stripcslashes($match[3]);
        }
    }
    return $values;
}

$liveHrConfigPath = hrConfigValue(getenv('HAMBELELA_HR_LIVE_CONFIG') ?: ($businessLocalSecrets['hr_live_config_path'] ?? ''));
if ($liveHrConfigPath === '') {
    $liveHrConfigPath = dirname($businessPortalRoot) . '/hr.hambelelaorganic.com/config.php';
}
$liveHrConfig = readHrConfigDefines($liveHrConfigPath);

define('DB_HOST', hrConfigValue(getenv('HAMBELELA_HR_DB_HOST') ?: ($businessLocalSecrets['hr_db_host'] ?? '')) ?: ($liveHrConfig['DB_HOST'] ?? 'localhost'));
define('DB_NAME', hrConfigValue(getenv('HAMBELELA_HR_DB_NAME') ?: ($businessLocalSecrets['hr_db_name'] ?? '')) ?: ($liveHrConfig['DB_NAME'] ?? ''));
define('DB_USER', hrConfigValue(getenv('HAMBELELA_HR_DB_USER') ?: ($businessLocalSecrets['hr_db_user'] ?? '')) ?: ($liveHrConfig['DB_USER'] ?? ''));
define('DB_PASS', hrConfigValue(getenv('HAMBELELA_HR_DB_PASS') ?: ($businessLocalSecrets['hr_db_pass'] ?? '')) ?: ($liveHrConfig['DB_PASS'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

// Site URL — no trailing slash
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', $scheme . '://' . $host . '/apps/hr-portal');

// Company defaults (editable later in Settings)
define('COMPANY_NAME', 'Hambelela Organic');
define('COMPANY_COUNTRY', 'Namibia');
define('COMPANY_CITY', 'Windhoek');

// Session name
define('SESSION_NAME', 'hambelela_hr_test_session');

// Timezone
date_default_timezone_set('Africa/Windhoek');

// Error reporting — set to 0 on live
error_reporting(0);
ini_set('display_errors', 0);

// ── Database connection ──────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed. Please contact your administrator.']));
        }
    }
    return $pdo;
}

// ── Session helper ───────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

// ── Auth helpers ─────────────────────────────────────────────
function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    $user = currentUser();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    }
}

// ── JSON response helper ─────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Sanitise input ───────────────────────────────────────────
function clean(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function hrColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function hrAddColumnSafe(PDO $db, string $table, string $column, string $definition): bool {
    try {
        if (hrColumnExists($db, $table, $column)) {
            return true;
        }
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function hrTableExists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hrMedicalAidDefaults(): array {
    return [
        'fund' => 'Khomas Loyalty Fund',
        'total' => 275.00,
        'company' => 110.00,
        'employee' => 165.00,
    ];
}

function hrEnsureMedicalAidSchemaSafe(PDO $db): bool {
    $ok = true;
    $ok = hrAddColumnSafe($db, 'employees', 'medical_aid_fund', "VARCHAR(120) NULL") && $ok;
    $ok = hrAddColumnSafe($db, 'employees', 'medical_aid_active', "TINYINT(1) NOT NULL DEFAULT 0") && $ok;
    $ok = hrAddColumnSafe($db, 'employees', 'medical_aid_total', "DECIMAL(10,2) NOT NULL DEFAULT 275.00") && $ok;
    $ok = hrAddColumnSafe($db, 'employees', 'medical_aid_company', "DECIMAL(10,2) NOT NULL DEFAULT 110.00") && $ok;
    $ok = hrAddColumnSafe($db, 'employees', 'medical_aid_employee', "DECIMAL(10,2) NOT NULL DEFAULT 165.00") && $ok;

    $ok = hrAddColumnSafe($db, 'payslips', 'medical_aid_fund', "VARCHAR(120) NULL") && $ok;
    $ok = hrAddColumnSafe($db, 'payslips', 'medical_aid_total', "DECIMAL(10,2) NOT NULL DEFAULT 0.00") && $ok;
    $ok = hrAddColumnSafe($db, 'payslips', 'medical_aid_company', "DECIMAL(10,2) NOT NULL DEFAULT 0.00") && $ok;
    $ok = hrAddColumnSafe($db, 'payslips', 'medical_aid_employee', "DECIMAL(10,2) NOT NULL DEFAULT 0.00") && $ok;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS medical_aid_memberships (
            employee_id INT UNSIGNED NOT NULL PRIMARY KEY,
            medical_aid_fund VARCHAR(120) NULL,
            medical_aid_active TINYINT(1) NOT NULL DEFAULT 0,
            medical_aid_total DECIMAL(10,2) NOT NULL DEFAULT 275.00,
            medical_aid_company DECIMAL(10,2) NOT NULL DEFAULT 110.00,
            medical_aid_employee DECIMAL(10,2) NOT NULL DEFAULT 165.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS medical_aid_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_month TINYINT NOT NULL,
            period_year INT NOT NULL,
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
        $db->exec("CREATE TABLE IF NOT EXISTS medical_aid_employee_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            period_month TINYINT NOT NULL,
            period_year INT NOT NULL,
            paid_status TINYINT(1) NOT NULL DEFAULT 0,
            paid_date DATE NULL,
            payment_reference VARCHAR(190) NULL,
            notes TEXT NULL,
            paid_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY employee_month_year (employee_id, period_month, period_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function hrHasMedicalAidSchema(PDO $db): bool {
    foreach (['medical_aid_fund','medical_aid_active','medical_aid_total','medical_aid_company','medical_aid_employee'] as $column) {
        if (!hrColumnExists($db, 'employees', $column)) return false;
    }
    foreach (['medical_aid_fund','medical_aid_total','medical_aid_company','medical_aid_employee'] as $column) {
        if (!hrColumnExists($db, 'payslips', $column)) return false;
    }
    return true;
}

function hrHasEmployeeMedicalAidColumns(PDO $db): bool {
    foreach (['medical_aid_fund','medical_aid_active','medical_aid_total','medical_aid_company','medical_aid_employee'] as $column) {
        if (!hrColumnExists($db, 'employees', $column)) return false;
    }
    return true;
}

function hrHasPayslipMedicalAidColumns(PDO $db): bool {
    foreach (['medical_aid_fund','medical_aid_total','medical_aid_company','medical_aid_employee'] as $column) {
        if (!hrColumnExists($db, 'payslips', $column)) return false;
    }
    return true;
}

function hrMedicalAidAvailable(PDO $db): bool {
    return true;
}

function hrMedicalAidMap(PDO $db): array {
    $map = [];
    try {
        $rows = $db->query("SELECT setting_key, setting_val FROM settings WHERE setting_key LIKE 'employee_medical_aid_%'")->fetchAll();
        foreach ($rows as $row) {
            $id = (int)substr((string)$row['setting_key'], strlen('employee_medical_aid_'));
            $data = json_decode((string)$row['setting_val'], true);
            if ($id > 0 && is_array($data)) {
                $map[$id] = $data;
            }
        }
    } catch (Throwable $e) {}

    if (hrTableExists($db, 'medical_aid_memberships')) {
        try {
            $rows = $db->query("SELECT * FROM medical_aid_memberships")->fetchAll();
            foreach ($rows as $row) {
                $map[(int)$row['employee_id']] = $row;
            }
        } catch (Throwable $e) {
            return $map;
        }
    }
    return $map;
}

function hrMedicalAidPaymentMap(PDO $db, int $month, int $year): array {
    $map = [];
    if (!hrTableExists($db, 'medical_aid_employee_payments')) {
        return $map;
    }
    try {
        $stmt = $db->prepare("SELECT * FROM medical_aid_employee_payments WHERE period_month=? AND period_year=?");
        $stmt->execute([$month, $year]);
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['employee_id']] = $row;
        }
    } catch (Throwable $e) {}
    return $map;
}

function hrSaveMedicalAidEmployeePayment(PDO $db, int $employeeId, int $month, int $year, array $data, int $userId): bool {
    if ($employeeId <= 0 || !hrTableExists($db, 'medical_aid_employee_payments')) {
        return false;
    }
    $paid = !empty($data['paid_status']) ? 1 : 0;
    $paidDate = trim((string)($data['paid_date'] ?? ''));
    $paidDate = $paidDate !== '' ? $paidDate : null;
    $reference = clean($data['payment_reference'] ?? '');
    $notes = clean($data['notes'] ?? '');
    try {
        $stmt = $db->prepare("INSERT INTO medical_aid_employee_payments
            (employee_id,period_month,period_year,paid_status,paid_date,payment_reference,notes,paid_by)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              paid_status=VALUES(paid_status),
              paid_date=VALUES(paid_date),
              payment_reference=VALUES(payment_reference),
              notes=VALUES(notes),
              paid_by=VALUES(paid_by)");
        $stmt->execute([$employeeId, $month, $year, $paid, $paidDate, $reference, $notes, $userId ?: null]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function hrApplyMedicalAidToEmployee(array $employee, array $medicalAidMap = []): array {
    $defaults = hrMedicalAidDefaults();
    $id = (int)($employee['id'] ?? 0);
    $fallback = $medicalAidMap[$id] ?? [];

    $employeeActive = (int)($employee['medical_aid_active'] ?? 0);
    $fallbackActive = (int)($fallback['medical_aid_active'] ?? 0);
    $employee['medical_aid_active'] = ($employeeActive === 1 || $fallbackActive === 1) ? 1 : 0;

    $fund = trim((string)($employee['medical_aid_fund'] ?? ''));
    if ($fund === '') {
        $fund = trim((string)($fallback['medical_aid_fund'] ?? ''));
    }
    $employee['medical_aid_fund'] = $fund !== '' ? $fund : $defaults['fund'];

    $amountSources = [
        'medical_aid_total' => $defaults['total'],
        'medical_aid_company' => $defaults['company'],
        'medical_aid_employee' => $defaults['employee'],
    ];
    foreach ($amountSources as $column => $default) {
        $value = (float)($employee[$column] ?? 0);
        if ($value <= 0 && array_key_exists($column, $fallback)) {
            $value = (float)$fallback[$column];
        }
        $employee[$column] = $value > 0 ? $value : (float)$default;
    }
    return $employee;
}

function hrSaveMedicalAidMembership(PDO $db, int $employeeId, array $data): void {
    if ($employeeId <= 0) return;
    $defaults = hrMedicalAidDefaults();
    $active = (int)(($data['medical_aid_active'] ?? '0') === '1');
    $fund = clean($data['medical_aid_fund'] ?? $defaults['fund']);
    $total = (float)($data['medical_aid_total'] ?? $defaults['total']);
    $company = (float)($data['medical_aid_company'] ?? 0);
    $employee = (float)($data['medical_aid_employee'] ?? 0);
    if ($total <= 0) $total = (float)$defaults['total'];
    if ($active && $company <= 0) $company = round($total * 0.40, 2);
    if ($active && $employee <= 0) $employee = round($total * 0.60, 2);
    if (!$active) {
        $company = $company > 0 ? $company : (float)$defaults['company'];
        $employee = $employee > 0 ? $employee : (float)$defaults['employee'];
    }
    if (hrTableExists($db, 'medical_aid_memberships')) {
        try {
            $stmt = $db->prepare("INSERT INTO medical_aid_memberships (employee_id,medical_aid_fund,medical_aid_active,medical_aid_total,medical_aid_company,medical_aid_employee)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE medical_aid_fund=VALUES(medical_aid_fund), medical_aid_active=VALUES(medical_aid_active), medical_aid_total=VALUES(medical_aid_total), medical_aid_company=VALUES(medical_aid_company), medical_aid_employee=VALUES(medical_aid_employee)");
            $stmt->execute([$employeeId, $fund, $active, $total, $company, $employee]);
            return;
        } catch (Throwable $e) {}
    }

    try {
        $payload = json_encode([
            'medical_aid_fund' => $fund,
            'medical_aid_active' => $active,
            'medical_aid_total' => $total,
            'medical_aid_company' => $company,
            'medical_aid_employee' => $employee,
        ]);
        $key = 'employee_medical_aid_' . $employeeId;
        $db->prepare("INSERT INTO settings (setting_key,setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
           ->execute([$key, $payload, $payload]);
    } catch (Throwable $e) {}
}
