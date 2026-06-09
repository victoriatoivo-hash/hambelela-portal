<?php
require_once __DIR__ . '/config.php';
startSession();
session_destroy();

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$portalRoot = '';
if (strpos($scriptName, '/apps/hr-portal/') !== false) {
    $portalRoot = substr($scriptName, 0, strpos($scriptName, '/apps/hr-portal/'));
}

header('Location: ' . ($portalRoot !== '' ? $portalRoot : SITE_URL) . '/index.php');
exit;
