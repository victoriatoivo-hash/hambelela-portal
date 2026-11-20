<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
try{$deliveryActor=$partnerAuth->actor($_SESSION['partner']);if(($deliveryActor['role']??'')!=='partner_admin')throw new DomainException();}catch(Throwable $e){http_response_code(403);exit('Administrator access required.');}
$access=new \Hambelela\Delivery\PartnerAccess(db());
try{$deliveryActor['can_issue_admin_password']=$access->canIssueAdminPassword($deliveryActor);}catch(Throwable $e){$deliveryActor['can_issue_admin_password']=false;error_log('Tedlaser password delegation unavailable: '.get_class($e));}
require_once BASE_PATH.'/shared/delivery/PartnerShell.php';\Hambelela\Delivery\PartnerShell::begin($deliveryActor);
if(isset($_GET['admin-access'])){
 if(!$deliveryActor['can_issue_admin_password']){http_response_code(403);exit('Toivo sign-in required.');}
 $secret=null;$error='';$_SESSION['admin_password_form']??=bin2hex(random_bytes(32));
 if($_SERVER['REQUEST_METHOD']==='POST')try{
  \Hambelela\Delivery\PartnerAuth::csrf((string)($_POST['csrf']??''));
  if(!hash_equals($_SESSION['admin_password_form'],(string)($_POST['request']??'')))throw new DomainException('This request has already been used. Nothing was generated.');
  $_SESSION['admin_password_form']=bin2hex(random_bytes(32));
  $secret=$access->issueAdminPassword($deliveryActor,(string)($_POST['credential']??''),(string)($_SERVER['REMOTE_ADDR']??''));
 }catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error='Unable to confirm issuance. Check with the Hambelela Owner before trying again.';}
 ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tedlaser Admin access</title></head><body><main class="delivery-main"><header class="pagehead"><div class="eyebrow">TEDLASER · ACCOUNT ACCESS</div><h1>Admin login password</h1></header><section class="panel">
 <?php if($secret!==null):?><h2>Temporary password for Admin</h2><p>Shown only now. Share privately with the person who logs deliveries. It works once and expires in 24 hours.</p><label>Temporary password<input readonly autocomplete="off" value="<?=htmlspecialchars($secret,ENT_QUOTES)?>"></label><p>Ask them to open <a href="login.php?mode=setup">Set up my PIN</a>, select <strong>Admin</strong> and enter this temporary password to choose their own PIN.</p>
 <?php else:?><p>Generate a one-time password for the <strong>Admin</strong> delivery-entry account. This signs Admin out, replaces any earlier temporary password and requires a new private PIN. Your own sign-in stays the same.</p>
 <?php if($error!==''):?><p class="form-error" role="alert"><?=htmlspecialchars($error,ENT_QUOTES)?></p><?php endif;?>
 <form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>"><input type="hidden" name="request" value="<?=htmlspecialchars($_SESSION['admin_password_form'],ENT_QUOTES)?>"><label>Confirm your own Toivo PIN or password<input type="password" name="credential" autocomplete="current-password" maxlength="128" required></label><button class="primary">Generate temporary password for Admin</button></form><?php endif;?></section></main></body></html><?php return;
}
require BASE_PATH.'/shared/delivery/WorkspaceView.php';
