<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/database.php';

if (($_GET['action'] ?? '') === 'logout') {
    logout_user();
}

$error = null;
$setupWarning = null;
$opsLoginReady = false;

function local_login_accounts(): array
{
    return [
        'victoria' => ['Victoria Toivo', 'victoria@hambelelaorganic.com', 'Owner/Admin', 'owner_admin', '2026'],
        'frontdesk' => ['Cecilia Shiweda', 'shiwedasecilia3@gmail.com', 'Front Desk/Admin Employee', 'front_desk_admin', '1001'],
        'klaudia' => ['Klaudia Averinus', 'klaudia@hambelelaorganic.com', 'Packer/Production Staff', 'packer', '1002'],
        'ndinelao' => ['Ndinelao Kalola', 'ndinelao@hambelelaorganic.com', 'Packer/Production Staff', 'packer', '1003'],
    ];
}

function try_local_login(string $identity, string $code): bool
{
    $identity = strtolower(trim(str_replace('local:', '', $identity)));
    $accounts = local_login_accounts();
    $matchedKey = isset($accounts[$identity]) ? $identity : null;

    if (!$matchedKey) {
        foreach ($accounts as $key => $account) {
            if (strtolower($account[1]) === $identity || strtolower($account[0]) === $identity) {
                $matchedKey = $key;
                break;
            }
        }
    }

    if (!$matchedKey) {
        return false;
    }

    [$name, $email, $role, $roleKey, $expectedCode] = $accounts[$matchedKey];
    if ($code !== $expectedCode) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => null,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'role_key' => $roleKey,
        'source' => 'local_fallback',
    ];

    return true;
}

function ensure_auth_tables(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_roles (
          id INT AUTO_INCREMENT PRIMARY KEY,
          role_key VARCHAR(60) NOT NULL UNIQUE,
          name VARCHAR(120) NOT NULL,
          description VARCHAR(255),
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_employees (
          id INT AUTO_INCREMENT PRIMARY KEY,
          role_id INT NOT NULL,
          full_name VARCHAR(160) NOT NULL,
          email VARCHAR(190) UNIQUE,
          phone VARCHAR(60),
          password_hash VARCHAR(255),
          status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_ops_employees_role_id (role_id),
          CONSTRAINT fk_ops_employees_role_id FOREIGN KEY (role_id) REFERENCES ops_roles(id)
        )"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_login_events (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          employee_id INT NULL,
          employee_name VARCHAR(160) NULL,
          role_key VARCHAR(60) NULL,
          login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          source VARCHAR(40) NOT NULL DEFAULT 'database',
          ip_address VARCHAR(80) NULL,
          user_agent VARCHAR(255) NULL,
          INDEX idx_login_employee_time (employee_id, login_at),
          INDEX idx_login_time (login_at)
        )"
    );
}

function record_login_event(array $user, string $source): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO ops_login_events (employee_id, employee_name, role_key, source, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            !empty($user['id']) ? (int) $user['id'] : null,
            (string) ($user['name'] ?? ''),
            (string) ($user['role_key'] ?? ''),
            $source,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Login tracking should never block staff from accessing the portal.
    }
}

