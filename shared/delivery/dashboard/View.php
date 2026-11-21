<?php
if(!defined('BASE_PATH'))exit;
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$paths=[
'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
'box'=>'<path d="m12 3 9 5v9l-9 5-9-5V8zM3 8l9 5 9-5M12 13v9M7.5 5.5l9 5"/>',
'bag'=>'<rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/>',
'card'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/>',
'chat'=>'<path d="M21 11.5a9 9 0 0 1-9 9 10 10 0 0 1-4-.8L3 21l1.3-5a9 9 0 1 1 16.7-4.5z"/>',
'home'=>'<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3z"/>',
'map'=>'<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16"/>',
'arrow'=>'<path d="m4 11 16-7-7 16-2-7z"/>',
'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
'pin'=>'<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0z"/><circle cx="12" cy="10" r="2"/>',
'chevron'=>'<path d="m9 5 7 7-7 7"/>',
'more'=>'<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
'star'=>'<path d="M12 2v20M2 12h20M5 5l14 14M5 19 19 5"/>',
'close'=>'<path d="m6 6 12 12M6 18 18 6"/>'];

$icon=static fn($key)=>'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'.$paths[$key].'</svg>';
$error=$dashboard['dataStatus']==='error';
$driver=$dashboard['driver']??['firstName'=>'Driver','initials'=>'','dutyState'=>'unknown'];
$next=$dashboard['nextDelivery']??null;
$p=$dashboard['progress']??null;$quick=$dashboard['quickActions']??[];
$percent=$p&&$p['assigned']?round(100*$p['delivered']/$p['assigned']):0;
$asset=static fn($path)=>$base.'/'.$path.'?v='.filemtime(BASE_PATH.'/'.$path);
$jobLink=$base.'/apps/delivery/index.php'.($next?'?delivery='.(int)$next['id']:'');
$clockNow=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#2e4a39"><title>Driver dashboard<?=$local?' · Local review':''?></title>
<link rel="preload" href="<?=$h($base)?>/assets/fonts/driver-inter.ttf" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="<?=$h($asset('assets/css/driver-dashboard.css'))?>"><link rel="stylesheet" href="<?=$h($asset('assets/css/driver-dashboard-integration.css'))?>">
</head><body class="ho-driver-page"><main class="ho-driver-dashboard" data-driver-dashboard data-driver-id="<?=$h($driver['id']??'')?>" data-preview="<?=$local?'1':'0'?>" data-presence="<?=$h($base)?>/apps/operations/portal-presence.php" data-csrf="<?=$h($_SESSION['csrf']??'')?>">
<div class="ho-driver-dashboard__scroll">
<p class="ho-driver-dashboard__offline" data-hd-network hidden role="status">Offline · showing the last loaded information</p>
<header class="ho-driver-dashboard__hero">
<svg class="ho-driver-dashboard__logo-filter" width="0" height="0" aria-hidden="true"><defs><filter id="hd-white-logo" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 -0.2126 -0.7152 -0.0722 0 1"/><feComposite in2="SourceAlpha" operator="in"/></filter></defs></svg>
<div class="ho-driver-dashboard__topline"><img class="ho-driver-dashboard__logo" src="<?=$h($base)?>/apps/hr-portal/assets/letter/hambelela-logo.jpg" alt="Hambelela Organic" width="787" height="289"><div class="ho-driver-dashboard__header-tools">
<span class="ho-driver-dashboard__duty" data-state="unknown" data-app-activity>App activity · checking…</span>
<div class="ho-driver-dashboard__head-actions"><div class="ho-driver-dashboard__clock" aria-label="Namibia date and time"><time class="ho-driver-dashboard__date" data-hd-date datetime="<?=$clockNow->format('Y-m-d')?>"><?=$h($clockNow->format('l j F'))?></time><time class="ho-driver-dashboard__time" data-hd-time datetime="<?=$clockNow->format('c')?>"><?=$clockNow->format('g:i:s A')?></time></div>
<a class="ho-driver-dashboard__head-icon" href="<?=$h($base)?>/notifications.php" aria-label="Notifications<?=$error?'':', '.(int)$dashboard['unreadNotifications'].' unread'?>"><?=$icon('bell')?><?php if(!$error&&$dashboard['unreadNotifications']):?><span class="ho-driver-dashboard__unread"></span><?php endif;?></a>
<a class="ho-driver-dashboard__avatar" href="<?=$h($base)?>/apps/delivery/index.php?profile=1" aria-label="My account"><?=$h($driver['initials'])?></a></div></div></div>
<div class="ho-driver-dashboard__hello-row"><h1 class="ho-driver-dashboard__hello"><?=$h($dashboard['greeting']??'Welcome')?>, <?=$h($driver['firstName'])?>.</h1></div>
</header>
<div class="ho-driver-dashboard__body">
<?php if($error):?><section class="ho-driver-dashboard__card ho-driver-dashboard__empty"><h2>Unable to load deliveries</h2><p>We could not load your records. No totals are shown.</p><a class="ho-driver-dashboard__primary" href="<?=$h($base)?>/index.php">Retry</a></section>
<?php else:?>
<section class="ho-driver-dashboard__card ho-driver-dashboard__progress-card" aria-labelledby="hd-progress"><div class="ho-driver-dashboard__cardhead"><h2 class="ho-driver-dashboard__progress-title" id="hd-progress">Today's progress</h2><span class="ho-driver-dashboard__pill"><?=$p['delivered']?> of <?=$p['assigned']?> complete</span></div>
<div class="ho-driver-dashboard__track" role="progressbar" aria-label="Today's completed deliveries" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=$percent?>"><span class="ho-driver-dashboard__track-fill" style="width:<?=$percent?>%"></span></div>
<div class="ho-driver-dashboard__metrics"><?php foreach(['assigned'=>'Assigned','delivered'=>'Delivered','remaining'=>'Remaining'] as $key=>$label):?><div class="ho-driver-dashboard__metric"><strong><?=str_pad((string)$p[$key],2,'0',STR_PAD_LEFT)?></strong><span><?=$label?></span></div><?php endforeach;?></div></section>
<div class="ho-driver-dashboard__sectionhead"><h2>Your next delivery</h2><a class="ho-driver-dashboard__viewall" href="<?=$h($base)?>/apps/delivery/index.php">View all <?=$icon('chevron')?></a></div>
<?php if($next):?><article class="ho-driver-dashboard__card ho-driver-dashboard__next-card">
<div class="ho-driver-dashboard__cardhead"><span class="ho-driver-dashboard__stop-tag"><?=!empty($next['isNextStop'])?'NEXT STOP':'ASSIGNED DELIVERY'?> · <?=$h($next['orderNumber'])?></span><span class="ho-driver-dashboard__pill ho-driver-dashboard__pill--warning"><?=$h($next['statusLabel'])?></span></div>
<h3 class="ho-driver-dashboard__destination"><a href="<?=$h($jobLink)?>"><?=$h($next['destinationArea']?:'Address needs review')?></a><?=$icon('chevron')?></h3>
<p class="ho-driver-dashboard__recipient"><?=$h($next['customerDisplayName'])?> · <?=$next['itemCount']===null?'Items not recorded':$next['itemCount'].' item line'.($next['itemCount']===1?'':'s')?> · <?=$h($next['paymentLabel'])?></p>
<p class="ho-driver-dashboard__timing"><?=$icon('clock')?><strong><?=$h($next['deliveryWindow'])?></strong></p>
<?php if(!$local&&$next['navigationUrl']):?><a class="ho-driver-dashboard__primary" href="<?=$h($next['navigationUrl'])?>" target="_blank" rel="noopener noreferrer"><?=$icon('arrow')?>Navigate to customer</a><?php else:?><button class="ho-driver-dashboard__primary" data-hd-dialog="map"><?=$icon('arrow')?>Navigate to customer</button><?php endif;?>
</article><?php else:?><section class="ho-driver-dashboard__card ho-driver-dashboard__empty"><h3><?=$p['assigned']?'No next delivery confirmed':'No deliveries assigned yet'?></h3><p><?=$p['assigned']?'Open Deliveries for completed work and assignments needing dispatch review.':'New released assignments will appear here.'?></p></section><?php endif;?>
<?php endif;?>
<div class="ho-driver-dashboard__sectionhead"><h2>Quick actions</h2></div><div class="ho-driver-dashboard__quickgrid">
<?php foreach([['box','Deliveries',($quick['deliveriesToday']??'—').' today','/apps/delivery/index.php',null],['bag','Errands',isset($quick['openErrands'])?$quick['openErrands'].' open':'Unavailable','/apps/delivery/index.php?errands=1',null],['card','Cash',isset($quick['cashDueNad'])?'N$'.number_format($quick['cashDueNad'],2).' due':'Unavailable','/apps/delivery/index.php?cash=1',null],['chat','Dispatch',isset($quick['unreadDispatch'])?$quick['unreadDispatch'].' unread':'Unavailable','/apps/delivery/index.php?dispatch=1',null]] as [$key,$label,$sub,$route,$dialog]):?>
<?php if($route):?><a class="ho-driver-dashboard__quick" href="<?=$h($base.$route)?>"><?php else:?><button class="ho-driver-dashboard__quick" type="button" data-hd-dialog="<?=$dialog?>"><?php endif;?><span class="ho-driver-dashboard__quickicon"><?=$icon($key)?></span><strong><?=$label?></strong><small><?=$h($sub)?></small><?=$route?'</a>':'</button>'?>
<?php endforeach;?></div>
<section class="ho-driver-dashboard__card ho-driver-dashboard__cash-home"><div class="ho-driver-dashboard__cardhead"><h2>Cash handover</h2><strong><?=isset($quick['cashDueNad'])?'N$'.number_format($quick['cashDueNad'],2):'Unavailable'?></strong></div><p>Confirm the amount you handed to Front Desk. Your amount and time appear in Bookkeeping.</p><a class="ho-driver-dashboard__primary" href="<?=$h($base)?>/apps/delivery/index.php?cash=1">Confirm handover</a></section>
</div></div>
<?php require __DIR__.'/BottomNav.php';?>
<?php foreach(['map'=>'Your route','cash'=>'Cash to hand over','dispatch'=>'Dispatch','more'=>'More'] as $key=>$title):?><dialog class="ho-driver-dashboard__sheet" id="hd-<?=$key?>" aria-labelledby="hd-title-<?=$key?>"><div class="ho-driver-dashboard__cardhead"><h2 id="hd-title-<?=$key?>"><?=$title?></h2><button class="ho-driver-dashboard__close" data-hd-close aria-label="Close"><?=$icon('close')?></button></div>
<?php if($key==='map'):?><p><?=$h($next['address']??'No active delivery address is available.')?></p><p><?=$local?'Live maps are disabled in this private local preview. No location is sent.':'Open the Map tab to locate yourself and your assigned stops, or use customer directions below.'?></p><?php if(!$local&&$next&&$next['navigationUrl']):?><a class="ho-driver-dashboard__primary" href="<?=$h($next['navigationUrl'])?>" target="_blank" rel="noopener noreferrer">Open directions</a><?php endif;?><a class="ho-driver-dashboard__menulink" href="<?=$h($jobLink)?>">View delivery details</a>
<?php elseif($key==='cash'):?><p><?=isset($quick['cashDueNad'])?'N$'.number_format($quick['cashDueNad'],2).' cash remaining':'Cash total unavailable'?></p><p>Record the amount you gave Front Desk and view your handover history.</p><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?cash=1">Open Cash handover</a>
<?php elseif($key==='dispatch'):?><p>Dispatch chat connects you to the authorised delivery dispatch team. It is separate from private assistance and anonymous reporting.</p><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?dispatch=1">Open dispatch chat</a>
<?php else:?><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?history=1">Delivery history</a><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?cash=1">Cash handover</a><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?dispatch=1">Dispatch chat</a><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php?profile=1">My account</a><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/notifications.php">Notifications</a><a class="ho-driver-dashboard__menulink" href="<?=$h($base)?>/apps/delivery/index.php">Active deliveries</a><p>For support, contact Front Desk.</p><?php endif;?></dialog><?php endforeach;?>
</main><script src="<?=$h($asset('assets/js/driver-dashboard.js'))?>"></script></body></html>

