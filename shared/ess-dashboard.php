<?php
declare(strict_types=1);

// Presentation only: the entry point supplies the existing, permission-filtered
// routes and metrics. Never query or mutate business data from this template.
if (($roleKey ?? '') !== 'owner_admin') { return; }
require_once __DIR__ . '/ess-inspiration.php';
require_once __DIR__ . '/ess-navigation.php';
$essNow = new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek'));
$essInspiration = ess_daily_inspiration($essNow);
$essFirstName = explode(' ', trim($headerUserName))[0] ?: 'there';
$essHour = (int) $essNow->format('G');
$essGreeting = $essHour < 12 ? 'Good morning' : ($essHour < 18 ? 'Good afternoon' : 'Good evening');
$essEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$essQuickLinks = [
    ['Packing List', '/apps/operations/consignments.php', 'package-open'],
    ['Courier waybills', '/apps/operations/courier.php', 'truck'],
    ['System Issues Log', '/apps/operations/system-issues.php', 'circle-help'],
    ['My account', '/apps/operations/my-account.php', 'user-round'],
];
?>
<a class="ess-skip-link" href="#ess-main">Skip to dashboard</a>
<aside class="ess-sidebar" aria-label="Administrator navigation">
    <button type="button" class="ess-sidebar-toggle" data-ess-sidebar-toggle aria-label="Collapse sidebar" aria-expanded="true" aria-controls="ess-sidebar-links"><i data-lucide="panel-left-close" aria-hidden="true"></i></button>
    <a class="ess-sidebar-logo" href="<?= BASE_URL ?>/index.php" aria-label="Essentials dashboard">
        <span class="ess-sidebar-logo-title">essentials<span>.</span></span>
        <span class="ess-sidebar-logo-subtitle">Hambelela Organic</span>
        <i class="ess-logo-compact" data-lucide="leaf" aria-hidden="true"></i>
    </a>
    <div class="ess-sidebar-divider">WORKSPACE</div>
    <nav id="ess-sidebar-links" class="ess-nav" aria-label="Business modules">
        <a class="ess-nav-item is-active" href="<?= BASE_URL ?>/index.php" aria-current="page" aria-label="Dashboard" title="Dashboard"><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Dashboard</span></a>
        <?php ess_render_navigation($apps); ?>
    </nav>
    <div class="ess-sidebar-bottom">
        <a class="ess-nav-item" href="<?= BASE_URL ?>/notifications.php" title="Notifications" aria-label="Notifications"><i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span></a>
        <a class="ess-nav-item" href="<?= BASE_URL ?>/apps/operations/my-account.php" title="My account" aria-label="My account"><i data-lucide="user-round" aria-hidden="true"></i><span>My account</span></a>
        <a class="ess-nav-item" href="<?= BASE_URL ?>/login.php?action=logout" title="Logout" aria-label="Logout"><i data-lucide="log-out" aria-hidden="true"></i><span>Logout</span></a>
        <a class="ess-support-card" href="<?= BASE_URL ?>/apps/operations/system-issues.php"><i data-lucide="circle-help" aria-hidden="true"></i><span><strong>Need a hand?</strong><small>Open System Issues Log <span aria-hidden="true">↗</span></small></span></a>
    </div>
