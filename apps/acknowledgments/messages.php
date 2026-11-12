<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_login();
require_once BASE_PATH.'/shared/acknowledgments/AnonymousAccess.php';
$anon=\Hambelela\Acknowledgments\AnonymousAccess::url((string)parse_url('https://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST));
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
$pageTitle='Acknowledgment messages';$activeApp='acknowledgments';include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?><main class="workspace ack-contact-page"><a href="index.php">← Acknowledgments</a><h1>Messages</h1>
<section><h2>Ask a question or request assistance</h2><p>This is a named conversation between the employee and the designated Owner. It is not anonymous. Message history stays here, off the dashboard.</p>
<button type="button" id="ack-private-open">Open private inbox</button><div id="ack-private"></div></section>
<section><h2>Report something anonymously</h2><p>Only the designated Owner can read the report. Your employee profile is not attached. Save your private code to check for a reply.</p>
<?php if($anon): ?><a href="<?=htmlspecialchars($anon,ENT_QUOTES)?>" rel="noopener noreferrer" referrerpolicy="no-referrer" target="_blank">Open anonymous reporting ↗</a>
<?php else: ?><p>Anonymous reporting is not enabled. Nothing will be submitted as a named message instead.</p><?php endif; ?></section>
<p id="ack-contact-error" role="alert"></p></main><script defer src="<?=BASE_URL?>/assets/js/ack-inbox.js?v=<?=filemtime(BASE_PATH.'/assets/js/ack-inbox.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
