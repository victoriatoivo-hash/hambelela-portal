<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/employee-features.php';
require_once BASE_PATH . '/shared/accounts-input-vat.php';
accounts_require_access();
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
      <a class="accounts-app-card" href="input-vat.php"><span class="accounts-app-icon" aria-hidden="true"><i data-lucide="receipt-text"></i></span><div><small>VAT</small><h3>Input VAT</h3><p>Record local VAT purchases, supporting invoices and monthly Input VAT.</p><strong>Open Input VAT <span aria-hidden="true">&rarr;</span></strong></div></a>
    </div>
    <p class="accounts-future-note">More accounting applications can be added here.</p>
  </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