</aside>
<main id="ess-main" class="workspace ess-dashboard-main" tabindex="-1">
    <header class="ess-topbar">
        <div class="ess-welcome"><span class="ess-section-label">YOUR WORKSPACE</span><h1><span data-ess-greeting><?= $essGreeting ?></span>, <?= $essEscape($essFirstName) ?></h1><p>Welcome to your business command center.</p></div>
        <div class="ess-topbar-actions">
            <label class="ess-search"><i data-lucide="search" aria-hidden="true"></i><input type="search" data-ess-search placeholder="Find a module…" aria-label="Search dashboard modules" aria-controls="ess-modules" autocomplete="off"><span aria-hidden="true">/</span></label>
            <div class="ess-account-slot" data-portal-header-status-target></div>
        </div>
    </header>
    <section class="ess-hero ess-single-thought" aria-label="Today’s Thought" data-ess-inspiration data-date="<?= $essNow->format('Y-m-d') ?>">
        <div class="ess-inspiration-block" data-ess-verse-panel<?= $essInspiration['type'] !== 'verse' ? ' hidden' : '' ?>><div class="ess-inspiration-label">TODAY’S THOUGHT</div><blockquote class="ess-daily-verse" data-ess-verse>“<?= $essEscape($essInspiration['verse']['text']) ?>”</blockquote><div class="ess-verse-reference" data-ess-reference><?= $essEscape($essInspiration['verse']['reference']) ?> · KJV</div></div>
        <div class="ess-inspiration-block" data-ess-quote-panel<?= $essInspiration['type'] !== 'quote' ? ' hidden' : '' ?>><div class="ess-inspiration-label">TODAY’S THOUGHT</div><blockquote class="ess-daily-verse" data-ess-quote>“<?= $essEscape($essInspiration['quote']['text']) ?>”</blockquote><a class="ess-quote-author" data-ess-author href="<?= $essEscape($essInspiration['quote']['source']) ?>" target="_blank" rel="noopener noreferrer"><?= $essEscape($essInspiration['quote']['author']) ?></a></div>
    </section>
    <div class="ess-section-heading"><h2>Business modules</h2><span>Everything you need, in one place</span></div>
    <section class="ess-module-grid" id="ess-modules" aria-label="Business apps">
        <?php foreach ($apps as $index => $app): ?>
        <a class="ess-module-card" data-ess-module data-ess-accent="<?= $essEscape($app['name']) ?>" data-search="<?= $essEscape($app['name'] . ' ' . $app['desc']) ?>" href="<?= $essEscape($app['href']) ?>" style="--ess-order:<?= (int) $index ?>" aria-label="<?= $essEscape($app['name'] . ' — ' . $app['desc']) ?>">
            <span class="ess-module-icon"><i data-lucide="<?= $essEscape($app['icon']) ?>" aria-hidden="true"></i></span>
            <h3><?= $essEscape($app['name']) ?></h3><p><?= $essEscape($app['desc']) ?></p>
            <?php if ($app['name'] === 'Packing List'): ?><span class="ess-module-badge<?= $dashboardPackingUnread > 0 ? '' : ' is-hidden' ?>" data-packing-unread-badge<?= $dashboardPackingUnread > 0 ? '' : ' hidden' ?> aria-label="<?= $dashboardPackingUnread ?> unread Packing List items"><?= $dashboardPackingUnread > 99 ? '99+' : $dashboardPackingUnread ?></span><?php endif; ?>
            <?php if ($app['name'] === 'System Issues Log' && !empty($app['badge'])): ?><span class="ess-module-badge<?= !empty($app['needs_information']) ? ' ess-needs-information' : '' ?>" aria-label="<?= (int) $app['badge'] ?> open system issues<?= !empty($app['needs_information']) ? ', information requested' : '' ?>"><?= (int) $app['badge'] > 99 ? '99+' : (int) $app['badge'] ?></span><?php endif; ?>
            <span class="ess-module-arrow" aria-hidden="true"><i data-lucide="arrow-up-right"></i></span>
        </a>
        <?php endforeach; ?>
    </section>
    <p class="ess-empty" data-ess-search-empty hidden>No modules match your search. Try another name.</p>
    <span class="ess-sr-only" data-ess-search-status role="status" aria-live="polite"></span>
    <section class="ess-panel ess-performance" aria-labelledby="ess-marketing-title">
        <header class="ess-panel-header"><div><span class="ess-section-label">MARKETING</span><h2 id="ess-marketing-title" class="ess-panel-title">Performance this month</h2></div><a class="ess-panel-link" href="<?= BASE_URL ?>/apps/marketing/index.php?view=analytics">Open Marketing <i data-lucide="arrow-up-right" aria-hidden="true"></i></a></header>
        <?php if ($dashboardMarketing !== null): ?>
        <div class="ess-performance-grid">
            <?php $essMetrics = [
                ['Published', number_format($dashboardMarketing['published']), 'send'],
                ['Awaiting Approval', number_format($dashboardMarketing['awaiting']), 'clock-3'],
                ['Active Campaigns', number_format($dashboardMarketing['campaigns']), 'megaphone'],
                ['Ad Spend', 'N$ ' . number_format($dashboardMarketing['spend'], 2), 'wallet'],
                ['Attributed Sales', $dashboardMarketing['sales'] === null ? 'Not connected' : 'N$ ' . number_format($dashboardMarketing['sales'], 2), 'chart-no-axes-combined'],
            ]; foreach ($essMetrics as [$label, $value, $icon]): ?>
            <article class="ess-stat"><span class="ess-stat-icon"><i data-lucide="<?= $icon ?>" aria-hidden="true"></i></span><div><div class="ess-stat-label"><?= $label ?></div><div class="ess-stat-value"><?= $essEscape($value) ?></div></div></article>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="ess-empty">Marketing data is currently unavailable. Open Marketing to check the latest information.</p><?php endif; ?>
    </section>
    <div class="ess-lower-grid">
        <section class="ess-panel" aria-labelledby="ess-activity-title"><header class="ess-panel-header"><div><span class="ess-section-label">KEEP UP TO DATE</span><h2 class="ess-panel-title" id="ess-activity-title">Recent notifications</h2></div><a class="ess-panel-link" href="<?= BASE_URL ?>/notifications.php">View all <i data-lucide="arrow-up-right" aria-hidden="true"></i></a></header>
            <div class="ess-activity-list">
            <?php foreach ($headerNotificationLatest as $notice): ?>
                <a class="ess-activity-item" href="<?= $essEscape(($notice['action_link'] ?? '') ?: BASE_URL . '/notifications.php') ?>"><span class="ess-activity-avatar"><i data-lucide="bell" aria-hidden="true"></i></span><span><strong><?= $essEscape($notice['title'] ?? 'Notification') ?></strong><span class="ess-activity-text"><?= $essEscape($notice['message'] ?? '') ?></span></span><time class="ess-activity-time"><?= !empty($notice['created_at']) ? $essEscape(date('d M', strtotime($notice['created_at']))) : '' ?></time></a>
            <?php endforeach; ?>
            <?php if (!$headerNotificationLatest): ?><p class="ess-empty">You’re all caught up. New notifications will appear here.</p><?php endif; ?>
            </div>
        </section>
        <section class="ess-panel" aria-labelledby="ess-quick-title"><header class="ess-panel-header"><div><span class="ess-section-label">YOUR SHORTCUTS</span><h2 class="ess-panel-title" id="ess-quick-title">Quick links</h2></div><i data-lucide="arrow-up-right" aria-hidden="true"></i></header><div class="ess-quick-links">
            <?php foreach ($essQuickLinks as [$label, $path, $icon]): ?><a class="ess-quick-link" href="<?= BASE_URL . $path ?>"><i data-lucide="<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span><i data-lucide="chevron-right" aria-hidden="true"></i></a><?php endforeach; ?>
        </div></section>
        <section class="ess-promo" aria-label="Essentials"><span class="ess-promo-small">GROWING WITH PURPOSE</span><h3>Small improvements.<br>Stronger systems.</h3><span class="ess-promo-foot">Hambelela Organic <i data-lucide="leaf" aria-hidden="true"></i></span></section>
    </div>
    <footer class="ess-page-footer"><span>essentials · Hambelela Organic</span><span>Built around your business.</span></footer>
