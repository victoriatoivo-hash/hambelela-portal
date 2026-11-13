<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_login();
$_SESSION['ack_csrf']=$_SESSION['ack_csrf']??bin2hex(random_bytes(32));
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
$isManager=$isManager??((current_user()['role_key']??'')==='owner_admin');$pageTitle='Messages & reporting';$activeApp='acknowledgments';
$single=!$isManager&&!empty($instructionRecord);
if($single){
    $extraStylesheets=[['path'=>'assets/css/acknowledgments.css','version'=>filemtime(BASE_PATH.'/assets/css/acknowledgments.css')]];
}
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?><main class="workspace ack-contact-page"><?php if($isManager): ?><a class="ack-contact-back" href="index.php">← Acknowledgments</a><?php endif; ?><h1>Messages &amp; reporting</h1>
<div class="ack-contact-grid">
<section class="ack-contact-card" id="private" aria-labelledby="ack-private-title"><header><i class="ack-contact-symbol" data-lucide="messages-square" aria-hidden="true"></i><h2 id="ack-private-title">Private assistance</h2></header><p>Ask a question or request help. Only you and the designated Owner can read the conversation. Your name is visible to the Owner.</p>
<button type="button" class="ack-contact-primary" id="ack-private-open">Open private inbox</button><div id="ack-private"></div></section>
<section class="ack-contact-card" id="anonymous" data-csrf="<?=htmlspecialchars($_SESSION['ack_csrf'],ENT_QUOTES)?>" aria-labelledby="ack-anon-title"><header><i class="ack-contact-symbol" data-lucide="shield-check" aria-hidden="true"></i><h2 id="ack-anon-title">Send without my name</h2></header>
<p>Only the designated Owner receives the report. Your name and employee ID are not attached or shown in the inbox. Avoid including details that identify you.</p>
<p class="ack-state">Hosting logs may record connection details, such as your IP address and the time you visited. This is not a guarantee of complete anonymity.</p>
<label for="unnamed-body">Your report</label><textarea id="unnamed-body" rows="3" maxlength="6000" autocomplete="off"></textarea>
<button type="button" class="ack-contact-primary" id="unnamed-send" disabled>Send without my name</button>
<p id="unnamed-result" role="status"></p>
<div id="unnamed-receipt" hidden><label for="unnamed-code">Your private reply code</label><input id="unnamed-code" type="text" readonly autocomplete="off"><p>Keep this code somewhere private. Anyone with it can check the reply, and the Owner cannot recover it for you.</p><button type="button" id="unnamed-done">I’ve saved my code</button></div>
<details><summary>Check a reply</summary><label for="unnamed-lookup">Private reply code</label><input id="unnamed-lookup" type="text" autocomplete="off" spellcheck="false" maxlength="48"><button type="button" id="unnamed-check" disabled>Check reply</button><p id="unnamed-answer" role="status"></p></details>
<noscript>Enable JavaScript to submit a report or check your reply.</noscript></section>
</div>
<p id="ack-contact-error" role="alert"></p>
<?php if($single): ?><div class="hb-ack-page">
<p id="ack-message" role="status"></p>
<dialog id="ack-drawer" data-record="<?=(int)$instructionRecord?>" data-employee="<?=(int)current_user()['id']?>" data-csrf="<?=htmlspecialchars($_SESSION['ack_csrf'],ENT_QUOTES)?>" aria-labelledby="ack-drawer-title"><header><h2 id="ack-drawer-title">Instruction</h2><button type="button" class="ack-action secondary" data-ack-close>Close</button></header><div id="ack-content"></div><p id="ack-error" role="alert"></p></dialog>
</div><script defer src="<?=BASE_URL?>/assets/js/acknowledgments.js?v=<?=filemtime(BASE_PATH.'/assets/js/acknowledgments.js')?>"></script><?php endif; ?>
</main><script defer src="<?=BASE_URL?>/assets/js/ack-inbox.js?v=<?=filemtime(BASE_PATH.'/assets/js/ack-inbox.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
