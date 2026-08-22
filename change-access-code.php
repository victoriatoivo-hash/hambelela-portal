<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/login-security.php';
require_once __DIR__ . '/shared/temporary-access-codes.php';

require_login();

final class ForcedCodeUserException extends RuntimeException
{
}

if (empty($_SESSION['must_change_access_code']) || ($_SESSION['login_type'] ?? '') !== 'temporary_code') {
    header('Location: ' . portal_post_login_destination(current_user()), true, 303);
    exit;
}

if (empty($_SESSION['change_access_code_csrf'])) {
    $_SESSION['change_access_code_csrf'] = bin2hex(random_bytes(32));
}

$error = null;
$employeeId = (int) (current_user()['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if ($submittedToken === '' || !hash_equals((string) $_SESSION['change_access_code_csrf'], $submittedToken)) {
            throw new ForcedCodeUserException('Security validation failed. Refresh the page and try again.');
        }

        $newCode = trim((string) ($_POST['new_code'] ?? ''));
        $confirmCode = trim((string) ($_POST['confirm_code'] ?? ''));
        if (!preg_match('/^\d{4}$/', $newCode)) {
            throw new ForcedCodeUserException('Your new access code must contain exactly 4 digits.');
        }
        if ($newCode !== $confirmCode) {
            throw new ForcedCodeUserException('The new access code and confirmation do not match.');
        }

        $database = db();
        ensure_temporary_access_schema($database);
        $temporary = temporary_access_account($database, $employeeId);
        if (!$temporary || empty($temporary['must_change_access_code']) || empty($temporary['temporary_code_hash'])) {
            throw new ForcedCodeUserException('This temporary access session is no longer valid. Ask an administrator to create a new code.');
        }
        if (password_verify($newCode, (string) $temporary['temporary_code_hash'])) {
            throw new ForcedCodeUserException('Choose a new private code that is different from the temporary code.');
        }
        if (access_secret_is_shared($newCode, $employeeId)) {
            throw new ForcedCodeUserException('That access code is already assigned to another account.');
        }

        $newHash = password_hash($newCode, PASSWORD_DEFAULT);
        if ($newHash === false) {
            throw new RuntimeException('Unable to secure the new access code.');
        }

        $update = $database->prepare(
            'UPDATE ops_employees
             SET password_hash = ?, temporary_code_hash = NULL, temporary_code_expires_at = NULL,
                 temporary_code_used_at = CURRENT_TIMESTAMP, must_change_access_code = 0,
                 failed_login_attempts = 0, locked_until = NULL, last_failed_login_at = NULL,
                 code_changed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = \'active\' AND must_change_access_code = 1'
        );
        $update->execute([$newHash, $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The access-code update could not be completed.');
        }

        $check = $database->prepare('SELECT password_hash FROM ops_employees WHERE id = ? AND status = \'active\' LIMIT 1');
        $check->execute([$employeeId]);
        if (!password_verify($newCode, (string) $check->fetchColumn())) {
            throw new RuntimeException('The saved access code could not be verified.');
        }

        record_security_event('access_code_changed_after_temporary_login', $employeeId);
        unset($_SESSION['must_change_access_code'], $_SESSION['change_access_code_csrf']);
        $_SESSION['login_type'] = 'employee';
        session_regenerate_id(true);
        header('Location: ' . portal_post_login_destination(current_user()), true, 303);
        exit;
    } catch (Throwable $changeError) {
        error_log('Forced access-code change failed: ' . $changeError->getMessage());
        $error = $changeError instanceof ForcedCodeUserException
            ? $changeError->getMessage()
            : 'Unable to save your new access code right now. Please try again.';
    }
}

$pageTitle = 'Create private access code | ' . APP_NAME;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css">
    <style>
        .forced-code-card{max-width:430px}.forced-code-note{margin:0 0 18px;padding:12px 14px;border:1px solid #ede3d8;border-radius:10px;background:#fffaf6;color:#604f46;font-size:12px;line-height:1.5}.forced-code-actions{display:flex;gap:10px;align-items:center}.forced-code-actions .login-submit{height:32px}.forced-code-logout{color:#721b1a;font-size:12px;text-decoration:none}
    </style>
</head>
<body class="login-page">
    <main class="login-card forced-code-card">
        <header class="login-header">
            <p class="login-eyebrow">Secure your account</p>
            <h1 class="login-title">Create a private access code</h1>
            <p class="login-subtitle">Your temporary code worked. Replace it before opening the portal.</p>
        </header>
        <p class="forced-code-note">Choose a private 4-digit code. Do not reuse the temporary code or share your new code with anyone.</p>
        <?php if ($error): ?><p class="login-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['change_access_code_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="login-field">
                <label for="new_code">New 4-digit access code</label>
                <input id="new_code" name="new_code" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="new-password" required>
            </div>
            <div class="login-field">
                <label for="confirm_code">Confirm new access code</label>
                <input id="confirm_code" name="confirm_code" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="new-password" required>
            </div>
            <div class="forced-code-actions">
                <button type="submit" class="login-submit">Save new access code</button>
                <a class="forced-code-logout" href="<?= BASE_URL ?>/login.php?action=logout">Sign out</a>
            </div>
        </form>
    </main>
</body>
</html>
