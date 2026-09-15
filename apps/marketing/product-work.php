<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/marketing.php';
marketing_require_access();
$owner=marketing_is_owner();$message='';
$id=(int)($_GET['id']??$_POST['id']??0);
$params=[$id];$scope='';
if(!$owner){$scope=' AND assigned_employee_id=?';$params[]=marketing_employee_id();}
$load=static function()use($params,$scope):array{
 $q=db()->prepare('SELECT * FROM marketing_product_changes WHERE id=?'.$scope);$q->execute($params);$row=$q->fetch();
 if(!$row){http_response_code(404);exit('Assigned website work not found.');}return $row;
};
$item=$load();
$fields=['proposed_name'=>'Product name','proposed_short_description'=>'Short description','proposed_description'=>'Description','proposed_seo_title'=>'SEO title','proposed_meta_description'=>'Meta description'];
$editable=in_array($item['status'],['draft','changes_requested'],true);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  marketing_verify();
  if(!$editable)throw new RuntimeException('This draft is awaiting review or has already been published.');
  $status=($_POST['submit_review']??'')==='1'?'submitted':$item['status'];
  $values=[];foreach($fields as$key=>$label)$values[]=trim((string)($_POST[$key]??$item[$key]));
  $values[]=$status;$values[]=$status;
  $stmt=db()->prepare('UPDATE marketing_product_changes SET proposed_name=?,proposed_short_description=?,proposed_description=?,proposed_seo_title=?,proposed_meta_description=?,status=?,submitted_at=CASE WHEN ?="submitted" THEN CURRENT_TIMESTAMP ELSE submitted_at END WHERE id=?'.$scope.' AND status IN ("draft","changes_requested")');
  $stmt->execute([...$values,...$params]);
  $message=$status==='submitted'?'Sent to the owner for review. No live website changes were made.':'Prepared copy saved.';
  $item=$load();$editable=in_array($item['status'],['draft','changes_requested'],true);
 }catch(Throwable $e){$message=$e->getMessage();}
}
$isEssDashboard=true;$pageUsesPortalSidebar=false;$activeApp='marketing';$pageTitle='Assigned Website Work | '.APP_NAME;
$extraStylesheets=[];foreach(['marketing','marketing-extras','ess-dashboard','marketing-execution']as$css)$extraStylesheets[]=['path'=>'assets/css/'.$css.'.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/'.$css.'.css')];
require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Marketing';
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main marketing-workspace ess-marketing-page marketing-execution-workspace">
<nav class="marketing-local-nav"><a href="index.php?view=website">Back to Website &amp; Products</a></nav>
<section class="marketing-panel"><h1><?=marketing_e($item['product_name'])?></h1><p class="marketing-instruction-copy"><?=marketing_e($item['change_notes']?:'Prepare the assigned website copy.')?></p><span class="marketing-status"><?=marketing_e(str_replace('_',' ',$item['status']))?></span></section>
<?php if($message):?><p class="marketing-notice" role="status"><?=marketing_e($message)?></p><?php endif;?>
<form method="post" class="marketing-panel"><input type="hidden" name="csrf" value="<?=marketing_csrf()?>"><input type="hidden" name="id" value="<?=$id?>">
<?php foreach($fields as$key=>$label):?><label><?=marketing_e($label)?><textarea name="<?=$key?>" <?=$editable?'':'readonly'?>><?=marketing_e((string)($item[$key]??''))?></textarea></label><?php endforeach;?>
<?php if($editable):?><button class="marketing-btn-secondary">Save prepared copy</button><button class="marketing-btn-primary" name="submit_review" value="1">Ready for owner review</button><?php endif;?>
<p>Only the owner can approve and publish these product changes to the shop.</p></form></main>
<?php include BASE_PATH.'/shared/ess-mobile-navigation.php';?><script defer src="<?=BASE_URL?>/assets/js/ess-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/ess-dashboard.js')?>"></script><?php include BASE_PATH.'/shared/footer.php';?>
