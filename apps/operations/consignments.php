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
    <aside class="order-updates-panel packing-item-panel" id="packing-panel" aria-hidden="true">
        <header class="packing-item-panel-header">
            <button type="button" class="packing-item-close" data-packing-panel-close aria-label="Close item details"><i data-lucide="x"></i></button>
            <div class="packing-item-heading">
                <p class="packing-item-kicker">Packing Item</p>
                <h1 class="packing-item-title" id="packing-panel-title">Packing item</h1>
                <div class="packing-item-header-meta">
                    <span id="packing-panel-item-id">Portal item</span>
                    <span id="packing-panel-source">Packing list</span>
                </div>
            </div>
        </header>
        <nav class="packing-item-tabs" role="tablist">
            <button class="packing-item-tab active is-active" type="button" role="tab" aria-selected="true" data-packing-panel-tab="details"><i data-lucide="layout-list"></i> Details</button>
            <button class="packing-item-tab" type="button" role="tab" aria-selected="false" data-packing-panel-tab="files"><i data-lucide="paperclip"></i> Files</button>
        </nav>
        <section class="updates-tab-panel packing-item-panel-body active" data-packing-panel-name="details">
            <section class="packing-item-section packing-item-notes-section">
                <div class="packing-item-section-header">
                    <div>
                        <h2 class="packing-item-section-title">Notes</h2>
                        <p class="packing-item-section-subtitle">Add packing instructions, corrections or internal notes.</p>
                    </div>
                </div>
                <textarea class="packing-item-notes-input" id="packing-panel-notes" rows="4" placeholder="Quantity differences, label issues, stock notes"></textarea>
                <div class="packing-item-section-actions"><button type="button" class="pk-btn pk-btn--primary" data-packing-save-notes>Save notes</button></div>
            </section>
            <div id="packing-panel-activity" class="packing-item-activity"></div>
        </section>
        <section class="updates-tab-panel packing-item-panel-body" data-packing-panel-name="files">
            <section class="packing-item-section">
                <h2 class="packing-item-section-title">Files</h2>
                <p class="packing-item-section-subtitle">Upload invoices, labels or product photos for this packing item.</p>
                <label class="packing-item-file-drop"><i data-lucide="upload-cloud"></i><span>Choose a file</span><input type="file" accept="image/png,image/jpeg,image/webp,application/pdf"></label>
                <p class="packing-item-file-note">File storage will be linked in the next storage step.</p>
            </section>
        </section>
    </aside>
    <div class="panel-backdrop" id="packing-backdrop" hidden></div>

    <div class="modal-backdrop packing-item-modal-overlay" id="packing-create-modal" hidden>
        <form class="packing-item-modal" data-packing-create-form>
            <header class="packing-item-modal-header">
                <div><p class="packing-item-modal-kicker">Packing List</p><h2 class="packing-item-modal-title">New packing item</h2><p class="packing-item-modal-subtitle">Add the item, quantities, assignment and packing details.</p></div>
                <button type="button" class="packing-item-modal-close" data-close-modal aria-label="Close new packing item"><i data-lucide="x"></i></button>
            </header>
            <div class="packing-item-modal-body">
                <section class="packing-item-form-section">
                    <div class="packing-item-form-section-header"><h3>Item details</h3><p>Enter the received item and quantities to be packed.</p></div>
                    <div class="packing-item-form-grid">
                        <div class="packing-item-form-field"><label>Item <span aria-hidden="true">*</span></label><input name="item_name" required placeholder="Chia Seeds"></div>
                        <div class="packing-item-form-field"><label>Received weight</label><input name="received_weight" placeholder="25kg"></div>
                        <div class="packing-item-form-field"><label>Quantity to pack <span aria-hidden="true">*</span></label><input name="quantity_planned" required placeholder="100g(150), 500g(8), 1kg(1)"></div>
                        <div class="packing-item-form-field" data-portal-date-field><label>Date loaded</label><input id="new-packing-date-display" class="portal-date-input" type="text" data-enable-time="true" data-submit-target="#new-packing-date" placeholder="Select date and time"><input id="new-packing-date" name="date_loaded" type="hidden" value=""></div>
                    </div>
                </section>
                <section class="packing-item-form-section">
                    <div class="packing-item-form-section-header"><h3>Assignment and priority</h3><p>Choose the urgency and responsible packer.</p></div>
                    <div class="packing-item-form-grid packing-item-form-grid--two">
                        <div class="packing-item-form-field"><label>Priority</label><select name="priority" data-portal-custom-select><option value="top_critical">Top Critical</option><option value="high" selected>High</option><option value="medium">Medium</option><option value="low">Low</option></select></div>
                        <div class="packing-item-form-field"><label>Person</label><select name="assigned_employee_id" data-create-person data-portal-custom-select><option value="">Auto assign</option></select></div>
                    </div>
                </section>
                <section class="packing-item-form-section">
                    <div class="packing-item-form-section-header"><h3>Notes</h3><p>Add packing instructions or internal information.</p></div>
                    <div class="packing-item-form-field packing-item-form-field--full"><label>Notes</label><textarea name="notes" placeholder="Invoice notes or packing instructions"></textarea></div>
                </section>
            </div>
            <footer class="packing-item-modal-footer"><button type="button" class="pk-btn pk-btn--secondary" data-close-modal>Cancel</button><button type="submit" class="pk-btn pk-btn--primary" data-create-packing-submit><span data-create-packing-submit-text>Create packing row</span></button></footer>
        </form>
    </div>

    <div class="invoice-upload-overlay" id="packing-invoice-modal" hidden>
        <form class="invoice-upload-modal packing-invoice-flow" data-invoice-draft-form role="dialog" aria-modal="true" aria-labelledby="invoice-upload-title">
            <header class="invoice-upload-header"><div><p class="invoice-upload-kicker">Packing List</p><h2 class="invoice-upload-title" id="invoice-upload-title">Upload invoice</h2><p class="invoice-upload-subtitle">Extract invoice items, review quantities, assign packers and sync approved rows.</p></div><button type="button" class="invoice-upload-close" data-close-modal aria-label="Close Upload Invoice"><i data-lucide="x"></i></button></header>
            <div class="invoice-upload-body">
                <nav class="invoice-progress" data-invoice-stepper aria-label="Invoice upload progress">
                    <?php foreach ([['upload','Upload'],['extract','Extract'],['review','Review'],['assign','Assign'],['create','Create']] as $index => [$stepKey,$stepLabel]): ?>
                    <?= $index ? '<span class="invoice-progress-line"></span>' : '' ?><button type="button" class="invoice-progress-step<?= $index === 0 ? ' active is-active' : '' ?>" data-invoice-step="<?= htmlspecialchars($stepKey, ENT_QUOTES, 'UTF-8') ?>"><span class="invoice-progress-number"><?= $index + 1 ?></span><span><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
                    <?php endforeach; ?>
                </nav>
                <section class="invoice-section"><div class="invoice-section-header"><div><h3 class="invoice-section-title">Invoice source</h3><p class="invoice-section-description">Choose the PDF and confirm the invoice details before extraction.</p></div></div>
                    <div class="invoice-form-grid">
                        <div class="invoice-form-field"><label for="invoice-file">Invoice PDF</label><div class="invoice-file-upload" data-invoice-file-upload><input id="invoice-file" type="file" name="invoice_file" accept="application/pdf" hidden><button type="button" class="invoice-file-button" data-select-invoice-file><i data-lucide="upload"></i><span>Choose invoice PDF</span></button><span class="invoice-file-name" data-invoice-file-name>No PDF selected</span><button type="button" class="invoice-file-remove" data-remove-invoice-file hidden aria-label="Remove selected PDF"><i data-lucide="x"></i></button></div></div>
                        <div class="invoice-form-field"><label for="invoice-supplier">Supplier name</label><input id="invoice-supplier" name="supplier_name" placeholder="Optional"></div>
                        <div class="invoice-form-field"><label for="invoice-number">Invoice number</label><input id="invoice-number" name="invoice_number" data-draft-invoice-number placeholder="Auto extracted"></div>
                        <div class="invoice-form-field"><label for="invoice-date-display">Invoice date</label><div data-portal-date-field><input id="invoice-date-display" class="portal-date-input" type="text" data-enable-time="false" data-submit-target="#invoice-date" placeholder="Select date"><input id="invoice-date" name="invoice_date" data-draft-invoice-date type="hidden"><button type="button" class="portal-date-trigger" aria-label="Choose invoice date"><i data-lucide="calendar-days"></i></button></div></div>
                    </div>
                </section>
                <section class="invoice-section"><div class="invoice-section-header"><div><h3 class="invoice-section-title">Extraction settings</h3><p class="invoice-section-description">Choose the default priority and how matching rows should be handled.</p></div></div><div class="invoice-form-grid invoice-form-grid--two">
                    <div class="invoice-form-field"><label for="invoice-priority">Priority before sync</label><select id="invoice-priority" name="invoice_priority" data-invoice-priority data-portal-custom-select><option value="top_critical">Top Critical</option><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select></div>
                    <div class="invoice-form-field"><label for="invoice-sync-mode">Sync mode</label><select id="invoice-sync-mode" name="sync_mode" data-portal-custom-select><option value="update_existing" selected>Update existing / skip duplicates</option><option value="skip_duplicates">Skip duplicates only</option><option value="create_only">Create new rows only</option></select></div>
                </div><div class="invoice-action-strip"><div class="invoice-action-help"><strong>Ready to extract?</strong><span>Upload a PDF or add rows manually if the invoice cannot be read.</span></div><div class="invoice-action-buttons"><button class="invoice-btn invoice-btn--primary" type="button" data-extract-invoice><i data-lucide="scan-text"></i><span>Extract invoice</span></button><button class="invoice-btn invoice-btn--secondary" type="button" data-add-draft-row><i data-lucide="plus"></i>Add row</button><button class="invoice-btn invoice-btn--secondary" type="button" data-redistribute-draft><i data-lucide="shuffle"></i>Redistribute packers</button></div></div>
                    <div class="invoice-process-state" data-invoice-progress hidden><span class="invoice-process-spinner" aria-hidden="true"></span><div><strong data-invoice-progress-title>Reading invoice</strong><span data-invoice-progress-text>Extracting products, weights and quantities…</span></div></div>
                </section>
                <section class="invoice-section"><div class="invoice-section-header"><div><h3 class="invoice-section-title">Manual fallback</h3><p class="invoice-section-description">Enter one item per line when the invoice PDF cannot be extracted.</p></div></div><div class="invoice-form-field"><label for="invoice-manual">Invoice rows</label><textarea id="invoice-manual" class="invoice-manual-input" name="invoice_draft" placeholder="Mango Butter | 5kg | 100g(20), 250g(8)"></textarea><p class="invoice-manual-example">Format: Product | received weight | quantity to pack</p></div></section>
                <section class="invoice-section"><div class="invoice-section-header"><div><h3 class="invoice-section-title">Extracted invoice rows</h3><p class="invoice-section-description">Review every row before creating or updating Packing List items.</p></div></div><div class="draft-workload-summary invoice-review-summary" data-draft-workload-summary hidden></div><div class="invoice-review-table-wrap invoice-draft-wrap"><table class="invoice-review-table invoice-draft-table"><thead><tr><th>Item</th><th>Received</th><th>Unit</th><th>Quantity to pack</th><th>Priority</th><th>Assigned</th><th>Workload</th><th>Monday</th><th>Actions</th></tr></thead><tbody data-invoice-draft-body><tr class="invoice-empty-row"><td colspan="9"><div class="invoice-empty-state"><i data-lucide="file-text"></i><div><strong>No invoice rows yet</strong><span>Upload and extract an invoice, or add a row manually.</span></div></div></td></tr></tbody></table></div></section>
            </div>
            <footer class="invoice-upload-footer"><div class="invoice-footer-status"><strong>Step 1 of 5</strong><span data-invoice-extract-status>Upload an invoice or add rows manually.</span></div><div class="invoice-footer-actions"><button class="invoice-btn invoice-btn--secondary" type="button" data-close-modal>Cancel</button><button class="invoice-btn invoice-btn--primary" type="submit">Confirm and sync to Monday</button></div></footer>
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
