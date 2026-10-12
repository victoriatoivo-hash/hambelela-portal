<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/sidebar-badges.css?v=<?= filemtime(BASE_PATH.'/assets/css/sidebar-badges.css') ?>">
<a class="ess-skip-link" href="#ess-main">Skip to main content</a>
<aside class="ess-sidebar" aria-label="Workspace navigation">
    <button type="button" class="ess-sidebar-toggle" data-ess-sidebar-toggle aria-label="Collapse sidebar" aria-expanded="true" aria-controls="ess-sidebar-links"><i data-lucide="panel-left-close" aria-hidden="true"></i></button>
    <a class="ess-sidebar-logo" href="<?= BASE_URL ?>/index.php" aria-label="Essentials dashboard">
        <span class="ess-sidebar-logo-title">essentials<span>.</span></span>
        <span class="ess-sidebar-logo-subtitle">Hambelela Organic</span>
        <i class="ess-logo-compact" data-lucide="leaf" aria-hidden="true"></i>
    </a>
    <div class="ess-sidebar-divider">WORKSPACE</div>
    <nav id="ess-sidebar-links" class="ess-nav" aria-label="Business modules">
        <a class="ess-nav-item<?= ($essActiveModule ?? 'Dashboard') === 'Dashboard' ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/index.php" <?= ($essActiveModule ?? 'Dashboard') === 'Dashboard' ? 'aria-current="page"' : '' ?> aria-label="Dashboard" title="Dashboard"><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Dashboard</span></a>
        <?php ess_render_navigation($essShellApps ?? $apps, $essActiveModule ?? 'Dashboard'); ?>
    </nav>
    <div class="ess-sidebar-bottom">
        <?php if (ess_can_access_settings()): ?><a class="ess-nav-item<?= ($essActiveModule??'')==='Settings'?' is-active':'' ?>" href="<?= BASE_URL ?>/settings.php" <?= ($essActiveModule??'')==='Settings'?'aria-current="page"':'' ?> title="Settings" aria-label="Settings"><i data-lucide="settings" aria-hidden="true"></i><span>Settings</span></a><?php endif; ?>
        <a class="ess-nav-item" href="<?= BASE_URL ?>/notifications.php" title="Notifications" aria-label="Notifications"><i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span><span data-notification-count class="ess-unread-badge<?= empty($headerNotificationUnread) ? ' is-hidden' : '' ?>" aria-label="Unread notifications"><?= empty($headerNotificationUnread) ? '' : ((int)$headerNotificationUnread > 99 ? '99+' : (int)$headerNotificationUnread) ?></span></a>
        <a class="ess-nav-item<?=($essActiveModule??'')==='My Profile'?' is-active':''?>" href="<?= BASE_URL ?>/profile.php" <?=($essActiveModule??'')==='My Profile'?'aria-current="page"':''?> title="My Profile" aria-label="My Profile"><i data-lucide="user-round" aria-hidden="true"></i><span>My Profile</span></a>
        <a class="ess-nav-item" href="<?= BASE_URL ?>/login.php?action=logout" title="Logout" aria-label="Logout"><i data-lucide="log-out" aria-hidden="true"></i><span>Logout</span></a>
        <?php if (!function_exists('portal_user_can_access_feature') || portal_user_can_access_feature('system_issues')): ?><a class="ess-support-card" href="<?= BASE_URL ?>/apps/operations/system-issues.php"><i data-lucide="circle-help" aria-hidden="true"></i><span><strong>Need a hand?</strong><small>Open System Issues Log <span aria-hidden="true">↗</span></small></span></a><?php endif; ?>
    </div>
</aside>
