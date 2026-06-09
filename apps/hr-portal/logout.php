<?php
require_once __DIR__ . '/config.php';
startSession();
session_destroy();

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$embeddedOffset = strpos($scriptName, '/apps/hr-portal/');

if ($embeddedOffset !== false) {
    $portalRoot = substr($scriptName, 0, $embeddedOffset);
    header('Location: ' . ($portalRoot === '' ? '' : $portalRoot) . '/index.php');
    exit;
}

header('Location: ' . SITE_URL . '/index.php');
exit;
