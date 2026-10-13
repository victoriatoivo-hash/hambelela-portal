<?php
declare(strict_types=1);
if (!defined('BASE_PATH') || !isset($owner) || $owner) { http_response_code(403); exit; }
$view=(string)($_GET['view']??'dashboard');
$myWork=marketing_employee_work();
$productQuery=db()->prepare('SELECT id,product_name,status,change_notes FROM marketing_product_changes WHERE assigned_employee_id=? ORDER BY updated_at DESC');$productQuery->execute([marketing_employee_id()]);$myProducts=$productQuery->fetchAll();
$portalTasks=[];
if(marketing_table_exists('ops_checklist_tasks')){
    $portalTaskQuery=db()->prepare("SELECT id,task_name,status,priority,deadline,COALESCE(date_assigned,created_at) AS assigned_at FROM ops_checklist_tasks WHERE assigned_employee_id=? AND employee_visible=1 AND (scheduled_at IS NULL OR released_at IS NOT NULL) AND archived_at IS NULL AND deleted_at IS NULL AND status NOT IN('complete','completed','done','cancelled') ORDER BY CASE WHEN deadline IS NULL THEN 1 ELSE 0 END,deadline,id DESC LIMIT 100");
    $portalTaskQuery->execute([marketing_employee_id()]);
    $portalTasks=$portalTaskQuery->fetchAll();
}
$apps=['tasks'=>['Content Tasks','list-checks',[]],'calendar'=>['Calendar','calendar-days',[]],
    'social'=>['Social Media','share-2',['social_post','story','carousel']], 'reels'=>['Reels & Video','clapperboard',['reel']],
    'whatsapp'=>['WhatsApp','message-circle-more',['whatsapp_post']], 'blog'=>['Blog & SEO','search-check',['blog']],
    'newsletter'=>['Newsletter','mail',['newsletter']], 'website'=>['Website & Products','panels-top-left',['website_banner','product_image','product_listing','website_update']],
    'media'=>['Media','image',[]],'library'=>['Content Library','library',[]]];
$pending=static fn(array $i):bool=>!in_array($i['status'],['published','completed','cancelled'],true);
$badges=[];foreach($apps as$key=>$app)$badges[$key]=count(array_filter($myWork,static fn(array $i):bool=>marketing_work_matches_app($i,$key)));
$badges['tasks']+=count($portalTasks);
$badges['website']+=count(array_filter($myProducts,fn($p)=>in_array($p['status'],['draft','changes_requested'],true)));
$weekStart=date('Y-m-d',strtotime('monday this week'));$weekEnd=date('Y-m-d',strtotime('monday next week'));
$stats=[['Assigned to Me',count(array_filter($myWork,$pending))+count($portalTasks),'list-checks'],
 ['Due Today',count(array_filter($myWork,fn($i)=>$pending($i)&&substr((string)$i['due_at'],0,10)===date('Y-m-d')))+count(array_filter($portalTasks,fn($i)=>substr((string)($i['deadline']??''),0,10)===date('Y-m-d'))),'calendar-days'],
 ['Due This Week',count(array_filter($myWork,fn($i)=>$pending($i)&&$i['due_at']>=$weekStart&&$i['due_at']<$weekEnd))+count(array_filter($portalTasks,fn($i)=>($i['deadline']??'')>=$weekStart&&($i['deadline']??'')<$weekEnd)),'calendar-clock'],
 ['Awaiting My Action',count(array_filter($myWork,fn($i)=>in_array($i['status'],['brief','to_create','in_progress','changes_requested'],true)))+count($portalTasks),'circle-play'],
 ['Ready to Publish',count(array_filter($myWork,fn($i)=>in_array($i['status'],['approved','scheduled'],true))),'send'],
 ['Completed This Week',count(array_filter($myWork,fn($i)=>in_array($i['status'],['published','completed'],true)&&($i['completed_at']??$i['published_at'])>=$weekStart&&($i['completed_at']??$i['published_at'])<$weekEnd)),'circle-check']];
