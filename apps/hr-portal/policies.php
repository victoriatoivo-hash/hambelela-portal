<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
// Redirect to documents page - policies are now managed under Documents
header('Location: documents.php');
exit;
