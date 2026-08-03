<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/apps/operations/operations.php';

require_login();

$pageTitle = APP_NAME;
$activeApp = 'dashboard';
$pageUsesPortalSidebar = false;
$hidePortalSidebar = true;
$roleKey = current_role_key();
$dashboardTaskRows = [];
$dashboardTaskCount = 0;
$dashboardPackingUnread = function_exists('notifications_packing_assignment_unread_count') ? notifications_packing_assignment_unread_count() : 0;
if ($roleKey !== 'owner_admin' && ops_table_exists('ops_checklist_tasks')) {
    $employeeId = ops_current_employee_id() ?: 0;
    $visibilityWhere = ops_column_exists('ops_checklist_tasks', 'employee_visible') ? ' AND employee_visible = 1' : '';
    $dashboardTaskRows = ops_rows(
        "SELECT id, task_name, deadline, priority FROM ops_checklist_tasks
         WHERE assigned_employee_id = ?{$visibilityWhere}
           AND archived_at IS NULL AND deleted_at IS NULL AND status <> 'complete'
         ORDER BY CASE WHEN deadline IS NOT NULL AND deadline < NOW() THEN 0 ELSE 1 END, deadline ASC LIMIT 8",
        [$employeeId]
    );
    $countRows = ops_rows(
        "SELECT COUNT(*) AS total FROM ops_checklist_tasks
         WHERE assigned_employee_id = ?{$visibilityWhere}
           AND archived_at IS NULL AND deleted_at IS NULL AND status <> 'complete'",
        [$employeeId]
    );
    $dashboardTaskCount = (int) ($countRows[0]['total'] ?? 0);
}

