<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Packing List | ' . APP_NAME;
$activeApp = 'operations-consignments';
$ready = ops_database_ready() && ops_table_exists('ops_packing_tasks');
$migrationReady = $ready
    && ops_column_exists('ops_packing_tasks', 'received_weight')
    && ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
    && ops_column_exists('ops_packing_tasks', 'date_started');
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$canEditHeaders = user_has_role('owner_admin');
$packingJsVersion = is_file(BASE_PATH . '/assets/js/packing-list.js')
    ? (string) filemtime(BASE_PATH . '/assets/js/packing-list.js') . '-visibility1'
    : (string) time();
$packingCssVersion = is_file(BASE_PATH . '/assets/css/packing-board.css')
    ? (string) filemtime(BASE_PATH . '/assets/css/packing-board.css') . '-v2'
    : (string) time();
$extraStylesheets[] = [
    'path' => 'assets/css/packing-board.css',
    'version' => $packingCssVersion,
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module ops-board-page packing-list-page packing-page-v2" data-board-theme="light">
    <section class="monday-board-top packing-page-shell">
        <div class="monday-board-head work-board-head packing-header">
            <div>
                <h1>Hambelela Packing <i data-lucide="chevron-down"></i></h1>
                <p class="packing-load-state" data-packing-count>Loading packing list...</p>
            </div>
            <div class="monday-board-head-actions">
                <button type="button" class="invite-btn packing-btn packing-btn-secondary" data-packing-export><i data-lucide="download"></i> Export Excel</button>
                <button type="button" class="packing-btn packing-btn-secondary" data-packing-undo disabled><i data-lucide="undo-2"></i> Undo</button>
            </div>
        </div>

        <section class="work-metric-grid packing-metric-grid packing-stats" aria-label="Packing summary">
            <article class="work-metric-card packing-stat-card pk-total">
                <span class="metric-icon"><i data-lucide="package-open"></i></span>
                <div><span class="metric-title">Total Items</span><strong data-packing-metric="total">0</strong></div>
            </article>
            <article class="work-metric-card packing-stat-card pk-packing">
                <span class="metric-icon"><i data-lucide="clock-3"></i></span>
                <div><span class="metric-title">Packing</span><strong data-packing-metric="packing">0</strong></div>
            </article>
            <article class="work-metric-card packing-stat-card pk-done">
                <span class="metric-icon"><i data-lucide="check-circle-2"></i></span>
                <div><span class="metric-title">Done</span><strong data-packing-metric="done">0</strong></div>
            </article>
            <article class="work-metric-card packing-stat-card pk-website">
                <span class="metric-icon"><i data-lucide="globe-2"></i></span>
                <div><span class="metric-title">Website Inventory</span><strong data-packing-metric="website">0</strong></div>
            </article>
            <article class="work-metric-card packing-stat-card pk-pending">
                <span class="metric-icon"><i data-lucide="hourglass"></i></span>
                <div><span class="metric-title">Pending</span><strong data-packing-metric="pending">0</strong></div>
            </article>
            <article class="work-metric-card packing-stat-card pk-unassigned">
                <span class="metric-icon"><i data-lucide="user-round-x"></i></span>
                <div><span class="metric-title">Unassigned</span><strong data-packing-metric="unassigned">0</strong></div>
            </article>
        </section>

        <section class="work-filter-bar packing-filter-bar packing-toolbar" aria-label="Packing filters">
            <label>Date
                <div class="portal-date-field" data-portal-date-field>
                    <input class="portal-date-input" type="text" placeholder="All months" autocomplete="off" data-month-mode="true" data-submit-target="#packing-date-value">
                    <input id="packing-date-value" data-packing-date type="hidden" value="">
                    <button type="button" class="portal-date-trigger" aria-label="Open month picker"><i data-lucide="calendar-days"></i></button>
                </div>
            </label>
            <label>Priority
                <select data-packing-filter="priority" data-portal-custom-select>
                    <option value="">All</option>
                    <option value="top_critical">Top Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </label>
            <label>Status
                <select data-packing-filter="status" data-portal-custom-select>
                    <option value="">All</option>
                    <option value="not_started">Not Started</option>
                    <option value="packing">Packing</option>
                    <option value="done">Done</option>
                    <option value="packed_label_needed">Packed Label Needed</option>
                    <option value="label_created">Label Created</option>
                    <option value="website">Website</option>
                    <option value="correction_needed">Correction Needed</option>
                </select>
            </label>
            <label>Person
                <select data-packing-filter="person" data-portal-custom-select><option value="">All</option></select>
            </label>
            <label>Group By
                <select data-packing-group-select data-portal-custom-select>
                    <option value="month">Month</option>
                    <option value="priority">Priority</option>
                    <option value="person">Person</option>
                    <option value="status">Status</option>
                </select>
            </label>
            <label class="work-search">Search
                <input class="packing-search-input" data-packing-search type="search" placeholder="Search packing items...">
            </label>
            <div class="work-filter-actions">
                <?php if ($canManage): ?>
                    <button type="button" data-open-packing-create><i data-lucide="plus"></i> New item</button>
                    <button type="button" data-open-invoice><i data-lucide="upload"></i> Upload invoice</button>
                    <button type="button" data-sync-monday-packing><i data-lucide="download-cloud"></i> Sync Monday</button>
                    <button type="button" data-find-packing-duplicates><i data-lucide="scan-search"></i> Find possible duplicates</button>
                    <button type="button" data-import-previous-packing><i data-lucide="copy-plus"></i> Import previous list</button>
                <?php endif; ?>
                <button type="button" data-packing-refresh><i data-lucide="refresh-cw"></i> Refresh</button>
            </div>
        </section>
    </section>

    <?php if (!$ready): ?>
        <?php ops_setup_notice(); ?>
    <?php elseif (!$migrationReady): ?>
        <section class="ops-alert">Import <code>operations-packing-list-migration.sql</code> in phpMyAdmin to activate received weight, website confirmation and time tracking fields.</section>
    <?php endif; ?>

    <section class="packing-board-shell packing-board-v2" aria-label="Packing board">
        <input type="checkbox" class="packing-select-all-master" data-packing-select-all aria-hidden="true" tabindex="-1">
        <div id="packing-list-body" class="packing-date-groups" aria-live="polite">
            <div class="packing-loading-state">Loading packing list...</div>
        </div>
    </section>

    <div class="label-menu" id="packing-label-menu" hidden></div>
    <div class="toolbar-popover" id="packing-popover" hidden></div>
    <aside class="order-updates-panel" id="packing-panel" aria-hidden="true">
        <div class="updates-panel-head">
            <button type="button" data-packing-panel-close><i data-lucide="x"></i></button>
            <h2 id="packing-panel-title">Packing item</h2>
        </div>
        <nav class="updates-tabs">
            <button class="active" type="button" data-packing-panel-tab="details"><i data-lucide="home"></i> Details</button>
            <button type="button" data-packing-panel-tab="files">Files</button>
        </nav>
        <section class="updates-tab-panel active" data-packing-panel-name="details">
            <div class="update-composer">
                <textarea id="packing-panel-notes" placeholder="Quantity differences, label issues, stock notes"></textarea>
                <div><button type="button" data-packing-save-notes>Update</button></div>
            </div>
            <div id="packing-panel-activity" class="activity-log"></div>
        </section>
        <section class="updates-tab-panel" data-packing-panel-name="files">
            <label class="file-drop">Upload invoice, labels or product photos<input type="file"></label>
            <div class="activity-line">File storage will be linked in the next storage step.</div>
        </section>
    </aside>
    <div class="panel-backdrop" id="packing-backdrop" hidden></div>

    <div class="modal-backdrop" id="packing-create-modal" hidden>
        <form class="panel ops-form packing-modal" data-packing-create-form>
            <div class="section-row"><h2>New packing item</h2><button type="button" data-close-modal>Close</button></div>
            <div class="form-grid compact">
                <label>Item<input name="item_name" required placeholder="Chia Seeds"></label>
                <label>Received weight<input name="received_weight" placeholder="25kg"></label>
                <label>Priority<select name="priority"><option value="top_critical">Top Critical</option><option value="high" selected>High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
                <label>Date loaded<input name="date_loaded" type="datetime-local" value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Quantity to pack<input name="quantity_planned" required placeholder="100g(150), 500g(8), 1kg(1)"></label>
                <label>Person<select name="assigned_employee_id" data-create-person><option value="">Auto assign</option></select></label>
            </div>
            <label>Notes<textarea name="notes" placeholder="Invoice notes or packing instructions"></textarea></label>
            <div class="ops-form-actions"><button class="button primary" type="submit">Create packing row</button></div>
        </form>
    </div>

    <div class="modal-backdrop" id="packing-invoice-modal" hidden>
        <form class="panel ops-form packing-modal packing-invoice-flow" data-invoice-draft-form>
            <div class="section-row"><h2>Upload invoice</h2><button type="button" data-close-modal>Close</button></div>
            <ol class="invoice-flow-steps" data-invoice-stepper>
                <li class="active" data-invoice-step="upload">Upload</li><li data-invoice-step="extract">Extract</li><li data-invoice-step="review">Review</li><li data-invoice-step="assign">Assign</li><li data-invoice-step="create">Create</li>
            </ol>
            <div class="form-grid compact">
                <label>Invoice PDF<input type="file" name="invoice_file" accept="application/pdf"></label>
                <label>Supplier name<input name="supplier_name" placeholder="Optional"></label>
                <label>Invoice number<input name="invoice_number" data-draft-invoice-number placeholder="Auto extracted"></label>
                <label>Invoice date<input name="invoice_date" data-draft-invoice-date type="date"></label>
                <label>Priority before sync<select name="invoice_priority" data-invoice-priority><option value="top_critical">Top Critical</option><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select></label>
                <label>Sync mode<select name="sync_mode"><option value="update_existing" selected>Update existing / skip duplicates</option><option value="skip_duplicates">Skip duplicates only</option><option value="create_only">Create new rows only</option></select></label>
            </div>
            <p class="muted">Upload a PDF to extract product lines automatically. If extraction is unavailable, use the manual fallback below.</p>
            <div class="ops-form-actions">
                <button class="button" type="button" data-extract-invoice><i data-lucide="scan-text"></i> Extract invoice</button>
                <button class="button" type="button" data-add-draft-row><i data-lucide="plus"></i> Add row</button>
                <button class="button" type="button" data-redistribute-draft><i data-lucide="shuffle"></i> Redistribute Packers</button>
            </div>
            <div class="invoice-progress" data-invoice-progress hidden>
                <span class="invoice-spinner" aria-hidden="true"></span>
                <div>
                    <strong data-invoice-progress-title>Extracting invoice items...</strong>
                    <p data-invoice-progress-text>Please wait while the system reads the invoice and prepares draft rows.</p>
                </div>
            </div>
            <label>Manual fallback<textarea name="invoice_draft" rows="4" placeholder="Product | received weight | quantity to pack, e.g. Mango Butter | 5kg | 100g(20), 250g(8)"></textarea></label>
            <div class="invoice-draft-wrap">
                <table class="invoice-draft-table">
                    <thead><tr><th>Item</th><th>Received</th><th>Unit</th><th>Quantity to pack</th><th>Priority</th><th>Assigned</th><th>Workload</th><th>Monday</th><th>Actions</th></tr></thead>
                    <tbody data-invoice-draft-body><tr><td colspan="9">Extract an invoice or add a row to review before saving.</td></tr></tbody>
                </table>
            </div>
            <div class="draft-workload-summary" data-draft-workload-summary hidden></div>
            <p class="muted" data-invoice-extract-status>Step 1: upload invoice or type rows manually.</p>
            <div class="ops-form-actions"><button class="button primary" type="submit">Confirm and Sync to Monday</button></div>
        </form>
    </div>
</main>
<script>
window.HambelelaPacking = {
  dataUrl: 'packing-list-data.php',
  actionUrl: 'packing-list-action.php',
  canEditHeaders: <?= $canEditHeaders ? 'true' : 'false' ?>
};
</script>
<script defer src="<?= BASE_URL ?>/assets/js/packing-list.js?v=<?= htmlspecialchars($packingJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
