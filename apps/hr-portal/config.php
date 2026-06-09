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
