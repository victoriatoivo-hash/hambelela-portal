<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user(): array
{
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }

    return [
        'id' => null,
        'name' => 'Guest',
        'role' => 'Guest',
        'role_key' => 'guest',
    ];
}

function refresh_logged_in_user(): void
{
    static $refreshed = false;
    if ($refreshed || !isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return;
    }
    $refreshed = true;

    $id = (int) ($_SESSION['user']['id'] ?? 0);
    if ($id <= 0) {
        logout_user();
        return;
    }

    try {
        require_once BASE_PATH . '/shared/database.php';
        $stmt = db()->prepare(
            "SELECT e.id, e.full_name, e.email, r.role_key, r.name AS role_name
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.id = ? AND e.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $employee = $stmt->fetch();
        $stmt->closeCursor();

        if (!$employee) {
            logout_user();
            return;
        }

        $_SESSION['user'] = [
            'id' => (int) $employee['id'],
            'name' => $employee['full_name'],
            'email' => $employee['email'],
            'role' => $employee['role_name'],
            'role_key' => $employee['role_key'],
            'source' => 'database',
        ];
    } catch (Throwable $e) {
        logout_user();
    }
}

function require_login(): void
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    refresh_logged_in_user();

    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

function user_has_role(string ...$roles): bool
{
    $user = current_user();

    return in_array((string) ($user['role_key'] ?? ''), $roles, true);
}

function user_is_admin(): bool
{
    return user_has_role('owner_admin', 'supervisor_manager');
}

function current_role_key(): string
{
    $user = current_user();

    return (string) ($user['role_key'] ?? 'guest');
}

function require_role(string ...$roles): void
{
    require_login();

    if (!user_has_role(...$roles)) {
        http_response_code(403);
        exit('You do not have access to this section.');
    }
}
