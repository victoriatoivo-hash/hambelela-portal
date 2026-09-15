<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/shared/auth.php';require_login();
if(current_role_key()!=='owner_admin'){http_response_code(403);exit('Owner access required.');}
require_once __DIR__.'/shared/ess-navigation.php';
$pageTitle='Settings | '.APP_NAME;$isEssDashboard=true;$pageUsesPortalSidebar=false;$essShellApps=ess_shell_apps();$essActiveModule='Settings';
$extraStylesheets=[['path'=>'assets/css/profile-settings.css','version'=>filemtime(BASE_PATH.'/assets/css/profile-settings.css')],['path'=>'assets/css/ess-dashboard.css','version'=>filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')]];
include __DIR__.'/shared/header.php';include __DIR__.'/shared/ess-sidebar.php';
?>
<main id="ess-main" class="workspace ess-dashboard-main portal-settings-page"><header><p class="page-eyebrow">PORTAL</p><h1>Settings</h1><p>Manage portal preferences, account options and business configuration.</p></header><div class="portal-settings-grid">
<?php foreach([
 ['Employees & Roles','Manage existing employee accounts and their workplace access.','users-round','/apps/operations/my-account.php?section=employees'],
 ['Account security','Review existing access-code and account security options.','lock-keyhole','/apps/operations/my-account.php?section=security'],
 ['Notification preferences','Manage supported sound and desktop notification preferences.','bell','/apps/operations/my-account.php?section=notifications'],
 ['Portal settings','Review existing portal and performance recording controls.','settings','/apps/operations/my-account.php?section=portal'],
 ['Appearance','Review display preferences and availability.','palette','/apps/operations/my-account.php?section=appearance'],
 ['Help & Support','Find guidance for common portal questions.','circle-help','/apps/operations/my-account.php?section=help'],
 ['WhatsApp integration','Manage the existing WhatsApp connection.','message-circle','/apps/operations/whatsapp-settings.php'],
 ['My Profile','View your personal account details.','user-round','/profile.php']
]as[$title,$description,$icon,$url]):?><section class="portal-settings-card"><i data-lucide="<?=$icon?>"></i><h2><?=$title?></h2><p><?=$description?></p><a href="<?=BASE_URL.$url?>">Open <?=$title?><i data-lucide="arrow-up-right"></i></a></section><?php endforeach;?></div></main>
<?php include __DIR__.'/shared/ess-mobile-navigation.php'; ?><script src="<?=BASE_URL?>/assets/js/ess-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/ess-dashboard.js')?>" defer></script><?php include __DIR__.'/shared/footer.php'; ?>
