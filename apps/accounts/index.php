<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/employee-features.php';
require_once BASE_PATH . '/shared/accounts-input-vat.php';
accounts_require_workspace_access();
$pageTitle = 'Accounts | ' . APP_NAME;
$activeApp = 'accounts';
$extraStylesheets = [['path' => 'assets/css/accounts.css', 'version' => (string) filemtime(BASE_PATH . '/assets/css/accounts.css')], ['path' => 'assets/css/accounts-hierarchy.css', 'version' => (string) filemtime(BASE_PATH . '/assets/css/accounts-hierarchy.css')]];
include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module accounts-page accounts-workspace">
  <header class="accounts-hero"><div><p class="eyebrow">Finance workspace</p><h1>Accounts</h1><p>Accounting and financial administration applications.</p></div></header>
  <section class="accounts-app-section" aria-labelledby="accounting-apps-title">
    <header><div><p class="eyebrow">Applications</p><h2 id="accounting-apps-title">Accounting Apps</h2></div></header>
    <div class="accounts-app-grid">
      <a class="accounts-app-card" href="input-vat.php"><span class="accounts-app-icon" aria-hidden="true"><i data-lucide="receipt-text"></i></span><div><small class="accounts-app-status is-available">Available</small><h3>Input VAT</h3><p>Record local VAT purchases, supporting invoices and monthly Input VAT.</p><strong>Open Input VAT <span aria-hidden="true">&rarr;</span></strong></div></a>
      <a class="accounts-app-card" href="output-vat.php"><span class="accounts-app-icon" aria-hidden="true"><i data-lucide="badge-dollar-sign"></i></span><div><small class="accounts-app-status is-available">Available · Owner only</small><h3>Output VAT</h3><p>Automatically reconcile WooCommerce sales and calculate Output VAT by tax period.</p><strong>Open Output VAT <span aria-hidden="true">&rarr;</span></strong></div></a>
      <?php foreach ([
        ['Import VAT', 'Import VAT documents and customs tax records.', 'ship'],
        ['Expenses', 'Expense capture and cost administration.', 'wallet-cards'],
        ['Supplier Statements', 'Supplier balances and statement review.', 'file-text'],
        ['Asset Register', 'Business assets and depreciation records.', 'boxes'],
        ['Reconciliations', 'Financial reconciliation workspaces.', 'scale'],
        ['VAT Return Preparation', 'VAT return checks and preparation.', 'clipboard-check'],
      ] as [$plannedName, $plannedDescription, $plannedIcon]): ?>
        <article class="accounts-app-card is-coming-soon" aria-disabled="true"><span class="accounts-app-icon" aria-hidden="true"><i data-lucide="<?=htmlspecialchars($plannedIcon, ENT_QUOTES, 'UTF-8')?>"></i></span><div><small class="accounts-app-status">Coming Soon</small><h3><?=htmlspecialchars($plannedName, ENT_QUOTES, 'UTF-8')?></h3><p><?=htmlspecialchars($plannedDescription, ENT_QUOTES, 'UTF-8')?></p><span class="accounts-coming-soon-control">Coming Soon</span></div></article>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
