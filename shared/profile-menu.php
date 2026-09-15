<?php
if (!defined('BASE_PATH') || !function_exists('current_user')) return;
$profileUser=current_user();if(!$profileUser)return;
$profileEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$profileName=(string)($profileUser['name']??'My account');
$profileInitials='';foreach(array_slice(preg_split('/\s+/',trim($profileName)),0,2)as$part){preg_match('/^./u',$part,$letter);$profileInitials.=$letter[0]??'';}
?>
<section id="portal-profile-popover" class="portal-profile-popover" role="dialog" aria-label="My account" aria-hidden="true" inert>
 <header class="portal-profile-head"><span class="portal-profile-avatar"><?=$profileEscape(strtoupper($profileInitials))?></span><div><strong class="portal-profile-name"><?=$profileEscape($profileName)?></strong><span><?=$profileEscape($profileUser['role']??str_replace('_',' ',$profileUser['role_key']??''))?></span><?php if(!empty($profileUser['email'])):?><small><?=$profileEscape($profileUser['email'])?></small><?php endif;?></div><button type="button" data-profile-close aria-label="Close account menu"><i data-lucide="x"></i></button></header>
 <nav class="portal-profile-menu" aria-label="Account actions">
 <?php $profileLinks=[['My Profile','user-round','/apps/operations/my-account.php?section=profile'],['Dashboard','layout-dashboard','/index.php']];if(portal_user_can_access_feature('task_management'))$profileLinks[]=['My Tasks','list-checks','/apps/operations/checklists.php'];if(portal_user_can_access_feature('system_issues'))$profileLinks[]=['Help / Support','circle-help','/apps/operations/system-issues.php'];foreach($profileLinks as[$label,$icon,$path]):?><a class="portal-profile-action" href="<?=BASE_URL.$path?>"><i data-lucide="<?=$icon?>"></i><?=$profileEscape($label)?></a><?php endforeach;?>
 <a class="portal-profile-action portal-profile-logout" href="<?=BASE_URL?>/login.php?action=logout"><i data-lucide="log-out"></i>Logout</a></nav>
</section>
