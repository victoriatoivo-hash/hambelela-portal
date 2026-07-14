<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';

require_login();

$pageTitle = APP_NAME;
$activeApp = 'dashboard';
$roleKey = current_role_key();
$isEmployeeWorkspace = $roleKey !== 'owner_admin';

if ($roleKey === 'owner_admin') {
    $apps = [
        ['name' => 'Cost Workbook', 'desc' => 'invoice to landed cost, margins, VAT and profit', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php', 'active' => true, 'tone' => 'pink'],
        ['name' => 'Operations', 'desc' => 'orders, packing, checklists, errors and KPIs', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'HR Portal', 'desc' => 'employee records, payroll and leave management', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'KPI Reports', 'desc' => 'packing speed, checklist compliance, accuracy and bonus support', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php', 'active' => true, 'tone' => 'violet'],
        ['name' => 'Packing List', 'desc' => 'consignment breakdowns, fair packer assignments and actual quantities', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'blue'],
        ['name' => 'Courier', 'desc' => 'upload waybills, alert front desk and track customer sends', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php', 'active' => true, 'tone' => 'green'],
    ];
} elseif (in_array($roleKey, ['front_desk_admin', 'supervisor_manager'], true)) {
    $apps = [];
} elseif ($roleKey === 'packer') {
    $apps = [];
} else {
    $apps = [];
}

if ($roleKey !== 'owner_admin') {
    $apps = [
        ['name'=>'Packing List','desc'=>'assigned consignment packing quantities and completion status','icon'=>'package-open','href'=>BASE_URL.'/apps/operations/consignments.php','active'=>true,'tone'=>'green'],
        ['name'=>'Orders','desc'=>'website orders, payments and daily status','icon'=>'table-2','href'=>'','active'=>false,'tone'=>'pink'],
        ['name'=>'Tasks','desc'=>'daily assigned tasks and completion tracking','icon'=>'list-checks','href'=>'','active'=>false,'tone'=>'green'],
        ['name'=>'Courier','desc'=>'courier labels and customer follow-up','icon'=>'truck','href'=>'','active'=>false,'tone'=>'green'],
        ['name'=>'Inventory','desc'=>'stock visibility and inventory updates','icon'=>'package','href'=>'','active'=>false,'tone'=>'pink'],
        ['name'=>'Bookkeeping','desc'=>'cash and bookkeeping workflows','icon'=>'book-open','href'=>'','active'=>false,'tone'=>'green'],
        ['name'=>'Notifications','desc'=>'portal alerts and updates','icon'=>'bell','href'=>'','active'=>false,'tone'=>'pink'],
        ['name'=>'Error Log','desc'=>'operational issue tracking','icon'=>'triangle-alert','href'=>'','active'=>false,'tone'=>'pink'],
        ['name'=>'HR Portal','desc'=>'leave, payslips and employee self-service','icon'=>'shield-check','href'=>'','active'=>false,'tone'=>'green'],
        ['name'=>'Reports','desc'=>'operational performance reports','icon'=>'chart-no-axes-combined','href'=>'','active'=>false,'tone'=>'pink'],
    ];
}

include __DIR__ . '/shared/header.php';
include __DIR__ . '/shared/sidebar.php';
?>
<main class="workspace launcher">
    <?php if ($roleKey !== 'owner_admin'): ?>
        <section class="employee-workspace-intro" aria-labelledby="employee-workspace-title"><h1 id="employee-workspace-title">Employee Workspace</h1><p>Packing List is currently available.<br>Additional portal sections are coming soon.</p></section>
    <?php endif; ?>
    <section class="launcher-hero" aria-labelledby="launcher-title">
        <h1 id="launcher-title">essentials <span class="mascot" aria-hidden="true">&#9822;</span></h1>
        <p>your business command center</p>
    </section>

    <section class="app-grid role-app-grid" aria-label="Business apps">
        <?php foreach ($apps as $app): ?>
            <?php $tag = $app['active'] ? 'a' : 'div'; ?>
            <<?= $tag ?> class="app-card <?= $app['active'] ? 'is-active' : 'is-muted' ?><?= $isEmployeeWorkspace ? ($app['active'] ? ' employee-app-tile--available' : ' employee-app-tile--coming-soon') : '' ?>" <?= $app['active'] ? 'href="' . htmlspecialchars($app['href'], ENT_QUOTES, 'UTF-8') . '"' . ($isEmployeeWorkspace ? ' data-employee-app-status="available"' : '') : 'data-employee-app-status="coming-soon" aria-disabled="true" tabindex="0" role="button"' ?>>
                <?php if ($isEmployeeWorkspace): ?><span class="employee-app-status"><?= $app['active'] ? 'Available' : 'Coming soon' ?></span><?php endif; ?>
                <span class="app-icon <?= htmlspecialchars($app['tone'], ENT_QUOTES, 'UTF-8') ?>">
                    <i data-lucide="<?= htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </span>
                <strong><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($app['desc'], ENT_QUOTES, 'UTF-8') ?></small>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
</main>
<?php if ($roleKey !== 'owner_admin'): ?>
<style>.employee-workspace-intro{margin:0 0 16px}.employee-workspace-intro h1{margin:0 0 5px;color:#721b1a;font-size:14px;font-weight:600}.employee-workspace-intro p{margin:0;color:#6b4c3b;font-size:12px;line-height:1.45}.employee-app-tile--coming-soon{position:relative;opacity:.72;cursor:default;filter:saturate(.65)}.employee-app-tile--coming-soon:hover{opacity:.82;transform:translateY(-1px)}.employee-app-status{position:absolute;top:12px;right:12px;min-height:22px;padding:0 8px;display:inline-flex;align-items:center;border-radius:999px;background:rgba(240,116,32,.08);color:#ab3619;font-size:10px;font-weight:600}.employee-app-tile--available .employee-app-status{background:rgba(168,202,25,.14);color:#721b1a}.employee-coming-soon-toast{position:fixed;left:50%;bottom:24px;z-index:50000;transform:translateX(-50%);padding:10px 14px;border:1px solid #ede3d8;border-radius:9px;background:#fff;color:#721b1a;box-shadow:0 12px 30px rgba(114,27,26,.14);font-size:12px}</style>
<script>function showEmployeeSoon(){document.querySelector('.employee-coming-soon-toast')?.remove();const toast=document.createElement('div');toast.className='employee-coming-soon-toast';toast.setAttribute('role','status');toast.textContent='This section is coming soon.';document.body.appendChild(toast);setTimeout(()=>toast.remove(),2400)}document.addEventListener('click',event=>{const tile=event.target.closest('[data-employee-app-status="coming-soon"]');if(!tile)return;event.preventDefault();event.stopPropagation();showEmployeeSoon()});document.addEventListener('keydown',event=>{if((event.key==='Enter'||event.key===' ')&&event.target.closest('[data-employee-app-status="coming-soon"]')){event.preventDefault();showEmployeeSoon()}});</script>
<?php endif; ?>
<?php include __DIR__ . '/shared/footer.php'; ?>
