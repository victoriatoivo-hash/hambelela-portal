<?php
if (!defined('BASE_PATH') || empty($owner)) { http_response_code(403); exit; }
// Presentation only: keep the existing scoped records, permissions and handlers.
$marketingApps=[
'tasks'=>['Content Tasks','Manage briefs, ownership and approval.','list-checks','Create Content'],
'calendar'=>['Calendar','Schedule and review upcoming Marketing work.','calendar-days','Create Content'],
'social'=>['Social Media','Plan, create and track social content.','share-2','Create Post'],
'reels'=>['Reels & Video','Manage short-form video production.','clapperboard','Create Video Brief'],
'whatsapp'=>['WhatsApp Marketing','Plan customer broadcasts and Marketing messaging.','message-circle','Create Broadcast'],
'blog'=>['Blog & SEO','Plan, write and publish search-focused content.','file-text','Create Article'],
'newsletter'=>['Newsletter','Create, schedule and review email campaigns.','mail','Create Newsletter'],
'website'=>['Website & Products','Manage Marketing-led website and product updates.','panels-top-left','Create Website Task'],
'library'=>['Content Library','Marketing assets, drafts and reference files.','library','Create Content'],
'ideas'=>['Ideas','Capture, review and develop Marketing ideas.','lightbulb','Add Idea'],
'performance'=>['Performance','Review execution, reliability and approval quality.','gauge','Create Content']
];
if(isset($marketingApps[$view])):$app=$marketingApps[$view];?>
<header class="marketing-app-shell">
 <span class="marketing-shell-icon"><i data-lucide="<?=$app[2]?>"></i></span>
 <div><p>MARKETING</p><h1><?=$app[0]?></h1><span><?=$app[1]?></span></div>
 <button type="button" class="marketing-btn-primary" data-open-form><i data-lucide="plus"></i><?=$app[3]?></button>
</header>
<?php if(!in_array($view,['performance','library'],true)):?>
<div class="marketing-app-summary">
<?php foreach(['In this view'=>count($items),'In progress'=>count(array_filter($items,fn($row)=>$row['status']==='in_progress')),'Awaiting approval'=>count(array_filter($items,fn($row)=>in_array($row['status'],['submitted','ready_for_review'],true))),'Published'=>count(array_filter($items,fn($row)=>$row['status']==='published'))]as$label=>$value):?>
<article><span><?=marketing_e($label)?></span><strong><?=$value?></strong><small>Loaded workspace records</small></article>
<?php endforeach;?>
</div>
<?php endif;endif;?>
