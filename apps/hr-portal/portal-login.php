<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/hr-access.php';

require_login();

final class HrBridgeUserException extends RuntimeException
{
}

function hr_bridge_config_defines(string $path): array
{
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

function hr_bridge_fail(string $message, int $status = 503): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    $safeMessage = htmlspecialchars("Your HR access needs administrator attention. Please contact the administrator to check your linked profile and employee account.", ENT_QUOTES, 'UTF-8');
    $loginUrl = htmlspecialchars((BASE_URL ?: '') . '/index.php', ENT_QUOTES, 'UTF-8');
    $fontUrl = htmlspecialchars((BASE_URL ?: '') . '/assets/fonts/jost-variable.woff2', ENT_QUOTES, 'UTF-8');
    header('Cache-Control: no-store, private');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HR Portal access</title><style>@font-face{font-family:Jost;src:url("' . $fontUrl . '") format("woff2");font-display:swap}*{box-sizing:border-box}body{margin:0;background:#f6f4ec;color:#303c27;font-family:Jost,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.hr-access-card{width:100%;max-width:480px;background:white;border:1px solid #e1e5d8;border-radius:18px;padding:32px;box-shadow:0 12px 36px #303c270a}h1{font-size:26px;line-height:1.2;margin:12px 0 16px}p{line-height:1.6;color:#5d6556}.hr-access-label{font-size:12px;letter-spacing:.12em;text-transform:uppercase}a{display:inline-block;margin-top:8px;background:#52633f;color:white;border-radius:10px;padding:12px 20px;text-decoration:none}a:focus-visible{outline:3px solid #adba8d;outline-offset:3px}@media(max-width:430px){body{padding:16px}.hr-access-card{padding:24px}h1{font-size:24px}}</style></head><body><main class="hr-access-card"><span class="hr-access-label">Hambelela · HR Portal</span><h1>HR Portal access isn’t ready</h1><p>' . $safeMessage . '</p><a href="' . $loginUrl . '">Return to Portal</a></main></body></html>';
    exit;
}

try {
    $portalUserId = (int) ($_SESSION['user']['id'] ?? 0);
    $portalRoleKey = current_role_key();
    $portalUserEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $canManageHr = current_user_has_capability('manage_hr');
    if ($portalUserId < 1) {
        throw new HrBridgeUserException('Your portal session does not contain a valid employee account.');
    }

    $local = isset($localSecrets) && is_array($localSecrets) ? $localSecrets : [];
    $liveConfigPath = trim((string) (getenv('HAMBELELA_HR_LIVE_CONFIG') ?: ($local['hr_live_config_path'] ?? '')));
    if ($liveConfigPath === '') {
        $liveConfigPath = dirname(BASE_PATH) . '/hr.hambelelaorganic.com/config.php';
    }
    $live = hr_bridge_config_defines($liveConfigPath);

    $hrHost = trim((string) (getenv('HAMBELELA_HR_DB_HOST') ?: ($local['hr_db_host'] ?? ($live['DB_HOST'] ?? 'localhost'))));
    $hrName = trim((string) (getenv('HAMBELELA_HR_DB_NAME') ?: ($local['hr_db_name'] ?? ($live['DB_NAME'] ?? ''))));
    $hrUser = trim((string) (getenv('HAMBELELA_HR_DB_USER') ?: ($local['hr_db_user'] ?? ($live['DB_USER'] ?? ''))));
    $hrPass = (string) (getenv('HAMBELELA_HR_DB_PASS') ?: ($local['hr_db_pass'] ?? ($live['DB_PASS'] ?? '')));

    if ($hrName === '' || $hrUser === '') {
        throw new HrBridgeUserException('The HR Portal connection is not configured. Please contact an administrator.');
    }

    $hrDb = new PDO(
        'mysql:host=' . $hrHost . ';dbname=' . $hrName . ';charset=utf8mb4',
        $hrUser,
        $hrPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($canManageHr) {
        if ($portalUserEmail === '') {
            throw new HrBridgeUserException('Your Owner/Admin account needs a verified email address before HR Administration can be opened.');
        }
        $accountStmt = $hrDb->prepare(
            "SELECT id, name, email, role, employee_id
             FROM users
             WHERE LOWER(email) = ? AND active = 1 AND role = 'admin'
             LIMIT 1"
        );
        $accountStmt->execute([$portalUserEmail]);
        $account = $accountStmt->fetch();
        $accountStmt->closeCursor();
    } else {
        $health = hr_access_health(db(), $hrDb, $portalUserId);
        if ($health['state'] !== 'ready') { throw new HrBridgeUserException($health['detail']); }
        $account = $health['account'];
    }

    if (!$account) {
        throw new HrBridgeUserException($canManageHr
            ? 'No active HR administrator account matches your authenticated Owner/Admin email address.'
            : 'The linked HR profile does not have an active HR Portal employee account.');
    }

    require_once __DIR__ . '/includes/policy-system.php';
    hrPolicyAssignCurrent($hrDb);
    session_write_close();
    session_name('hambelela_hr_test_session');
    // Do not reuse or regenerate the Business Portal session ID under the HR
    // cookie name. A fresh ID keeps both authenticated sessions independent.
    session_id('');
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['user'] = [
        'id' => (int) $account['id'],
        'name' => (string) $account['name'],
        'email' => (string) $account['email'],
        'role' => (string) $account['role'],
        'emp_id' => (int) $account['employee_id'],
        'portal_user_id' => $portalUserId,
        'portal_role_key' => $portalRoleKey,
        'capabilities' => $canManageHr ? ['manage_hr'] : [],
    ];
    $_SESSION['portal_return_to'] = (BASE_URL ?: '') . '/index.php';
    session_write_close();

    $requested = trim((string)($_GET['return'] ?? ''));
    $allowedReturn = preg_match('#^(?:my-loans|loan-view)\.php(?:\?[A-Za-z0-9_=&%-]+)?$#', $requested) ? $requested : '';
    if ($allowedReturn === '') { $allowedReturn = hrPolicyBridgeReturn($requested); }
    $destination = $allowedReturn !== '' ? $allowedReturn : ($canManageHr ? 'dashboard.php' : 'self-service.php');
    header('Location: ' . (BASE_URL ?: '') . '/apps/hr-portal/' . $destination, true, 303);
    exit;
} catch (Throwable $error) {
    error_log('HR portal bridge failed: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    $message = $error instanceof HrBridgeUserException
        ? $error->getMessage()
        : 'Unable to sign in to the HR Portal right now. Please try again or contact an administrator.';
    hr_bridge_fail($message);
}
