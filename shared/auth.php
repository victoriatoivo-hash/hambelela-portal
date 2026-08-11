<?php

declare(strict_types=1);

const PORTAL_SESSION_MAX_SECONDS = 36000;
const PORTAL_SESSION_TIMEZONE = 'Africa/Windhoek';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Keep the server-side session available for the full working-day window.
    // Application metadata below remains authoritative; this is not a rolling timeout.
    ini_set('session.gc_maxlifetime', (string) PORTAL_SESSION_MAX_SECONDS);
    ini_set('session.cookie_lifetime', (string) PORTAL_SESSION_MAX_SECONDS);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => PORTAL_SESSION_MAX_SECONDS,
        'path' => '/',
        'secure' => portal_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function portal_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function portal_session_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(PORTAL_SESSION_TIMEZONE));
}

function portal_calculate_absolute_expiry(DateTimeImmutable $authenticatedAt): DateTimeImmutable
{
    $login = $authenticatedAt->setTimezone(new DateTimeZone(PORTAL_SESSION_TIMEZONE));
    $tenHours = $login->modify('+' . PORTAL_SESSION_MAX_SECONDS . ' seconds');
    $nextDay = $login->modify('tomorrow')->setTime(0, 0, 0);
    return $tenHours < $nextDay ? $tenHours : $nextDay;
}

function portal_set_session_cookie(int $expiresAt): void
{
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) return;
    setcookie(session_name(), session_id(), [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => portal_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function portal_initialize_authenticated_session(array $user, ?DateTimeImmutable $now = null): void
{
    $now = ($now ?? portal_session_now())->setTimezone(new DateTimeZone(PORTAL_SESSION_TIMEZONE));
    $expiry = portal_calculate_absolute_expiry($now);
    $_SESSION['authenticated_at'] = $now->format(DateTimeInterface::ATOM);
    $_SESSION['absolute_expires_at'] = $expiry->getTimestamp();
    $_SESSION['last_activity_at'] = $now->format(DateTimeInterface::ATOM);
    $_SESSION['login_date'] = $now->format('Y-m-d');
    $_SESSION['session_user_id'] = (int) ($user['id'] ?? 0);
    $_SESSION['session_identifier'] = hash('sha256', session_id());
    portal_set_session_cookie($expiry->getTimestamp());
}

function portal_send_private_cache_headers(): void
{
    if (headers_sent()) return;
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie', false);
}

function portal_safe_return_path($candidate): string
{
    if ($candidate !== null && !is_string($candidate)) return BASE_URL . '/index.php';
    $candidate = trim((string) $candidate);
    if ($candidate === '' || $candidate[0] !== '/' || strpos($candidate, '//') === 0 || preg_match('/[\r\n]/', $candidate)) {
        return BASE_URL . '/index.php';
    }
    return $candidate;
}

function portal_expire_session(): void
{
    logout_user();
}

function portal_validate_authenticated_session(): bool
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) return false;
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) return false;

    // Give already-authenticated pre-deployment sessions a bounded working-day
    // lifetime instead of invalidating every user at deployment time.
    if (empty($_SESSION['absolute_expires_at'])) {
        portal_initialize_authenticated_session($_SESSION['user']);
    }

    $now = portal_session_now();
    $expiry = (int) ($_SESSION['absolute_expires_at'] ?? 0);
    $loginDate = (string) ($_SESSION['login_date'] ?? '');
    $boundUserId = (int) ($_SESSION['session_user_id'] ?? 0);
    $identifier = (string) ($_SESSION['session_identifier'] ?? '');
    if (
        $expiry <= $now->getTimestamp()
        || $loginDate === ''
        || $loginDate !== $now->format('Y-m-d')
        || $boundUserId !== $userId
        || !hash_equals(hash('sha256', session_id()), $identifier)
    ) {
        portal_expire_session();
        return false;
    }

    $_SESSION['last_activity_at'] = $now->format(DateTimeInterface::ATOM);
    portal_send_private_cache_headers();
    return true;
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
        // A temporary database or network failure must not revoke a still-valid
        // authenticated session. Authorization is rechecked on the next request.
        error_log('Unable to refresh authenticated portal user: ' . $e->getMessage());
    }
}

