<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Live Orders Board | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$hasAvailability = $ready && ops_table_exists('ops_employee_availability');
$defaultBoardDate = date('Y-m-d');
$isAdminBoard = user_has_role('owner_admin', 'supervisor_manager');
$canBulkAssign = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$canOpenOrdersTools = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$canEditHeaders = current_role_key() !== 'guest';
$boardAssetVersion = is_file(BASE_PATH . '/assets/js/orders-board.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/orders-board.js') . '-orders-rebuild'
    : (string) time();
$ordersStylesVersion = is_file(BASE_PATH . '/assets/css/orders-board.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/orders-board.css')
    : (string) time();
$extraStylesheets = [
    ['path' => 'assets/css/portal-column-resize.css', 'version' => is_file(BASE_PATH . '/assets/css/portal-column-resize.css') ? (string) filemtime(BASE_PATH . '/assets/css/portal-column-resize.css') : (string) time()],
    ['path' => 'assets/css/orders-board.css', 'version' => $ordersStylesVersion],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<style>
.workspace.module.orders-page .orders-summary-label {
    margin-bottom: 4px;
    color: #A08070;
    font-size: 10px;
    line-height: 1;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
</style>
<main class="workspace module ops-board-page portal-page orders-page" data-board-theme="light">
    <section class="monday-board-top orders-page-top">
        <header class="monday-board-head work-board-head portal-page-header orders-page-header">
            <div>
                <h1 style="color: #721B1A;">Hambelela Orders <i data-lucide="chevron-down"></i></h1>
            </div>
            <div class="monday-board-head-actions">
                <?php if ($canOpenOrdersTools): ?>
                <button type="button" class="orders-tools-trigger" data-orders-tools-open><i data-lucide="wrench"></i><span>Orders tools</span></button>
                <?php endif; ?>
                <button type="button" class="invite-btn packing-btn packing-btn-secondary orders-export-button" data-export-excel><i data-lucide="download"></i> Export Excel</button>
                <div class="availability-switch-wrap">
                    <span>Available</span>
                    <button class="availability-switch is-available" type="button" data-availability-toggle aria-pressed="true" aria-label="Toggle lunch availability">
                        <span></span>
                    </button>
                    <span>Lunch</span>
                </div>
                <span class="board-state" id="board-sync-state" aria-live="polite"></span>
            </div>
        </header>

        <section class="work-metric-grid portal-stat-grid orders-stat-grid <?= $isAdminBoard ? 'admin-metrics' : '' ?>" aria-label="Work summary">
            <?php if ($isAdminBoard): ?>
                <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="shopping-bag"></i></span><div><span class="metric-title">Total Orders</span><strong data-work-metric="total_orders">0</strong><small>All time</small></div></article>
                <article class="work-metric-card metric-slate"><span class="metric-icon"><i data-lucide="file-plus-2"></i></span><div><span class="metric-title">New Orders</span><strong data-work-metric="new_today">0</strong><small>Today</small></div></article>
                <article class="work-metric-card metric-orange"><span class="metric-icon"><i data-lucide="clock-3"></i></span><div><span class="metric-title">In Progress</span><strong data-work-metric="in_progress_today">0</strong><small>Today</small></div></article>
                <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="circle-check"></i></span><div><span class="metric-title">Completed</span><strong data-work-metric="completed_all">0</strong><small>All time</small></div></article>
                <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="user-round-x"></i></span><div><span class="metric-title">Unassigned</span><strong data-work-metric="unassigned_orders">0</strong><small>Orders</small></div></article>
                <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="triangle-alert"></i></span><div><span class="metric-title">Overdue</span><strong data-work-metric="overdue_orders">0</strong><small>Orders</small></div></article>
            <?php else: ?>
                <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="clipboard-list"></i></span><div><span class="metric-title">My Orders</span><strong data-work-metric="my_orders">0</strong><small>Assigned to you</small></div></article>
                <article class="work-metric-card metric-orange"><span class="metric-icon"><i data-lucide="clock-3"></i></span><div><span class="metric-title">In Progress</span><strong data-work-metric="in_progress">0</strong><small>Currently working</small></div></article>
                <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="circle-check"></i></span><div><span class="metric-title">Completed Today</span><strong data-work-metric="completed_today">0</strong><small>Today</small></div></article>
                <article class="work-metric-card metric-red"><span class="metric-icon"><i data-lucide="hourglass"></i></span><div><span class="metric-title">Pending Orders</span><strong data-work-metric="pending_orders">0</strong><small>Awaiting action</small></div></article>
                <article class="work-metric-card metric-pink"><span class="metric-icon"><i data-lucide="user-round-x"></i></span><div><span class="metric-title">Unassigned</span><strong data-work-metric="unassigned_orders">0</strong><small>Needs assignment</small></div></article>
            <?php endif; ?>
        </section>

        <section class="ob-video-toolbar orders-tools-bar" aria-label="Orders tools">
            <button type="button" class="ob-view-selector"><i data-lucide="table-2"></i> Main table <i data-lucide="chevron-down"></i></button>
            <button type="button" class="ob-new-task" data-board-action="sync"><span>New task</span><i data-lucide="chevron-down"></i></button>
            <label class="ob-toolbar-search"><i data-lucide="search"></i><input data-board-search type="search" placeholder="Search"></label>
            <button type="button" data-toolbar="person"><i data-lucide="circle-user-round"></i> Person</button>
            <button type="button" data-toolbar="filter"><i data-lucide="filter"></i> Filter</button>
            <button type="button" data-toolbar="hide"><i data-lucide="eye-off"></i> Hide</button>
            <button type="button" data-toolbar="group"><i data-lucide="columns-3"></i> Group by</button>
            <button type="button" data-toolbar="more"><i data-lucide="ellipsis"></i></button>
        </section>

        <section class="work-filter-bar portal-filter-panel orders-filter-panel" aria-label="Board filters">
            <label>Date Range
                <div class="date-filter-row">
                    <input id="board-date-filter" type="date" value="<?= htmlspecialchars($defaultBoardDate, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" data-date-all>All dates</button>
                </div>
            </label>
            <label>Status
                <div class="orders-filter-select" data-orders-filter-select="status">
                    <input type="hidden" data-board-filter="status" value="">
                    <button type="button" class="orders-filter-trigger" data-orders-filter-trigger aria-haspopup="listbox" aria-expanded="false"><span>All statuses</span><i data-lucide="chevron-down"></i></button>
                </div>
            </label>
            <label>Mode
                <div class="orders-filter-select" data-orders-filter-select="mode">
                    <input type="hidden" data-board-filter="mode" value="">
                    <button type="button" class="orders-filter-trigger" data-orders-filter-trigger aria-haspopup="listbox" aria-expanded="false"><span>All modes</span><i data-lucide="chevron-down"></i></button>
                </div>
            </label>
            <label>Payment
                <div class="orders-filter-select" data-orders-filter-select="payment">
                    <input type="hidden" data-board-filter="payment" value="">
                    <button type="button" class="orders-filter-trigger" data-orders-filter-trigger aria-haspopup="listbox" aria-expanded="false"><span>All payments</span><i data-lucide="chevron-down"></i></button>
                </div>
            </label>
            <label>Group By
                <div class="orders-filter-select" data-orders-filter-select="group">
                    <input type="hidden" data-board-group-select value="date">
                    <button type="button" class="orders-filter-trigger" data-orders-filter-trigger aria-haspopup="listbox" aria-expanded="false"><span>Date</span><i data-lucide="chevron-down"></i></button>
                </div>
            </label>
            <label class="work-search">Search Orders
                <input data-board-search type="search" placeholder="Search orders...">
            </label>
            <div class="work-filter-actions">
                <button type="button" data-clear-board-filters><i data-lucide="refresh-cw"></i> Clear Filters</button>
                <button type="button" data-board-refresh><i data-lucide="refresh-cw"></i> Refresh</button>
                <button type="button" data-toolbar="more"><i data-lucide="sliders-horizontal"></i> More Filters</button>
                <button type="button" data-reset-orders-columns><i data-lucide="columns-3"></i> Reset column widths</button>
            </div>
            <div class="orders-active-filter-chips" data-orders-active-filter-chips hidden></div>
        </section>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif (!$hasAvailability): ?>
        <section class="ops-alert">Import <code>operations-live-board-migration.sql</code> in phpMyAdmin first. This adds packer lunch/availability tracking.</section>
    <?php endif; ?>

    <section class="orders-date-groups">
        <div class="ops-board-scroll orders-grid-scroll">
            <div class="ops-board-table monday-board orders-board-v2 orders-grid-root" id="orders-board-body" data-orders-board data-board-key="orders">
                <div class="board-empty-state">Loading orders...</div>
            </div>
        </div>
    </section>

    <div class="label-menu" id="board-label-menu" hidden></div>
    <div class="toolbar-popover" id="toolbar-popover" hidden></div>
    <div class="orders-filter-menu" id="orders-filter-menu" role="listbox" hidden></div>
    <div class="orders-more-backdrop" data-orders-more-backdrop hidden></div>
    <aside class="orders-more-panel" data-orders-more-panel aria-hidden="true" aria-labelledby="orders-more-title">
        <header class="orders-more-header">
            <div>
                <span>ORDERS</span>
                <h2 id="orders-more-title">More filters</h2>
                <p>Refine the Orders Board and customise which columns are visible.</p>
            </div>
            <div class="orders-more-header-actions">
                <span class="orders-more-active-count" data-orders-more-active-count>No active filters</span>
                <button type="button" class="orders-more-close" data-orders-more-close aria-label="Close More filters"><i data-lucide="x"></i></button>
            </div>
        </header>
        <div class="orders-more-body" data-orders-more-body></div>
        <footer class="orders-more-footer">
            <button type="button" class="orders-more-button orders-more-button--reset" data-orders-more-reset><i data-lucide="rotate-ccw"></i> Reset filters</button>
            <div>
                <button type="button" class="orders-more-button" data-orders-more-cancel>Cancel</button>
                <button type="button" class="orders-more-button orders-more-button--primary" data-orders-more-apply>Apply filters</button>
            </div>
        </footer>
    </aside>
    <?php if ($canOpenOrdersTools): ?>
    <div class="orders-tools-backdrop" data-orders-tools-backdrop hidden></div>
    <aside class="orders-tools-panel" data-orders-tools-panel aria-hidden="true" aria-labelledby="orders-tools-title">
        <header class="orders-tools-header">
            <div><span>ORDERS</span><h2 id="orders-tools-title">Orders tools</h2><p>Review deleted orders, restore archived records and track changes made to the Orders Board.</p></div>
            <button type="button" class="orders-tools-close" data-orders-tools-close aria-label="Close Orders tools"><i data-lucide="x"></i></button>
        </header>
        <nav class="orders-tools-tabs" aria-label="Orders tools sections">
            <button type="button" class="orders-tools-tab is-active" data-orders-tools-tab="trash">Trash</button>
            <button type="button" class="orders-tools-tab" data-orders-tools-tab="activity">Activity</button>
            <button type="button" class="orders-tools-tab" data-orders-tools-tab="archived">Archived</button>
            <button type="button" class="orders-tools-tab" data-orders-tools-tab="bulk">Bulk actions</button>
        </nav>
        <div class="orders-tools-content" data-orders-tools-content><div class="orders-tools-loading">Loading Orders tools…</div></div>
    </aside>
    <?php endif; ?>
    <aside class="order-panel" id="order-updates-panel" data-orders-details-panel aria-hidden="true" aria-labelledby="panel-order-title">
        <header class="order-panel-header">
            <button class="order-panel-close" type="button" data-panel-close aria-label="Close order panel"><i data-lucide="x"></i></button>
            <div class="order-panel-heading"><span class="order-panel-kicker">Order</span><h2 class="order-panel-title" id="panel-order-title">Order</h2><div class="order-panel-meta" id="panel-order-meta"></div></div>
            <button class="order-panel-menu" type="button" data-order-panel-menu aria-label="Open order actions"><span></span><span></span><span></span></button>
        </header>
        <nav class="order-panel-tabs updates-tabs" aria-label="Order details sections">
            <button class="order-panel-tab is-active active" type="button" data-panel-tab="details">Details</button>
            <button class="order-panel-tab" type="button" data-panel-tab="items">Items</button>
            <button class="order-panel-tab" type="button" data-panel-tab="updates" id="panel-updates-tab">Updates</button>
            <button class="order-panel-tab" type="button" data-panel-tab="files">Files</button>
            <button class="order-panel-tab" type="button" data-panel-tab="activity">Activity</button>
        </nav>
        <div class="order-panel-body">
        <section class="order-panel-section updates-tab-panel active" data-panel-name="details"><div id="panel-order-details"></div></section>
        <section class="order-panel-section updates-tab-panel" data-panel-name="items"><div class="order-panel-card order-items-card" id="panel-order-items"></div></section>
        <section class="order-panel-section updates-tab-panel" data-panel-name="updates">
            <div class="order-update-composer" id="order-update-composer">
                <textarea class="order-update-textarea" id="panel-update-editor" placeholder="Write an update about this order..." rows="4"></textarea>
                <input type="file" id="order-update-file-input" hidden multiple accept="image/*,.pdf,.doc,.docx">
                <div class="order-selected-attachments" id="order-selected-attachments" hidden></div>
                <div class="order-update-actions">
                    <button type="button" class="order-update-attach" data-composer-action="attach" disabled title="Order file storage is not connected"><i data-lucide="paperclip"></i> Attach</button>
                    <button class="order-update-submit" type="button" data-save-notes>Add update</button>
                </div>
            </div>
            <div class="order-panel-empty" id="panel-empty-updates" hidden><i data-lucide="message-square"></i><strong>No updates yet</strong><span>Add an update to share progress or important order information.</span></div>
            <div id="panel-updates-list" class="order-updates-list"></div>
        </section>
        <section class="order-panel-section updates-tab-panel" data-panel-name="files">
            <div class="order-file-dropzone is-disabled" aria-disabled="true"><i data-lucide="paperclip"></i><strong>Order file storage is coming soon</strong><span>Uploads are unavailable until secure order storage is connected.</span></div>
            <div class="order-panel-empty" id="panel-files-list"><strong>No files uploaded</strong><span>Files attached to updates are listed inside the update entry.</span></div>
        </section>
        <section class="order-panel-section order-activity-tab updates-tab-panel" data-panel-name="activity">
            <header><h3 class="order-activity-heading">Activity</h3><p class="order-activity-subheading">Operational changes made to this order.</p></header>
            <div id="panel-activity-log" class="portal-activity-timeline" aria-live="polite"></div>
        </section>
        </div>
    </aside>
    <div class="order-panel-backdrop" id="panel-backdrop" hidden></div>
</main>
<script>
window.HambelelaBoard = {
  dataUrl: 'orders-board-data.php',
  actionUrl: 'orders-board-action.php',
  statuses: <?= json_encode(OPS_ORDER_STATUSES) ?>,
  canEditHeaders: <?= $canEditHeaders ? 'true' : 'false' ?>,
  canOpenOrdersTools: <?= $canOpenOrdersTools ? 'true' : 'false' ?>,
  currentRole: <?= json_encode(current_role_key()) ?>,
  currentUserId: <?= (int) (current_user()['id'] ?? 0) ?>
};
</script>
<script defer src="<?= BASE_URL ?>/assets/js/portal-column-resize.js?v=<?= is_file(BASE_PATH . '/assets/js/portal-column-resize.js') ? (string) filemtime(BASE_PATH . '/assets/js/portal-column-resize.js') : (string) time() ?>"></script>
<script defer src="<?= BASE_URL ?>/assets/js/orders-board.js?v=<?= htmlspecialchars($boardAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
