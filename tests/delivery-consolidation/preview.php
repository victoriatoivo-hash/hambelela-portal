<?php
// Loopback-only synthetic UI fixture. No production config, database, session or writes.
if(PHP_SAPI!=='cli-server'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:51843'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');
header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('#^/assets/(css|js|fonts)/[a-z0-9-]+\.(css|js|woff2|ttf)$#D',$path)&&is_file(BASE_PATH.$path)){
    header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','woff2'=>'font/woff2','ttf'=>'font/ttf'][pathinfo($path,PATHINFO_EXTENSION)]));readfile(BASE_PATH.$path);return;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('Synthetic preview is read-only.');}
require_once BASE_PATH.'/shared/delivery/OwnerNavigation.php';
require_once BASE_PATH.'/shared/ess-navigation.php';
function current_role_key(){return 'owner_admin';}
$base='';$now='2026-11-23 09:00:00';
$row=['id'=>16,'reference'=>'Order 123','customer'=>'Synthetic Customer','driver'=>'Synthetic Driver','driver_id'=>3,'status'=>'ready','payment'=>'Due N$ 20.00','payment_review'=>false,'proof'=>'Not recorded','proof_url'=>'/apps/delivery/delivery-details.php?delivery_id=16','workspace_url'=>'/apps/delivery/workspace.php?delivery=16','can_manage'=>true];
$ops=['generated_at'=>$now,'business_date'=>'2026-11-23','summary'=>['active_deliveries'=>1,'drivers_total'=>1,'issues_today'=>1,'issues_scope'=>'Synthetic fixture','cash'=>['available'=>true,'recorded_unconfirmed_minor'=>17000,'declarations_minor'=>0]],'drivers'=>[['id'=>3,'name'=>'Synthetic Driver','code'=>'TEST-3','active'=>1]],'urgent_issues'=>[['id'=>16,'reference'=>'Order 123','driver'=>'Synthetic Driver','reason'=>'Address needs confirmation','urgent'=>false,'at'=>$now,'url'=>'/apps/delivery/delivery-details.php?delivery_id=16']],'deliveries'=>['rows'=>[$row],'page'=>1,'page_size'=>5,'total'=>1,'counts'=>['all'=>1,'onroute'=>0,'delivered'=>0,'issues'=>1]],'selected_route'=>null];
$summary=['driver'=>['app_open'=>null],'assignment'=>['assigned'=>1,'delivered'=>0],'next'=>['id'=>16,'orderNumber'=>'Order 123','destinationArea'=>'Sample area','customerDisplayName'=>'Synthetic Customer','deliveryWindow'=>'Not recorded','isNextStop'=>false],'proof'=>null,'cash'=>['amount'=>170,'meaning'=>'Synthetic declaration, not a live balance'],'notifications'=>[['unread'=>true,'title'=>'Synthetic assignment','description'=>'Layout fixture only','time'=>'09:00']],'activities'=>[['created_at'=>$now,'event_type'=>'assigned']],'as_of'=>$now];
if(strpos($path,'/apps/operations/')===0){$map=['driver-operations-dashboard.php'=>'driver-operations.php','driver-delivery-details.php'=>'delivery-details.php','view-as-driver.php'=>'view-as-driver.php'];if(isset($map[basename($path)])){\Hambelela\Delivery\OwnerNavigation::redirectLegacy($map[basename($path)]);return;}}
if(isset($_GET['data'])){header('Content-Type: application/json');echo json_encode(['ok'=>true,'data'=>$ops]);return;}
if(isset($_GET['mode'])){
    $mode=$_GET['mode'];$value=[];
    if($mode==='drivers')$value=[['id'=>3,'full_name'=>'Synthetic Driver']];
    if($mode==='open')$value=['cap'=>'synthetic-capability','summary'=>$summary];
    if($mode==='summary')$value=$summary;
    if($mode==='phone')$value=['html'=>'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Inter,sans-serif;background:#f8f7f2;padding:18px;color:#344126"><h2>Synthetic driver preview</h2><p>Layout fixture only.</p><p>The live page uses the existing authenticated driver screens in read-only mode.</p></body></html>'];
    header('Content-Type: application/json');echo json_encode(['ok'=>true,'data'=>$value]);return;
}
$page=basename($path);$kind=$page==='delivery-details.php'?'proof-viewer':($page==='view-as-driver.php'?'view-as-driver':'operations');
$essActiveModule='Delivery';$essShellApps=[['name'=>'Operations','href'=>'/apps/operations/index.php','icon'=>'clipboard-check'],['name'=>'Delivery','href'=>'/apps/delivery/driver-operations.php','icon'=>'bike']];$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';$essOwnerOperationsSearch=$kind==='operations';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery design review — synthetic data</title>
<?php foreach(\Hambelela\Delivery\OwnerNavigation::styles($kind) as $style):?><link rel="stylesheet" href="/<?=$style['path']?>"><?php endforeach;?>
<style>.review-label{font:400 11px Jost,sans-serif;background:#fff1d8;padding:8px 12px;border-radius:8px;margin-bottom:14px}.ess-account-slot:after{content:'Synthetic Owner · Layout review';font:400 12px Jost,sans-serif}.ess-topbar{min-height:68px}@media(max-width:700px){.ess-topbar{flex-wrap:wrap}.ess-topbar-actions{flex-basis:100%;flex-wrap:wrap}.ess-account-slot{display:none}}</style></head><body class="ess-dashboard"><div class="shell ess-dashboard-shell">
<?php require BASE_PATH.'/shared/ess-sidebar.php';?><main id="ess-main" class="workspace ess-dashboard-main"><?php require BASE_PATH.'/shared/ess-topbar.php';?><p class="review-label">SYNTHETIC LAYOUT REVIEW · No live customer or financial data</p>
<?php
if($kind==='operations')require BASE_PATH.'/shared/delivery/OwnerOperationsContent.php';
elseif($kind==='view-as-driver')require BASE_PATH.'/shared/delivery/OwnerDriverViewContent.php';
else{
    require_once BASE_PATH.'/shared/delivery/OwnerProofViewer.php';
    $viewer=new stdClass();$id=16;$endpoint='/apps/delivery/delivery-details.php?delivery_id=16';$_SESSION=['delivery_front_csrf'=>'synthetic-only'];
    $data=['job'=>['id'=>16,'status'=>'ready','delivered_at'=>null,'customer_name'=>'Synthetic Customer','customer_mobile'=>'','source'=>'hambelela','order_id'=>123,'driver_employee_id'=>3,'area'=>'Sample area'],'order'=>['total_amount'=>20,'payment_method'=>'Cash','payment_status'=>'Unpaid'],'driver'=>['full_name'=>'Synthetic Driver'],'proof'=>['files'=>[],'receipts'=>[],'warnings'=>[]],'open'=>[],'payment'=>['warning'=>null,'due'=>2000,'allocations'=>[],'collections'=>[]],'review'=>null,'version'=>'synthetic-version','address'=>'Address not recorded','phone'=>'','reference'=>'Order 123','ready'=>true,'navigable'=>false,'items'=>[['product_name'=>'Synthetic product — 500ml','quantity'=>1,'line_total'=>20]],'events'=>[['id'=>1,'event_type'=>'assigned','actor_name'=>'Synthetic Owner','created_at'=>$now,'metadata_json'=>'{}']],'declarations'=>[],'actions'=>[]];
    require BASE_PATH.'/shared/delivery/OwnerProofContent.php';
}
?></main><?php require BASE_PATH.'/shared/ess-mobile-navigation.php';?></div><script src="/assets/js/ess-dashboard.js"></script></body></html>