/**
 * Identify phones/tablets without treating a narrow desktop window or a
 * touch-enabled Windows workstation as mobile. Role remains authoritative;
 * this signal is only applied after the database-backed account is resolved.
 */
function portal_request_is_phone_or_tablet(): bool
{
    $clientMobile = trim((string) ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
    if ($clientMobile === '?1') return true;
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return false;
    if (preg_match('/windows nt|cros|x11; linux x86_64/', $ua)) return false;
    return (bool) preg_match('/iphone|ipod|ipad|android|kindle|silk|tablet|mobile|macintosh.*mobile/', $ua);
}

function portal_render_employee_desktop_required(): void
{
    portal_send_private_cache_headers();
    http_response_code(403);
    $logout = htmlspecialchars(BASE_URL . '/login.php?action=logout', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#721b1a"><title>Desktop Access Required | Hambelela Portal</title><style>*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Figtree,system-ui,-apple-system,sans-serif;background:#fbf7f2;color:#2b1b16}body{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:max(24px,env(safe-area-inset-top)) max(18px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(18px,env(safe-area-inset-left))}.desktop-required{width:min(440px,100%);padding:30px 24px;border:1px solid #e8ddd3;border-radius:16px;background:#fff;box-shadow:0 18px 48px rgba(114,27,26,.1);text-align:center}.brand{display:grid;place-items:center;width:54px;height:54px;margin:0 auto 18px;border-radius:13px;background:#721b1a;color:#fff;font-size:24px;font-weight:800}.eyebrow{margin:0 0 8px;color:#ab3619;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}h1{margin:0 0 12px;color:#721b1a;font-size:25px}p{margin:0;color:#6b4c3b;font-size:14px;line-height:1.6}a{display:inline-flex;min-height:44px;margin-top:24px;padding:0 20px;align-items:center;justify-content:center;border-radius:10px;background:#721b1a;color:#fff;font-weight:700;text-decoration:none}</style></head><body><main class="desktop-required"><div class="brand" aria-hidden="true">H</div><p class="eyebrow">Hambelela Organic</p><h1>Desktop Access Required</h1><p>Your employee portal is available on the workplace desktop.<br>Please use your assigned work computer to access Hambelela Portal.</p><a href="'.$logout.'">Sign Out</a></main></body></html>';
    exit;
}

function require_login(): void
{
    $requestedPath = portal_safe_return_path((string) ($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php')));
    $hadUser = isset($_SESSION['user']) && is_array($_SESSION['user']);
    if (!$hadUser || !portal_validate_authenticated_session()) {
        $reason = $hadUser ? '&reason=session_expired' : '';
        header('Location: ' . BASE_URL . '/login.php?return=' . rawurlencode($requestedPath) . $reason, true, 303);
        exit;
    }

    refresh_logged_in_user();

    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/login.php?return=' . rawurlencode($requestedPath), true, 303);
        exit;
    }

    if ((string) ($_SESSION['user']['role_key'] ?? '') !== 'owner_admin' && portal_request_is_phone_or_tablet()) {
        portal_render_employee_desktop_required();
    }

    $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (
        !empty($_SESSION['must_change_access_code'])
        && !in_array($scriptName, ['change-access-code.php', 'login.php'], true)
    ) {
        header('Location: ' . BASE_URL . '/change-access-code.php', true, 303);
        exit;
    }

    require_once __DIR__ . '/employee-features.php';
    enforce_employee_feature_for_current_request();
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => (bool) ($params['secure'] ?? portal_request_is_https()),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }
    portal_send_private_cache_headers();
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
