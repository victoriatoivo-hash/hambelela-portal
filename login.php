<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/login-security.php';
require_once __DIR__ . '/shared/temporary-access-codes.php';

if (($_GET['action'] ?? '') === 'logout') {
    $epiLogoutUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
    $epiLogoutSession = (string) ($_SESSION['kpi_session_token'] ?? '');
    try {
        $kpiSessionToken = (string) ($_SESSION['kpi_session_token'] ?? '');
        if ($kpiSessionToken !== '') {
            db()->prepare("UPDATE kpi_sessions SET last_seen_at = UTC_TIMESTAMP(), logout_at = UTC_TIMESTAMP(), explicit_logout_at = UTC_TIMESTAMP(), end_reason = 'explicit_logout' WHERE session_token = ? AND logout_at IS NULL")->execute([$kpiSessionToken]);
        }
    } catch (Throwable $kpiError) {
        error_log(date(DATE_ATOM) . ' logout: ' . $kpiError->getMessage() . PHP_EOL, 3, __DIR__ . '/logs/kpi_errors.log');
    }
    record_epi_attendance_event('logout', $epiLogoutUser, 'explicit_logout', $epiLogoutSession);
    logout_user();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['user']) && is_array($_SESSION['user']) && portal_validate_authenticated_session()) {
    $destination = !empty($_SESSION['must_change_access_code'])
        ? BASE_URL . '/change-access-code.php'
        : portal_safe_return_path($_GET['return'] ?? null);
    header('Location: ' . $destination, true, 303);
    exit;
}

$error = ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['reason'] ?? '') === 'session_expired')
    ? 'Your working-day session has expired. Please log in again.'
    : null;
$setupWarning = null;
$opsLoginReady = false;