function ensure_default_ops_accounts(): void
{
    $roles = [
        'owner_admin' => ['Owner/Admin', 'Full access to operations, reports, financial tracking and settings'],
        'front_desk_admin' => ['Front Desk/Admin Employee', 'Customer orders, delivery coordination, supplier requests, petty cash and errors'],
        'packer' => ['Packer/Production Staff', 'Assigned packing tasks, barcode screens, checklists and own performance'],
        'supervisor_manager' => ['Supervisor/Manager', 'Operations supervision and approval role'],
    ];

    $roleStmt = db()->prepare("INSERT IGNORE INTO ops_roles (role_key, name, description) VALUES (?, ?, ?)");
    foreach ($roles as $key => [$name, $description]) {
        $roleStmt->execute([$key, $name, $description]);
    }

    $defaults = local_login_accounts();

    $roleLookup = db()->prepare('SELECT id FROM ops_roles WHERE role_key = ? LIMIT 1');
    $findByEmail = db()->prepare('SELECT id FROM ops_employees WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $findByName = db()->prepare('SELECT id FROM ops_employees WHERE LOWER(full_name) = LOWER(?) LIMIT 1');
    $updateEmployee = db()->prepare(
        "UPDATE ops_employees
         SET role_id = ?, full_name = ?, email = ?, password_hash = CASE WHEN password_hash IS NULL OR password_hash = '' THEN ? ELSE password_hash END, status = 'active'
         WHERE id = ?"
    );
    $insertEmployee = db()->prepare(
        "INSERT INTO ops_employees (role_id, full_name, email, password_hash, status)
         VALUES (?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), full_name = VALUES(full_name), status = 'active'"
    );

    foreach ($defaults as [$name, $email, $role, $roleKey, $code]) {
        $roleLookup->execute([$roleKey]);
        $roleId = (int) $roleLookup->fetchColumn();
        if ($roleId <= 0) {
            continue;
        }

        $hash = password_hash($code, PASSWORD_DEFAULT);
        $findByEmail->execute([strtolower($email)]);
        $employeeId = (int) $findByEmail->fetchColumn();
        if ($employeeId <= 0) {
            $findByName->execute([strtolower($name)]);
            $employeeId = (int) $findByName->fetchColumn();
        }

        if ($employeeId > 0) {
            $updateEmployee->execute([$roleId, $name, strtolower($email), $hash, $employeeId]);
        } else {
            $insertEmployee->execute([$roleId, $name, strtolower($email), $hash]);
        }
    }

    db()->exec(
        "UPDATE ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         SET e.status = 'inactive'
         WHERE r.role_key = 'front_desk_admin'
           AND (
             LOWER(e.email) = 'frontdesk@hambelelaorganic.com'
             OR LOWER(e.full_name) IN ('front desk admin', 'front_desk_admin', 'frontdesk')
           )"
    );
}

try {
    ensure_auth_tables();
    db()->query('SELECT 1 FROM ops_employees LIMIT 1');
    ensure_default_ops_accounts();
    $opsLoginReady = true;
} catch (Throwable $e) {
    $opsLoginReady = false;
    $setupWarning = 'Database employee login is unavailable, so local 4-digit code login is active.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));

    if ($opsLoginReady) {
        $employeeId = str_starts_with($identity, 'db:') ? (int) substr($identity, 3) : 0;
        $stmt = db()->prepare(
            "SELECT e.id, e.full_name, e.email, e.password_hash, r.role_key, r.name AS role_name
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.status = 'active' AND (e.id = ? OR LOWER(e.email) = LOWER(?) OR LOWER(e.full_name) = LOWER(?))
             LIMIT 1"
        );
        $stmt->execute([$employeeId, $identity, $identity]);
        $employee = $stmt->fetch();

        if ($employee && $employee['password_hash'] && password_verify($code, $employee['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int) $employee['id'],
                'name' => $employee['full_name'],
                'email' => $employee['email'],
                'role' => $employee['role_name'],
                'role_key' => $employee['role_key'],
                'source' => 'database',
            ];
            record_login_event($_SESSION['user'], 'database');
            header('Location: index.php');
            exit;
        }
    }

    if (try_local_login($identity, $code)) {
        record_login_event($_SESSION['user'], 'local_fallback');
        header('Location: index.php');
        exit;
    }

    $error = $opsLoginReady
        ? 'Invalid employee or code. Admin can reset the 4-digit code in Operations > Employees & Roles.'
        : 'Database login is not ready, so use one of the default local employees and their 4-digit code.';
}

$loginEmployees = [];
if ($opsLoginReady) {
    try {
        $loginEmployees = db()->query(
            "SELECT e.id, e.full_name, e.email, r.name AS role_name
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.status = 'active'
             ORDER BY FIELD(r.role_key, 'owner_admin', 'front_desk_admin', 'supervisor_manager', 'packer'), e.full_name"
        )->fetchAll();
    } catch (Throwable $e) {
        $loginEmployees = [];
    }
}

$pageTitle = 'Login | ' . APP_NAME;
$assetVersion = is_file(BASE_PATH . '/assets/css/portal.css') ? (string) filemtime(BASE_PATH . '/assets/css/portal.css') : (string) time();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <p class="eyebrow">Hambelela Business Portal</p>
        <h1>Sign in</h1>
        <?php if ($error): ?><p class="ops-alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post">
            <?php if (!$opsLoginReady): ?>
                <p class="ops-alert">Local staff profile login is active. Choose a name and enter the 4-digit code.</p>
            <?php endif; ?>
            <label for="identity">Employee</label>
            <select id="identity" name="identity" required>
                <option value="">Choose your name</option>
                <?php foreach ($loginEmployees as $employee): ?>
                    <option value="db:<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['full_name'] . ' - ' . $employee['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
                <?php if ($loginEmployees): ?><option value="" disabled>-- Local profiles --</option><?php endif; ?>
                <?php foreach (local_login_accounts() as $key => [$name, $email, $role]): ?>
                    <option value="local:<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name . ' - ' . $role, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="code">4-digit code</label>
            <input id="code" name="code" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="current-password" required>
            <button type="submit">Continue</button>
        </form>
        <p class="login-help">To change your own code, sign in and open <strong>Operations &gt; My Account</strong>. If you forgot your code, ask an Owner/Admin to reset it.</p>
        <?php if (!$opsLoginReady): ?>
            <p class="login-help">Default codes: Victoria <strong>2026</strong>, Front Desk <strong>1001</strong>, Klaudia <strong>1002</strong>, Ndinelao <strong>1003</strong>.</p>
        <?php endif; ?>
    </main>
</body>
</html>
