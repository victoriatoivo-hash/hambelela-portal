<section class="courier-hub" data-waybill-app>
<div class="courier-kpi-grid">
<?php foreach ([['uploaded_today','Uploaded Today','upload-cloud'],['pending','Pending Send','clock-3'],['overdue','Overdue','triangle-alert'],['sent_this_month','Sent This Month','send']] as [$key,$label,$icon]): ?>
<article class="courier-kpi-card"><span class="courier-kpi-icon"><i data-lucide="<?= wb_e($icon) ?>" aria-hidden="true"></i></span><div><div class="courier-kpi-label"><?= wb_e($label) ?></div><strong class="courier-kpi-value" data-stat="<?= wb_e($key) ?>"><?= number_format($payload['stats'][$key]) ?></strong></div></article>
<?php endforeach; ?>
</div>
<div class="courier-workspace<?= !$canUploadWaybills ? ' courier-workspace--no-upload' : '' ?>">
        <?php if ($canUploadWaybills): ?>
        <section class="section-card courier-section courier-panel courier-upload-panel">
                <div class="card-head courier-section-header">
                    <div>
                        <h2 class="card-title"><i data-lucide="upload-cloud" aria-hidden="true"></i>Upload Waybills</h2>
                        <p class="card-sub">Uploading as: <strong><?= wb_e(wb_current_name()) ?></strong>. Multiple files can be uploaded in one batch.</p>
                    </div>
                    <span class="due-badge"><i data-lucide="alarm-clock"></i> Due by: <?= wb_e(wb_due_label($duePreview)) ?></span>
                </div>

                <form class="upload-form" data-waybill-upload enctype="multipart/form-data">
                    <input type="hidden" name="action" value="waybill_upload">
                    <div class="field-label span-2 waybill-files-field">
                        <span>Waybill files *</span>
                        <label class="dropzone" data-dropzone>
                            <input class="courier-file-input" name="waybill_files[]" type="file" aria-label="Choose waybill files" accept=".pdf,.jpg,.jpeg,.png" multiple required>
                            <div class="dz-icon"><i data-lucide="upload-cloud"></i></div>
                            <div class="dz-main">Drag and drop waybill files here</div>
                            <div class="dz-sub">or click to browse · PDF, JPG or PNG · multiple files supported</div>
                        </label>
                        <div data-file-chips></div>
                    </div>
                    <label class="field-label upload-input-field">Sent Date
                        <input name="sent_date" type="date" value="<?= wb_e(date('Y-m-d')) ?>" required>
                    </label>
                    <div class="field-label span-2 courier-field">
                        <span>Courier</span>
                        <div class="courier-chips">
                            <?php foreach (wb_allowed_couriers() as $courier): ?>
                                <label class="courier-chip"><input type="checkbox" name="couriers[]" value="<?= wb_e($courier) ?>"> <?= wb_e($courier) ?></label>
                            <?php endforeach; ?>
                            <button class="btn-add-courier" type="button" data-add-courier>+ Add courier</button>
                        </div>
                        <dialog class="add-courier-inline" data-add-courier-inline aria-labelledby="courier-add-title">
                            <header><div><h2 id="courier-add-title">Add courier</h2><p>Add a courier name to this upload, not a saved partner record.</p></div><button type="button" data-add-courier-close aria-label="Close Add Courier">×</button></header>
                            <label>Courier name<input type="text" data-add-courier-name placeholder="Courier name"></label>
                            <footer><button class="btn-secondary" type="button" data-add-courier-close>Cancel</button><button class="btn-primary" type="button" data-add-courier-save>Add to upload</button></footer>
                        </dialog>
                    </div>
                    <label class="field-label span-2 notes-field">Notes for Front Desk
                        <textarea name="notes" placeholder="Optional note for Secilia"></textarea>
                    </label>
                    <div class="span-2 form-actions">
                        <button class="btn-primary" type="submit" data-upload-submit><i data-lucide="upload"></i> Upload Waybills</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php include BASE_PATH.'/shared/ess-courier-side.php'; ?>
