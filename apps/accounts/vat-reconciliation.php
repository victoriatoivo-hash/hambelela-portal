<?php
declare(strict_types=1);require_once dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/accounts-vat-reconciliation.php';vat_reconciliation_require_owner();vat_reconciliation_schema_ready();$pageTitle='VAT Reconciliation | '.APP_NAME;$activeApp='accounts';$extraStylesheets=[['path'=>'assets/css/accounts.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/accounts.css')]];include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?>
<main class="workspace module accounts-page vat-reconciliation-page" id="vatReconciliationApp" data-api="vat-reconciliation-api.php">
 <header class="accounts-hero"><div><p class="eyebrow">Accounts application · Owner only</p><h1>VAT Reconciliation</h1><p>Combine final Output VAT, local Input VAT and reviewed Import VAT credits into one auditable return position.</p></div><nav class="vr-source-links" aria-label="VAT sources"><a href="output-vat.php">Output VAT</a><a href="input-vat.php">Input VAT</a><a href="import-vat.php">Import VAT</a></nav></header>
 <section class="vr-period-bar"><button type="button" data-period-step="-2" aria-label="Previous VAT period">←</button><label>Active VAT period<select id="vrPeriod"></select></label><button type="button" data-period-step="2" aria-label="Next VAT period">→</button><span id="vrDue"></span><button type="button" class="accounts-button is-secondary" id="vrRefresh">Refresh sources</button></section>
 <div id="vrNotice" class="vr-notice" role="status" hidden></div><section id="vrContent" aria-live="polite"><p class="accounts-empty">Loading saved VAT sources…</p></section>
</main>
<script defer src="<?=BASE_URL?>/assets/js/vat-reconciliation.js?v=<?=filemtime(BASE_PATH.'/assets/js/vat-reconciliation.js')?>"></script>
<?php include BASE_PATH.'/shared/footer.php'; ?>
