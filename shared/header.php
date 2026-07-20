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
$responsiveAssetVersion = defined('BASE_PATH') && is_file(BASE_PATH . '/assets/css/portal-responsive.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/portal-responsive.css')
    : $assetVersion;
$datePickerCssVersion = (string) filemtime(BASE_PATH . '/assets/css/portal-date-picker.css');
$datePickerJsVersion = (string) filemtime(BASE_PATH . '/assets/js/portal-date-picker.js');
$presenceJsVersion = is_file(BASE_PATH . '/assets/js/portal-presence.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/portal-presence.js')
    : $assetVersion;
$headerAccountCssVersion = is_file(BASE_PATH . '/assets/css/portal-header-account.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/portal-header-account.css')
    : $assetVersion;
$stickyScrollbarCssVersion = is_file(BASE_PATH . '/assets/css/portal-sticky-scrollbar.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/portal-sticky-scrollbar.css')
    : $assetVersion;
$portalJsVersion = is_file(BASE_PATH . '/assets/js/portal.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/portal.js')
    : $assetVersion;
$headerUser = current_user();
$showPortalHeaderStatus = (string) ($headerUser['role_key'] ?? 'guest') !== 'guest';
$pageUsesPortalSidebar = (bool) ($pageUsesPortalSidebar ?? true);
$showPortalHeaderAccount = $showPortalHeaderStatus && !$pageUsesPortalSidebar;
$headerNotificationUnread = 0;
if ($showPortalHeaderStatus && function_exists('notifications_summary_for_current_user')) {
    $headerNotificationSummary = notifications_summary_for_current_user(1);
    $headerNotificationUnread = (int) ($headerNotificationSummary['unread_count'] ?? 0);
}
$headerUserName = trim((string) ($headerUser['name'] ?? 'User'));
$headerUserInitials = '';
foreach (preg_split('/\s+/', $headerUserName) ?: [] as $headerNamePart) {
    if ($headerNamePart !== '') {
        $headerUserInitials .= strtoupper(substr($headerNamePart, 0, 1));
    }
    if (strlen($headerUserInitials) >= 2) {
        break;
    }
}
$headerUserInitials = $headerUserInitials !== '' ? $headerUserInitials : 'U';
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-responsive.css?v=<?= htmlspecialchars($responsiveAssetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-header-account.css?v=<?= htmlspecialchars($headerAccountCssVersion, ENT_QUOTES, 'UTF-8') ?>">
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-date-picker.css?v=<?= htmlspecialchars($datePickerCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-sticky-scrollbar.css?v=<?= htmlspecialchars($stickyScrollbarCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <script defer src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal-date-picker.js?v=<?= htmlspecialchars($datePickerJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal.js?v=<?= htmlspecialchars($portalJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php if ($showPortalHeaderStatus): ?>
        <script defer src="<?= BASE_URL ?>/assets/js/portal-presence.js?v=<?= htmlspecialchars($presenceJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endif; ?>
</head>
<body>
<div class="shell">
    <?php if ($showPortalHeaderStatus): ?>
        <section class="portal-header-status<?= $showPortalHeaderAccount ? ' portal-header-status--has-account' : '' ?>" data-portal-header-status
                 data-presence-endpoint="<?= htmlspecialchars(BASE_URL . '/apps/operations/portal-presence.php', ENT_QUOTES, 'UTF-8') ?>">
            <div class="portal-header-clock" aria-label="Current Namibia time">
                <span data-portal-date>---</span>
                <strong data-portal-time>--:-- --</strong>
            </div>
            <div class="portal-online-widget" data-portal-online-widget tabindex="0"
                 aria-label="Online employees" aria-expanded="false">
                <div class="portal-online-avatars" data-portal-online-avatars></div>
                <span class="portal-online-count" data-portal-online-count>0 online</span>
                <div class="portal-online-popover" data-portal-online-popover hidden>
                    <strong>Currently online</strong>
                    <div data-portal-online-list>
                        <p class="portal-online-empty">Checking staff status…</p>
                    </div>
                </div>
            </div>
            <a class="portal-header-notifications" href="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>"
               aria-label="Notifications">
                <i data-lucide="bell"></i>
                <?php if ($headerNotificationUnread > 0): ?>
                    <span><?= htmlspecialchars($headerNotificationUnread > 99 ? '99+' : (string) $headerNotificationUnread, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </a>
            <?php if ($showPortalHeaderAccount): ?>
                <div class="portal-header-account" data-portal-header-account>
                    <a class="portal-header-user portal-header-account-identity"
                       href="<?= htmlspecialchars(BASE_URL . '/apps/operations/my-account.php', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="portal-header-avatar"><?= htmlspecialchars($headerUserInitials, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="portal-header-account-copy">
                            <strong><?= htmlspecialchars($headerUserName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars((string) ($headerUser['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    </a>
                    <a class="portal-header-logout"
                       href="<?= htmlspecialchars(BASE_URL . '/login.php?action=logout', ENT_QUOTES, 'UTF-8') ?>">
                        <i data-lucide="log-out" aria-hidden="true"></i>
                        <span>Logout</span>
                    </a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
