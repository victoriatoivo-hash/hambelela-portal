<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/employee-features.php';
require_once BASE_PATH.'/shared/accounts-input-vat.php';

accounts_require_input_vat_access();
accounts_input_vat_schema_ready();

$rate = accounts_standard_vat_rate();
$rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
$pageTitle = 'Input VAT | '.APP_NAME;
$activeApp = 'accounts';
$extraStylesheets = [
    ['path' => 'assets/css/accounts.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/accounts.css')],
    ['path' => 'assets/css/accounts-hierarchy.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/accounts-hierarchy.css')],
];
include BASE_PATH.'/shared/header.php';
include BASE_PATH.'/shared/sidebar.php';
?>

<main class="workspace module accounts-page" id="inputVatPage" data-api="input-vat-api.php" data-csrf="<?=htmlspecialchars(accounts_csrf_token(), ENT_QUOTES, 'UTF-8')?>" data-owner="<?=accounts_is_owner() ? '1' : '0'?>" data-rate="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>" data-capture-start="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>" data-business-today="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" data-business-timezone="Africa/Windhoek">
  <?php if(accounts_is_owner()): ?>
  <nav class="accounts-breadcrumb" aria-label="Breadcrumb"><a href="index.php">Accounts</a><span aria-hidden="true">&rsaquo;</span><strong>Input VAT</strong></nav>
  <?php endif; ?>

  <header class="accounts-hero">
    <div class="accounts-hero-copy">
      <p class="eyebrow">Accounts application</p>
      <h1>Input VAT</h1>
      <p>Record local VAT purchases and calculate Input VAT for the selected period.</p>
      <p class="input-vat-active-period" data-active-period><i data-lucide="calendar-days" aria-hidden="true"></i><span class="input-vat-active-period-label" data-active-period-kicker>ACTIVE PERIOD</span><strong data-active-period-value><?=htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8')?></strong></p>
    </div>
    <div class="accounts-actions" data-portal-header-status-target>
      <button class="portal-button portal-button--primary" type="button" data-add-purchase><span class="portal-button__icon" aria-hidden="true">+</span> Add Purchase</button>
      <?php if(accounts_is_owner()): ?><button type="button" class="portal-button portal-button--ghost input-vat-settings-action" data-open-rate-settings><i data-lucide="settings" aria-hidden="true"></i><span>Settings</span></button><?php endif; ?>
    </div>
  </header>

  <section class="accounts-summary" data-summary aria-live="polite" data-monthly-section></section>

  <nav class="input-vat-primary-tabs portal-theme-tabs" role="tablist" aria-label="Input VAT views">
    <button type="button" class="portal-theme-tab is-active" role="tab" aria-selected="true" data-vat-view="monthly"><i data-lucide="calendar-range" aria-hidden="true"></i><span>Monthly Input VAT</span></button>
    <?php if(accounts_is_owner()): ?><button type="button" class="portal-theme-tab" role="tab" aria-selected="false" data-vat-view="history"><i data-lucide="history" aria-hidden="true"></i><span>Transaction History</span></button><?php endif; ?>
  </nav>

  <section class="input-vat-month-workspace" data-month-workspace>
    <div class="input-vat-month-nav">
      <button type="button" class="input-vat-month-arrow" data-previous-month aria-label="Previous month"><i data-lucide="chevron-left" aria-hidden="true"></i></button>
      <label class="input-vat-year-select"><span>Year</span><select data-month-year data-portal-custom-select data-portal-select-variant="input-vat" aria-label="Input VAT year"></select></label>
      <nav class="input-vat-month-tabs" aria-label="Input VAT months" data-month-tabs></nav>
      <button type="button" class="input-vat-month-arrow" data-next-month aria-label="Next month"><i data-lucide="chevron-right" aria-hidden="true"></i></button>
    </div>
    <div class="input-vat-month-status" data-active-capture-status></div>
  </section>

  <section class="accounts-toolbar" aria-label="Input VAT working filters" data-monthly-toolbar>
    <input type="hidden" data-month value="<?=date('Y-m')?>">
    <label class="accounts-search-control"><span>SEARCH</span><input type="search" data-search placeholder="Search supplier, description or reference"></label>
    <label class="accounts-status-control"><span>STATUS</span><select data-status data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All statuses</option><option value="captured">Captured</option><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option></select></label>
  </section>

  <?php if(accounts_is_owner()): ?>
  <section class="accounts-toolbar input-vat-history-toolbar" data-history-toolbar hidden aria-label="Transaction History filters">
    <label><span>MONTH</span><input type="month" data-history-month min="<?=htmlspecialchars(substr(accounts_historical_capture_start_date(),0,7), ENT_QUOTES, 'UTF-8')?>" max="<?=htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8')?>"></label>
    <label><span>FROM</span><input type="date" data-history-from min="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>"></label>
    <label><span>TO</span><input type="date" data-history-to max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>"></label>
    <label class="accounts-search-control"><span>SEARCH</span><input type="search" data-history-search placeholder="Supplier, description or reference"></label>
    <label><span>ENTERED BY</span><input type="search" data-history-entered-by placeholder="Employee name"></label>
    <label><span>STATUS</span><select data-history-status data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All statuses</option><option value="captured">Captured</option><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option></select></label>
    <label><span>ADJUSTMENT</span><select data-history-manual data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All records</option><option value="1">Manual adjustments</option><option value="0">Automatic calculations</option></select></label>
  </section>
  <?php endif; ?>

  <?php if(accounts_is_owner()): ?>
  <aside class="input-vat-period-notice" data-period-notice role="status" aria-live="polite" hidden>
    <div><strong data-period-notice-title></strong><span data-period-notice-copy></span></div>
    <button type="button" class="portal-button portal-button--secondary" data-view-saved-month>View period</button>
  </aside>
  <?php endif; ?>

  <section class="accounts-breakdowns" data-monthly-section>
    <article><h2>VAT treatment</h2><div data-treatment-summary></div></article>
    <article><h2>Suppliers</h2><div data-supplier-summary></div></article>
    <article><h2>Descriptions</h2><div data-description-summary></div></article>
  </section>
  <section class="accounts-table-card">
    <p class="accounts-scroll-hint">Swipe to view more &rarr;</p>
    <div class="accounts-table-wrap" data-portal-horizontal-scroll-source>
      <table>
        <thead>
          <tr><th data-sort="purchase_date">Date</th><th data-sort="supplier">Supplier</th><th>Description</th><th class="money">Incl VAT</th><th class="money">Input VAT</th><th class="money">Excl VAT</th><th>Attachment</th><th>Entered By</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody data-rows></tbody>
        <tfoot data-totals></tfoot>
      </table>
    </div>
  </section>

  <dialog class="accounts-dialog input-vat-purchase-dialog" data-dialog>
    <form method="dialog" data-form enctype="multipart/form-data">
      <header>
        <div><p class="eyebrow">Input VAT</p><h2 data-form-title>Add Purchase</h2></div>
        <button type="button" data-close-purchase aria-label="Close">&times;</button>
      </header>
      <input type="hidden" name="id">
      <div class="accounts-form-grid">
        <h3 class="input-vat-form-section-title span-2">Purchase details</h3>
        <label>Purchase date<input name="purchase_date" type="date" min="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>" max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" required><small class="field-help">Use the actual invoice or receipt date.</small></label>
        <label>Supplier<input name="supplier" maxlength="190" required></label>
        <label>Invoice / Receipt Number<input name="invoice_reference" maxlength="190"></label>
        <label class="span-2">Description<textarea name="description" required></textarea></label>
        <h3 class="input-vat-form-section-title span-2">VAT calculation</h3>
        <label>Calculation source<select name="calculation_source" data-portal-custom-select data-portal-select-variant="input-vat"><option value="inclusive">Amount incl VAT</option><option value="exclusive">Amount excl VAT</option></select></label>
        <label>VAT treatment<select name="vat_treatment" data-portal-custom-select data-portal-select-variant="input-vat" required><option value="standard" data-standard-rate-option>Standard VAT <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</option><option value="zero_rated">Zero Rated</option><option value="no_vat">No VAT / Non-VAT</option><option value="manual_vat">Manual VAT</option><option value="review_required">Review Required</option></select><small class="field-help" data-standard-rate-hint>The configured standard VAT rate is <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%.</small></label>
        <label data-inclusive-source>Amount incl VAT (N$)<input name="inclusive" type="number" min="0" step="0.01"></label>
        <label data-exclusive-source hidden>Amount excl VAT (N$)<input name="exclusive_source" type="number" min="0" step="0.01"></label>
        <label class="span-2 input-vat-override-toggle"><input name="manual_override" type="checkbox" value="1"><span>Edit calculated amounts</span><small>Use only when the supplier document differs from the automatic calculation.</small></label>
        <div class="span-2 input-vat-manual-wrap" data-manual-wrap hidden>
          <label data-manual-vat-wrap>Input VAT (N$)<input name="manual_vat" type="number" min="0" step="0.01"></label>
          <label>Adjusted excl VAT (N$)<input name="manual_exclusive" type="number" min="0" step="0.01"></label>
          <label class="span-2">Reason for adjustment<select name="override_reason" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Select reason</option><option>Supplier invoice adjustment</option><option>Mixed VAT treatment</option><option>Rounding correction</option><option>Special accounting treatment</option></select></label>
        </div>
        <div class="vat-preview span-2" data-vat-preview></div>
        <h3 class="input-vat-form-section-title span-2">Notes &amp; evidence</h3>
        <label class="span-2">Notes (optional)<textarea name="notes"></textarea></label>
        <div class="input-vat-month-warning" data-month-warning-panel hidden>
          <i data-lucide="calendar-clock" aria-hidden="true"></i>
          <p data-month-warning aria-live="polite"></p>
          <div><button type="button" class="portal-button portal-button--ghost" data-month-warning-cancel>Cancel</button><button type="button" class="portal-button portal-button--primary" data-month-warning-confirm>Save to period</button></div>
        </div>
        <div class="span-2 input-vat-file-upload">
          <div class="input-vat-file-upload__area" data-upload-area>
            <div class="input-vat-file-upload__content">
              <svg class="input-vat-file-upload__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18h10a4 4 0 0 0 .7-7.94A6 6 0 0 0 6.2 8.4 4.8 4.8 0 0 0 7 18Z"/><path class="input-vat-file-upload__arrow" d="M12 15V8m0 0-3 3m3-3 3 3"/></svg>
              <div class="input-vat-file-upload__text">
                <strong data-upload-instruction>Drag &amp; drop invoice or receipt here</strong><span aria-hidden="true">&middot;</span>
                <span>PDF, JPG or PNG</span><span aria-hidden="true">&middot;</span>
                <span class="input-vat-file-upload__hint" data-file-upload-hint>No files selected</span>
              </div>
              <button type="button" class="portal-button portal-button--secondary input-vat-choose-files" data-choose-files>Choose Files</button>
            </div>
            <input id="inputVatEvidenceFiles" name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
          </div>
        </div>
        <div class="pending-files span-2" data-pending-files></div>
      </div>
      <p class="form-message" data-form-message></p>
      <footer>
        <button type="button" data-close-purchase class="portal-button portal-button--secondary">Cancel</button>
        <button type="submit" class="portal-button portal-button--primary" data-save>Save Purchase</button>
      </footer>
    </form>
  </dialog>

  <?php if(accounts_is_owner()): ?>
  <dialog class="accounts-dialog input-vat-rate-dialog" data-rate-dialog>
    <form method="dialog" data-rate-form>
      <header>
        <div><p class="eyebrow">Input VAT Settings</p><h2>Update Standard VAT Rate</h2></div>
        <button type="button" data-close-rate aria-label="Close">&times;</button>
      </header>
      <div class="input-vat-rate-dialog-body">
        <div class="rate-change-grid">
          <div class="input-vat-rate-card"><span>Current rate</span><strong data-current-rate><?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</strong></div>
          <label class="input-vat-rate-card"><span>New rate</span><div class="rate-input-wrap"><input data-rate-setting type="number" min="0" max="100" step="0.01" value="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>"><span>%</span></div></label>
        </div>
        <label>Historical capture start date<input data-capture-start-setting type="date" max="<?=htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars(accounts_historical_capture_start_date(), ENT_QUOTES, 'UTF-8')?>"></label>
        <p class="settings-warning">This change applies to <strong>new Standard VAT records only</strong>. Existing saved Input VAT records will not be recalculated.</p>
        <p class="form-message" data-rate-message></p>
      </div>
      <footer>
        <button type="button" data-close-rate class="portal-button portal-button--ghost">Cancel</button>
        <button type="submit" class="portal-button portal-button--primary" data-save-rate>Update Rate</button>
      </footer>
    </form>
  </dialog>
  <?php endif; ?>

  <dialog class="accounts-dialog" data-duplicate-dialog>
    <form method="dialog"><header><div><p class="eyebrow">Duplicate check</p><h2>Possible duplicate purchase</h2></div><button value="cancel" aria-label="Close">&times;</button></header><p>The same date, supplier and amount already exist. Review before saving again.</p><div data-duplicate-matches></div><footer><button value="cancel" class="portal-button portal-button--ghost">Cancel</button><button type="button" class="portal-button portal-button--primary" data-save-duplicate>Save Anyway</button></footer></form>
  </dialog>

  <dialog class="accounts-dialog accounts-review-dialog" data-review-dialog>
    <form method="dialog" data-review-form>
      <header>
        <h2>Review purchase</h2>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <input type="hidden" name="id">
      <label>Status<select name="review_status" data-portal-custom-select data-portal-select-variant="input-vat"><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option><option value="captured">Captured</option></select></label>
      <label>Reason / note<textarea name="review_note"></textarea></label>
      <p data-review-message></p>
      <footer>
        <button value="cancel" class="portal-button portal-button--ghost">Cancel</button>
        <button type="submit" class="portal-button portal-button--primary">Save Review</button>
      </footer>
    </form>
  </dialog>

  <dialog class="accounts-dialog" data-audit-dialog>
    <form method="dialog">
      <header>
        <h2>Audit history</h2>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <div data-audit-history></div>
      <footer>
        <button value="cancel" class="portal-button portal-button--ghost">Close</button>
      </footer>
    </form>
  </dialog>
</main>
<script defer src="<?=BASE_URL?>/assets/js/input-vat.js?v=<?=filemtime(BASE_PATH.'/assets/js/input-vat.js')?>"></script>
<?php include BASE_PATH.'/shared/footer.php'; ?>
