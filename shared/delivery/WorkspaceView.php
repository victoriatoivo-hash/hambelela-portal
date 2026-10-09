<?php
if(!defined('BASE_PATH'))exit;
require_once __DIR__.'/WorkspaceApps.php';
$apps=\Hambelela\Delivery\WorkspaceApps::forActor($deliveryActor);
$base=htmlspecialchars(BASE_URL,ENT_QUOTES);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery Workspace · Hambelela</title></head><body data-delivery-accounting="true"><main class="delivery-main delivery-workspace">
<header class="delivery-workspace-header"><p class="eyebrow">Operations Workspace</p><h1>Delivery</h1><p>Local delivery operations, collections and delivery accounting.</p></header>
<section aria-labelledby="delivery-apps-title"><header class="delivery-apps-header"><h2 id="delivery-apps-title">Delivery Applications</h2><label class="delivery-app-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="delivery-app-search" type="search" placeholder="Search applications..." aria-label="Search applications"></label></header>
<div class="delivery-app-grid">
<?php foreach($apps as [$href,$theme,$name,$description,$action,$icon]):?><a class="delivery-app-card delivery-app-card--<?=htmlspecialchars($theme)?>" href="<?=htmlspecialchars($href)?>" data-app-search="<?=htmlspecialchars(strtolower($name.' '.$description),ENT_QUOTES)?>"><span class="delivery-app-card__arrow" aria-hidden="true">↗</span><span class="delivery-app-icon" aria-hidden="true"><i data-lucide="<?=htmlspecialchars($icon)?>"></i></span><div class="delivery-app-card__content"><span class="delivery-app-status">Available</span><h3 class="delivery-app-card__title"><?=htmlspecialchars($name)?></h3><p class="delivery-app-card__description"><?=htmlspecialchars($description)?></p><strong class="delivery-app-card__action"><?=htmlspecialchars($action)?> <span aria-hidden="true">→</span></strong></div></a><?php endforeach;?>
</div><p id="delivery-app-empty" role="status" hidden>No applications match your search.</p></section>
<?php if(($deliveryActor['role']??'')==='owner_admin'):?><section class="delivery-management"><h2>Management</h2><p>Employee profiles remain in Settings → Employees &amp; Roles.</p><nav aria-label="Delivery management"><a href="partner-routing.php">Partner routing</a><a href="reviews.php">Payment reviews</a></nav></section><?php endif;?>
</main><script src="<?=$base?>/assets/js/delivery-workspace.js?v=<?=filemtime(BASE_PATH.'/assets/js/delivery-workspace.js')?>"></script></body></html>
