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
$headerNotificationLatest = [];
$headerNotificationPreferences = ['desktop_enabled' => 1, 'sound_enabled' => 0, 'sound_volume' => 65];
if ($showPortalHeaderStatus && function_exists('notifications_summary_for_current_user')) {
    try {
        $headerNotificationSummary = notifications_summary_for_current_user(3);
        $headerNotificationUnread = (int) ($headerNotificationSummary['unread_count'] ?? 0);
        $headerNotificationLatest = array_slice((array) ($headerNotificationSummary['latest'] ?? []), 0, 3);
        $headerNotificationPreferences = (array) ($headerNotificationSummary['preferences'] ?? $headerNotificationPreferences);
    } catch (Throwable $headerNotificationError) {
        error_log('Notification header summary failed: ' . $headerNotificationError->getMessage());
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($showPortalHeaderStatus): ?><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/urgent-task-alert.css?v=<?= htmlspecialchars((string) filemtime(BASE_PATH . '/assets/css/urgent-task-alert.css'), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
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
    <script>window.HambelelaPortalUser={id:<?= (int) ($headerUser['id'] ?? 0) ?>,role:<?= json_encode((string) ($headerUser['role_key'] ?? 'guest')) ?>};</script>
    <script defer src="<?= BASE_URL ?>/assets/js/portal.js?v=<?= htmlspecialchars($portalJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php if ($showPortalHeaderStatus): ?>
        <script defer src="<?= BASE_URL ?>/assets/js/portal-presence.js?v=<?= htmlspecialchars($presenceJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endif; ?>
</head>
<body>
<div class="shell">
    <?php if ($showPortalHeaderStatus): ?>
        <section class="portal-header-status<?= $showPortalHeaderAccount ? ' portal-header-status--has-account' : '' ?>" data-portal-header-status
                 data-presence-endpoint="<?= htmlspecialchars(BASE_URL . '/apps/operations/portal-presence.php', ENT_QUOTES, 'UTF-8') ?>"
                 data-notification-endpoint="<?= htmlspecialchars(BASE_URL . '/api/notifications.php?mode=summary', ENT_QUOTES, 'UTF-8') ?>">
            <div class="portal-header-actions">
            <div class="portal-header-action portal-online-widget portal-online-staff" data-portal-online-widget data-online-staff-button
                 role="button" tabindex="0" aria-label="Online employees" aria-expanded="false">
                <div class="portal-online-avatars" data-portal-online-avatars></div>
                <span class="portal-online-count portal-online-staff__count" data-portal-online-count>0 online</span>
                <div class="portal-online-popover" data-portal-online-popover hidden>
                    <strong>Currently online</strong>
                    <div data-portal-online-list>
                        <p class="portal-online-empty">Checking staff status…</p>
                    </div>
                </div>
            </div>
            <div class="portal-notification-control">
            <a class="portal-header-action portal-header-notifications portal-notification-button" data-notification-button href="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>"
               aria-label="Notifications, <?= (int) $headerNotificationUnread ?> unread" aria-expanded="false" aria-controls="portal-notification-preview">
                <i data-lucide="bell"></i>
                <span class="portal-notification-button__badge<?= $headerNotificationUnread > 0 ? '' : ' is-hidden' ?>" data-notification-count><?= htmlspecialchars($headerNotificationUnread > 99 ? '99+' : ($headerNotificationUnread > 0 ? (string) $headerNotificationUnread : ''), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <div id="portal-notification-preview" class="portal-notification-preview" data-notification-preview role="tooltip" aria-hidden="true">
                <div class="portal-notification-preview__header"><strong class="portal-notification-preview__title">Notifications</strong><span class="portal-notification-preview__count" data-notification-preview-count><?= (int) $headerNotificationUnread ?> unread</span></div>
                <div data-notification-preview-list>
                <?php if ($headerNotificationLatest): foreach ($headerNotificationLatest as $headerNotification): ?>
                    <a class="portal-notification-preview__item" href="<?= htmlspecialchars((string) (($headerNotification['action_link'] ?? '') ?: BASE_URL . '/notifications.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="portal-notification-preview__indicator" aria-hidden="true"></span><span><strong class="portal-notification-preview__item-title"><?= htmlspecialchars((string) ($headerNotification['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></strong><span class="portal-notification-preview__item-text"><?= htmlspecialchars((string) ($headerNotification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></span><time class="portal-notification-preview__time"><?= htmlspecialchars(date('h:i A', strtotime((string) ($headerNotification['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?></time>
                    </a>
                <?php endforeach; else: ?>
                    <div class="portal-notification-preview__empty"><strong>No new notifications</strong><span>You are all caught up.</span></div>
                <?php endif; ?>
                </div>
                <form class="portal-notification-preview__settings notification-sound-settings" data-notification-sound-settings>
                    <div class="notification-sound-settings__toggles">
                        <label class="notification-sound-toggle" for="notification-sounds-enabled">
                            <span>Sounds</span>
                            <input type="checkbox" id="notification-sounds-enabled" name="sound_enabled" value="1" aria-label="Enable notification sounds" <?= !empty($headerNotificationPreferences['sound_enabled']) ? 'checked' : '' ?>>
                        </label>
                        <label class="notification-sound-toggle" for="desktop-notifications-enabled">
                            <span>Desktop alerts</span>
                            <input type="checkbox" id="desktop-notifications-enabled" name="desktop_enabled" value="1" aria-label="Enable desktop notifications" <?= !empty($headerNotificationPreferences['desktop_enabled']) ? 'checked' : '' ?>>
                        </label>
                    </div>
                    <div class="notification-volume-row">
                        <label for="notification-volume">Volume</label>
                        <input type="range" id="notification-volume" name="sound_volume" min="0" max="100" step="1" value="<?= (int) ($headerNotificationPreferences['sound_volume'] ?? 65) ?>" aria-label="Notification volume">
                        <button type="button" class="notification-test-sound" data-notification-test-sound <?= empty($headerNotificationPreferences['sound_enabled']) ? 'disabled' : '' ?>>Test</button>
                    </div>
                </form>
                <a class="portal-notification-preview__footer" href="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>">View all notifications →</a>
            </div>
            </div>
            <div class="portal-header-clock" aria-label="Current date and time">
                <span class="portal-header-clock__date" data-portal-date>---</span>
                <strong class="portal-header-clock__time" data-portal-time>--:-- --</strong>
            </div>
            </div>
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
