<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/login-security.php';

if (($_GET['action'] ?? '') === 'logout') {
    logout_user();
}

$error = isset($_SESSION['login_error']) ? (string) $_SESSION['login_error'] : null;
unset($_SESSION['login_error']);
$opsLoginReady = false;
$loginEmployees = [];

function login_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Existing installations may already contain the requested schema change.
    }
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
          failed_login_attempts INT NOT NULL DEFAULT 0,
          locked_until DATETIME NULL,
          last_failed_login_at DATETIME NULL,
          requires_code_reset TINYINT(1) NOT NULL DEFAULT 0,
          status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_ops_employees_role_id (role_id),
          CONSTRAINT fk_ops_employees_role_id FOREIGN KEY (role_id) REFERENCES ops_roles(id)
        )"
    );
    login_try_sql('ALTER TABLE ops_employees ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER password_hash');
    login_try_sql('ALTER TABLE ops_employees ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts');
    login_try_sql('ALTER TABLE ops_employees ADD COLUMN last_failed_login_at DATETIME NULL AFTER locked_until');
    login_try_sql('ALTER TABLE ops_employees ADD COLUMN requires_code_reset TINYINT(1) NOT NULL DEFAULT 0 AFTER last_failed_login_at');

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_login_attempts (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          employee_id INT NULL,
          identity_hash CHAR(64) NOT NULL,
          ip_address VARCHAR(80) NOT NULL,
          successful TINYINT(1) NOT NULL DEFAULT 0,
          attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_login_attempt_ip_time (ip_address, attempted_at),
          INDEX idx_login_attempt_employee_time (employee_id, attempted_at)
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_security_events (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          event_type VARCHAR(60) NOT NULL,
          employee_id INT NULL,
          ip_address VARCHAR(80) NULL,
          user_agent VARCHAR(255) NULL,
          metadata_json TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_security_event_time (event_type, created_at),
          INDEX idx_security_employee_time (employee_id, created_at)
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_security_migrations (
          migration_key VARCHAR(100) PRIMARY KEY,
          applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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

function invalidate_pre_rotation_credentials(): void
{
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT IGNORE INTO ops_security_migrations (migration_key) VALUES (?)');
        $stmt->execute(['invalidate_public_login_defaults_v1']);
        if ($stmt->rowCount() === 1) {
            db()->exec(
                "UPDATE ops_employees
                 SET password_hash = NULL,
                     requires_code_reset = 1,
                     failed_login_attempts = 0,
                     locked_until = NULL,
                     last_failed_login_at = NULL"
            );
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function record_login_event(array $user): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO ops_login_events (employee_id, employee_name, role_key, source, ip_address, user_agent)
             VALUES (?, ?, ?, 'database', ?, ?)"
        );
        $stmt->execute([
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['role_key'],
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Login history is informational and must not block authentication.
    }
}

function record_login_attempt(?int $employeeId, string $identity, string $ipAddress, bool $successful): void
{
    $stmt = db()->prepare(
        'INSERT INTO ops_login_attempts (employee_id, identity_hash, ip_address, successful) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$employeeId, hash('sha256', strtolower($identity)), $ipAddress, $successful ? 1 : 0]);
}

try {
    ensure_auth_tables();
    invalidate_pre_rotation_credentials();
    db()->exec("DELETE FROM ops_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    $opsLoginReady = true;
} catch (Throwable $e) {
    $opsLoginReady = false;
}

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($opsLoginReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['login_csrf_token'] ?? '');
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request. Reload the page and try again.';
    } else {
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $secret = (string) ($_POST['access_code'] ?? '');
        $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
        $employeeId = str_starts_with($identity, 'db:') ? (int) substr($identity, 3) : 0;

        $ipStmt = db()->prepare(
            "SELECT COUNT(*) FROM ops_login_attempts
             WHERE ip_address = ? AND successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $ipStmt->execute([$ipAddress]);
        $ipFailures = (int) $ipStmt->fetchColumn();

        $stmt = db()->prepare(
            "SELECT e.id, e.full_name, e.email, e.password_hash, e.failed_login_attempts,
                    e.locked_until, e.last_failed_login_at, e.requires_code_reset, r.role_key, r.name AS role_name
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.status = 'active' AND e.id = ?
             LIMIT 1"
        );
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        $accountLocked = $employee && !empty($employee['locked_until']) && strtotime((string) $employee['locked_until']) > time();

        if ($ipFailures >= 5 || $accountLocked) {
            http_response_code(429);
            $error = 'Too many attempts. Try again later.';
            record_security_event('login_lockout', $employee ? (int) $employee['id'] : null, ['scope' => $ipFailures >= 5 ? 'ip' : 'account']);
        } elseif (
            $employee &&
            empty($employee['requires_code_reset']) &&
            !empty($employee['password_hash']) &&
            password_verify($secret, (string) $employee['password_hash'])
        ) {
            $resetStmt = db()->prepare(
                'UPDATE ops_employees SET failed_login_attempts = 0, locked_until = NULL, last_failed_login_at = NULL WHERE id = ?'
            );
            $resetStmt->execute([(int) $employee['id']]);
            record_login_attempt((int) $employee['id'], $identity, $ipAddress, true);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $employee['id'],
                'name' => $employee['full_name'],
                'email' => $employee['email'],
                'role' => $employee['role_name'],
                'role_key' => $employee['role_key'],
                'source' => 'database',
            ];
            unset($_SESSION['login_csrf_token']);
            record_login_event($_SESSION['user']);
            record_security_event('login_success', (int) $employee['id']);
            header('Location: ' . BASE_URL . '/index.php', true, 303);
            exit;
        } else {
            $failedEmployeeId = $employee ? (int) $employee['id'] : null;
            record_login_attempt($failedEmployeeId, $identity, $ipAddress, false);
            if ($employee) {
                $lastFailure = !empty($employee['last_failed_login_at']) ? strtotime((string) $employee['last_failed_login_at']) : 0;
                $attempts = ($lastFailure >= time() - 900 ? (int) $employee['failed_login_attempts'] : 0) + 1;
                $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $failStmt = db()->prepare(
                    'UPDATE ops_employees SET failed_login_attempts = ?, locked_until = ?, last_failed_login_at = NOW() WHERE id = ?'
                );
                $failStmt->execute([$attempts, $lockUntil, (int) $employee['id']]);
                if ($lockUntil !== null) {
                    record_security_event('login_lockout', (int) $employee['id'], ['scope' => 'account']);
                }
            }
            record_security_event('login_failed', $failedEmployeeId);
            $error = 'Unable to sign in. Check your details and try again.';
        }
    }

    if ($error !== null) {
        $_SESSION['login_error'] = $error;
        unset($_SESSION['login_csrf_token']);
        header('Location: ' . BASE_URL . '/login.php', true, 303);
        exit;
    }
}

if ($opsLoginReady) {
    try {
        $loginEmployees = db()->query(
            "SELECT e.id, e.full_name
             FROM ops_employees e
             WHERE e.status = 'active'
             ORDER BY e.full_name"
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <header class="login-header">
            <p class="login-eyebrow">Hambelela Business Portal</p>
            <h1 class="login-title">Sign in</h1>
            <p class="login-subtitle">Choose your staff profile and enter your private access code.</p>
        </header>

        <?php if ($error): ?><p class="login-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if (!$opsLoginReady): ?><p class="login-alert" role="alert">Staff access is temporarily unavailable. Contact an administrator.</p><?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/login.php" class="login-form" id="login-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['login_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="login-field">
                <label id="login-identity-label">Employee</label>
                <div class="portal-custom-select login-employee-select" data-login-select>
                    <input type="hidden" name="identity" id="identity" required>
                    <button type="button" class="portal-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="login-identity-label">
                        <span class="portal-custom-select-value">Choose your name</span>
                        <svg class="portal-custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div class="portal-custom-select-menu" role="listbox" tabindex="-1">
                        <?php foreach ($loginEmployees as $employee): ?>
                            <button type="button" class="portal-custom-select-option" role="option" data-value="db:<?= (int) $employee['id'] ?>" aria-selected="false"><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="login-field">
                <label for="access-code">Access code</label>
                <div class="login-code-wrap">
                    <input type="password" id="access-code" name="access_code" autocomplete="current-password" required>
                    <button type="button" class="login-code-toggle" aria-label="Show access code" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="login-submit" <?= !$opsLoginReady || !$loginEmployees ? 'disabled' : '' ?>>Continue</button>
        </form>

        <footer class="login-footer">Hambelela Business Portal<br>Authorised staff access only.<br>Contact an administrator if you cannot access your account.</footer>
    </main>
<script>
(() => {
  const select = document.querySelector('[data-login-select]');
  const trigger = select?.querySelector('.portal-custom-select-trigger');
  const valueLabel = select?.querySelector('.portal-custom-select-value');
  const hiddenInput = select?.querySelector('input[name="identity"]');
  const options = Array.from(select?.querySelectorAll('.portal-custom-select-option') || []);
  const codeInput = document.getElementById('access-code');
  const toggle = document.querySelector('.login-code-toggle');
  let activeIndex = -1;

  const setOpen = (open) => {
    select?.classList.toggle('is-open', open);
    trigger?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && options.length) {
      activeIndex = Math.max(0, options.findIndex((option) => option.getAttribute('aria-selected') === 'true'));
      options[activeIndex]?.classList.add('is-active');
      options[activeIndex]?.focus();
    }
  };
  const choose = (option) => {
    options.forEach((item) => {
      const selected = item === option;
      item.setAttribute('aria-selected', selected ? 'true' : 'false');
      item.classList.toggle('is-active', selected);
    });
    hiddenInput.value = option.dataset.value || '';
    valueLabel.textContent = option.textContent || '';
    codeInput.value = '';
    codeInput.type = 'password';
    toggle.setAttribute('aria-pressed', 'false');
    toggle.setAttribute('aria-label', 'Show access code');
    setOpen(false);
    trigger.focus();
  };

  trigger?.addEventListener('click', () => setOpen(!select.classList.contains('is-open')));
  trigger?.addEventListener('keydown', (event) => {
    if (!['ArrowDown', 'ArrowUp', 'Escape'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Escape') return setOpen(false);
    setOpen(true);
  });
  options.forEach((option, index) => {
    option.addEventListener('click', () => choose(option));
    option.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(option);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        setOpen(false);
        trigger.focus();
      } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = event.key === 'ArrowDown' ? Math.min(options.length - 1, index + 1) : Math.max(0, index - 1);
        options[activeIndex]?.focus();
      }
    });
  });
  document.addEventListener('click', (event) => {
    if (select && !select.contains(event.target)) setOpen(false);
  });
  toggle?.addEventListener('click', () => {
    const showing = codeInput.type === 'text';
    codeInput.type = showing ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    toggle.setAttribute('aria-label', showing ? 'Show access code' : 'Hide access code');
    codeInput.focus();
  });
  document.getElementById('login-form')?.addEventListener('submit', (event) => {
    if (!hiddenInput.value) {
      event.preventDefault();
      setOpen(true);
      return;
    }
    const submit = event.currentTarget.querySelector('.login-submit');
    submit.disabled = true;
    submit.textContent = 'Signing in...';
  });
})();
</script>
</body>
</html>
