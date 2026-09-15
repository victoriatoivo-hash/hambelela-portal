<?php
// Presentation only. Each settings controller retains its own permission guard.
require_once BASE_PATH.'/shared/ess-navigation.php';
$isEssDashboard=true;
$pageUsesPortalSidebar=false;
$essShellApps=ess_shell_apps();
$essActiveModule='Settings';
$extraStylesheets[]=['path'=>'assets/css/ess-dashboard.css','version'=>filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')];
$extraStylesheets[]=['path'=>'assets/css/settings-detail.css','version'=>filemtime(BASE_PATH.'/assets/css/settings-detail.css')];
$extraStylesheets[]=['path'=>'assets/css/profile-settings.css','version'=>filemtime(BASE_PATH.'/assets/css/profile-settings.css')];
