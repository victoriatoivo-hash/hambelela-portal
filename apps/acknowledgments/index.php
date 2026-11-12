<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_login();
require_once BASE_PATH.'/shared/acknowledgments/integration.php';
$service=new \Hambelela\Acknowledgments\Service(db());$actor=$service->actor((int)(current_user()['id']??0));$isManager=$actor['role_key']==='owner_admin';$sectionName=$isManager?'Acknowledgments':'My instructions';
if(!$isManager){
    // Keep the existing menu URL and render the employee destination on the first paint.
    // A notification may additionally open one assigned instruction, never the register.
    $instructionRecord=filter_var($_GET['record']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    require __DIR__.'/messages.php';
    return;
}
$pageTitle=$sectionName.' | '.APP_NAME;$activeApp='acknowledgments';$extraStylesheets=[['path'=>'assets/css/acknowledgments.css','version'=>filemtime(BASE_PATH.'/assets/css/acknowledgments.css')]];
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?><main class="workspace hb-ack-page" id="ack-app">
<header class="ack-top"><div><p class="hb-ack-eyebrow">Employee communication</p><h1><?=$sectionName?></h1><p><?=$isManager?'Send instructions, request confirmations and track employee understanding.':'Read your instructions, ask questions and confirm your understanding.'?></p></div><?php if($isManager): ?><button class="ack-action" id="ack-new" hidden>+ New Acknowledgment</button><?php endif; ?></header>
<a class="ack-action secondary" href="messages.php">Messages &amp; assistance</a>
<?php if($isManager): ?><section id="ack-private-queue" hidden aria-labelledby="ack-private-queue-title"><h2 id="ack-private-queue-title">Awaiting your reply</h2><div data-queue-items></div></section><?php endif; ?>
<p id="ack-message" role="status"></p><section id="ack-summary" class="ack-summary"></section>
<nav id="ack-tabs" aria-label="<?=$sectionName?> views"></nav>
<div class="ack-filters"><label>Search<input id="ack-search" type="search" placeholder="<?=$isManager?'Employee or instruction':'Find an instruction'?>"></label><label>Sort<select id="ack-sort" data-portal-custom-select><option value="latest">Latest first</option><option value="deadline">Deadline</option><?php if($isManager): ?><option value="employee">Employee</option><?php endif; ?></select></label><label>Deadline<input id="ack-deadline-filter" type="date"></label><button class="ack-action secondary" id="ack-clear">Clear filters</button></div>
<div class="ack-table" id="ack-table"></div><div class="ack-pagination"><button class="ack-action secondary" id="ack-prev">Previous</button><span id="ack-page"></span><button class="ack-action secondary" id="ack-next">Next</button></div>
<dialog id="ack-drawer"><header><h2 id="ack-drawer-title"><?=$isManager?'Acknowledgment':'Instruction'?></h2><button class="ack-action secondary" data-ack-close>Close</button></header><div id="ack-content"></div><p id="ack-error" role="alert"></p></dialog>
</main><script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/acknowledgments.js?v=<?=filemtime(BASE_PATH.'/assets/js/acknowledgments.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
