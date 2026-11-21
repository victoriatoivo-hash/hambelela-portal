<?php if(!defined('BASE_PATH'))exit; ?>
<nav class="ho-driver-dashboard__bottomnav" aria-label="Driver navigation">
<?php foreach([['home','Home','/index.php'],['box','Deliveries','/apps/delivery/index.php'],['map','Map','/apps/delivery/index.php?route=1'],['bag','Errands','/apps/delivery/index.php?errands=1'],['more','More',null]] as [$key,$label,$path]): ?>
<?php if($path!==null): ?><a class="ho-driver-dashboard__tab" href="<?=$h($base.$path)?>" <?=$key===($activeDriverTab??'home')?'aria-current="page"':''?>><?=$icon($key)?><span><?=$label?></span></a>
<?php else: ?><button class="ho-driver-dashboard__tab" type="button" data-hd-dialog="<?=$key?>" <?=$key===($activeDriverTab??'home')?'aria-current="page"':''?>><?=$icon($key)?><span><?=$label?></span></button><?php endif;?>
<?php endforeach;?>
</nav>
<script src="<?=$h($asset('assets/js/driver-live-updates.js'))?>"></script>
<?php if(function_exists('current_role_key')&&current_role_key()==='delivery_driver'): $_SESSION['driver_profile_csrf']??=bin2hex(random_bytes(32)); ?>
<script src="<?=$h($asset('assets/js/driver-app-activity.js'))?>" data-app-activity-config data-endpoint="<?=$h($base)?>/apps/delivery/index.php?profile=1" data-actor="<?=(int)current_user()['id']?>" data-csrf="<?=$h($_SESSION['driver_profile_csrf'])?>"></script>
<?php endif; ?>
