<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/login-security.php';

$message = isset($_SESSION['recovery_message']) ? (string) $_SESSION['recovery_message'] : null;
unset($_SESSION['recovery_message']);
$error = null;
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

db()->exec(
    "CREATE TABLE IF NOT EXISTS ops_access_recovery (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      employee_id INT NOT NULL,
      token_hash CHAR(64) NOT NULL UNIQUE,
      requested_ip VARCHAR(80) NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_access_recovery_employee_time (employee_id, created_at),
      INDEX idx_access_recovery_expiry (expires_at)
    )"
);

if (empty($_SESSION['recovery_csrf_token'])) {
    $_SESSION['recovery_csrf_token'] = bin2hex(random_bytes(32));
}

function recovery_url(string $token): string
{
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'portal.hambelelaorganic.com'));
    return 'https://' . $host . BASE_URL . '/recover-access.php?token=' . rawurlencode($token);
}

function valid_recovery_row(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT ar.id, ar.employee_id, e.email, r.role_key
         FROM ops_access_recovery ar
         JOIN ops_employees e ON e.id = ar.employee_id
         JOIN ops_roles r ON r.id = e.role_id
         WHERE ar.token_hash = ? AND ar.used_at IS NULL AND ar.expires_at > NOW()
           AND e.status = 'active' AND r.role_key = 'owner_admin'
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['recovery_csrf_token'], $submittedCsrf)) {
        $error = 'Invalid request. Reload the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'request') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
        $limitStmt = db()->prepare('SELECT COUNT(*) FROM ops_access_recovery WHERE requested_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
        $limitStmt->execute([$ip]);
        if ((int) $limitStmt->fetchColumn() < 3) {
            $stmt = db()->prepare(
                "SELECT e.id, e.email
                 FROM ops_employees e
                 JOIN ops_roles r ON r.id = e.role_id
                 WHERE e.status = 'active' AND r.role_key = 'owner_admin' AND LOWER(e.email) = ?
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $owner = $stmt->fetch();
            if ($owner && filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) {
                $plainToken = bin2hex(random_bytes(32));
                $insert = db()->prepare('INSERT INTO ops_access_recovery (employee_id, token_hash, requested_ip, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
                $insert->execute([(int) $owner['id'], hash('sha256', $plainToken), $ip]);
                $url = recovery_url($plainToken);
                $subject = 'Hambelela Portal access recovery';
                $body = "A secure Owner/Admin access reset was requested.\n\nOpen this link within 30 minutes:\n" . $url . "\n\nIf you did not request this, ignore this email.";
                $headers = "From: Hambelela Business Portal <no-reply@hambelelaorganic.com>\r\nContent-Type: text/plain; charset=UTF-8";
                if (!mail((string) $owner['email'], $subject, $body, $headers)) {
                    error_log('Portal access recovery email could not be sent.');
                }
                record_security_event('owner_recovery_requested', (int) $owner['id']);
            }
        }
        $_SESSION['recovery_message'] = 'If that address belongs to the Owner/Admin account, a recovery link has been sent.';
        header('Location: ' . BASE_URL . '/recover-access.php', true, 302);
        exit;
    } elseif (($_POST['action'] ?? '') === 'reset') {
        $row = valid_recovery_row($token);
        $password = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');
        if (!$row) {
            $error = 'This recovery link is invalid or has expired.';
        } elseif ($password !== $confirmation) {
            $error = 'The new password and confirmation do not match.';
        } elseif (($validation = access_secret_validation_error($password, 'owner_admin')) !== null) {
            $error = $validation;
        } elseif (access_secret_is_shared($password, (int) $row['employee_id'])) {
            $error = 'Choose a password that is not used by another account.';
        } else {
            db()->beginTransaction();
            try {
                $update = db()->prepare('UPDATE ops_employees SET password_hash = ?, requires_code_reset = 0, failed_login_attempts = 0, locked_until = NULL, last_failed_login_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['employee_id']]);
                $used = db()->prepare('UPDATE ops_access_recovery SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
                $used->execute([(int) $row['id']]);
                db()->commit();
                record_security_event('owner_recovery_completed', (int) $row['employee_id']);
                $_SESSION['login_error'] = 'Your password was reset. Sign in with the new password.';
                header('Location: ' . BASE_URL . '/login.php', true, 302);
                exit;
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $error = 'The password could not be reset. Please try again.';
            }
        }
    }
}

$validToken = $token !== '' ? valid_recovery_row($token) : null;
$assetVersion = is_file(BASE_PATH . '/assets/css/portal.css') ? (string) filemtime(BASE_PATH . '/assets/css/portal.css') : (string) time();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recover access | <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="login-page">
<main class="login-card">
    <header class="login-header">
        <p class="login-eyebrow">Hambelela Business Portal</p>
        <h1 class="login-title">Recover access</h1>
        <p class="login-subtitle"><?= $validToken ? 'Set a new Owner/Admin password.' : 'Request a time-limited recovery link.' ?></p>
    </header>
    <?php if ($message): ?><p class="login-alert" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error): ?><p class="login-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($validToken): ?>
        <form method="post" action="<?= BASE_URL ?>/recover-access.php" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['recovery_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="login-field"><label for="new-password">New password</label><input id="new-password" type="password" name="new_password" autocomplete="new-password" required></div>
            <div class="login-field"><label for="confirm-password">Confirm password</label><input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" required></div>
            <button type="submit" class="login-submit">Reset password</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>/recover-access.php" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['recovery_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="request">
            <div class="login-field"><label for="recovery-email">Owner/Admin email</label><input id="recovery-email" type="email" name="email" autocomplete="email" required></div>
            <button type="submit" class="login-submit">Send recovery link</button>
        </form>
    <?php endif; ?>
    <footer class="login-footer"><a href="<?= BASE_URL ?>/login.php">Back to sign in</a></footer>
</main>
</body>
</html>
