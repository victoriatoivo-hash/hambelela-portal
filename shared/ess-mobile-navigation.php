<?php
$essMobileApp = ['name'=>'Operations','icon'=>'clipboard-check','href'=>BASE_URL.'/apps/operations/index.php'];
if (function_exists('current_role_key') && current_role_key() !== 'owner_admin') {
    $essMobileApp = ($essShellApps ?? $apps)[0] ?? ['name'=>'My account','icon'=>'user-round','href'=>BASE_URL.'/apps/operations/my-account.php'];
}
$essDeliveryShortcut = function_exists('current_role_key') && in_array(current_role_key(), ['delivery_driver','owner_admin'], true);
$essDeliveryPath = BASE_URL.'/apps/delivery/index.php';
$essMobileIsDelivery = $essDeliveryShortcut && rtrim((string)($essMobileApp['href'] ?? ''), '/') === $essDeliveryPath;
if ($essMobileIsDelivery) { $essMobileApp['name']='Deliveries'; $essMobileApp['icon']='truck'; }
$essOnDelivery = in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['index.php','driver-view.php'], true) && strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/apps/delivery/') !== false;
?>
<nav class="ess-mobile-nav" aria-label="Mobile navigation"><a href="<?= BASE_URL ?>/index.php" <?= ($essActiveModule ?? 'Dashboard') === 'Dashboard' ? 'aria-current="page"' : '' ?>><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Dashboard</span></a><a href="<?= htmlspecialchars($essMobileApp['href'], ENT_QUOTES, 'UTF-8') ?>" <?= $essMobileIsDelivery && $essOnDelivery ? 'aria-current="page"' : '' ?>><i data-lucide="<?= htmlspecialchars($essMobileApp['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($essMobileApp['name'], ENT_QUOTES, 'UTF-8') ?></span></a><?php if ($essDeliveryShortcut && !$essMobileIsDelivery): ?><a href="<?= htmlspecialchars($essDeliveryPath,ENT_QUOTES,'UTF-8') ?>" <?= $essOnDelivery ? 'aria-current="page"' : '' ?>><i data-lucide="truck" aria-hidden="true"></i><span>Deliveries</span></a><?php endif; ?><a href="<?= BASE_URL ?>/notifications.php"><i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span></a><button type="button" data-ess-more aria-controls="ess-more-dialog" aria-expanded="false"><i data-lucide="menu" aria-hidden="true"></i><span>More</span></button></nav>
<dialog id="ess-more-dialog" class="ess-more-dialog" aria-labelledby="ess-more-title"><header><h2 id="ess-more-title">Your workspace</h2><button class="ess-icon-btn" type="button" data-ess-close aria-label="Close navigation"><i data-lucide="x" aria-hidden="true"></i></button></header><nav aria-label="All portal links">
    <?php ess_render_navigation($essShellApps ?? $apps, $essActiveModule ?? 'Dashboard'); ?>
    <a class="ess-quick-link" href="<?= BASE_URL ?>/apps/operations/my-account.php"><i data-lucide="user-round" aria-hidden="true"></i>My account</a><a class="ess-quick-link" href="<?= BASE_URL ?>/login.php?action=logout"><i data-lucide="log-out" aria-hidden="true"></i>Logout</a>
</nav></dialog>
