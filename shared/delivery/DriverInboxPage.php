<?php
if(!defined('BASE_PATH'))exit;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/DriverInbox.php';
require_once __DIR__.'/ReleaseFlags.php';
if(!DELIVERY_ENABLED){http_response_code(503);exit('Delivery updates unavailable.');}
header('Cache-Control: private, no-store');
try{$actor=\Hambelela\Delivery\EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));}catch(Throwable $e){http_response_code(403);exit('Active driver required.');}
if(isset($_GET['driver_feed'])){
 header('Content-Type: application/json; charset=utf-8');
 try{
  if($_SERVER['REQUEST_METHOD']==='POST'){
    \Hambelela\Delivery\EmployeeDriverSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
    if((int)($_POST['actor']??0)!==$actor['id'])throw new DomainException('Account changed. Reload.');
    $action=$_POST['action']??'';if(!in_array($action,['read','read_all'],true))throw new DomainException('Unknown action.');
    \Hambelela\Delivery\DriverInbox::mark(db(),$actor,(int)($_POST['id']??0),$action==='read_all');echo '{"ok":true}';
  }elseif($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(\Hambelela\Delivery\DriverInbox::feed(db(),$actor,(int)($_GET['before']??0),(string)($_GET['tab']??'all')),JSON_THROW_ON_ERROR);}
  else{http_response_code(405);echo '{"error":"Method not allowed"}';}
 }catch(DomainException $e){http_response_code(403);echo '{"error":"Not authorised. Reload and retry."}';}catch(Throwable $e){error_log('Driver inbox unavailable: '.get_class($e));http_response_code(503);echo '{"error":"Updates unavailable. Please retry."}';}return;
}
$base=htmlspecialchars(BASE_URL,ENT_QUOTES);$asset=static function($p)use($base){return $base.'/'.$p.'?v='.filemtime(BASE_PATH.'/'.$p);};
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#194d30"><title>Notifications · Deliveries</title><link rel="stylesheet" href="<?=$asset('assets/css/driver-delivery-ui.css')?>"><link rel="stylesheet" href="<?=$asset('assets/css/driver-inbox.css')?>"></head>
<body class="driver-compact-app dn-app" data-base="<?=$base?>" data-inbox="<?=$base?>/notifications.php?driver_feed=1" data-api="<?=$base?>/apps/delivery/driver/api.php" data-actor="<?=(int)$actor['id']?>" data-csrf="<?=htmlspecialchars($_SESSION['csrf']??'',ENT_QUOTES)?>">
<main class="dn-main"><header class="dn-head"><a href="<?=$base?>/apps/delivery/index.php" aria-label="Back to deliveries">‹</a><h1>Notifications</h1><span data-dn-icon="bell" aria-hidden="true"></span></header>
<nav class="dn-tabs" aria-label="Filter notifications"><?php foreach(['All','Orders','Updates','Alerts'] as $tab):?><button type="button" data-tab="<?=strtolower($tab)?>" aria-pressed="<?=$tab==='All'?'true':'false'?>"><?=$tab?> <small>0</small></button><?php endforeach;?></nav>
<div class="dn-tools"><button type="button" data-mark disabled>Mark all as read</button></div><div data-status role="status">Loading delivery updates…</div><div data-list></div><button data-older hidden>Load older notifications</button><noscript>Enable JavaScript to view delivery updates.</noscript></main>
<script src="<?=$asset('assets/js/driver-delivery-ui.js')?>"></script><script src="<?=$asset('assets/js/driver-inbox.js')?>"></script></body></html>