function default_ops_account_profiles(): array
{
    return [
        ['Victoria Toivo', 'victoria@hambelelaorganic.com', 'Owner/Admin', 'owner_admin'],
        ['Secilia Shiweda', 'shiwedasecilia3@gmail.com', 'Front Desk/Admin Employee', 'front_desk_admin'],
        ['Klaudia Averinus', 'klaudia@hambelelaorganic.com', 'Packer/Production Staff', 'packer'],
        ['Ndinelao Kalola', 'ndinelao@hambelelaorganic.com', 'Packer/Production Staff', 'packer'],
    ];
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

function record_kpi_login_session(int $userId): void
{
    if ($userId <= 0) return;
    try {
        $token = hash('sha256', session_id() . '|' . $userId . '|' . bin2hex(random_bytes(16)));
        db()->prepare('INSERT INTO kpi_sessions (session_token, user_id, login_at, last_seen_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([$token, $userId]);
        $_SESSION['kpi_session_token'] = $token;
    } catch (Throwable $kpiError) {
        error_log(date(DATE_ATOM) . ' login: ' . $kpiError->getMessage() . PHP_EOL, 3, __DIR__ . '/logs/kpi_errors.log');
    }
}

function record_epi_attendance_event(string $event, array $user, string $source, string $sessionReference = ''): void
{
    try {
        require_once __DIR__ . '/shared/epi/bootstrap.php';
        if ($event === 'login') {
            \Hambelela\EPI\AttendanceActivityBridge::recordLogin($user, $source, $sessionReference);
        } else {
            \Hambelela\EPI\AttendanceActivityBridge::recordLogout($user, $source, $sessionReference);
        }
    } catch (Throwable $epiError) {
        error_log(date(DATE_ATOM) . ' attendance ' . $event . ': ' . $epiError->getMessage() . PHP_EOL, 3, __DIR__ . '/logs/epi-attendance.log');
    }
}

function ensure_default_ops_accounts(): void
{
    $roles = [
        'owner_admin' => ['Owner/Admin', 'Full access to operations, reports, financial tracking and settings'],
        'front_desk_admin' => ['Front Desk/Admin Employee', 'Customer orders, delivery coordination, supplier requests, petty cash and errors'],
        'packer' => ['Packer/Production Staff', 'Assigned packing tasks, barcode screens, checklists and own performance'],
        'supervisor_manager' => ['Supervisor/Manager', 'Operations supervision and approval role'],
        'accountant' => ['Accountant', 'Restricted Finance Workspace access for VAT accounting and amendments'],
    ];

    $roleStmt = db()->prepare("INSERT IGNORE INTO ops_roles (role_key, name, description) VALUES (?, ?, ?)");
    foreach ($roles as $key => [$name, $description]) {
        $roleStmt->execute([$key, $name, $description]);
    }

    $defaults = default_ops_account_profiles();

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

    foreach ($defaults as [$name, $email, $role, $roleKey]) {
        $roleLookup->execute([$roleKey]);
        $roleId = (int) $roleLookup->fetchColumn();
        if ($roleId <= 0) {
            continue;
        }

        $hash = null;
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
    db()->query('SELECT 1 FROM ops_employees LIMIT 1');
    $opsLoginReady = true;
} catch (Throwable $e) {
    error_log('Employee login database probe failed: ' . $e->getMessage());
    $opsLoginReady = false;
    $setupWarning = 'Database employee login is temporarily unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));

    try {
        if ($opsLoginReady) {
            $employeeId = strpos($identity, 'db:') === 0 ? (int) substr($identity, 3) : 0;
            $stmt = db()->prepare(
                "SELECT e.id, e.full_name, e.email, e.password_hash, r.role_key, r.name AS role_name
                 FROM ops_employees e
                 JOIN ops_roles r ON r.id = e.role_id
                 WHERE e.status = 'active' AND e.id = ?
                 LIMIT 1"
            );
            $stmt->execute([$employeeId]);
            $employee = $stmt->fetch();
            $permanentValid = $employee
                && !empty($employee['password_hash'])
                && password_verify($code, (string) $employee['password_hash']);

            if ($permanentValid) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $employee['id'],
                    'name' => $employee['full_name'],
                    'email' => $employee['email'],
                    'role' => $employee['role_name'],
                    'role_key' => $employee['role_key'],
                    'source' => 'database',
                ];
                portal_initialize_authenticated_session($_SESSION['user']);
                record_login_event($_SESSION['user'], 'database');
                record_kpi_login_session((int) $_SESSION['user']['id']);
                record_epi_attendance_event('login', $_SESSION['user'], 'database', (string) ($_SESSION['kpi_session_token'] ?? ''));
                header('Location: ' . portal_post_login_destination($_SESSION['user'], $_GET['return'] ?? null), true, 303);
                exit;
            }

            $temporaryAccount = $employee ? temporary_access_account(db(), (int) $employee['id']) : null;
            $temporaryHashMatches = $temporaryAccount
                && !empty($temporaryAccount['temporary_code_hash'])
                && password_verify($code, (string) $temporaryAccount['temporary_code_hash']);
            $temporaryNotExpired = $temporaryAccount
                && !empty($temporaryAccount['temporary_code_expires_at'])
                && strtotime((string) $temporaryAccount['temporary_code_expires_at']) >= time();
            $temporaryValid = $temporaryHashMatches
                && $temporaryNotExpired
                && empty($temporaryAccount['temporary_code_used_at'])
                && !empty($temporaryAccount['must_change_access_code']);

            if ($temporaryValid) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $employee['id'],
                    'name' => $employee['full_name'],
                    'email' => $employee['email'],
                    'role' => $employee['role_name'],
                    'role_key' => $employee['role_key'],
                    'source' => 'database',
                ];
                portal_initialize_authenticated_session($_SESSION['user']);
                $_SESSION['login_type'] = 'temporary_code';
                $_SESSION['must_change_access_code'] = true;
                record_login_event($_SESSION['user'], 'temporary_code');
                record_kpi_login_session((int) $_SESSION['user']['id']);
                record_epi_attendance_event('login', $_SESSION['user'], 'temporary_code', (string) ($_SESSION['kpi_session_token'] ?? ''));
                record_security_event('temporary_access_code_used', (int) $employee['id']);
                header('Location: change-access-code.php', true, 303);
                exit;
            }

            if ($temporaryHashMatches && !$temporaryNotExpired) {
                $error = 'This temporary access code has expired. Ask an administrator to create a new one.';
            } elseif ($temporaryHashMatches) {
                $error = 'This temporary access code is no longer valid.';
            }
        }

        if ($error === null) {
            $error = $opsLoginReady
                ? 'Unable to sign in. Check your details and try again.'
                : 'Unable to sign in. Contact an administrator.';
        }
    } catch (Throwable $loginError) {
        error_log(
            'Portal login failed: ' . $loginError->getMessage()
            . ' in ' . $loginError->getFile()
            . ':' . $loginError->getLine()
        );
        $error = 'Unable to sign in right now. Please try again.';
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#721b1a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Hambelela">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>/assets/pwa/hambelela-180.png?v=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <style>.login-notice{margin:18px 0 0;padding:12px 14px;border:1px dashed rgba(171,54,25,.28);border-radius:11px;background:rgba(240,116,32,.04);color:var(--login-text-mid);font-size:12px;line-height:1.45;font-weight:400}@media(max-width:600px){body.login-page{padding:max(18px,env(safe-area-inset-top)) max(14px,env(safe-area-inset-right)) max(18px,env(safe-area-inset-bottom)) max(14px,env(safe-area-inset-left))}.login-card{width:100%;max-width:440px}.login-form input,.portal-custom-select-trigger,.login-submit{min-height:48px}.login-submit{margin-bottom:max(0px,env(safe-area-inset-bottom))}}</style>
</head>
<body class="login-page">
    <main class="login-card">
        <header class="login-header">
            <p class="login-eyebrow">Hambelela Business Portal</p>
            <h1 class="login-title">Sign in</h1>
            <p class="login-subtitle">Choose your staff profile and enter your private access code.</p>
        </header>
        <?php if ($error): ?><p class="login-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" class="login-form" id="login-form">
            <?php if (!$opsLoginReady): ?>
                <p class="login-notice">Employee login is temporarily unavailable. Contact an administrator.</p>
            <?php endif; ?>
            <div class="login-field">
                <label id="identity-label">Employee</label>
                <div class="portal-custom-select" data-login-select>
                    <input id="identity" type="hidden" name="identity" required>
                    <button type="button" class="portal-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="identity-label">
                        <span class="portal-custom-select-value">Choose your name</span>
                        <svg class="portal-custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div class="portal-custom-select-menu" role="listbox" tabindex="-1">
                        <?php foreach ($loginEmployees as $employee): ?>
                            <button type="button" class="portal-custom-select-option" role="option" data-value="db:<?= (int) $employee['id'] ?>" aria-selected="false"><?= htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8') ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="login-field">
                <label for="code">Access code</label>
                <div class="login-code-wrap">
                    <input id="code" name="code" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="current-password" required>
                    <button type="button" class="login-code-toggle" aria-label="Show access code" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="login-submit">Continue</button>
        </form>
        <footer class="login-footer">Hambelela Business Portal<br>Authorised staff access only.</footer>
    </main>
<script>
(() => {
  const select = document.querySelector('[data-login-select]');
  const trigger = select.querySelector('.portal-custom-select-trigger');
  const valueLabel = select.querySelector('.portal-custom-select-value');
  const identity = select.querySelector('input[name="identity"]');
  const options = Array.from(select.querySelectorAll('.portal-custom-select-option'));
  const code = document.getElementById('code');
  const toggle = document.querySelector('.login-code-toggle');
  let activeIndex = -1;

  const openMenu = () => {
    select.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
    activeIndex = Math.max(0, options.findIndex((option) => option.getAttribute('aria-selected') === 'true'));
    options[activeIndex]?.focus();
  };
  const closeMenu = () => {
    select.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
  };
  const choose = (option) => {
    options.forEach((item) => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
    identity.value = option.dataset.value || '';
    valueLabel.textContent = option.textContent || '';
    code.value = '';
    code.type = 'password';
    toggle.setAttribute('aria-pressed', 'false');
    toggle.setAttribute('aria-label', 'Show access code');
    closeMenu();
    trigger.focus();
  };

  trigger.addEventListener('click', () => select.classList.contains('is-open') ? closeMenu() : openMenu());
  options.forEach((option, index) => {
    option.addEventListener('click', () => choose(option));
    option.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(option); }
      if (event.key === 'Escape') { event.preventDefault(); closeMenu(); trigger.focus(); }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = event.key === 'ArrowDown' ? Math.min(options.length - 1, index + 1) : Math.max(0, index - 1);
        options[activeIndex]?.focus();
      }
    });
  });
  document.addEventListener('click', (event) => { if (!select.contains(event.target)) closeMenu(); });
  toggle.addEventListener('click', () => {
    const showing = code.type === 'text';
    code.type = showing ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    toggle.setAttribute('aria-label', showing ? 'Show access code' : 'Hide access code');
    code.focus();
  });
  document.getElementById('login-form').addEventListener('submit', (event) => {
    if (identity.value) return;
    event.preventDefault();
    openMenu();
  });
})();
</script>
</body>
</html>
