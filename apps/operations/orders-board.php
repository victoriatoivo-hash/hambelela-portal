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
$canEditHeaders = user_has_role('owner_admin');
$boardAssetVersion = is_file(BASE_PATH . '/assets/js/orders-board.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/orders-board.js') . '-bulk-actions-clean2'
    : (string) time();

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module ops-board-page" data-board-theme="light">
    <section class="monday-board-top">
        <div class="monday-board-head work-board-head">
            <div>
                <h1>My Work <i data-lucide="chevron-down"></i></h1>
                <p class="work-board-subtitle">Assigned orders, packing status and live website order flow.</p>
            </div>
            <div class="monday-board-head-actions">
                <div class="board-viewers" id="board-viewers" aria-label="Currently viewing"></div>
                <button type="button" class="invite-btn" data-export-excel><i data-lucide="download"></i> Export Excel</button>
                <button type="button" data-undo-board disabled><i data-lucide="undo-2"></i> Undo</button>
                <button type="button" data-theme-toggle><i data-lucide="moon"></i></button>
            </div>
        </div>

        <section class="work-metric-grid <?= $isAdminBoard ? 'admin-metrics' : '' ?>" aria-label="Work summary">
            <?php if ($isAdminBoard): ?>
                <article class="work-metric-card metric-blue"><span class="metric-icon"><i data-lucide="shopping-bag"></i></span><div><span class="metric-title">Total Orders</span><strong data-work-metric="total_orders">0</strong><small>All time</small></div></article>
                <article class="work-metric-card metric-slate"><span class="metric-icon"><i data-lucide="file-plus-2"></i></span><div><span class="metric-title">New Orders</span><strong data-work-metric="new_today">0</strong><small>Today</small></div></article>
                <article class="work-metric-card metric-orange"><span class="metric-icon"><i data-lucide="clock-3"></i></span><div><span class="metric-title">In Progress</span><strong data-work-metric="in_progress_today">0</strong><small>Today</small></div></article>
                <article class="work-metric-card metric-green"><span class="metric-icon"><i data-lucide="circle-check"></i></span><div><span class="metric-title">Completed</span><strong data-work-metric="completed_all">0</strong><small>All time</small></div></article>
                <article class="work-metric-card metric-purple"><span class="metric-icon"><i data-lucide="badge-dollar-sign"></i></span><div><span class="metric-title">Total Revenue</span><strong data-work-metric="total_revenue">N$0</strong><small>Visible paid Woo total</small></div></article>
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

        <section class="work-filter-bar" aria-label="Board filters">
            <label>Date Range
                <div class="date-filter-row">
                    <input id="board-date-filter" type="date" value="<?= htmlspecialchars($defaultBoardDate, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" data-date-all>All dates</button>
                </div>
            </label>
            <label>Status
                <select data-board-filter="status">
                    <option value="">All</option>
                    <option value="new_order">New Order</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Complete</option>
                </select>
            </label>
            <label>Mode
                <select data-board-filter="mode">
                    <option value="">All</option>
                    <option value="collection">Collection</option>
                    <option value="delivery">Delivery</option>
                    <option value="courier">Courier</option>
                </select>
            </label>
            <label>Payment
                <select data-board-filter="payment">
                    <option value="">All</option>
                    <option value="Cash">Cash</option>
                    <option value="EFT">EFT</option>
                    <option value="Ewallet">Ewallet</option>
                    <option value="Bluewallet">Bluewallet</option>
                    <option value="Swipe">Swipe</option>
                </select>
            </label>
            <label>Group By
                <select data-board-group-select>
                    <option value="date">Date</option>
                    <option value="status">Status</option>
                    <option value="packer">Picked by</option>
                    <option value="mode">Mode</option>
                </select>
            </label>
            <label class="work-search">Search Orders
                <input data-board-search type="search" placeholder="Search orders...">
            </label>
            <div class="work-filter-actions">
                <button type="button" data-clear-board-filters><i data-lucide="rotate-ccw"></i> Clear Filters</button>
                <button type="button" data-board-refresh><i data-lucide="refresh-cw"></i> Refresh</button>
                <button type="button" data-toolbar="more"><i data-lucide="sliders-horizontal"></i> More Filters</button>
            </div>
        </section>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif (!$hasAvailability): ?>
        <section class="ops-alert">Import <code>operations-live-board-migration.sql</code> in phpMyAdmin first. This adds packer lunch/availability tracking.</section>
    <?php endif; ?>

    <section class="monday-control-strip">
        <div class="board-day-control">
            <i data-lucide="calendar-days"></i>
            <button type="button" id="board-group-label" data-toolbar="group">Grouped by date</button>
        </div>
        <div class="availability-switch-wrap">
            <span>Available</span>
            <button class="availability-switch is-available" type="button" data-availability-toggle aria-pressed="true">
                <span></span>
            </button>
            <span>Lunch</span>
        </div>
        <div class="board-state" id="board-sync-state">Live</div>
        <div class="board-quick-actions"></div>
    </section>

    <section class="ops-board-shell">
        <div class="ops-board-scroll">
            <table class="ops-board-table">
                <thead>
                    <tr>
                        <th class="check-cell"><input type="checkbox" data-select-all-orders aria-label="Select all visible orders"></th>
                        <th class="comment-cell"></th>
                        <th data-column-key="task" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>TASK</th>
                        <th data-column-key="date" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>DATE</th>
                        <th data-column-key="mode" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>MODE</th>
                        <th data-column-key="mobile" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>MOBILE NUMBER</th>
                        <th data-column-key="amount" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>AMOUNT</th>
                        <th data-column-key="payment" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>PAYMENT</th>
                        <th data-column-key="paid" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>PAID</th>
                        <th data-column-key="status" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>STATUS</th>
                        <th data-column-key="packer" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>PICKED BY</th>
                        <th data-column-key="text" <?= $canEditHeaders ? 'contenteditable="true"' : '' ?>>TEXT</th>
                        <th class="add-column-cell"><button type="button" data-add-column>+</button></th>
                    </tr>
                </thead>
                <tbody id="orders-board-body"><tr><td colspan="13">Loading orders...</td></tr></tbody>
            </table>
        </div>
    </section>

    <div class="label-menu" id="board-label-menu" hidden></div>
    <div class="toolbar-popover" id="toolbar-popover" hidden></div>
    <aside class="order-updates-panel" id="order-updates-panel" aria-hidden="true">
        <div class="updates-panel-head">
            <button type="button" data-panel-close><i data-lucide="x"></i></button>
            <h2 id="panel-order-title">Order</h2>
            <span class="avatar-dot">SS</span>
            <button type="button"><i data-lucide="ellipsis"></i></button>
        </div>
        <nav class="updates-tabs">
            <button class="active" type="button" data-panel-tab="updates"><i data-lucide="home"></i> Updates / 1</button>
            <button type="button" data-panel-tab="files">Files</button>
            <button type="button" data-panel-tab="activity">Activity Log</button>
            <button type="button">+</button>
        </nav>
        <section class="updates-tab-panel active" data-panel-name="updates">
            <div class="update-composer">
                <textarea id="panel-notes" placeholder="Write an update and mention others with @"></textarea>
                <div><span>@</span><span>GIF</span><span>Smile</span><button type="button" data-save-notes>Update</button></div>
            </div>
            <article class="update-card">
                <div><span class="avatar-dot">SS</span><strong>Hambelela Operations</strong><small>now</small></div>
                <p id="panel-note-preview">No updates yet.</p>
                <footer><button type="button"><i data-lucide="thumbs-up"></i> Like</button><button type="button"><i data-lucide="reply"></i> Reply</button></footer>
            </article>
        </section>
        <section class="updates-tab-panel" data-panel-name="files">
            <label class="file-drop">Upload file, proof of payment, delivery note or packing photo<input type="file"></label>
            <div class="activity-line">Files will be linked to this order in the next storage step.</div>
        </section>
        <section class="updates-tab-panel" data-panel-name="activity">
            <div id="panel-activity-log" class="activity-log"></div>
        </section>
    </aside>
    <div class="panel-backdrop" id="panel-backdrop" hidden></div>
</main>
<script>
window.HambelelaBoard = {
  dataUrl: 'orders-board-data.php',
  actionUrl: 'orders-board-action.php',
  statuses: <?= json_encode(OPS_ORDER_STATUSES) ?>,
  canEditHeaders: <?= $canEditHeaders ? 'true' : 'false' ?>
};
</script>
<script defer src="<?= BASE_URL ?>/assets/js/orders-board.js?v=<?= htmlspecialchars($boardAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
