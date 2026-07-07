<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$activeApp = $activeApp ?? 'dashboard';
$notifications = [];
$notificationPreferences = ['desktop_enabled' => 1, 'sound_enabled' => 1];
$notificationUnread = 0;
$notificationLastId = 0;
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
    <?php foreach (($extraStylesheets ?? []) as $stylesheet): ?>
        <?php
            $stylesheetPath = (string) ($stylesheet['path'] ?? '');
            $stylesheetVersion = (string) ($stylesheet['version'] ?? $assetVersion);
            if ($stylesheetPath === '') {
                continue;
            }
            $stylesheetHref = ltrim($stylesheetPath, '/');
        ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/<?= htmlspecialchars($stylesheetHref, ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($stylesheetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
    <script defer src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
<div class="shell">
