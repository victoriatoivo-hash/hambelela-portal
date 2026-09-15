<?php
declare(strict_types=1);
if (!defined('BASE_PATH') || !isset($owner) || $owner) { http_response_code(403); exit; }
$view=(string)($_GET['view']??'dashboard');
$myWork=marketing_employee_work();
$productQuery=db()->prepare('SELECT id,product_name,status,change_notes FROM marketing_product_changes WHERE assigned_employee_id=? ORDER BY updated_at DESC');$productQuery->execute([marketing_employee_id()]);$myProducts=$productQuery->fetchAll();
$apps=['tasks'=>['Content Tasks','list-checks',[]],'calendar'=>['Calendar','calendar-days',[]],
    'social'=>['Social Media','share-2',['social_post','story','carousel']], 'reels'=>['Reels & Video','clapperboard',['reel']],
    'whatsapp'=>['WhatsApp','message-circle-more',['whatsapp_post']], 'blog'=>['Blog & SEO','search-check',['blog']],
    'newsletter'=>['Newsletter','mail',['newsletter']], 'website'=>['Website & Products','panels-top-left',['website_banner','product_image','product_listing','website_update']],
    'library'=>['Content Library','library',[]]];
$pending=static fn(array $i):bool=>!in_array($i['status'],['published','cancelled'],true);
$badges=[];foreach($apps as$key=>$app)$badges[$key]=count(array_filter($myWork,static fn(array $i):bool=>marketing_work_matches_app($i,$key)));
$badges['website']+=count(array_filter($myProducts,fn($p)=>in_array($p['status'],['draft','changes_requested'],true)));
$weekStart=date('Y-m-d',strtotime('monday this week'));$weekEnd=date('Y-m-d',strtotime('monday next week'));
$stats=[['Assigned to Me',count(array_filter($myWork,$pending)),'list-checks'],
 ['Due Today',count(array_filter($myWork,fn($i)=>$pending($i)&&substr((string)$i['due_at'],0,10)===date('Y-m-d'))),'calendar-days'],
 ['Due This Week',count(array_filter($myWork,fn($i)=>$pending($i)&&$i['due_at']>=$weekStart&&$i['due_at']<$weekEnd)),'calendar-clock'],
 ['Awaiting My Action',count(array_filter($myWork,fn($i)=>in_array($i['status'],['brief','to_create','in_progress','changes_requested'],true))),'circle-play'],
 ['Ready to Publish',count(array_filter($myWork,fn($i)=>in_array($i['status'],['approved','scheduled'],true))),'send'],
 ['Completed This Week',count(array_filter($myWork,fn($i)=>$i['status']==='published'&&$i['published_at']>=$weekStart&&$i['published_at']<$weekEnd)),'circle-check']];
$isEssDashboard=true;$pageTitle='My Marketing Work | '.APP_NAME;$activeApp='marketing';
$extraStylesheets=[];foreach(['marketing','marketing-extras','marketing-execution'] as$css)$extraStylesheets[]=['path'=>'assets/css/'.$css.'.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/'.$css.'.css')];
require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Marketing';$pageUsesPortalSidebar=false;$extraStylesheets[]=['path'=>'assets/css/ess-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')];include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main marketing-workspace ess-marketing-page marketing-execution-workspace">
<nav class="marketing-local-nav" aria-label="Marketing"><a href="?view=dashboard">Home</a><a href="?view=apps">Apps</a></nav>
<?php if($message):?><p class="marketing-notice" role="status"><?=marketing_e($message)?></p><?php endif;?>
<section class="marketing-hero"><div class="marketing-hero-copy"><p class="marketing-eyebrow">MARKETING &amp; SALES</p><h1 class="marketing-hero-title">My Work</h1><p class="marketing-hero-subtitle">Your assigned content, instructions and execution progress.</p><div class="marketing-hero-actions"><a class="marketing-btn-primary" href="?view=tasks">My Content Tasks</a><a class="marketing-btn-secondary" href="<?=BASE_URL?>/apps/operations/courier.php">Courier</a></div></div></section>
<?php if($view==='dashboard'):?><section class="marketing-kpi-grid" aria-label="My work overview"><?php foreach($stats as$stat):?><article class="marketing-kpi-card"><span class="marketing-kpi-icon"><i data-lucide="<?=$stat[2]?>"></i></span><div><span class="marketing-kpi-label"><?=$stat[0]?></span><strong><?=$stat[1]?></strong></div></article><?php endforeach;?></section><?php endif;?>
<?php if(in_array($view,['dashboard','apps'],true)):?><section class="marketing-panel"><header class="marketing-panel-header"><h2>Marketing Apps</h2></header><div class="marketing-app-grid"><?php foreach($apps as$key=>$app):?><a class="marketing-app-tile" href="?view=<?=$key?>"><span class="marketing-app-icon"><i data-lucide="<?=$app[1]?>"></i></span><span><strong><?=$app[0]?></strong><small>Your assigned work</small></span><?php if($badges[$key]>0&&$key!=='library'):?><span class="marketing-app-count" aria-label="<?=$badges[$key]?> outstanding"><?=$badges[$key]?></span><?php endif;?></a><?php endforeach;?></div></section><?php endif;?>
<?php if($view==='calendar') include __DIR__.'/employee-calendar.php'; ?>
<?php if($view==='library'):
 $files=db()->prepare('SELECT v.id,v.file_name,v.version_label,w.title FROM marketing_item_versions v JOIN marketing_work_items w ON w.id=v.item_id WHERE w.assigned_employee_id=? AND w.cancelled_at IS NULL ORDER BY v.id DESC');$files->execute([marketing_employee_id()]);?>
