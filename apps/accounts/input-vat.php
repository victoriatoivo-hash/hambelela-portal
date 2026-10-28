<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/employee-features.php';
require_once BASE_PATH.'/shared/accounts-input-vat.php';

accounts_require_input_vat_access();
accounts_input_vat_schema_ready();

$requestedView = strtolower(trim((string) ($_GET['view'] ?? $_GET['period'] ?? '')));
$requestedMonth = preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
if (!accounts_can('input_vat.history') && in_array($requestedView, ['history', 'transaction-history'], true)) {
    header('Location: input-vat.php');
    exit;
}

$rate = accounts_standard_vat_rate();
$rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
$pageTitle = 'Input VAT | '.APP_NAME;
$activeApp = 'accounts';
$extraStylesheets = [
    ['path' => 'assets/css/accounts-vat-workspace.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/accounts-vat-workspace.css')],
];
include BASE_PATH.'/shared/header.php';
include BASE_PATH.'/shared/sidebar.php';
?>

<main class="workspace module accounts-page vatx-page vatx-page--input" id="inputVatPage" data-vat-workspace="input" data-api="input-vat-api.php" data-csrf="<?=htmlspecialchars(accounts_csrf_token(), ENT_QUOTES, 'UTF-8')?>" data-owner="<?=accounts_can('input_vat.history') ? '1' : '0'?>" data-rate="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>" data-capture-start="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>" data-business-today="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" data-business-timezone="Africa/Windhoek">
  <?php if(accounts_can_access_workspace()): ?>
  <nav class="accounts-breadcrumb" aria-label="Breadcrumb"><a href="index.php">Accounts</a><span aria-hidden="true">&rsaquo;</span><strong>Input VAT</strong></nav>
  <?php endif; ?>

  <header class="accounts-hero vatx-header">
    <div class="accounts-hero-copy">
      <p class="eyebrow vatx-header__eyebrow">Accounts application</p>
      <h1 class="vatx-header__title">Input VAT</h1>
      <p>Record local purchases, manage receipts and review claimable Input VAT.</p>
      <p class="input-vat-active-period" data-active-period hidden><span data-active-period-kicker>Active period</span><strong data-active-period-value></strong></p>
    </div>
    <div class="accounts-actions vatx-header__actions" data-portal-header-status-target>
      <button class="vatx-button portal-button portal-button--primary vatx-button--primary" type="button" data-add-purchase><span class="portal-button__icon" aria-hidden="true">+</span> Add Purchase</button>
      <?php if(accounts_is_owner()): ?><button type="button" class="vatx-button portal-button portal-button--ghost input-vat-settings-action" data-open-rate-settings><i data-lucide="settings" aria-hidden="true"></i><span>Settings</span></button><?php endif; ?>
    </div>
  </header>

  <?php if(accounts_can('input_vat.history')): ?>
  <nav class="input-vat-primary-tabs vatx-tabs portal-theme-tabs" role="tablist" aria-label="Input VAT views">
    <button type="button" class="portal-theme-tab vatx-tab is-active" role="tab" aria-selected="true" data-vat-view="monthly"><i data-lucide="calendar-range" aria-hidden="true"></i><span>Monthly Input VAT</span></button>
    <button type="button" class="portal-theme-tab vatx-tab" role="tab" aria-selected="false" data-vat-view="history"><i data-lucide="history" aria-hidden="true"></i><span>Transaction History</span></button>
  </nav>
  <?php endif; ?>

  <section class="vatx-period" data-month-workspace aria-label="Input VAT period">
      <button type="button" class="input-vat-month-arrow vatx-period__arrow" data-previous-month aria-label="Previous month"><i data-lucide="chevron-left" aria-hidden="true"></i></button>
      <button type="button" class="vatx-period-trigger vatx-period__trigger" data-vat-period-open aria-haspopup="dialog"><i data-lucide="calendar-days" aria-hidden="true"></i><span data-vat-period-label><?=htmlspecialchars($requestedMonth, ENT_QUOTES, 'UTF-8')?></span><i data-lucide="chevron-down" aria-hidden="true"></i></button>
      <button type="button" class="input-vat-month-arrow vatx-period__arrow" data-next-month aria-label="Next month"><i data-lucide="chevron-right" aria-hidden="true"></i></button>
      <span class="vatx-period-caption">Capture period · Namibia</span>
      <dialog class="accounts-dialog vatx-period-dialog vatx-dialog" data-vat-period-dialog aria-label="Choose capture period"><header class="vatx-dialog__header"><h2>Choose capture period</h2><button type="button" data-vat-period-close aria-label="Close period picker">&times;</button></header><div class="vatx-year-nav"><button type="button" data-vat-year-step="-1" aria-label="Previous year">&lsaquo;</button><label class="vatx-field">Year<select data-month-year aria-label="Input VAT year"></select></label><button type="button" data-vat-year-step="1" aria-label="Next year">&rsaquo;</button></div><nav class="input-vat-month-tabs vatx-month-grid" aria-label="Input VAT months" data-month-tabs></nav><p class="vatx-help">Statuses reflect saved capture progress, not the age of a period.</p></dialog>
  </section>
  <section class="accounts-summary vatx-kpis" data-summary aria-live="polite" data-monthly-section></section>
  <div class="vatx-workspace vatx-workspace--input"><div class="vatx-ledger">

  <section class="accounts-toolbar vatx-filterbar" aria-label="Input VAT working filters" data-monthly-toolbar>
    <input type="hidden" data-month value="<?=htmlspecialchars($requestedMonth, ENT_QUOTES, 'UTF-8')?>">
    <label class="accounts-search-control vatx-field"><span>Search</span><span class="input-vat-search-field"><i data-lucide="search" aria-hidden="true"></i><input type="search" data-search placeholder="Search supplier, description or reference"></span></label>
    <label class="accounts-status-control vatx-field"><span>Status</span><select data-status data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All records</option><option value="captured">Captured</option><option value="needs_correction">Needs Correction</option></select></label>
  </section>

  <?php if(accounts_is_owner()): ?>
  <section class="accounts-toolbar vatx-filterbar input-vat-history-toolbar" data-history-toolbar hidden aria-label="Transaction History filters">
    <div class="input-vat-history-primary">
      <label class="accounts-search-control input-vat-history-search vatx-field"><span>Search</span><span class="input-vat-search-field"><i data-lucide="search" aria-hidden="true"></i><input type="search" data-history-search placeholder="Search supplier, description or reference"></span></label>
      <label class="vatx-field"><span>Month</span><input type="month" data-history-month min="<?=htmlspecialchars(substr(accounts_historical_capture_start_date(),0,7), ENT_QUOTES, 'UTF-8')?>" max="<?=htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="vatx-field"><span>Status</span><select data-history-status data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All statuses</option><option value="captured">Captured</option><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option></select></label>
      <button type="button" class="vatx-button portal-button portal-button--secondary vatx-button--secondary input-vat-history-more" data-history-more aria-expanded="false" aria-controls="inputVatHistoryAdvanced"><i data-lucide="list-filter" aria-hidden="true"></i><span>More filters</span><b data-history-filter-count hidden></b></button>
    </div>
    <div class="input-vat-history-advanced" id="inputVatHistoryAdvanced" data-history-advanced hidden>
      <div class="input-vat-history-advanced-grid">
        <label class="vatx-field"><span>From</span><input type="date" data-history-from min="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>"></label>
        <label class="vatx-field"><span>To</span><input type="date" data-history-to max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>"></label>
        <label class="vatx-field"><span>Entered by</span><span class="input-vat-search-field"><i data-lucide="user" aria-hidden="true"></i><input type="search" data-history-entered-by placeholder="Employee name"></span></label>
        <label class="vatx-field"><span>Adjustment</span><select data-history-manual data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All records</option><option value="1">Manual adjustments</option><option value="0">Automatic calculations</option></select></label>
      </div>
      <footer class="vatx-dialog__footer"><button type="button" class="vatx-button portal-button portal-button--secondary vatx-button--secondary" data-history-clear>Clear all</button><button type="button" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-history-apply>Apply filters</button></footer>
    </div>
    <div class="input-vat-history-chips" data-history-chips hidden></div>
  </section>
  <?php endif; ?>

  <aside class="input-vat-period-notice" data-period-notice role="status" aria-live="polite" hidden>
    <div><strong data-period-notice-title></strong><span data-period-notice-copy></span></div>
    <button type="button" class="vatx-button portal-button portal-button--secondary vatx-button--secondary" data-view-saved-month>View period</button>
  </aside>

  <section class="accounts-table-card vatx-card">
    <header class="vatx-section-heading"><h2>Purchase records</h2><span data-vat-ledger-count></span></header>
    <p class="accounts-scroll-hint">Swipe to view more &rarr;</p>
    <div class="accounts-table-wrap vatx-table-wrap" data-portal-horizontal-scroll-source>
      <table class="vatx-table">
        <thead>
          <tr><th data-sort="purchase_date">Date</th><th data-sort="supplier">Supplier</th><th>Description</th><th class="money">Incl VAT</th><th class="money">Input VAT</th><th class="money">Excl VAT</th><th>Attachment</th><th>Entered By</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody data-rows></tbody>
        <tfoot data-totals></tfoot>
      </table>
    </div>
  </section>
  </div><aside class="vatx-panel vatx-capture-panel" data-monthly-section><h2>Capture progress</h2><div data-active-capture-status></div><p class="vatx-help">Completion records the capture review. Saved purchase values and their audit history remain unchanged.</p></aside></div>
  <section class="accounts-breakdowns vatx-supporting" data-monthly-section>
    <article class="vatx-panel"><h2>VAT treatment</h2><div data-treatment-summary></div></article>
    <article class="vatx-panel"><h2>Suppliers</h2><div data-supplier-summary></div></article>
    <article class="vatx-panel"><h2>Descriptions</h2><div data-description-summary></div></article>
  </section>

  <dialog class="accounts-dialog input-vat-purchase-dialog vatx-dialog vatx-dialog--drawer" data-dialog>
    <form method="dialog" data-form enctype="multipart/form-data">
      <header class="vatx-dialog__header"><div><p class="eyebrow vatx-header__eyebrow">Input VAT</p><h2 class="vatx-dialog__title" data-form-title>Add Purchase</h2></div>
        <button type="button" data-close-purchase aria-label="Close">&times;</button>
      </header>
      <input type="hidden" name="id">
      <div class="vatx-drawer-body vatx-dialog__body"><section class="vatx-form-card vatx-form-section accounts-form-grid vatx-form-grid">
        <h3 class="input-vat-form-section-title span-2">Purchase details</h3>
        <label class="vatx-field">Purchase date<input name="purchase_date" type="date" min="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>" max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" required><small class="field-help">Use the actual invoice or receipt date.</small></label>
        <label class="vatx-field">Supplier<input name="supplier" maxlength="190" required></label>
        <label class="vatx-field">Invoice / Receipt Number<input name="invoice_reference" maxlength="190"></label>
        <label class="vatx-form-grid__full span-2 vatx-field">Description<textarea name="description" required></textarea></label>
      </section><section class="vatx-form-card vatx-form-section accounts-form-grid vatx-form-grid">
        <h3 class="input-vat-form-section-title span-2">VAT calculation</h3>
        <label class="vatx-field">Calculation source<select name="calculation_source" data-portal-custom-select data-portal-select-variant="input-vat"><option value="inclusive">Amount incl VAT</option><option value="exclusive">Amount excl VAT</option></select></label>
        <label class="vatx-field">VAT treatment<select name="vat_treatment" data-portal-custom-select data-portal-select-variant="input-vat" required><option value="standard" data-standard-rate-option>Standard VAT <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</option><option value="zero_rated">Zero Rated 0%</option><option value="no_vat">No VAT / Non-VAT</option><option value="mixed" data-mixed-rate-option>Mixed VAT (<?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>% + 0%)</option><option value="manual_vat">Manual VAT</option><option value="review_required">Review Required</option></select><small class="field-help" data-standard-rate-hint>The configured standard VAT rate is <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%.</small></label>
        <label data-inclusive-source><span data-inclusive-label>Amount incl VAT (N$)</span><input name="inclusive" type="number" min="0" step="0.01"></label>
        <label data-exclusive-source hidden><span data-exclusive-label>Amount excl VAT (N$)</span><input name="exclusive_source" type="number" min="0" step="0.01"></label>
        <div class="vatx-form-grid__full span-2 input-vat-mixed-breakdown" data-mixed-wrap hidden>
          <p class="input-vat-mixed-breakdown__title">Mixed VAT breakdown</p>
          <label class="vatx-field">Zero-rated amount (N$)<input name="zero_rated_amount" type="number" min="0" step="0.01" value="0.00"><small class="field-help" data-mixed-guidance aria-live="polite"></small></label>
          <div class="input-vat-mixed-breakdown__values" aria-live="polite"><span>Standard VAT portion <strong data-mixed-standard>N$ 0.00</strong></span><span>Zero-rated portion <strong data-mixed-zero>N$ 0.00</strong></span></div>
        </div>
        <label class="vatx-form-grid__full span-2 input-vat-override-toggle vatx-field"><input name="manual_override" type="checkbox" value="1"><span>Edit calculated amounts</span><small>Use only when the supplier document differs from the automatic calculation.</small></label>
        <div class="vatx-form-grid__full span-2 input-vat-manual-wrap" data-manual-wrap hidden>
          <label data-manual-vat-wrap>Input VAT (N$)<input name="manual_vat" type="number" min="0" step="0.01"></label>
          <label class="vatx-field">Adjusted excl VAT (N$)<input name="manual_exclusive" type="number" min="0" step="0.01"></label>
          <label class="vatx-form-grid__full span-2 vatx-field">Reason for adjustment<select name="override_reason" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Select reason</option><option>Supplier invoice adjustment</option><option>Mixed VAT treatment</option><option>Rounding correction</option><option>Special accounting treatment</option></select></label>
        </div>
        <div class="vat-preview span-2" data-vat-preview></div>
      </section><section class="vatx-form-card vatx-form-section accounts-form-grid vatx-form-grid">
        <h3 class="input-vat-form-section-title span-2">Receipt &amp; supporting evidence</h3>
        <label class="vatx-form-grid__full span-2 vatx-field">Notes (optional)<textarea name="notes"></textarea></label>
        <div class="input-vat-month-warning" data-month-warning-panel hidden>
          <i data-lucide="calendar-clock" aria-hidden="true"></i>
          <p data-month-warning aria-live="polite"></p>
          <div><button type="button" class="vatx-button portal-button portal-button--ghost" data-month-warning-cancel>Cancel</button><button type="button" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-month-warning-confirm>Save to period</button></div>
        </div>
        <div class="vatx-form-grid__full span-2 input-vat-file-upload">
          <div class="input-vat-file-upload__area" data-upload-area>
            <div class="input-vat-file-upload__content">
              <svg class="input-vat-file-upload__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18h10a4 4 0 0 0 .7-7.94A6 6 0 0 0 6.2 8.4 4.8 4.8 0 0 0 7 18Z"/><path class="input-vat-file-upload__arrow" d="M12 15V8m0 0-3 3m3-3 3 3"/></svg>
              <div class="input-vat-file-upload__text">
                <strong data-upload-instruction>Drag &amp; drop invoice or receipt here</strong><span aria-hidden="true">&middot;</span>
                <span>Images, PDF or supporting documents</span><span aria-hidden="true">&middot;</span>
                <span class="input-vat-file-upload__hint" data-file-upload-hint>No files selected</span>
              </div>
              <button type="button" class="vatx-button portal-button portal-button--secondary vatx-button--secondary input-vat-choose-files" data-choose-files>Choose Files</button>
            </div>
            <input id="inputVatEvidenceFiles" name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
          </div>
        </div>
        <div class="pending-files span-2" data-pending-files></div>
        <div class="vatx-form-grid__full span-2" data-existing-evidence></div>
      </section><section class="vatx-form-card vatx-form-section"><h3>Review &amp; save</h3><p class="vatx-help">Check the purchase date, VAT treatment and evidence. Your changes are saved only after server confirmation.</p></section></div>
      <p class="form-message" data-form-message></p>
      <footer class="vatx-dialog__footer">
        <button type="button" data-close-purchase class="vatx-button portal-button portal-button--secondary vatx-button--secondary">Cancel</button>
        <button type="submit" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-save>Save Purchase</button>
      </footer>
    </form>
  </dialog>

  <?php if(accounts_is_owner()): ?>
  <dialog class="accounts-dialog input-vat-rate-dialog vatx-dialog" data-rate-dialog>
    <form method="dialog" data-rate-form>
      <header class="vatx-dialog__header"><div><p class="eyebrow vatx-header__eyebrow">Input VAT Settings</p><h2>Update Standard VAT Rate</h2></div>
        <button type="button" data-close-rate aria-label="Close">&times;</button>
      </header>
      <div class="input-vat-rate-dialog-body">
        <div class="rate-change-grid">
          <div class="input-vat-rate-card"><span>Current rate</span><strong data-current-rate><?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</strong></div>
          <label class="input-vat-rate-card vatx-field"><span>New rate</span><div class="rate-input-wrap"><input data-rate-setting type="number" min="0" max="100" step="0.01" value="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>"><span>%</span></div></label>
        </div>
        <label class="vatx-field">Historical capture start date<input data-capture-start-setting type="date" max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>"></label>
        <p class="settings-warning">This change applies to <strong>new Standard VAT records only</strong>. Existing saved Input VAT records will not be recalculated.</p>
        <p class="form-message" data-rate-message></p>
      </div>
      <footer class="vatx-dialog__footer">
        <button type="button" data-close-rate class="vatx-button portal-button portal-button--ghost">Cancel</button>
        <button type="submit" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-save-rate>Update Rate</button>
      </footer>
    </form>
  </dialog>
  <?php endif; ?>

  <dialog class="accounts-dialog vatx-dialog" data-duplicate-dialog>
    <form method="dialog"><header class="vatx-dialog__header"><div><p class="eyebrow vatx-header__eyebrow">Duplicate check</p><h2>Possible duplicate purchase</h2></div><button value="cancel" aria-label="Close">&times;</button></header><p>The same date, supplier and amount already exist. Review before saving again.</p><div data-duplicate-matches></div><footer class="vatx-dialog__footer"><button value="cancel" class="vatx-button portal-button portal-button--ghost">Cancel</button><button type="button" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-save-duplicate>Save Anyway</button></footer></form>
  </dialog>

  <dialog class="accounts-dialog vatx-dialog" data-delete-confirm-dialog>
    <form method="dialog"><header class="vatx-dialog__header"><div><p class="eyebrow vatx-header__eyebrow">Input VAT</p><h2>Delete purchase</h2></div><button value="cancel" aria-label="Close">&times;</button></header><div class="vatx-dialog-body"><p data-delete-target></p><p>This removes the entry from active VAT records. Its existing audit history is preserved.</p></div><footer class="vatx-dialog__footer"><button value="cancel" class="vatx-button portal-button portal-button--ghost">Cancel</button><button type="button" class="vatx-button portal-button portal-button--primary vatx-button--primary" data-confirm-delete>Delete Entry</button></footer></form>
  </dialog>

  <dialog class="accounts-dialog vatx-dialog" data-audit-dialog>
    <form method="dialog">
      <header class="vatx-dialog__header"><h2>Audit history</h2>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <div data-audit-history></div>
      <footer class="vatx-dialog__footer">
        <button value="cancel" class="vatx-button portal-button portal-button--ghost">Close</button>
      </footer>
    </form>
  </dialog>
</main>
<script defer src="<?=BASE_URL?>/assets/js/accounts-vat-workspace.js?v=<?=filemtime(BASE_PATH.'/assets/js/accounts-vat-workspace.js')?>"></script>
<script defer src="<?=BASE_URL?>/assets/js/input-vat.js?v=<?=filemtime(BASE_PATH.'/assets/js/input-vat.js')?>"></script>
<?php include BASE_PATH.'/shared/footer.php'; ?>
