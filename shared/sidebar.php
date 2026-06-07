<?php

declare(strict_types=1);

$roleKey = current_role_key();
$navItems = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => BASE_URL . '/index.php'],
];

if ($roleKey === 'owner_admin') {
    $navItems[] = ['id' => 'cost-manager', 'label' => 'Cost Workbook', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php'];
    $navItems[] = ['id' => 'operations', 'label' => 'Operations', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php'];
    $navItems[] = ['id' => 'operations-employees', 'label' => 'Employees', 'icon' => 'users', 'href' => BASE_URL . '/apps/operations/employees.php'];
    $navItems[] = ['id' => 'kpi', 'label' => 'KPI Reports', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php'];
    $navItems[] = ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'wallet-cards', 'href' => BASE_URL . '/apps/operations/bookkeeping.php'];
    $navItems[] = ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php'];
} elseif (in_array($roleKey, ['front_desk_admin', 'supervisor_manager'], true)) {
    $navItems[] = ['id' => 'operations', 'label' => 'Live Orders', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php'];
    if ($roleKey === 'front_desk_admin') {
        $navItems[] = ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'wallet-cards', 'href' => BASE_URL . '/apps/operations/bookkeeping.php'];
    }
    $navItems[] = ['id' => 'operations-checklists', 'label' => 'Tasks', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php'];
    $navItems[] = ['id' => 'operations-errors', 'label' => 'Error Log', 'icon' => 'triangle-alert', 'href' => BASE_URL . '/apps/operations/errors.php'];
} elseif ($roleKey === 'packer') {
    $navItems[] = ['id' => 'operations', 'label' => 'Live Orders', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php'];
    $navItems[] = ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php'];
    $navItems[] = ['id' => 'operations-checklists', 'label' => 'Tasks', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php'];
    $navItems[] = ['id' => 'operations-barcode', 'label' => 'Barcode Soon', 'icon' => 'scan-barcode', 'href' => BASE_URL . '/apps/operations/barcode.php'];
}
?>
<aside class="sidebar" id="portal-sidebar" aria-label="Portal navigation">
    <nav>
        <?php foreach ($navItems as $item): ?>
            <a class="<?= $activeApp === $item['id'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
