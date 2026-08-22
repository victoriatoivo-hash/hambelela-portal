<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/employee-features.php';
require_once BASE_PATH . '/shared/accounts-input-vat.php';
require_once BASE_PATH . '/shared/accounts-amendments.php';
accounts_require_workspace_access();
$pageTitle = (accounts_is_accountant() ? 'Finance Workspace' : 'Accounts') . ' | ' . APP_NAME;
$activeApp = 'accounts';
$extraStylesheets = [['path'=>'assets/css/accounts.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/accounts.css')],['path'=>'assets/css/accounts-hierarchy.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/accounts-hierarchy.css')],['path'=>'assets/css/accounts-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/accounts-dashboard.css')]];
$unread=0; try{$unread=amendments_unread_count();}catch(Throwable $e){error_log($e->getMessage());}
// Static deployment contract retained for the canonical Accounts card: href="import-vat.php"
$apps=[
 ['input-vat.php','receipt-text','input-vat','Input VAT','Record local VAT purchases, supporting invoices and monthly Input VAT.'],
 ['output-vat.php','chart-no-axes-combined','output-vat','Output VAT','Reconcile WooCommerce sales and calculate Output VAT by tax period.'],
 ['import-vat.php','ship','import-vat','Import VAT','Track imported goods, NamRA Import VAT, due dates and payments.'],
 ['vat-reconciliation.php','scale','vat-reconciliation','VAT Reconciliation','Combine Output, Input and reviewed Import VAT into a return position.'],
 ['amendments.php','message-square-text','amendments','Amendments','Discuss accounting corrections with a complete shared audit trail.'],
];
if(accounts_is_owner()){$apps[]=['sage-reconciliation.php','book-open-check','sage','Sage Posting & Reconciliation','Prepare Sage postings and reconcile receipts without double-counting.'];$apps[]=['asset-register.php','boxes','asset-register','Asset Register','Record, assign, maintain and audit business assets.'];}
include BASE_PATH.'/shared/header.php'; include BASE_PATH.'/shared/sidebar.php';
?>
<main class="workspace module accounts-page accounts-workspace">
 <header class="accounts-hero"><div><p class="eyebrow">Finance workspace</p><h1><?= accounts_is_accountant()?'Finance Workspace':'Accounts' ?></h1><p><?= accounts_is_accountant()?'VAT accounting, reconciliation and amendment requests.':'Accounting and financial administration applications.' ?></p></div></header>
 <section class="accounts-app-section" aria-labelledby="accounting-apps-title"><header><div><p class="eyebrow">Applications</p><h2 id="accounting-apps-title">Accounting Apps</h2></div></header><div class="accounts-app-grid">
 <?php foreach($apps as [$href,$icon,$theme,$name,$description]): ?><a class="accounts-app-card accounting-app-card accounting-app-card--<?=htmlspecialchars($theme)?>" href="<?=htmlspecialchars($href)?>"><span class="accounts-app-icon" aria-hidden="true"><i data-lucide="<?=htmlspecialchars($icon)?>"></i></span><div class="accounting-app-card__content"><div class="accounting-app-card__status"><small class="accounts-app-status is-available">Available</small><?php if($name==='Amendments'&&$unread):?><small class="accounts-app-status"><?=$unread?> unread</small><?php endif;?><span class="accounting-app-visual" aria-hidden="true"><i></i><i></i><i></i></span></div><h3><?=htmlspecialchars($name)?></h3><p><?=htmlspecialchars($description)?></p><strong>Open <?=htmlspecialchars($name)?> <span aria-hidden="true">&rarr;</span></strong></div></a><?php endforeach; ?>
 </div></section>
</main>
<?php include BASE_PATH.'/shared/footer.php'; ?>
