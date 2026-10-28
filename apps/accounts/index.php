<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/employee-features.php';
require_once BASE_PATH . '/shared/accounts-input-vat.php';
require_once BASE_PATH . '/shared/accounts-amendments.php';
accounts_require_workspace_access();
$isAccountant = accounts_is_accountant();
$pageTitle = ($isAccountant ? 'Finance Workspace' : 'Accounts') . ' | ' . APP_NAME;
$activeApp = 'accounts';
$extraStylesheets = [['path'=>'assets/css/accounts-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/accounts-dashboard.css')]];
$unread=0; try{$unread=amendments_unread_count();}catch(Throwable $e){error_log($e->getMessage());}
// Static deployment contract retained for the canonical Accounts card: href="import-vat.php"
$apps=[
 ['input-vat.php','receipt-text','input-vat','Input VAT','Record local VAT purchases, supporting invoices and monthly Input VAT.'],
 ['output-vat.php','chart-no-axes-combined','output-vat','Output VAT','Reconcile WooCommerce sales and calculate Output VAT by tax period.'],
 ['import-vat.php','ship','import-vat','Import VAT','Track imported goods, NamRA Import VAT, due dates and payments.'],
 ['paye.php','landmark','paye','PAYE','Upload NamRA Employee Tax statements and reconcile assessed, paid and outstanding PAYE.'],
 ['vat-reconciliation.php','scale','vat-reconciliation','VAT Reconciliation','Combine Output, Input and reviewed Import VAT into a return position.'],
 ['amendments.php','message-square-text','amendments','Amendments',$isAccountant?'Send me any accounting amendments or corrections you need me to make.':'Discuss accounting corrections with a complete shared audit trail.'],
];
if(accounts_is_owner()){$apps[]=['sage-reconciliation.php','book-open-check','sage','Sage Posting & Reconciliation','Prepare Sage postings and reconcile receipts without double-counting.'];$apps[]=['asset-register.php','boxes','asset-register','Asset Register','Record, assign, maintain and audit business assets.'];}
if(portal_user_can_access_feature('payment_reconciliation'))$apps[]=['payment-reconciliation.php','badge-check','payments','Payment Reconciliation','Verify customer payments against Orders, Bookkeeping, bank transactions and driver collections.'];
$features = [
 'input-vat'=>[['file-text','Invoices'],['receipt-text','VAT tracking'],['chart-column','Monthly reports']],
 'output-vat'=>[['shopping-cart','Sales reconciliation'],['file-text','VAT calculations'],['chart-column','Tax reports']],
 'import-vat'=>[['package','Import tracking'],['calendar-days','Due dates'],['credit-card','Payments']],
 'paye'=>[['users','Employee tax'],['file-text','Reconciliation'],['credit-card','Payment tracking']],
 'vat-reconciliation'=>[['link','VAT matching'],['file-text','Return preparation'],['shield-check','Audit trail']],
 'amendments'=>[['message-square','Correction requests'],['file-text','Audit trail'],['clock','Status tracking']],
 'sage'=>[['link','Receipt matching'],['database','Sage integration'],['chart-column','Reconciliation']],
 'asset-register'=>[['package','Asset tracking'],['user-round','Assignment'],['shield-check','Audit history']],
 'payments'=>[['badge-check','Payment evidence'],['receipt-text','Order matching'],['shield-check','Audit trail']],
];
$blueThemes = ['output-vat','import-vat','amendments','sage'];
include BASE_PATH.'/shared/header.php'; include BASE_PATH.'/shared/sidebar.php';
?>
<main id="accounts-landing" class="workspace module accounts-landing" data-accounts-landing>
 <header class="accounts-hero">
  <div class="accounts-hero__copy"><p class="accounts-eyebrow">Finance workspace</p><h1><?= $isAccountant?'Finance Workspace':'Accounts' ?></h1><p>Accounting and financial administration applications.</p></div>
  <label class="accounts-search"><i data-lucide="search" aria-hidden="true"></i><span class="accounts-sr-only">Search accounting apps</span><input type="search" data-accounts-search placeholder="Search accounting apps…" autocomplete="off" aria-controls="accounts-apps"></label>
 </header>
 <section class="accounts-app-section" aria-labelledby="accounting-apps-title">
  <header class="accounts-section-header"><h2 id="accounting-apps-title">Accounting applications</h2><div class="accounts-view-switch" role="group" aria-label="Application view"><button type="button" data-accounts-view="cards" aria-pressed="true" aria-controls="accounts-apps"><i data-lucide="layout-grid" aria-hidden="true"></i>Card view</button><button type="button" data-accounts-view="list" aria-pressed="false" aria-controls="accounts-apps"><i data-lucide="list" aria-hidden="true"></i>List view</button></div></header>
  <p class="accounts-sr-only" data-accounts-result-count role="status" aria-live="polite"><?=count($apps)?> applications</p>
  <div id="accounts-apps" class="accounts-app-grid" data-accounts-grid>
  <?php foreach($apps as [$href,$icon,$theme,$name,$description]): ?>
   <article class="accounts-app-card<?=in_array($theme,$blueThemes,true)?' accounts-app-card--blue':''?>" data-accounts-app>
    <span class="accounts-app-card__icon" aria-hidden="true"><i data-lucide="<?=htmlspecialchars($icon)?>"></i></span>
    <div class="accounts-app-card__copy"><div class="accounts-app-card__badges"><span class="accounts-app-status">Available</span><?php if($theme==='amendments'&&$unread):?><span class="accounts-app-status"><?=$unread?> unread</span><?php endif;?></div><h3 class="accounts-app-card__title"><?=htmlspecialchars($name)?></h3><p class="accounts-app-card__description"><?=htmlspecialchars($description)?></p></div>
    <span class="accounts-app-card__visual" aria-hidden="true"><i></i><i></i><i></i></span>
    <div class="accounts-app-card__footer"><a class="accounts-app-card__button" href="<?=htmlspecialchars($href)?>">Open <?=htmlspecialchars($theme==='sage'?'Sage Posting':$name)?> <span aria-hidden="true">&rarr;</span></a><div class="accounts-app-card__features"><span class="accounts-app-card__feature-label">Key features:</span><ul><?php foreach($features[$theme] as [$featureIcon,$feature]):?><li><i data-lucide="<?=htmlspecialchars($featureIcon)?>" aria-hidden="true"></i><span><?=htmlspecialchars($feature)?></span></li><?php endforeach;?></ul></div></div>
   </article>
  <?php endforeach; ?>
  </div>
  <p class="accounts-empty" data-accounts-empty hidden>No accounting apps match your search. Try an application name or feature.</p>
 </section>
</main>
<script defer src="<?=BASE_URL?>/assets/js/accounts-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/accounts-dashboard.js')?>"></script>
<?php include BASE_PATH.'/shared/footer.php'; ?>
