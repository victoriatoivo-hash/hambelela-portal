<?php
if(!defined('BASE_PATH')||!isset($deliveryActor))exit;
require_once __DIR__.'/DriverErrands.php';
require_once __DIR__.'/ErrandManagementAccess.php';
if(!$driverPage&&!\Hambelela\Delivery\ErrandManagementAccess::allowed($deliveryActor)){http_response_code(403);exit('Errand management access required.');}
$errands=new \Hambelela\Delivery\DriverErrands(db());
$_SESSION['delivery_errand_csrf']??=bin2hex(random_bytes(32));
$token=$_SESSION['delivery_errand_csrf'];$api=in_array($_GET['errands'],['notice','data'],true);
$url=BASE_URL.'/apps/delivery/index.php?errands=1';
function errand_h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if($api){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
 try{
  $errands->install();
  if($_SERVER['REQUEST_METHOD']==='POST'){
   if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new DomainException('Session expired. Save your draft before signing in again.');
   $action=(string)($_POST['action']??'');
   if($action==='send')$result=['id'=>$errands->send($deliveryActor,$_POST)];
   else $result=$errands->change($deliveryActor,(int)($_POST['id']??0),(int)($_POST['revision']??0),$action,$_POST);
   echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);return;
  }
  if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo '{"error":"Method not allowed"}';return;}
  if(isset($_GET['history'])){echo json_encode(['history'=>$errands->history($deliveryActor,(int)$_GET['history'])],JSON_THROW_ON_ERROR);return;}
  $response=['pending'=>$driverPage?$errands->countPending($deliveryActor):0,'errand'=>$driverPage?$errands->next($deliveryActor):null];
  if($_GET['errands']==='data'){
   $show=(string)($_GET['show']??'open');$page=max(0,min(100000,(int)($_GET['page']??0)));
   $rows=$errands->entries($deliveryActor,$show==='collected',$page,$show==='cancelled');
   $response+=['entries'=>array_slice($rows,0,20),'more'=>count($rows)>20];
  }
  echo json_encode($response,JSON_THROW_ON_ERROR);return;
 }catch(DomainException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);return;}
 catch(Throwable $e){error_log('Driver errands unavailable: '.get_class($e));http_response_code(503);echo '{"error":"Errands are temporarily unavailable. Your draft is kept; check the list before retrying a send."}';return;}
}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
try{$errands->install();$drivers=$driverPage?[]:$errands->drivers($deliveryActor);}
catch(Throwable $e){http_response_code(503);exit('Errands are temporarily unavailable. Please try again shortly.');}
require_once __DIR__.'/PortalShell.php';\Hambelela\Delivery\PortalShell::begin(true,true);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Driver errands</title></head><body data-delivery-operations><main class="delivery-main">
<link rel="stylesheet" href="<?=errand_h(BASE_URL)?>/assets/css/driver-errands.css?v=<?=filemtime(BASE_PATH.'/assets/css/driver-errands.css')?>">
<div class="errands-app" data-errands-app data-driver="<?=$driverPage?'1':'0'?>">
<header class="pagehead"><div><div class="eyebrow">OPERATIONS · COLLECTIONS</div><h1><?=$driverPage?'My errands':'Driver errands'?></h1><p>Pickups and collections that aren’t customer orders.</p></div><div class="actions"><a class="secondary" href="<?=errand_h(BASE_URL)?>/apps/<?=$driverPage?'delivery/index.php':'operations/orders-board.php'?>"><?=$driverPage?'My deliveries':'Order Board'?></a></div></header>
<?php if(!$driverPage):?><section class="panel errand-compose"><h2>Log an errand</h2><p class="errand-muted">The assigned driver receives the pickup and payment instructions.</p><form data-errand-send>
<input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(16))?>"><input type="hidden" name="action" value="send">
<div class="errand-grid">
<label>Pickup place or address<input required maxlength="255" name="place" autocomplete="off"></label>
<label>Contact person<input required maxlength="190" name="contact_name" autocomplete="off"></label>
<label class="errand-wide">What needs to be collected?<textarea required maxlength="1500" name="contents" rows="2"></textarea></label>
<label>Driver<select required name="driver_id"><option value="">Choose driver</option><?php foreach($drivers as $d):?><option value="<?=(int)$d['id']?>" <?=(int)$d['id']===18?'selected':''?>><?=errand_h($d['full_name'])?></option><?php endforeach;?></select></label>
<label>Payment status<select required name="payment_state"><option value="">Choose payment status</option><option value="paid">Paid</option><option value="unpaid">Not paid</option></select></label>
<label>Amount (N$), if known<input name="amount" inputmode="decimal" pattern="[0-9]{1,9}([.][0-9]{1,2})?" placeholder="Leave blank if unconfirmed"></label>
<label>Who pays / payment instructions<input name="payer" maxlength="190" placeholder="For example: front desk will pay supplier"></label>
</div><div class="errand-form-actions"><button class="primary" <?=$drivers?'':'disabled'?>>Send errand to driver</button><span data-send-status role="status"></span></div>
<?php if(!$drivers):?><p>No active drivers available.</p><?php endif;?></form></section><?php endif;?>
<section class="panel"><div class="errand-list-heading"><h2>Errand activity</h2><span class="errand-muted" data-refresh-status role="status">Connecting…</span></div>
<nav class="actions errand-tabs" aria-label="Errand status"><button type="button" class="secondary" data-show="open" aria-pressed="true">Open</button><button type="button" class="secondary" data-show="collected" aria-pressed="false">Collected</button><button type="button" class="secondary" data-show="cancelled" aria-pressed="false">Cancelled</button><button type="button" class="secondary" data-refresh>Refresh</button></nav>
<div data-errand-list></div><p data-errand-empty hidden>No errands in this view.</p><nav class="actions" aria-label="Errand pages"><button type="button" class="secondary" data-page="-1" disabled>Previous</button><span data-page-label>Page 1</span><button type="button" class="secondary" data-page="1" disabled>Next</button></nav></section>
</div><script defer src="<?=errand_h(BASE_URL)?>/assets/js/driver-errands.js?v=<?=filemtime(BASE_PATH.'/assets/js/driver-errands.js')?>" data-errand-notices data-url="<?=errand_h(BASE_URL)?>/apps/delivery/index.php?errands=data" data-csrf="<?=errand_h($token)?>" data-driver="<?=$driverPage?'1':'0'?>"></script>
</main></body></html>
