<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_login();
require_once BASE_PATH.'/shared/acknowledgments/integration.php';
$service=new \Hambelela\Acknowledgments\Service(db());$service->actor((int)(current_user()['id']??0));
$pageTitle='Acknowledgments | '.APP_NAME;$activeApp='acknowledgments';$extraStylesheets=[['path'=>'assets/css/acknowledgments.css','version'=>filemtime(BASE_PATH.'/assets/css/acknowledgments.css')]];
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
?><main class="workspace hb-ack-page" id="ack-app">
<header class="ack-top"><div><p class="hb-ack-eyebrow">Employee communication</p><h1>Acknowledgments</h1><p>Send instructions, request confirmations and track employee understanding.</p></div><button id="ack-new" hidden>+ New Acknowledgment</button></header>
<p id="ack-message" role="status"></p><section id="ack-summary" class="ack-summary"></section>
<nav id="ack-tabs" aria-label="Acknowledgment views"></nav>
<div class="ack-filters"><label>Search<input id="ack-search" type="search" placeholder="Employee or instruction"></label><label>Sort<select id="ack-sort"><option value="latest">Latest first</option><option value="deadline">Deadline</option><option value="employee">Employee</option></select></label><label>Deadline<input id="ack-deadline-filter" type="date"></label><button id="ack-clear" class="secondary">Clear filters</button></div>
<div class="ack-table" id="ack-table"></div><div class="ack-pagination"><button id="ack-prev" class="secondary">Previous</button><span id="ack-page"></span><button id="ack-next" class="secondary">Next</button></div>
<dialog id="ack-drawer"><header><h2 id="ack-drawer-title">Acknowledgment</h2><button data-ack-close class="secondary">Close</button></header><div id="ack-content"></div><p id="ack-error" role="alert"></p></dialog>
</main><script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/acknowledgments.js?v=<?=filemtime(BASE_PATH.'/assets/js/acknowledgments.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
