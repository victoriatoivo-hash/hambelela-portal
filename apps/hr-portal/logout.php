<?php
require_once __DIR__ . '/config.php';
startSession();
session_destroy();
header('Location: ' . SITE_URL . '/index.php');
exit;
