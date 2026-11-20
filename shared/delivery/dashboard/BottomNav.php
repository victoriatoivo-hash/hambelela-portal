<?php if(!defined('BASE_PATH'))exit; ?>
<nav class="ho-driver-dashboard__bottomnav" aria-label="Driver navigation">
<?php foreach([['home','Home','/index.php'],['box','Deliveries','/apps/delivery/index.php'],['map','Map','/apps/delivery/index.php?route=1'],['bag','Errands','/apps/delivery/index.php?errands=1'],['more','More',null]] as [$key,$label,$path]): ?>
<?php if($path!==null): ?><a class="ho-driver-dashboard__tab" href="<?=$h($base.$path)?>" <?=$key===($activeDriverTab??'home')?'aria-current="page"':''?>><?=$icon($key)?><span><?=$label?></span></a>
<?php else: ?><button class="ho-driver-dashboard__tab" type="button" data-hd-dialog="<?=$key?>"><?=$icon($key)?><span><?=$label?></span></button><?php endif;?>
<?php endforeach;?>
</nav>
