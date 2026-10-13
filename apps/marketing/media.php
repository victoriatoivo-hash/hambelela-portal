<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/marketing.php';
marketing_require_access();
$owner=marketing_is_owner();$message='';
$jsonRequest=strpos((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')!==false;
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        marketing_verify();$action=(string)($_POST['action']??'');$id=0;
        if ($action==='upload') {
            $id=marketing_media_upload($_FILES['asset']??[],(int)($_POST['item_id']??0),(string)($_POST['channel_key']??''));
            $message='Media uploaded.';
        } elseif ($action==='attach') {
            marketing_media_attach((int)$_POST['media_id'],(int)$_POST['item_id'],(string)($_POST['channel_key']??''));
            $message='Media attached to assignment.';
        } elseif ($action==='delete') {
            marketing_media_delete((int)$_POST['media_id']);$message='Moved to Recently Removed. The original file is retained.';
        } elseif ($action==='restore') {
            marketing_require_owner();db()->prepare('UPDATE marketing_item_versions SET deleted_at=NULL WHERE id=?')->execute([(int)$_POST['media_id']]);$message='Media restored.';
        } else throw new RuntimeException('Choose a valid media action.');
        if ($jsonRequest) {header('Content-Type: application/json');echo json_encode(['ok'=>true,'id'=>$id,'message'=>$message]);exit;}
        header('Location: media.php?message='.rawurlencode($message));exit;
    }
} catch(Throwable $e) {
    $message=$e->getMessage();
    if ($jsonRequest) {http_response_code(422);header('Content-Type: application/json');echo json_encode(['ok'=>false,'message'=>$message]);exit;}
}
if (!$message) $message=(string)($_GET['message']??'');
$type=(string)($_GET['type']??'all');if(!in_array($type,['all','images','videos','audio'],true))$type='all';
$search=trim((string)($_GET['q']??''));$sort=($_GET['sort']??'newest')==='oldest'?'ASC':'DESC';$trash=$owner&&($_GET['trash']??'')==='1';
$params=[];$scope=marketing_media_scope($params);$where='v.deleted_at IS '.($trash?'NOT ':'').'NULL AND '.$scope;
$total=db()->prepare("SELECT COUNT(*) FROM marketing_item_versions v WHERE $where");$total->execute($params);$libraryCount=(int)$total->fetchColumn();
if($search!==''){$where.=' AND v.file_name LIKE ?';$params[]='%'.$search.'%';}
if($type!=='all'){$where.=' AND v.mime_type LIKE ?';$params[]=['images'=>'image/%','videos'=>'video/%','audio'=>'audio/%'][$type];}
$q=db()->prepare("SELECT v.* FROM marketing_item_versions v WHERE $where ORDER BY v.uploaded_at $sort,v.id $sort");$q->execute($params);$files=$q->fetchAll();
$params=[];$scope=marketing_scope_sql($params);$q=db()->prepare("SELECT id,title FROM marketing_work_items WHERE $scope AND cancelled_at IS NULL ORDER BY id DESC");$q->execute($params);$assignments=$q->fetchAll();$titles=array_column($assignments,'title','id');
// The UI reflects both existing application validation and PHP's effective request limits.
$uploadLimit=30*1024*1024;
foreach(['upload_max_filesize','post_max_size'] as $setting){$raw=trim((string)ini_get($setting));$bytes=(float)$raw;$suffix=strtolower(substr($raw,-1));if(in_array($suffix,['k','m','g'],true))$bytes*=1024**(['k'=>1,'m'=>2,'g'=>3][$suffix]);if($bytes>0)$uploadLimit=min($uploadLimit,(int)$bytes-($setting==='post_max_size'?65536:0));}
$uploadLimit=max(1,$uploadLimit);$limitLabel=rtrim(rtrim(number_format($uploadLimit/1024/1024,2,'.',''),'0'),'.').' MB';
$renderLibrary=static function() use($files,$assignments,$titles,$trash,$owner,$type,$search,$libraryCount):void { ?>
<div data-media-results data-result-count="<?=count($files)?>">
<div class="media-grid <?=$type==='audio'?'is-audio':''?>">
<?php foreach($files as$file):
    $id=(int)$file['id'];$url='file.php?id='.$id;$kind=marketing_media_type($file['mime_type']);$associated=[];
    if(isset($titles[$file['item_id']]))$associated[]=$titles[$file['item_id']];
    $links=db()->prepare('SELECT item_id FROM marketing_media_links WHERE version_id=?');$links->execute([$id]);
    foreach($links->fetchAll()as$link)if(isset($titles[$link['item_id']]))$associated[]=$titles[$link['item_id']];
    $association=$associated?implode(' · ',array_unique($associated)):'My Media — not attached';
    $format=strtoupper(pathinfo($file['file_name'],PATHINFO_EXTENSION));
?>
<article class="media-card">
<?php if(!$trash):?>
<div class="media-card__preview <?=$kind==='audio'?'is-audio':''?>">
<?php if($kind==='audio'): marketing_media_player($file); else: ?>
<button type="button" class="media-preview-button" data-media-open="<?=$id?>" aria-label="Preview <?=marketing_e($file['file_name'])?>">
<?php if($kind==='images'):?><img loading="lazy" src="<?=marketing_e($url)?>&inline=1" alt="<?=marketing_e($file['file_name'])?>">
<?php elseif($kind==='videos'):?><video preload="metadata" muted playsinline src="<?=marketing_e($url)?>&inline=1#t=0.1"></video><span class="media-play"><i data-lucide="play"></i></span>
<?php else:?><i data-lucide="file-text"></i><?php endif;?></button>
<?php endif;?></div><?php endif;?>
<div class="media-card__body"><span class="media-format"><?=marketing_e($format?:ucfirst($kind))?></span>
<h3><button type="button" class="media-card__name" data-media-open="<?=$id?>"><?=marketing_e($file['file_name'])?></button></h3>
<p class="media-card__meta"><?=marketing_e($file['uploaded_by_name'])?> · <?=marketing_e(marketing_display_time($file['uploaded_at']))?></p>
<p class="media-card__meta"><?=number_format((int)$file['file_size']/1024/1024,2)?> MB · <?=marketing_e($file['version_label'])?></p>
<p class="media-association"><?=marketing_e($association)?></p>
<button class="media-btn media-btn--secondary media-btn--small" type="button" data-media-open="<?=$id?>"><i data-lucide="expand"></i>View details</button>
</div></article>
<template id="media-detail-<?=$id?>"><h2 data-detail-name><?=marketing_e($file['file_name'])?></h2>
<?php if(!$trash):?><div class="media-detail-preview"><?php marketing_media_player($file,true);?></div><?php endif;?>
<dl class="media-details-grid"><div><dt>File type</dt><dd><?=marketing_e($format)?></dd></div><div><dt>File size</dt><dd><?=number_format((int)$file['file_size']/1024/1024,2)?> MB</dd></div><div><dt>Uploaded by</dt><dd><?=marketing_e($file['uploaded_by_name'])?></dd></div><div><dt>Uploaded</dt><dd><?=marketing_e(marketing_display_time($file['uploaded_at']))?> · Namibia</dd></div><div class="media-wide"><dt>Assignment</dt><dd><?=marketing_e($association)?></dd></div></dl>
<?php if(!$trash):?><div class="media-detail-actions"><a class="media-btn media-btn--primary" href="<?=marketing_e($url)?>"><i data-lucide="download"></i>Download</a><button class="media-btn media-btn--secondary" type="button" data-copy-media="<?=marketing_e($url)?>"><i data-lucide="link"></i>Copy link</button></div>
<form method="post" class="media-attach-form"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="attach"><input type="hidden" name="media_id" value="<?=$id?>"><label for="attach-<?=$id?>">Attach to Assignment</label><select id="attach-<?=$id?>" class="media-select" name="item_id" required><option value="">Choose assignment</option><?php foreach($assignments as$a):?><option value="<?=$a['id']?>"><?=marketing_e($a['title'])?></option><?php endforeach;?></select><button class="media-btn media-btn--secondary">Attach file</button></form><?php endif;?>
<?php if($owner):?><form method="post" class="media-remove-form"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="<?=$trash?'restore':'delete'?>"><input type="hidden" name="media_id" value="<?=$id?>"><button class="media-btn media-btn--secondary"><i data-lucide="<?=$trash?'rotate-ccw':'trash-2'?>"></i><?=$trash?'Restore file':'Move to Recently Removed'?></button></form><?php endif;?></template>
<?php endforeach;?></div>
<?php if(!$files):$empty=$libraryCount===0&&$search===''&&$type==='all';?>
<section class="media-empty"><span class="media-empty__icon"><i data-lucide="<?=$empty?'images':'search'?>"></i></span>
<h2><?=$empty?($trash?'No recently removed files':'Your Media Library is empty'):'No matching files'?></h2>
<p><?=$empty?($trash?'Files removed by Owner will appear here and can be restored.':'Upload images, videos or audio to start organising Marketing content.'):'Try changing your search or filters.'?></p>
<?php if($empty&&!$trash):?><button type="button" class="media-btn media-btn--primary" data-open-upload><i data-lucide="upload"></i>Upload Media</button><?php elseif(!$empty):?><button type="button" class="media-btn media-btn--secondary" data-clear-media-filters>Clear filters</button><?php endif;?></section>
<?php endif;?></div>
<?php };
if(($_GET['fragment']??'')==='1'){header('Content-Type: text/html; charset=utf-8');$renderLibrary();exit;}
$isEssDashboard=true;$activeApp='marketing';$pageTitle='Media Library | '.APP_NAME;
$extraStylesheets=[];foreach(['ess-dashboard','marketing-workspace']as$css)$extraStylesheets[]=['path'=>'assets/css/'.$css.'.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/'.$css.'.css')];
require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Marketing';$pageUsesPortalSidebar=false;include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main hb-marketing-media">
<header class="media-context-bar"><a href="index.php?view=apps" class="media-eyebrow">MARKETING / MEDIA</a><div data-portal-header-status-target></div></header>
<div class="media-page-header"><div><h1 class="media-title">Media Library</h1><p class="media-subtitle">Store, organise and reuse images, videos and audio.</p></div><button type="button" class="media-btn media-btn--primary" data-open-upload><i data-lucide="upload"></i>Upload Media</button></div>
<p class="media-message" role="status" <?=$message?'':'hidden'?>><?=marketing_e($message)?></p>
<nav class="media-tabs" aria-label="Media type"><?php foreach(['all'=>'All Media','images'=>'Images','videos'=>'Videos','audio'=>'Audio']as$key=>$label):?><a class="media-tab" data-media-tab="<?=$key?>" href="?<?=marketing_e(http_build_query(['type'=>$key,'q'=>$search,'sort'=>$sort==='ASC'?'oldest':'newest']))?>" <?=!$trash&&$type===$key?'aria-current="page"':''?>><?=$label?></a><?php endforeach;?><?php if($owner):?><a class="media-tab" data-media-tab="trash" href="?trash=1" <?=$trash?'aria-current="page"':''?>>Recently Removed</a><?php endif;?></nav>
<form class="media-toolbar" method="get" data-media-filters><input type="hidden" name="trash" value="<?=$trash?'1':'0'?>"><label class="media-search">Search files<span><i data-lucide="search"></i><input class="media-input" type="search" name="q" value="<?=marketing_e($search)?>" placeholder="Search media by filename..." autocomplete="off"></span></label><label>Type<select class="media-select" name="type"><?php foreach(['all'=>'All types','images'=>'Images','videos'=>'Videos','audio'=>'Audio']as$key=>$label):?><option value="<?=$key?>" <?=$type===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Sort<select class="media-select" name="sort"><option value="newest">Newest first</option><option value="oldest" <?=$sort==='ASC'?'selected':''?>>Oldest first</option></select></label><span class="media-count" role="status" data-media-count><?=count($files)?> files</span><noscript><button class="media-btn media-btn--secondary">Apply filters</button></noscript></form>
<?php $renderLibrary();?>
<dialog class="media-upload-drawer" id="media-upload" aria-labelledby="media-upload-title">
<div class="media-upload-header"><div><p class="media-eyebrow">MEDIA LIBRARY</p><h2 id="media-upload-title">Upload Media</h2><p>Upload creative assets and optionally attach them to an assignment.</p></div><button class="media-icon-button" type="button" data-close-upload aria-label="Close upload"><i data-lucide="x"></i></button></div>
<form id="media-upload-form" class="media-upload-body" method="post" enctype="multipart/form-data" data-upload-limit="<?=$uploadLimit?>"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="upload">
<section class="media-upload-section"><h3>Select Files</h3><input id="media-files" type="file" name="asset" hidden multiple accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mp3,.m4a,.wav,.pdf,.txt,.docx,.xlsx,.pptx"><button type="button" class="media-dropzone" data-media-dropzone><i data-lucide="cloud-upload"></i><span class="media-dropzone__title">Drag files here or <span>browse files</span></span><span class="media-dropzone__hint">JPG, PNG, WebP, GIF · MP4, WebM · MP3, M4A, WAV<br>PDF, text and Office documents also supported.<br>Maximum file size: <?=$limitLabel?> per file.</span></button><p class="media-upload-hint">Multiple files upload individually using the existing secure storage.</p><div class="media-file-queue" aria-live="polite" data-upload-queue></div></section>
<section class="media-upload-section"><h3>Attach to Assignment</h3><label for="media-assignment">Optional Marketing assignment</label><select id="media-assignment" class="media-select" name="item_id"><option value="0">My Media — attach later</option><?php foreach($assignments as$a):?><option value="<?=$a['id']?>"><?=marketing_e($a['title'])?></option><?php endforeach;?></select><p class="media-upload-hint">Files stay private. Uploading does not complete or approve an assignment.</p></section><p class="media-message" data-upload-message role="status" hidden></p></form>
<div class="media-upload-footer"><button class="media-btn media-btn--secondary" type="button" data-close-upload>Cancel</button><button class="media-btn media-btn--primary" type="submit" form="media-upload-form" data-upload-submit disabled><i data-lucide="upload"></i>Upload Files</button></div>
</dialog>
<dialog id="media-preview" class="media-upload-drawer media-preview-drawer" aria-label="Media details"><div class="media-upload-header"><div><p class="media-eyebrow">MEDIA LIBRARY</p><h2>File details</h2></div><button type="button" class="media-icon-button" data-close-media-preview aria-label="Close preview"><i data-lucide="x"></i></button></div><div class="media-upload-body" data-media-preview-body></div><div class="media-upload-footer"><button type="button" class="media-btn media-btn--secondary" data-close-media-preview>Close</button></div></dialog>
</main><script defer src="<?=BASE_URL?>/assets/js/marketing-workspace.js?v=<?=filemtime(BASE_PATH.'/assets/js/marketing-workspace.js')?>"></script><?php include BASE_PATH.'/shared/ess-mobile-navigation.php';include BASE_PATH.'/shared/footer.php';?>