if ($roleKey === 'owner_admin') {
    $apps = [
        ['name' => 'Cost Workbook', 'desc' => 'invoice to landed cost, margins, VAT and profit', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php', 'active' => true, 'tone' => 'pink'],
        ['name' => 'Operations', 'desc' => 'orders, packing, checklists, errors and KPIs', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'HR Portal', 'desc' => 'employee records, payroll and leave management', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/portal-login.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'KPI Reports', 'desc' => 'packing speed, checklist compliance, accuracy and bonus support', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php', 'active' => true, 'tone' => 'violet'],
        ['name' => 'Packing List', 'desc' => 'consignment breakdowns, fair packer assignments and actual quantities', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php', 'active' => true, 'tone' => 'blue'],
        ['name' => 'Courier', 'desc' => 'upload waybills, alert front desk and track customer sends', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php', 'active' => true, 'tone' => 'green'],
    ];
} else {
    $apps = [
        ['name' => 'Packing List', 'desc' => 'assigned consignment packing quantities and completion status', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php?assigned=me&unread=1', 'active' => true, 'tone' => 'green'],
        ['name' => 'Courier Waybills', 'desc' => 'courier labels and customer follow-up', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'HR Portal', 'desc' => 'leave, payslips and employee self-service', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/portal-login.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'Orders', 'desc' => 'website orders, payments and daily status', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php', 'active' => true, 'tone' => 'pink'],
        ['name' => 'Tasks', 'desc' => 'daily assigned tasks and completion tracking', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'Bookkeeping', 'desc' => 'cash and bookkeeping workflows', 'icon' => 'book-open', 'href' => BASE_URL . '/apps/operations/bookkeeping.php', 'active' => true, 'tone' => 'green'],
        ['name' => 'Notifications', 'desc' => 'your account alerts and updates', 'icon' => 'bell', 'href' => BASE_URL . '/notifications.php', 'active' => true, 'tone' => 'green'],
    ];
    if (in_array($roleKey, ['front_desk_admin', 'front_desk_admin_employee'], true)) {
        $apps[] = ['name' => 'Error Log', 'desc' => 'operational issue tracking', 'icon' => 'triangle-alert', 'href' => BASE_URL . '/apps/operations/errors.php', 'active' => true, 'tone' => 'pink'];
    }
}

include __DIR__ . '/shared/header.php';
include __DIR__ . '/shared/sidebar.php';
?>
<main class="workspace launcher">
    <header class="launcher-account-header" data-portal-header-status-target aria-label="Portal account and status"></header>
    <?php if ($roleKey !== 'owner_admin'): ?><section class="employee-workspace-intro"><h1>Your operational apps are available.</h1><p>Open an app below to begin working.</p></section><?php endif; ?>
    <section class="launcher-hero" aria-labelledby="launcher-title">
        <h1 id="launcher-title">essentials <span class="mascot" aria-hidden="true">&#9822;</span></h1>
        <p>your business command center</p>
    </section>

    <section class="app-grid role-app-grid" aria-label="Business apps">
        <?php foreach ($apps as $app): ?>
            <a class="app-card is-active employee-app-tile--available" href="<?= htmlspecialchars($app['href'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($roleKey !== 'owner_admin'): ?><span class="employee-app-status">Available</span><?php endif; ?>
                <span class="app-icon <?= htmlspecialchars($app['tone'], ENT_QUOTES, 'UTF-8') ?>">
                    <i data-lucide="<?= htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </span>
                <strong><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($app['desc'], ENT_QUOTES, 'UTF-8') ?></small>
                <?php if ($app['name'] === 'Tasks' && $dashboardTaskCount > 0): ?><span class="employee-task-count" aria-label="<?= $dashboardTaskCount ?> incomplete tasks"><?= $dashboardTaskCount > 99 ? '99+' : $dashboardTaskCount ?></span><?php endif; ?>
                <?php if ($app['name'] === 'Packing List'): ?><span class="employee-task-count<?= $dashboardPackingUnread > 0 ? '' : ' is-hidden' ?>" data-packing-unread-badge<?= $dashboardPackingUnread > 0 ? '' : ' hidden' ?> aria-label="<?= $dashboardPackingUnread ?> new packing assignments"><?= $dashboardPackingUnread > 0 ? ($dashboardPackingUnread > 99 ? '99+' : $dashboardPackingUnread) : '' ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </section>
</main>
<?php if ($roleKey !== 'owner_admin' && $dashboardTaskCount > 0): ?><div class="dashboard-task-reminder" data-dashboard-task-reminder hidden><button type="button" class="dashboard-task-reminder-backdrop" data-dashboard-task-dismiss aria-label="Close task reminder"></button><section><button type="button" class="dashboard-task-reminder-close" data-dashboard-task-dismiss aria-label="Close"><i data-lucide="x"></i></button><span>Tasks requiring attention</span><h2>You have <?= number_format($dashboardTaskCount) ?> incomplete task<?= $dashboardTaskCount === 1 ? '' : 's' ?>.</h2><div><?php foreach ($dashboardTaskRows as $task): ?><a href="<?= BASE_URL ?>/apps/operations/checklists.php?task_id=<?= (int) $task['id'] ?>"><strong><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= !empty($task['deadline']) ? htmlspecialchars(date('D j M, H:i', strtotime((string) $task['deadline'])), ENT_QUOTES, 'UTF-8') : 'No due time' ?></small></a><?php endforeach; ?></div><a class="dashboard-task-reminder-open" href="<?= BASE_URL ?>/apps/operations/checklists.php">Open Task Management</a></section></div><script>(()=>{const modal=document.querySelector('[data-dashboard-task-reminder]');if(!modal)return;const key='taskReminder:<?= date('Y-m-d') ?>:<?= (int) (current_user()['id'] ?? 0) ?>';if(!sessionStorage.getItem(key))modal.hidden=false;modal.querySelectorAll('[data-dashboard-task-dismiss]').forEach(button=>button.addEventListener('click',()=>{modal.hidden=true;sessionStorage.setItem(key,'dismissed')}));})();</script><?php endif; ?>
<?php if ($roleKey !== 'owner_admin'): ?><style>.employee-workspace-intro{margin:0 0 16px}.employee-workspace-intro h1{margin:0 0 5px;color:#721b1a;font-size:14px;font-weight:600}.employee-workspace-intro p{margin:0;color:#6b4c3b;font-size:12px;line-height:1.45}.employee-app-status{position:absolute;top:12px;right:12px;min-height:22px;padding:0 8px;display:inline-flex;align-items:center;border-radius:999px;background:rgba(168,202,25,.14);color:#721b1a;font-size:10px;font-weight:600}</style><?php endif; ?>
<?php include __DIR__ . '/shared/footer.php'; ?>
