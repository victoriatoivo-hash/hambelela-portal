<?php
if(!defined('BASE_PATH')||!isset($deliveryActor))exit;
require_once __DIR__.'/DriverErrands.php';
if(!$driverPage&&!\Hambelela\Delivery\DeliveryPolicy::can($deliveryActor,'delivery_arrange')){http_response_code(403);exit('Delivery arrangement access required.');}
$errands=new \Hambelela\Delivery\DriverErrands(db());
$_SESSION['delivery_errand_csrf']??=bin2hex(random_bytes(32));
$token=$_SESSION['delivery_errand_csrf'];$notice=$_GET['errands']==='notice';
$url=BASE_URL.'/apps/delivery/index.php?errands=1';$error='';$fields=[];$key=bin2hex(random_bytes(16));
function errand_h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if($notice)header('Content-Type: application/json; charset=utf-8');
try{
 $errands->install();
 if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new DomainException('Session expired. Reload before trying again.');
    $action=$_POST['action']??'';
    if($action==='send'&&!$driverPage&&!$notice){$fields=$_POST;$key=(string)($_POST['request_key']??'');$sent=$errands->send($deliveryActor,$fields);$_SESSION['errand_flash']='Errand #'.$sent.' sent to the driver.';}
    elseif($action==='collected'&&$driverPage&&!$notice){$errands->mark($deliveryActor,(int)($_POST['id']??0),true);$_SESSION['errand_flash']='Errand marked collected.';}
    elseif($action==='seen'&&$driverPage&&$notice){$errands->mark($deliveryActor,(int)($_POST['id']??0),false);echo '{"ok":true}';return;}
    else throw new DomainException('Action not permitted.');
    header('Location: '.$url,true,303);return;
 }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);return;}
 elseif($notice){echo json_encode(['errand'=>$errands->next($deliveryActor)],JSON_THROW_ON_ERROR);return;}
}catch(DomainException $e){$error=$e->getMessage();if($notice){http_response_code(403);echo '{"error":"Not permitted"}';return;}}
catch(Throwable $e){error_log('Driver errands unavailable: '.get_class($e));http_response_code(503);echo $notice?'{"error":"Unavailable"}':'Errands are temporarily unavailable. Reopen the list to check saved errands before retrying.';return;}
$done=($_GET['show']??'')==='collected';$page=max(0,min(100000,(int)($_GET['page']??0)));
$entries=$errands->entries($deliveryActor,$done,$page);$more=count($entries)>20;$drivers=$driverPage?[]:$errands->drivers($deliveryActor);
$flash=$_SESSION['errand_flash']??'';unset($_SESSION['errand_flash']);
require_once __DIR__.'/PortalShell.php';\Hambelela\Delivery\PortalShell::begin(true,true);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Driver errands</title></head><body data-delivery-operations><main class="delivery-main">
<header class="pagehead"><div><div class="eyebrow">OPERATIONS</div><h1><?=$driverPage?'My errands':'Driver errands'?></h1><p>Pickups and collections that aren’t customer orders.</p></div><div class="actions"><?php if($driverPage):?><a class="secondary" href="index.php">My deliveries</a><?php else:?><a class="secondary" href="<?=errand_h(BASE_URL)?>/apps/operations/orders-board.php">Order Board</a><?php endif;?></div></header>
<?php if($driverPage):?><script defer src="<?=errand_h(BASE_URL)?>/assets/js/driver-errands.js?v=1" data-errand-notices data-url="<?=errand_h(BASE_URL)?>/apps/delivery/index.php?errands=notice" data-csrf="<?=errand_h($token)?>"></script><?php endif;?>
<?php if($flash):?><p role="status"><?=errand_h($flash)?></p><?php endif;?>
<?php if($error):?><p class="form-error" role="alert"><?=errand_h($error)?></p><?php endif;?>
<?php if(!$driverPage):?><section class="panel"><h2>Log an errand</h2><form method="post" action="<?=errand_h($url)?>">
<input type="hidden" name="csrf" value="<?=errand_h($token)?>"><input type="hidden" name="request_key" value="<?=errand_h($key)?>"><input type="hidden" name="action" value="send">
<label>Pickup place or address<input required maxlength="255" name="place" value="<?=errand_h($fields['place']??'')?>"></label>
<label>Contact person<input required maxlength="190" name="contact_name" value="<?=errand_h($fields['contact_name']??'')?>"></label>
<label>What needs to be collected?<textarea required maxlength="1500" name="contents"><?=errand_h($fields['contents']??'')?></textarea></label>
<label>Payment status<select required name="payment_state"><option value="">Select payment status</option><?php foreach(['paid'=>'Paid','unpaid'=>'Not paid'] as $value=>$label):?><option value="<?=$value?>" <?=($fields['payment_state']??'')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
<label>Driver<select required name="driver_id"><option value="">Choose driver</option><?php foreach($drivers as $d):?><option value="<?=(int)$d['id']?>" <?=(int)($fields['driver_id']??18)===(int)$d['id']?'selected':''?>><?=errand_h($d['full_name'])?></option><?php endforeach;?></select></label>
<button class="primary" <?=$drivers?'':'disabled'?>>Send errand to driver</button><?php if(!$drivers):?><p>No active drivers available.</p><?php endif;?></form></section><?php endif;?>
<section class="panel"><h2><?=$done?'Collected errands':'Open errands'?></h2><nav class="actions" aria-label="Errand status"><a class="secondary" href="<?=errand_h($url)?>">Open</a><a class="secondary" href="<?=errand_h($url)?>&amp;show=collected">Collected</a></nav>
<?php if(!$entries):?><p>No <?=$done?'collected':'open'?> errands.</p><?php endif;?>
<?php foreach(array_slice($entries,0,20) as $j):?><article class="card"><div class="eyebrow">Errand #<?=(int)$j['id']?> · <?=errand_h($j['driver_name'])?></div><h3><?=errand_h($j['place'])?></h3><p>Contact: <?=errand_h($j['contact_name'])?></p><p>Collect: <?=nl2br(errand_h($j['contents']))?></p><p>Payment: <strong><?=$j['payment_state']==='paid'?'Paid':'Not paid'?></strong> · <?=$j['collected_at']?'Collected':($j['seen_at']?'Seen by driver':'Sent to driver')?></p>
<?php if($driverPage&&!$done):?><form method="post" action="<?=errand_h($url)?>"><input type="hidden" name="csrf" value="<?=errand_h($token)?>"><input type="hidden" name="id" value="<?=(int)$j['id']?>"><button class="secondary" name="action" value="collected">Mark collected</button></form><?php endif;?></article><?php endforeach;?>
<nav class="actions" aria-label="Errand pages"><?php foreach(['Previous'=>$page-1,'Next'=>$page+1] as $label=>$p):if($p<0||($p>$page&&!$more))continue;?><a href="<?=errand_h($url)?>&amp;show=<?=$done?'collected':'open'?>&amp;page=<?=$p?>"><?=$label?></a><?php endforeach;?></nav></section></main></body></html>
