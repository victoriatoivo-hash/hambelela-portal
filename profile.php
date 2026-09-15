<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/shared/auth.php';require_login();
require_once __DIR__.'/shared/ess-navigation.php';
$profile=current_user();$pageTitle='My Profile | '.APP_NAME;$isEssDashboard=true;$pageUsesPortalSidebar=false;$essShellApps=ess_shell_apps();$essActiveModule='My Profile';
$extraStylesheets=[['path'=>'assets/css/profile-settings.css','version'=>filemtime(BASE_PATH.'/assets/css/profile-settings.css')],['path'=>'assets/css/ess-dashboard.css','version'=>filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')]];
include __DIR__.'/shared/header.php';include __DIR__.'/shared/ess-sidebar.php';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<main id="ess-main" class="workspace ess-dashboard-main portal-settings-page"><header><p class="page-eyebrow">ACCOUNT</p><h1>My Profile</h1><p>Your signed-in portal account.</p></header><div class="portal-settings-grid"><section class="portal-settings-card"><i data-lucide="user-round"></i><h2>Account details</h2><dl><dt>Name</dt><dd><?=$e($profile['name']??'')?></dd><dt>Role</dt><dd><?=$e($profile['role']??str_replace('_',' ',$profile['role_key']??''))?></dd><?php if(!empty($profile['email'])):?><dt>Email</dt><dd><?=$e($profile['email'])?></dd><?php endif;?></dl><p>Contact the owner if your account details need updating.</p></section><section class="portal-settings-card"><i data-lucide="layout-dashboard"></i><h2>Your workspace</h2><p>Return to your permitted portal tools and assigned work.</p><a href="<?=BASE_URL?>/index.php">Open Dashboard</a></section></div></main>
<?php include __DIR__.'/shared/ess-mobile-navigation.php'; ?><script src="<?=BASE_URL?>/assets/js/ess-dashboard.js?v=<?=filemtime(BASE_PATH.'/assets/js/ess-dashboard.js')?>" defer></script><?php include __DIR__.'/shared/footer.php'; ?>
