<?php
require_once __DIR__ . '/config.php';
startSession();
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$embeddedOffset = strpos($scriptName, '/apps/hr-portal/');
if ($embeddedOffset !== false) {
    $portalRoot = substr($scriptName, 0, $embeddedOffset);
    $defaultReturn = ($portalRoot === '' ? '' : $portalRoot) . '/index.php';
} else {
    $defaultReturn = SITE_URL . '/index.php';
}

$returnTo = (string)($_SESSION['portal_return_to'] ?? $defaultReturn);
$allowedReturns = [$defaultReturn];
if (!in_array($returnTo, $allowedReturns, true)) {
    $returnTo = $defaultReturn;
}

// Destroy only the dedicated HR session. The main portal session has a
// different cookie name and must remain available for the return redirect.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: ' . $returnTo, true, 303);
exit;
