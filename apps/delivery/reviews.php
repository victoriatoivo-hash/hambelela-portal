<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{$actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if(!\Hambelela\Delivery\DeliveryPolicy::can($actor,'delivery_reconcile'))throw new DomainException();}
catch(DomainException $e){http_response_code(403);exit('Payment review access denied.');}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment review · Delivery</title><link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/delivery-core.css"></head>
<body><main class="delivery-main"><header class="pagehead"><div><div class="eyebrow">HAMBELELA DELIVERY OPERATIONS</div><h1>Payment review</h1><p>Compare website and POS snapshots with protected portal payments.</p></div><div class="actions"><a class="secondary" href="index.php">Back to deliveries</a><button class="secondary" id="refresh">Refresh</button></div></header>
<div class="notice">Nothing here automatically changes an order payment. If the external snapshot is correct, leave the review open for an authorised accounting correction. Only an Owner can close a review after verifying that the portal payment should remain.</div>
<p id="review-message" role="status"></p><p id="review-count"></p><div id="review-list"></div>
<dialog id="review-dialog"><form id="review-form"><h2>Keep verified portal payment</h2><p>Confirm you checked the payment evidence. This closes the review without changing either system's payment records.</p><label>Verification reason<textarea name="note" minlength="10" maxlength="450" required></textarea></label><label class="check"><input type="checkbox" name="verified" required>I verified that the current portal payment is correct.</label><p class="form-error" role="alert"></p><div class="modalactions"><button type="button" class="secondary" id="review-cancel">Cancel</button><button class="primary">Close review</button></div></form></dialog>
</main><script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/delivery-reviews.js"></script></body></html>
