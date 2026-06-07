<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$activeApp = $activeApp ?? 'dashboard';
$assetVersion = defined('BASE_PATH') && is_file(BASE_PATH . '/assets/css/portal.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/portal.css')
    : (string) time();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <script defer src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
<header class="topbar">
    <button class="mobile-nav-toggle" type="button" aria-label="Open navigation" aria-controls="portal-sidebar" aria-expanded="false">
        <i data-lucide="menu"></i>
    </button>
    <a class="brand" href="<?= BASE_URL ?>/index.php" aria-label="Hambelela portal home">
        Hambelela <span>Business Portal</span>
    </a>
    <div class="account">
        <div>
            <strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <small><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <a class="logout" href="<?= BASE_URL ?>/login.php?action=logout">Logout</a>
    </div>
</header>
<div class="shell">
