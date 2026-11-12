<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_login();
require_once BASE_PATH.'/shared/acknowledgments/AnonymousAccess.php';
$anon=\Hambelela\Acknowledgments\AnonymousAccess::url((string)parse_url('https://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST));
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
$isManager=$isManager??((current_user()['role_key']??'')==='owner_admin');$pageTitle='Messages & reporting';$activeApp='acknowledgments';
$single=!$isManager&&!empty($instructionRecord);
if($single){
    $_SESSION['ack_csrf']=$_SESSION['ack_csrf']??bin2hex(random_bytes(32));
    $extraStylesheets=[['path'=>'assets/css/acknowledgments.css','version'=>filemtime(BASE_PATH.'/assets/css/acknowledgments.css')]];
}
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?><main class="workspace ack-contact-page"><?php if($isManager): ?><a class="ack-contact-back" href="index.php">← Acknowledgments</a><?php endif; ?><h1>Messages &amp; reporting</h1>
<div class="ack-contact-grid">
<section class="ack-contact-card" id="private" aria-labelledby="ack-private-title"><header><i class="ack-contact-symbol" data-lucide="messages-square" aria-hidden="true"></i><h2 id="ack-private-title">Private assistance</h2></header><p>Ask a question or request help. Only you and the designated Owner can read the conversation. Your name is visible to the Owner.</p>
<button type="button" class="ack-contact-primary" id="ack-private-open">Open private inbox</button><div id="ack-private"></div></section>
<section class="ack-contact-card" id="anonymous" aria-labelledby="ack-anon-title"><header><i class="ack-contact-symbol" data-lucide="shield-check" aria-hidden="true"></i><h2 id="ack-anon-title">Anonymous reporting</h2></header><p>Only the designated Owner can read your report. Your name is not attached. Keep your private code to check for a reply.</p>
<?php if($anon): ?><a class="ack-contact-primary" href="<?=htmlspecialchars($anon,ENT_QUOTES)?>" rel="noopener noreferrer" referrerpolicy="no-referrer" target="_blank">Open anonymous reporting ↗</a>
<?php else: ?><p class="ack-state">Anonymous reporting is not enabled. Nothing will be submitted as a named message instead.</p><?php endif; ?></section>
</div>
<p id="ack-contact-error" role="alert"></p>
<?php if($single): ?><div class="hb-ack-page">
<p id="ack-message" role="status"></p>
<dialog id="ack-drawer" data-record="<?=(int)$instructionRecord?>" data-employee="<?=(int)current_user()['id']?>" data-csrf="<?=htmlspecialchars($_SESSION['ack_csrf'],ENT_QUOTES)?>" aria-labelledby="ack-drawer-title"><header><h2 id="ack-drawer-title">Instruction</h2><button type="button" class="ack-action secondary" data-ack-close>Close</button></header><div id="ack-content"></div><p id="ack-error" role="alert"></p></dialog>
</div><script defer src="<?=BASE_URL?>/assets/js/acknowledgments.js?v=<?=filemtime(BASE_PATH.'/assets/js/acknowledgments.js')?>"></script><?php endif; ?>
</main><script defer src="<?=BASE_URL?>/assets/js/ack-inbox.js?v=<?=filemtime(BASE_PATH.'/assets/js/ack-inbox.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
