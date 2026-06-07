<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

$error = null;
$success = null;
$ready = true;
$hasAdmin = false;

try {
    db()->query('SELECT 1 FROM ops_employees LIMIT 1');
    $hasAdmin = (int) db()->query('SELECT COUNT(*) FROM ops_employees')->fetchColumn() > 0;
} catch (Throwable $e) {
    $ready = false;
}

if ($ready && !$hasAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($fullName === '' || $email === '' || strlen($password) < 8) {
            throw new RuntimeException('Enter a name, email and password of at least 8 characters.');
        }

        $roleStmt = db()->prepare("SELECT id FROM ops_roles WHERE role_key = 'owner_admin' LIMIT 1");
        $roleStmt->execute();
        $roleId = (int) $roleStmt->fetchColumn();

        if ($roleId <= 0) {
            throw new RuntimeException('Owner/Admin role was not found. Please import operations-migration.sql again.');
        }

        $stmt = db()->prepare(
            "INSERT INTO ops_employees (role_id, full_name, email, password_hash, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $stmt->execute([
            $roleId,
            $fullName,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        $success = 'Owner/Admin account created. You can now delete this setup file and sign in.';
        $hasAdmin = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Operations Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css">
</head>
<body class="login-page">
    <main class="login-card">
        <p class="eyebrow">Hambelela Operations</p>
        <h1>Create admin</h1>

        <?php if (!$ready): ?>
            <p class="ops-alert">Please import <code>operations-migration.sql</code> in phpMyAdmin first, then reload this page.</p>
        <?php elseif ($success): ?>
            <p class="ops-alert"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
            <p><a class="button primary" href="login.php">Go to login</a></p>
        <?php elseif ($hasAdmin): ?>
            <p class="ops-alert">An employee account already exists. This setup page is now locked.</p>
            <p><a class="button primary" href="login.php">Go to login</a></p>
        <?php else: ?>
            <?php if ($error): ?><p class="ops-alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <form method="post">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" value="Victoria Toivo" autocomplete="name" required>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                <button type="submit">Create Owner/Admin</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