$isEssDashboard=true;$pageTitle=(in_array($view,['dashboard','apps'],true)?'Marketing Applications':'My Marketing Work').' | '.APP_NAME;$activeApp='marketing';
$extraStylesheets=[];foreach(['marketing','marketing-extras','marketing-execution'] as$css)$extraStylesheets[]=['path'=>'assets/css/'.$css.'.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/'.$css.'.css')];
require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Marketing';$pageUsesPortalSidebar=false;$extraStylesheets[]=['path'=>'assets/css/ess-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')];include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main marketing-workspace ess-marketing-page hb-marketing">
<nav class="marketing-local-nav" aria-label="Marketing"><a class="<?=$view==='dashboard'?'is-active':''?>" href="?view=dashboard">Home</a><a class="<?=$view==='apps'?'is-active':''?>" href="?view=apps">Apps</a></nav>
<?php if($message):?><p class="marketing-notice" role="status"><?=marketing_e($message)?></p><?php endif;?>
<?php marketing_workspace_assets(); ?>
<?php if(in_array($view,['dashboard','apps'],true)):?>
<section class="hb-marketing-home" aria-labelledby="marketing-home-title">
<header><p class="mk-home-eyebrow">MARKETING WORKSPACE</p><h1 class="mk-home-title" id="marketing-home-title">Marketing Applications</h1><p class="mk-home-description">Choose an application to open your assigned work, media or calendar.</p></header>
<?php marketing_employee_application_cards($apps,$badges); ?>
</section>
<?php endif;?>
<?php $items=array_values(array_filter($myWork,fn($i)=>marketing_work_matches_app($i,$view,false)));include __DIR__.'/app-shell.php'; ?>
<?php if($view==='calendar') include __DIR__.'/employee-calendar.php'; ?>
<?php if($view==='tasks'):?><section class="marketing-board"><header><div><p>TASK MANAGEMENT</p><h2>Portal tasks assigned to me</h2></div><a class="marketing-btn-secondary" href="<?=BASE_URL?>/apps/operations/checklists.php?task_view=active">Open Task Management</a></header><?php if(!$portalTasks):?><div class="marketing-empty"><strong>No active portal tasks.</strong><span>New assignments will appear here automatically.</span></div><?php else:?><div class="marketing-table-wrap"><table><thead><tr><th>Task</th><th>Assigned</th><th>Due</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($portalTasks as$task):?><tr><td><strong><?=marketing_e((string)$task['task_name'])?></strong><small>Task #<?=(int)$task['id']?></small></td><td><?=marketing_e((string)($task['assigned_at']??'—'))?></td><td><?=marketing_e((string)($task['deadline']??'Not scheduled'))?></td><td><?=marketing_e(ucwords((string)($task['priority']??'normal')))?></td><td><span class="marketing-status <?=marketing_e((string)$task['status'])?>"><?=marketing_e(ucwords(str_replace('_',' ',(string)$task['status'])))?></span></td><td><a class="marketing-btn-primary" href="<?=BASE_URL?>/apps/operations/checklists.php?task_view=active&amp;task_id=<?=(int)$task['id']?>">Open task</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endif;?>
<?php if($view==='library'): marketing_content_table($myWork,'completed');
 $files=db()->prepare('SELECT v.id,v.file_name,v.version_label,w.title FROM marketing_item_versions v JOIN marketing_work_items w ON w.id=v.item_id WHERE w.assigned_employee_id=? AND v.deleted_at IS NULL AND w.cancelled_at IS NULL ORDER BY v.id DESC');$files->execute([marketing_employee_id()]);?>
<section class="marketing-panel"><h2>Assigned Content Library</h2><div class="marketing-library"><?php foreach($files->fetchAll() as$file):?><article><strong><?=marketing_e($file['title'])?></strong><span><?=marketing_e($file['file_name'])?></span><a class="marketing-btn-secondary" href="<?=BASE_URL?>/apps/marketing/file.php?id=<?=(int)$file['id']?>">Download <?=marketing_e($file['version_label'])?></a></article><?php endforeach;?></div></section>
<?php elseif(!in_array($view,['apps','dashboard','calendar'],true)):
 $visible=array_values(array_filter($myWork,fn($i)=>marketing_work_matches_app($i,$view,false))); marketing_assignment_grid($visible,($_GET['completed']??'')==='1'?'completed':'active'); ?>
<?php endif;?>
<?php if($view==='website'&&$myProducts):?><section class="marketing-panel"><h2>Assigned product copy</h2><p>Prepare the copy here. The owner reviews and publishes changes to the shop.</p><?php foreach($myProducts as$p):?><article class="marketing-calendar-day"><h3><?=marketing_e($p['product_name'])?></h3><p><?=marketing_e(str_replace('_',' ',$p['status']))?></p><a href="product-work.php?id=<?=(int)$p['id']?>">Open assigned website work</a></article><?php endforeach;?></section><?php endif;?>
</main>
<?php include BASE_PATH.'/shared/ess-mobile-navigation.php'; ?><script defer src="<?=BASE_URL?>/assets/js/ess-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/ess-dashboard.js')?>"></script><?php include BASE_PATH.'/shared/footer.php'; ?>
