<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/BackCapture.php';
require_once BASE_PATH.'/shared/woocommerce.php';
header('Cache-Control: no-store');
try{
 $actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));
 $service=new \Hambelela\Delivery\BackCapture(db(),static function($path){return wc_get($path,[],8);});$service->owner($actor);
 $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));
 if(isset($_GET['api'])){
  header('Content-Type: application/json');
  if($_SERVER['REQUEST_METHOD']==='POST'){
   \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
   $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
   echo json_encode($service->save($actor,$body),JSON_THROW_ON_ERROR);
  }elseif($_SERVER['REQUEST_METHOD']==='GET')echo json_encode($service->candidates($actor,$_GET)+['csrf'=>$_SESSION['delivery_front_csrf']],JSON_THROW_ON_ERROR);
  else http_response_code(405);
  exit;
 }
}catch(Throwable $e){http_response_code($e instanceof DomainException?403:503);if(isset($_GET['api'])){header('Content-Type: application/json');echo json_encode(['error'=>$e instanceof DomainException?$e->getMessage():'Historical order data unavailable. Nothing was saved.']);}else echo 'Owner access required.';exit;}
require_once BASE_PATH.'/shared/delivery/PortalShell.php';
\Hambelela\Delivery\PortalShell::begin(true,true);
?><!doctype html><html><head><title>Back-Capture Deliveries · Hambelela</title></head><body data-delivery-accounting data-delivery-operations><main class="delivery-main">
<style>#back-orders input[type=checkbox]{width:18px;height:18px;min-height:18px;padding:0;accent-color:#59694A}#back-selected fieldset{border:1px solid #E4E6DC;border-radius:10px;padding:14px;margin:16px 0}#back-selected legend{color:#465636;font-weight:500}#history-filter{flex-wrap:wrap}#history-filter label{flex:1 1 160px}#back-preview form>header{display:flex;justify-content:space-between;gap:12px}#back-orders td{padding:10px;border-bottom:1px solid #E4E6DC}</style>
<header class="pagehead"><div><div class="eyebrow">DELIVERY / FRONT OPERATIONS</div><h1>Back-Capture Deliveries</h1><p>Confirm only deliveries actually performed. All dates and times below are Namibia time.</p></div><a class="secondary" href="workspace.php">Front Operations</a></header>
<form id="history-filter" class="actions"><label>Mode<select aria-label="Mode" disabled><option selected>Delivery</option></select></label><label>Order date from<input type="datetime-local" name="from" value="2026-10-06T00:00" required></label><label>Order date through<input type="datetime-local" name="to" value="2026-10-08T23:59" required></label><label>Order or customer<input type="search" name="q" placeholder="Search orders"></label><button class="primary">Find historical orders</button></form>
<p>Mode = Delivery, using the Live Orders List classification and displayed order date (operational date, falling back to creation date). Archived and deleted orders are excluded, as on the Live Orders List. All payment and order statuses are included. POS completion is supporting information only and may fall outside this range or be missing. Recorded completion and existing payments are populated automatically where available. Missing completion dates still require evidence.</p>
<p id="back-message" role="status"></p><section id="back-orders"></section>
<div class="actions"><button type="button" class="secondary" id="back-prev">Previous</button><span id="back-page"></span><button type="button" class="secondary" id="back-next">Next</button><button type="button" class="primary" id="back-review">Back-Capture as Completed</button></div>
<dialog id="back-preview" class="delivery-details-drawer"><form id="back-confirm"><header><h2>Review historical deliveries</h2><button type="button" class="secondary" id="back-close">Close</button></header><label>Driver who actually delivered<select name="driver" required><option value="">Choose Driver employee</option></select></label><p>Original POS payment dates are used when available. Otherwise, using recorded history confirms funds were received by the recorded completion time; the audit preserves this distinction. Cash fields are audit declarations, not new payments. Unrecorded cash must be reconciled through the existing payment workflow. No GPS, arrival time, journey duration or punctuality score will be created.</p><div id="back-selected"></div><label><input name="confirmed" type="checkbox" required> I confirm these deliveries were completed by the selected Driver at the times shown.</label><p id="back-error" role="alert"></p><button class="primary" id="back-save">Save confirmed historical deliveries</button></form></dialog>
<script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/delivery-back-capture.js?v=<?=filemtime(BASE_PATH.'/assets/js/delivery-back-capture.js')?>"></script>
</main></body></html>
