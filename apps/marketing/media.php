<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/marketing.php';
marketing_require_access();$owner=marketing_is_owner();$message='';
try{if($_SERVER['REQUEST_METHOD']==='POST'){
 marketing_verify();$action=(string)($_POST['action']??'');
 if($action==='upload'){marketing_media_upload($_FILES['asset']??[],(int)($_POST['item_id']??0),(string)($_POST['channel_key']??''));$message='Media uploaded.';}
 elseif($action==='attach'){marketing_media_attach((int)$_POST['media_id'],(int)$_POST['item_id'],(string)($_POST['channel_key']??''));$message='Media attached to assignment.';}
 elseif($action==='delete'){marketing_media_delete((int)$_POST['media_id']);$message='Moved to Recently removed. Existing files and evidence are retained.';}
 elseif($action==='restore'){marketing_require_owner();db()->prepare('UPDATE marketing_item_versions SET deleted_at=NULL WHERE id=?')->execute([(int)$_POST['media_id']]);$message='Media restored.';}
 else throw new RuntimeException('Choose a valid media action.');
 header('Location: media.php?message='.rawurlencode($message));exit;
}}catch(Throwable $e){$message=$e->getMessage();}
if(!$message)$message=(string)($_GET['message']??'');
$type=(string)($_GET['type']??'all');if(!in_array($type,['all','images','videos','audio'],true))$type='all';
$search=trim((string)($_GET['q']??''));$sort=($_GET['sort']??'newest')==='oldest'?'ASC':'DESC';$trash=$owner&&($_GET['trash']??'')==='1';
$params=[];$scope=marketing_media_scope($params);$where='v.deleted_at IS '.($trash?'NOT ':'').'NULL AND '.$scope;
if($search!==''){$where.=' AND v.file_name LIKE ?';$params[]='%'.$search.'%';}
if($type!=='all'){$where.=' AND v.mime_type LIKE ?';$params[]=['images'=>'image/%','videos'=>'video/%','audio'=>'audio/%'][$type];}
$q=db()->prepare("SELECT v.* FROM marketing_item_versions v WHERE $where ORDER BY v.uploaded_at $sort,v.id $sort");$q->execute($params);$files=$q->fetchAll();
$params=[];$scope=marketing_scope_sql($params);$q=db()->prepare("SELECT id,title FROM marketing_work_items WHERE $scope AND cancelled_at IS NULL ORDER BY id DESC");$q->execute($params);$assignments=$q->fetchAll();$titles=array_column($assignments,'title','id');
$isEssDashboard=true;$activeApp='marketing';$pageTitle='Marketing Media | '.APP_NAME;$extraStylesheets=[];foreach(['marketing','marketing-extras','marketing-workspace','ess-dashboard']as$css)$extraStylesheets[]=['path'=>'assets/css/'.$css.'.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/'.$css.'.css')];
require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Marketing';$pageUsesPortalSidebar=false;include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main marketing-workspace ess-marketing-page hb-marketing">
<a class="mk-button" href="index.php?view=apps">Marketing Apps</a><header class="mk-work-header"><p class="mk-eyebrow">MARKETING</p><h1>Media</h1><p>Store and organise marketing images, videos and audio.</p></header>
<?php if($message):?><p class="mk-notice" role="status"><?=marketing_e($message)?></p><?php endif;?>
<section class="mk-detail-section"><h2>Upload Media</h2><form method="post" enctype="multipart/form-data" class="mk-form-row"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="upload"><label>File (maximum 30 MB)<input type="file" name="asset" required accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mp3,.m4a,.wav,.pdf,.txt,.docx,.xlsx,.pptx"></label><label>Attach to assignment<select name="item_id"><option value="0">My Media — attach later</option><?php foreach($assignments as$a):?><option value="<?=$a['id']?>"><?=marketing_e($a['title'])?></option><?php endforeach;?></select></label><button class="mk-primary">Upload Media</button></form><p class="mk-meta">Private files: links require an authorised portal session. Uploading does not complete or approve an assignment.</p></section>
<nav class="mk-tabs" aria-label="Media type"><?php foreach(['all'=>'All Media','images'=>'Images','videos'=>'Videos','audio'=>'Audio']as$key=>$label):?><a class="mk-button" href="?<?=marketing_e(http_build_query(['type'=>$key,'q'=>$search,'sort'=>strtolower($sort)==='asc'?'oldest':'newest','trash'=>$trash?'1':'0']))?>" <?=$type===$key?'aria-current="page"':''?>><?=$label?></a><?php endforeach;?><?php if($owner):?><a class="mk-button" href="?trash=<?=$trash?'0':'1'?>"><?=$trash?'Back to library':'Recently removed'?></a><?php endif;?></nav>
<form class="mk-media-toolbar" method="get"><input type="hidden" name="type" value="<?=marketing_e($type)?>"><input type="hidden" name="trash" value="<?=$trash?'1':'0'?>"><label>Search files<input type="search" name="q" value="<?=marketing_e($search)?>" placeholder="Filename"></label><label>Sort<select name="sort"><option value="newest">Newest first</option><option value="oldest" <?=$sort==='ASC'?'selected':''?>>Oldest first</option></select></label><button>Search</button><span><?=count($files)?> files</span></form>
<div class="mk-media-grid <?=$type==='audio'?'is-audio':''?>">
<?php foreach($files as$file):$url='file.php?id='.(int)$file['id'];$associated=[];if(isset($titles[$file['item_id']]))$associated[]=$titles[$file['item_id']];$links=db()->prepare('SELECT item_id FROM marketing_media_links WHERE version_id=?');$links->execute([$file['id']]);foreach($links->fetchAll()as$link)if(isset($titles[$link['item_id']]))$associated[]=$titles[$link['item_id']];?>
<article class="mk-media-card"><?php if(!$trash):?><div class="mk-media-preview"><?php marketing_media_player($file);?></div><?php endif;?><div class="mk-media-body"><h3><?=marketing_e($file['file_name'])?></h3><p><?=marketing_e($file['uploaded_by_name'])?> · <?=marketing_e(marketing_display_time($file['uploaded_at']))?></p><p><?=number_format((int)$file['file_size']/1024/1024,2)?> MB · <?=marketing_e($file['version_label'])?></p><p><?=marketing_e($associated?implode(' · ',array_unique($associated)):'Not attached')?></p>
<?php if(!$trash):?><div class="mk-media-actions"><?php if(marketing_media_type($file['mime_type'])!=='documents'):?><button type="button" data-preview-media>Preview</button><?php endif;?><a class="mk-button" href="<?=marketing_e($url)?>">Download</a><button type="button" data-copy-media="<?=marketing_e($url)?>">Copy link</button></div>
<details><summary>Attach to Assignment</summary><form method="post"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="attach"><input type="hidden" name="media_id" value="<?=$file['id']?>"><label>Assignment<select name="item_id" required><option value="">Choose assignment</option><?php foreach($assignments as$a):?><option value="<?=$a['id']?>"><?=marketing_e($a['title'])?></option><?php endforeach;?></select></label><button>Attach file</button></form></details><?php endif;?>
<?php if($owner):?><form method="post"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="action" value="<?=$trash?'restore':'delete'?>"><input type="hidden" name="media_id" value="<?=$file['id']?>"><button><?=$trash?'Restore':'Remove from library'?></button></form><?php endif;?></div></article>
<?php endforeach;?></div><?php if(!$files):?><p class="mk-notice">No media matches this view.</p><?php endif;?>
<dialog id="mk-media-viewer" class="mk-media-viewer"><header><h2 data-preview-title>Media preview</h2><button type="button" data-close-preview>Close</button></header><div data-preview-body></div></dialog>
</main><script defer src="<?=BASE_URL?>/assets/js/marketing-workspace.js?v=<?=filemtime(BASE_PATH.'/assets/js/marketing-workspace.js')?>"></script><?php include BASE_PATH.'/shared/ess-mobile-navigation.php';include BASE_PATH.'/shared/footer.php';?>