</div>
<section id="courier-records" class="courier-panel courier-records"><header class="courier-panel-header"><span class="courier-section-icon"><i data-lucide="list-checks" aria-hidden="true"></i></span><div><h2>Waybill records</h2><p>Search, sort and grouping apply to the queue. Date filters apply to sent history.</p></div></header>
        <form class="section-card courier-section filter-strip" method="get" data-waybill-filter>
            <div class="filter-date-row">
                <label class="field-label">From
                    <input type="date" name="date_from" value="<?= wb_e($historyDateFrom) ?>">
                </label>
                <label class="field-label">To
                    <input type="date" name="date_to" value="<?= wb_e($historyDateTo) ?>">
                </label>
            </div>
            <label class="field-label filter-search-row">Search
                <input type="search" name="search" placeholder="Search courier waybills">
            </label>
            <div class="filter-actions-row">
                <button class="btn-primary filter-apply-button" type="submit"><i data-lucide="check"></i> Apply filters</button>
                <a class="btn-secondary filter-clear-button" href="courier.php"><i data-lucide="rotate-ccw"></i> Reset</a>
            </div>
        </form>
<div class="courier-tabs" role="tablist" aria-label="Waybill records"><button type="button" class="courier-tab is-active" role="tab" aria-selected="true" data-ess-record-tab="queue">Waybill Queue</button><button type="button" class="courier-tab" role="tab" aria-selected="false" data-ess-record-tab="history">Sent History</button></div>

        <section class="section-card courier-section courier-record-panel" data-ess-record-panel="queue">
            <div class="courier-section-inner">
                <div class="card-head courier-section-header">
                    <div>
                        <h2 class="card-title">Waybill Queue</h2>
                    </div>
                    <div class="courier-queue-header-actions">
                        <button class="btn-secondary refresh-btn courier-secondary-btn" type="button" data-refresh-waybills data-view-sync-action><i data-lucide="refresh-cw"></i> Refresh</button>
                    </div>
                </div>
                <div class="courier-table-scroll courier-table-wrap">
                        <div class="courier-table-shell courier-table-shell--queue">
                        <div class="courier-grid courier-grid-waybill courier-grid-header queue-head">
                            <div class="courier-cell courier-select-cell" data-column-key="select">
                                <label class="portal-grid-checkbox courier-select-all" aria-label="Select all waybill batches">
                                    <input class="portal-grid-checkbox-input" type="checkbox" data-courier-select-all>
                                    <span class="portal-grid-checkbox-box" aria-hidden="true">
                                        <svg viewBox="0 0 12 12" aria-hidden="true">
                                            <path d="M2.25 6.25 4.8 8.8 9.75 3.85" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                </label>
                            </div><div class="courier-cell queue-main" data-column-key="courier"><span>Courier</span></div><div class="courier-cell" data-column-key="waybills">Waybills</div><div class="courier-cell" data-column-key="uploaded">Uploaded</div><div class="courier-cell" data-column-key="uploaded_by">Uploaded By</div><div class="courier-cell" data-column-key="due">Due</div><div class="courier-cell" data-column-key="status">Status</div><div class="courier-cell" data-column-key="sent_at">Sent At</div><div class="courier-cell" data-column-key="sent_by">Sent By</div><div class="courier-cell" data-column-key="notes">Notes</div><div class="courier-cell" data-column-key="actions">Actions</div>
                        </div>
                        <div class="queue-list" data-waybill-queue><?= $payload['queue_html'] ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-card courier-section courier-record-panel" data-ess-record-panel="history">
            <div class="courier-section-inner">
                <div class="card-head courier-section-header history-summary">
                    <div>
                        <h2 class="card-title">Sent History</h2>
                        <p class="card-sub">Waybills marked sent from <?= wb_e($historyDateFrom) ?> to <?= wb_e($historyDateTo) ?>.</p>
                    </div>
                </div>
                <div class="courier-table-scroll courier-table-wrap">
                    <div class="courier-table-shell courier-table-shell--history">
                        <div class="courier-grid courier-grid-history courier-grid-header history-head">
                            <div class="courier-cell">Courier</div><div class="courier-cell">Uploaded</div><div class="courier-cell">Uploaded By</div><div class="courier-cell">Due</div><div class="courier-cell">Sent At</div><div class="courier-cell">Sent By</div><div class="courier-cell">Result</div><div class="courier-cell courier-history-actions-header" data-column-key="actions">Actions</div>
                        </div>
                        <div class="history-list" data-waybill-history><?= $payload['history_html'] ?></div>
                    </div>
                </div>
            </div>
        </section>

</section></section>
