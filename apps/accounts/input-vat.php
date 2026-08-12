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

<main class="workspace module accounts-page" id="inputVatPage" data-api="input-vat-api.php" data-csrf="<?=htmlspecialchars(accounts_csrf_token(), ENT_QUOTES, 'UTF-8')?>" data-owner="<?=accounts_is_owner() ? '1' : '0'?>" data-rate="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>">
  <?php if(accounts_is_owner()): ?>
  <nav class="accounts-breadcrumb" aria-label="Breadcrumb"><a href="index.php">Accounts</a><span aria-hidden="true">&rsaquo;</span><strong>Input VAT</strong></nav>
  <?php endif; ?>

  <header class="accounts-hero">
    <div>
      <p class="eyebrow">Accounts application</p>
      <h1>Input VAT</h1>
      <p>Record local VAT purchases and calculate Input VAT for the selected period.</p>
      <p class="input-vat-active-period" data-active-period><span>Active period</span> <strong data-active-period-label><?=htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8')?></strong></p>
    </div>
    <div class="accounts-actions">
      <button class="btn-primary iv-btn iv-btn--primary" type="button" data-add-purchase><span class="iv-btn-icon" aria-hidden="true">+</span> Add Purchase</button>
      <button type="button" class="btn-secondary iv-btn iv-btn--secondary" data-print><span class="iv-btn-icon" aria-hidden="true">&#128438;</span> Print</button>
      <a class="btn-secondary iv-btn iv-btn--tertiary" data-export href="input-vat-api.php?action=export"><span class="iv-btn-icon" aria-hidden="true">&#8595;</span> Export CSV</a>
    </div>
  </header>

  <section class="accounts-toolbar" aria-label="Input VAT working filters">
    <div class="accounts-filter-group accounts-period-group">
      <span class="accounts-control-label">PERIOD</span>
      <div class="accounts-period-controls">
        <button type="button" class="btn-secondary iv-btn iv-btn--secondary accounts-month-step" data-previous-month aria-label="Previous month"><span class="iv-btn-icon" aria-hidden="true">&#8592;</span><span>Previous</span></button>
        <label><span>PERIOD RANGE</span><select data-period><option value="current">Selected Month</option><?php if(accounts_is_owner()): ?><option value="all">All Periods</option><?php endif; ?></select></label>
        <label><span>MONTH</span><input type="month" data-month value="<?=date('Y-m')?>"></label>
        <button type="button" class="btn-secondary iv-btn iv-btn--secondary accounts-month-step" data-next-month aria-label="Next month"><span>Next</span><span class="iv-btn-icon" aria-hidden="true">&#8594;</span></button>
      </div>
    </div>
    <label class="accounts-search-control"><span>SEARCH</span><input type="search" data-search placeholder="Search supplier or description"></label>
    <label class="accounts-status-control"><span>STATUS</span><select data-status><option value="">All statuses</option><option value="captured">Captured</option><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option></select></label>
  </section>

  <?php if(accounts_is_owner()): ?>
  <section class="input-vat-settings-card" aria-labelledby="vatSettingsTitle">
    <div class="input-vat-settings-copy">
      <span class="input-vat-settings-icon" aria-hidden="true">⚙️</span>
      <div>
        <p class="eyebrow">Configuration</p>
        <h2 id="vatSettingsTitle">Input VAT Settings</h2>
        <p>Update once and apply to new Standard VAT records only.</p>
      </div>
    </div>
    <div class="input-vat-settings-value">
      <span>Standard VAT Rate</span><strong data-rate-display><?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</strong><button type="button" class="btn-secondary iv-btn iv-btn--secondary" data-open-rate-settings>Input VAT Settings</button>
    </div>
  </section>
  <?php else: ?>
  <div class="input-vat-rate-indicator" role="note">
    <span>Standard VAT Rate</span><strong data-rate-display><?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</strong>
  </div>
  <?php endif; ?>

  <section class="accounts-summary" data-summary aria-live="polite"></section>
  <section class="accounts-breakdowns">
    <article><h2>VAT treatment</h2><div data-treatment-summary></div></article>
    <article><h2>Suppliers</h2><div data-supplier-summary></div></article>
    <article><h2>Descriptions</h2><div data-description-summary></div></article>
  </section>
  <section class="accounts-table-card">
    <p class="accounts-scroll-hint">Swipe to view more &rarr;</p>
    <div class="accounts-table-wrap" data-portal-horizontal-scroll-source>
      <table>
        <thead>
          <tr><th data-sort="purchase_date">Date</th><th data-sort="supplier">Supplier</th><th>Description</th><th>Incl VAT</th><th>Input VAT</th><th>Excl VAT</th><th>Attachment</th><th>Entered By</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody data-rows></tbody>
        <tfoot data-totals></tfoot>
      </table>
    </div>
  </section>

  <dialog class="accounts-dialog" data-dialog>
    <form method="dialog" data-form enctype="multipart/form-data">
      <header>
        <div><p class="eyebrow">Input VAT</p><h2 data-form-title>Add Purchase</h2></div>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <input type="hidden" name="id">
      <div class="accounts-form-grid">
        <label>Date<input name="purchase_date" type="date" required></label>
        <label>Supplier<input name="supplier" maxlength="190" required></label>
        <label class="span-2">Description<textarea name="description" required></textarea></label>
        <label>Amount incl VAT (N$)<input name="inclusive" type="number" min="0" step="0.01" required></label>
        <label>VAT treatment<select name="vat_treatment" required><option value="standard" data-standard-rate-option>Standard VAT <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</option><option value="zero_rated">Zero Rated</option><option value="no_vat">No VAT / Non-VAT</option><option value="manual_vat">Manual VAT</option><option value="review_required">Review Required</option></select><small class="field-help" data-standard-rate-hint>The configured standard VAT rate is <?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%.</small></label>
        <label data-manual-wrap hidden>Manual VAT (N$)<input name="manual_vat" type="number" min="0" step="0.01"></label>
        <div class="vat-preview span-2" data-vat-preview></div>
        <p class="field-help" data-month-warning aria-live="polite"></p>
        <label class="span-2">Evidence files<input name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"></label>
        <div class="pending-files span-2" data-pending-files></div>
      </div>
      <p class="form-message" data-form-message></p>
      <footer>
        <button value="cancel" class="btn-secondary iv-btn iv-btn--secondary">Cancel</button>
        <button type="submit" class="btn-primary iv-btn iv-btn--primary" data-save>Save Purchase</button>
      </footer>
    </form>
  </dialog>

  <?php if(accounts_is_owner()): ?>
  <dialog class="accounts-dialog input-vat-rate-dialog" data-rate-dialog>
    <form method="dialog" data-rate-form>
      <header>
        <div><p class="eyebrow">Input VAT Settings</p><h2>Update Standard VAT Rate?</h2></div>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <div class="rate-change-grid">
        <div><span>Current</span><strong data-current-rate><?=htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8')?>%</strong></div>
        <label>New rate<div class="rate-input-wrap"><input data-rate-setting type="number" min="0" max="100" step="0.01" value="<?=htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8')?>"><span>%</span></div></label>
      </div>
      <p class="settings-warning">This change applies to <strong>new Standard VAT records only</strong>. Existing saved Input VAT records will not be recalculated.</p>
      <p class="form-message" data-rate-message></p>
      <footer>
        <button value="cancel" class="btn-secondary iv-btn iv-btn--secondary">Cancel</button>
        <button type="submit" class="btn-primary iv-btn iv-btn--primary" data-save-rate>Update Rate</button>
      </footer>
    </form>
  </dialog>
  <?php endif; ?>

  <dialog class="accounts-dialog accounts-review-dialog" data-review-dialog>
    <form method="dialog" data-review-form>
      <header>
        <h2>Review purchase</h2>
        <button value="cancel" aria-label="Close">&times;</button>
      </header>
      <input type="hidden" name="id">
      <label>Status<select name="review_status"><option value="reviewed">Reviewed</option><option value="needs_correction">Needs Correction</option><option value="captured">Captured</option></select></label>
      <label>Reason / note<textarea name="review_note"></textarea></label>
      <p data-review-message></p>
      <footer>
        <button value="cancel" class="btn-secondary iv-btn iv-btn--secondary">Cancel</button>
        <button type="submit" class="btn-primary iv-btn iv-btn--primary">Save Review</button>
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
        <button value="cancel" class="btn-secondary iv-btn iv-btn--secondary">Close</button>
      </footer>
    </form>
  </dialog>
</main>
<script defer src="<?=BASE_URL?>/assets/js/input-vat.js?v=<?=filemtime(BASE_PATH.'/assets/js/input-vat.js')?>"></script>
<?php include BASE_PATH.'/shared/footer.php'; ?>

