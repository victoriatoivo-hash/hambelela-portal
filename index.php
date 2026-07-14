<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';

require_login();

$pageTitle = APP_NAME;
$activeApp = 'dashboard';
$roleKey = current_role_key();

if ($roleKey === 'owner_admin') {
    $apps = [
        ['name' => 'Cost Workbook', 'desc' => 'invoice to landed cost, margins, VAT and profit', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php', 'active' => true, 'tone' => 'pink'],
        ['name' => 'Operations', 'desc' => 'orders, packing, checklists, errors and KPIs', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'HR Portal', 'desc' => 'employee records, payroll and leave management', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'KPI Reports', 'desc' => 'packing speed, checklist compliance, accuracy and bonus support', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php', 'active' => true, 'tone' => 'violet'],
        ['name' => 'Packing List', 'desc' => 'consignment breakdowns, fair packer assignments and actual quantities', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'blue'],
        ['name' => 'Courier', 'desc' => 'upload waybills, alert front desk and track customer sends', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php', 'active' => true, 'tone' => 'green'],
    ];
} else {
    $apps = [
        ['name' => 'Packing List', 'desc' => 'assigned consignment packing quantities and completion status', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'green'],
    ];
}

include __DIR__ . '/shared/header.php';
include __DIR__ . '/shared/sidebar.php';
?>
<main class="workspace launcher">
    <section class="launcher-hero" aria-labelledby="launcher-title">
        <h1 id="launcher-title">essentials <span class="mascot" aria-hidden="true">&#9822;</span></h1>
        <p>your business command center</p>
    </section>

    <section class="app-grid role-app-grid" aria-label="Business apps">
        <?php foreach ($apps as $app): ?>
            <?php $tag = $app['active'] ? 'a' : 'div'; ?>
            <<?= $tag ?> class="app-card <?= $app['active'] ? 'is-active' : 'is-muted' ?>" <?= $app['active'] ? 'href="' . htmlspecialchars($app['href'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <?php if (!$app['active']): ?><span class="soon">Soon</span><?php endif; ?>
                <span class="app-icon <?= htmlspecialchars($app['tone'], ENT_QUOTES, 'UTF-8') ?>">
                    <i data-lucide="<?= htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </span>
                <strong><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($app['desc'], ENT_QUOTES, 'UTF-8') ?></small>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
</main>
<?php include __DIR__ . '/shared/footer.php'; ?>
