<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

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
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $loginUrl = htmlspecialchars((BASE_URL ?: '') . '/index.php', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HR Portal access</title></head><body style="font-family:system-ui,sans-serif;padding:32px;color:#1a1a1a"><main style="max-width:560px;margin:auto"><h1 style="color:#721b1a">Unable to open HR Portal</h1><p>' . $safeMessage . '</p><p><a href="' . $loginUrl . '">Return to the Business Portal</a></p></main></body></html>';
    exit;
}

try {
    $portalUserId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($portalUserId < 1) {
        throw new HrBridgeUserException('Your portal session does not contain a valid employee account.');
    }

    $link = db()->prepare(
        'SELECT hr_employee_id
         FROM employee_user_links
         WHERE portal_user_id = ? AND active = 1
         LIMIT 1'
    );
    $link->execute([$portalUserId]);
    $hrEmployeeId = (int) $link->fetchColumn();
    $link->closeCursor();

    if ($hrEmployeeId < 1) {
        throw new HrBridgeUserException('Your employee account is not linked to an HR profile. Ask an Owner/Admin to add the link in Employees & Roles.');
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

    $accountStmt = $hrDb->prepare(
        'SELECT id, name, email, role, employee_id
         FROM users
         WHERE employee_id = ? AND active = 1
         LIMIT 1'
    );
    $accountStmt->execute([$hrEmployeeId]);
    $account = $accountStmt->fetch();
    $accountStmt->closeCursor();

    if (!$account) {
        throw new HrBridgeUserException('The linked HR profile does not have an active HR Portal account.');
    }

    session_write_close();
    session_name('hambelela_hr_test_session');
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['user'] = [
        'id' => (int) $account['id'],
        'name' => (string) $account['name'],
        'email' => (string) $account['email'],
        'role' => (string) $account['role'],
        'emp_id' => (int) $account['employee_id'],
    ];
    $_SESSION['portal_return_to'] = (BASE_URL ?: '') . '/index.php';
    session_write_close();

    $destination = (string) $account['role'] === 'employee' ? 'self-service.php' : 'dashboard.php';
    header('Location: ' . (BASE_URL ?: '') . '/apps/hr-portal/' . $destination, true, 303);
    exit;
} catch (Throwable $error) {
    error_log('HR portal bridge failed: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    $message = $error instanceof HrBridgeUserException
        ? $error->getMessage()
        : 'Unable to sign in to the HR Portal right now. Please try again or contact an administrator.';
    hr_bridge_fail($message);
}
