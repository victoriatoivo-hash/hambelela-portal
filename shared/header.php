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
<header class="topbar">
    <button class="mobile-nav-toggle" type="button" aria-label="Open navigation" aria-controls="portal-sidebar" aria-expanded="false">
        <i data-lucide="menu"></i>
    </button>
    <a class="brand" href="<?= BASE_URL ?>/index.php" aria-label="Hambelela portal home">
        Hambelela <span>Business Portal</span>
    </a>
    <div class="topbar-actions">
        <div
            class="notification-center"
            data-notification-center
            data-notification-endpoint="<?= BASE_URL ?>/notifications-api.php"
            data-notification-last-id="<?= (int) $notificationLastId ?>"
            data-notification-desktop="<?= !empty($notificationPreferences['desktop_enabled']) ? '1' : '0' ?>"
            data-notification-sound="<?= !empty($notificationPreferences['sound_enabled']) ? '1' : '0' ?>"
        >
            <button class="notification-bell" type="button" data-notification-toggle aria-label="Notifications" aria-expanded="false">
                <i data-lucide="bell"></i>
                <span class="notification-count<?= $notificationUnread > 0 ? '' : ' is-hidden' ?>" data-notification-count><?= (int) $notificationUnread ?></span>
            </button>
            <div class="notification-menu" data-notification-menu hidden>
                <div class="notification-menu-head">
                    <div>
                        <strong>Notifications</strong>
                        <small data-notification-summary><?= $notificationUnread ?> unread</small>
                    </div>
                    <button type="button" data-notification-mark-read>Mark all read</button>
                </div>
                <div class="notification-list" data-notification-list>
                    <?php if (!$notifications): ?>
                        <div class="notification-empty">No notifications yet.</div>
                    <?php endif; ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                            $priority = (string) ($notification['priority'] ?? 'normal');
                            $link = (string) ($notification['action_link'] ?? '');
                            $isUnread = empty($notification['read_at']);
                        ?>
                        <button
                            class="notification-item<?= $isUnread ? ' is-unread' : '' ?>"
                            type="button"
                            data-notification-item
                            data-notification-id="<?= (int) $notification['id'] ?>"
                            data-notification-link="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <span class="notification-priority <?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>"></span>
                            <span>
                                <strong><?= htmlspecialchars((string) ($notification['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                                <em><?= htmlspecialchars((string) ($notification['module'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></em>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="notification-menu-foot">
                    <a href="<?= BASE_URL ?>/notifications.php">View all</a>
                    <a href="<?= BASE_URL ?>/apps/operations/my-account.php#notification-preferences">Settings</a>
                    <button type="button" data-notification-clear>Clear</button>
                </div>
            </div>
        </div>
        <div class="account">
        <div>
            <strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <small><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <a class="logout" href="<?= BASE_URL ?>/login.php?action=logout">Logout</a>
        </div>
    </div>
</header>
<div class="shell">