<section class="marketing-panel"><h2>Assigned Content Library</h2><div class="marketing-library"><?php foreach($files->fetchAll() as$file):?><article><strong><?=marketing_e($file['title'])?></strong><span><?=marketing_e($file['file_name'])?></span><a class="marketing-btn-secondary" href="<?=BASE_URL?>/apps/marketing/file.php?id=<?=(int)$file['id']?>">Download <?=marketing_e($file['version_label'])?></a></article><?php endforeach;?></div></section>
<?php elseif($view!=='apps'):
 $types=$apps[$view][2]??[];$showCompleted=($_GET['completed']??'')==='1';
 $visible=array_filter($myWork,fn($i)=>marketing_work_matches_app($i,$view,!$showCompleted)&&(!$showCompleted||$i['status']==='published'));?>
<section class="marketing-board"><header><div><p>ASSIGNED CONTENT</p><h2><?=marketing_e($apps[$view][0]??'Awaiting my action')?></h2></div><a class="marketing-btn-secondary" href="?view=<?=marketing_e($view)?>&amp;completed=<?=$showCompleted?'0':'1'?>"><?=$showCompleted?'Outstanding work':'Completed work'?></a></header>
<?php if(!$visible):?><div class="marketing-empty"><strong>You're all caught up.</strong><span>No Marketing work currently needs your attention in this view.</span></div><?php else:?><div class="marketing-table-wrap"><table><thead><tr><th>Content</th><th>Channel</th><th>Priority</th><th>Due</th><th>Publish</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($visible as$i):?><tr><td><?=marketing_e($i['title'])?></td><td><?=marketing_e(implode(', ',array_map(fn($c)=>marketing_execution_channels()[$c['channel_key']]??($i['platform']?:'Assigned work'),$i['execution_channels'])))?></td><td><?=marketing_e($i['priority'])?></td><td><?=marketing_e($i['due_at']?:'Not scheduled')?></td><td><?=marketing_e($i['publish_at']?:'Not scheduled')?></td><td><span class="marketing-status <?=marketing_e($i['status'])?>"><?=marketing_e(str_replace('_',' ',$i['status']))?></span></td><td><a class="marketing-btn-secondary" href="execution.php?id=<?=(int)$i['id']?>">Open work</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endif;?>
<?php if($view==='website'&&$myProducts):?><section class="marketing-panel"><h2>Assigned product copy</h2><p>Prepare the copy here. The owner reviews and publishes changes to the shop.</p><?php foreach($myProducts as$p):?><article class="marketing-calendar-day"><h3><?=marketing_e($p['product_name'])?></h3><p><?=marketing_e(str_replace('_',' ',$p['status']))?></p><a href="product-work.php?id=<?=(int)$p['id']?>">Open assigned website work</a></article><?php endforeach;?></section><?php endif;?>
</main>
<?php include BASE_PATH.'/shared/ess-mobile-navigation.php'; ?><script defer src="<?=BASE_URL?>/assets/js/ess-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/ess-dashboard.js')?>"></script><?php include BASE_PATH.'/shared/footer.php'; ?>