</main>
<nav class="ess-mobile-nav" aria-label="Mobile navigation"><a href="<?= BASE_URL ?>/index.php" aria-current="page"><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Dashboard</span></a><a href="<?= BASE_URL ?>/apps/operations/index.php"><i data-lucide="clipboard-check" aria-hidden="true"></i><span>Operations</span></a><a href="<?= BASE_URL ?>/notifications.php"><i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span></a><button type="button" data-ess-more aria-controls="ess-more-dialog" aria-expanded="false"><i data-lucide="menu" aria-hidden="true"></i><span>More</span></button></nav>
<dialog id="ess-more-dialog" class="ess-more-dialog" aria-labelledby="ess-more-title"><header><h2 id="ess-more-title">Your workspace</h2><button class="ess-icon-btn" type="button" data-ess-close aria-label="Close navigation"><i data-lucide="x" aria-hidden="true"></i></button></header><nav aria-label="All portal links">
    <?php ess_render_navigation($apps); ?>
    <a class="ess-quick-link" href="<?= BASE_URL ?>/apps/operations/my-account.php"><i data-lucide="user-round" aria-hidden="true"></i>My account</a><a class="ess-quick-link" href="<?= BASE_URL ?>/login.php?action=logout"><i data-lucide="log-out" aria-hidden="true"></i>Logout</a>
</nav></dialog>
<script type="application/json" id="ess-inspiration-data"><?= json_encode(ess_inspiration_catalog(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script defer src="<?= BASE_URL ?>/assets/js/ess-dashboard.js?v=<?= filemtime(BASE_PATH . '/assets/js/ess-dashboard.js') ?>"></script>
