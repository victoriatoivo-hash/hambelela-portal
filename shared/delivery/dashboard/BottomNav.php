<?php if(!defined('BASE_PATH'))exit; ?>
<nav class="ho-driver-dashboard__bottomnav" aria-label="Driver navigation">
<?php foreach([['home','Home','/index.php'],['box','Deliveries','/apps/delivery/index.php'],['map','Map','/apps/delivery/index.php?route=1'],['bag','Errands','/apps/delivery/index.php?errands=1'],['more','More',null]] as [$key,$label,$path]): ?>
<?php if($path!==null): ?><a class="ho-driver-dashboard__tab" href="<?=$h($base.$path)?>" <?=$key===($activeDriverTab??'home')?'aria-current="page"':''?>><?=$icon($key)?><span><?=$label?></span></a>
<?php else: ?><button class="ho-driver-dashboard__tab" type="button" data-hd-dialog="<?=$key?>" <?=$key===($activeDriverTab??'home')?'aria-current="page"':''?>><?=$icon($key)?><span><?=$label?></span></button><?php endif;?>
<?php endforeach;?>
</nav>
<link rel="stylesheet" href="<?=$h($asset('assets/css/driver-more-menu.css'))?>">
<dialog class="driver-more-sheet" data-driver-more-sheet aria-labelledby="driver-more-heading">
 <header><h2 id="driver-more-heading">More</h2><button type="button" data-driver-more-close aria-label="Close More">×</button></header>
 <nav aria-label="More driver options">
 <?php foreach([['Delivery history','/apps/delivery/index.php?history=1'],['Cash handover','/apps/delivery/index.php?cash=1'],['Dispatch chat','/apps/delivery/index.php?dispatch=1'],['My account','/apps/delivery/index.php?profile=1'],['Notifications','/notifications.php']] as [$moreLabel,$morePath]): ?>
 <a href="<?=$h($base.$morePath)?>"><?=$h($moreLabel)?></a>
 <?php endforeach;?>
 <a href="https://github.com/victoriatoivo-hash/hambelela-portal/releases/download/driver-android-v1.0.1/Hambelela-Driver-1.0.1.apk" rel="noopener noreferrer" referrerpolicy="no-referrer">Download Android app</a>
 </nav>
</dialog>
<script src="<?=$h($asset('assets/js/driver-more-menu.js'))?>"></script>
<script src="<?=$h($asset('assets/js/driver-live-updates.js'))?>"></script>
<?php if(function_exists('current_role_key')&&current_role_key()==='delivery_driver'): $_SESSION['driver_profile_csrf']??=bin2hex(random_bytes(32)); ?>
<script src="<?=$h($asset('assets/js/driver-app-activity.js'))?>" data-app-activity-config data-endpoint="<?=$h($base)?>/apps/delivery/index.php?profile=1" data-actor="<?=(int)current_user()['id']?>" data-csrf="<?=$h($_SESSION['driver_profile_csrf'])?>"></script>
<?php endif; ?>
