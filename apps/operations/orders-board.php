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
$canEditHeaders = current_role_key() !== 'guest';
$boardAssetVersion = is_file(BASE_PATH . '/assets/js/orders-board.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/orders-board.js') . '-orders-rebuild'
    : (string) time();
$ordersStylesVersion = is_file(BASE_PATH . '/assets/css/orders-board.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/orders-board.css')
    : (string) time();
$extraStylesheets = [
    ['path' => 'assets/css/orders-board.css', 'version' => $ordersStylesVersion],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module ops-board-page portal-page orders-page" data-board-theme="light">
    <section class="monday-board-top orders-page-top">
        <header class="monday-board-head work-board-head portal-page-header orders-page-header">
            <div>
                <h1>Hambelela Orders <i data-lucide="chevron-down"></i></h1>
            </div>
            <div class="monday-board-head-actions">
                <div class="board-viewers" id="board-viewers" aria-label="Currently viewing"></div>
                <button type="button" class="invite-btn" data-export-excel><i data-lucide="download"></i> Export Excel</button>
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
            </div>
        </section>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif (!$hasAvailability): ?>
        <section class="ops-alert">Import <code>operations-live-board-migration.sql</code> in phpMyAdmin first. This adds packer lunch/availability tracking.</section>
    <?php endif; ?>

    <section class="orders-date-groups">
        <div class="ops-board-scroll orders-grid-scroll">
            <div class="ops-board-table monday-board orders-board-v2 orders-grid-root" id="orders-board-body">
                <div class="board-empty-state">Loading orders...</div>
            </div>
        </div>
    </section>

    <div class="label-menu" id="board-label-menu" hidden></div>
    <div class="toolbar-popover" id="toolbar-popover" hidden></div>
    <div class="orders-filter-menu" id="orders-filter-menu" role="listbox" hidden></div>
    <aside class="orders-details-panel order-updates-panel order-side-panel" id="order-updates-panel" data-orders-details-panel aria-hidden="true">
        <div class="orders-details-panel-header order-panel-header updates-panel-head">
            <button class="order-panel-close" type="button" data-panel-close aria-label="Close order details"><i data-lucide="x"></i></button>
            <h2 class="order-panel-title" id="panel-order-title">Order</h2>
            <button class="order-panel-menu" type="button" aria-label="More order actions"><i data-lucide="ellipsis"></i></button>
        </div>
        <nav class="orders-details-panel-tabs order-panel-tabs updates-tabs">
            <button type="button" data-panel-tab="details">Details</button>
            <button class="order-panel-tab is-active active" type="button" data-panel-tab="updates" id="panel-updates-tab">Notes</button>
            <button type="button" data-panel-tab="files">Files</button>
            <button type="button" data-panel-tab="activity">Activity Log</button>
        </nav>
        <section class="updates-tab-panel" data-panel-name="details">
            <dl class="order-details-list" id="panel-order-details"></dl>
        </section>
        <section class="updates-tab-panel active" data-panel-name="updates">
            <div class="order-update-meta-actions">
                <span><i data-lucide="mail"></i> Update via email</span>
                <span><i data-lucide="message-square-heart"></i> Give feedback</span>
            </div>
            <div class="order-updates-content">
                <div class="order-update-composer update-composer" id="order-update-composer">
                    <div class="order-format-toolbar" aria-label="Update formatting toolbar">
                        <button type="button" class="order-editor-button" data-editor-command="formatBlock" data-editor-value="P" title="Paragraph">P</button>
                        <button type="button" class="order-editor-button" data-editor-command="bold" title="Bold"><strong>B</strong></button>
                        <button type="button" class="order-editor-button" data-editor-command="italic" title="Italic"><em>I</em></button>
                        <button type="button" class="order-editor-button" data-editor-command="underline" title="Underline"><u>U</u></button>
                        <button type="button" class="order-editor-button" data-editor-command="strikeThrough" title="Strikethrough"><s>S</s></button>
                        <button type="button" class="order-editor-button" data-editor-popup="colour" title="Text colour">A</button>
                        <button type="button" class="order-editor-button" data-editor-popup="font-size" title="Font size">14</button>
                        <button type="button" class="order-editor-button" data-editor-command="insertOrderedList" title="Numbered list">1.</button>
                        <button type="button" class="order-editor-button" data-editor-command="insertUnorderedList" title="Bullet list">UL</button>
                        <button type="button" class="order-editor-button" data-editor-command="createLink" title="Insert link"><i data-lucide="link"></i></button>
                        <button type="button" class="order-editor-button" data-editor-popup="align" title="Align text"><i data-lucide="align-left"></i></button>
                        <button type="button" class="order-editor-button" data-editor-command="insertHorizontalRule" title="Horizontal rule">HR</button>
                        <button type="button" class="order-editor-button" data-editor-command="undo" title="Undo"><i data-lucide="undo-2"></i></button>
                        <button type="button" class="order-editor-button" data-editor-action="confirm" title="Confirm"><i data-lucide="check"></i></button>
                    </div>
                    <div class="order-update-editor" id="panel-update-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write an update and mention others with @"></div>
                    <input type="file" class="update-file-input" id="order-update-file-input" hidden multiple>
                    <div class="order-selected-attachments" id="order-selected-attachments" hidden></div>
                    <div class="order-composer-bottom">
                        <div class="order-composer-icons">
                            <button type="button" class="order-composer-icon-button" data-composer-action="mention" title="Mention someone">@</button>
                            <button type="button" class="order-composer-icon-button" data-composer-action="attach" title="Attach files"><i data-lucide="paperclip"></i></button>
                            <button type="button" class="order-composer-icon-button" data-composer-action="gif" title="Add GIF">GIF</button>
                            <button type="button" class="order-composer-icon-button" data-composer-action="emoji" title="Add emoji"><i data-lucide="smile"></i></button>
                            <button type="button" class="order-composer-icon-button" data-composer-action="magic" title="Refine text"><i data-lucide="sparkles"></i></button>
                        </div>
                        <div class="order-update-submit-wrap">
                            <button class="order-update-button" type="button" data-save-notes>Update</button>
                            <button class="order-update-dropdown-button" type="button" data-update-schedule-toggle aria-label="Schedule update"><i data-lucide="chevron-down"></i></button>
                            <div class="order-schedule-popover" id="order-schedule-popover" hidden>
                                <button class="order-schedule-option" type="button" data-schedule-option>Tomorrow at 09:00 AM</button>
                                <button class="order-schedule-option" type="button" data-schedule-option>Monday at 09:00 AM</button>
                                <div class="order-schedule-divider"></div>
                                <button class="order-schedule-option" type="button" data-schedule-option>Custom time</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="order-empty-updates" id="panel-empty-updates" hidden>
                    <div class="order-empty-illustration" aria-hidden="true"><i data-lucide="messages-square"></i></div>
                    <div class="order-empty-title">No updates yet</div>
                    <p class="order-empty-text">Share progress, mention a teammate, or upload a file to get things moving</p>
                </div>
                <div id="panel-updates-list" class="order-updates-list"></div>
            </div>
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
