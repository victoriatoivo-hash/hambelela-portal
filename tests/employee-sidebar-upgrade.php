<?php
declare(strict_types=1);
require __DIR__.'/marketing-assistant-fixture.php';
$source=file_get_contents(BASE_PATH.'/shared/header.php');
$start=strpos($source,'$employeeSidebarUpgrade =');
$end=strpos($source,'$ownerPwaEnabled =',$start);
if($start===false||$end===false)throw new RuntimeException('Sidebar setup not found');
$setup=str_replace('__DIR__',var_export(BASE_PATH.'/shared',true),substr($source,$start,$end-$start));
$checks=0;
foreach(['packer','packer_production_staff','front_desk_admin','front_desk_admin_employee','marketing_sales','owner_admin','supervisor_manager']as$role){
 $GLOBALS['fixtureRole']=$role;$headerUser=current_user();$isEssDashboard=false;$hidePortalSidebar=false;$extraStylesheets=[];
 $_SERVER['SCRIPT_NAME']='/notifications.php';$_SERVER['REQUEST_URI']='/notifications.php';
 eval($setup);
 $expected=in_array($role,['packer','packer_production_staff','front_desk_admin','front_desk_admin_employee','marketing_sales'],true);
 if($employeeSidebarUpgrade!==$expected)throw new RuntimeException('Wrong role activation: '.$role);
 if($expected){ob_start();include BASE_PATH.($checks===0?'/shared/sidebar.php':'/shared/ess-sidebar.php');$html=ob_get_clean();if(substr_count($html,'<aside class="ess-sidebar"')!==1||str_contains($html,'class="portal-sidebar'))throw new RuntimeException('Duplicate or legacy sidebar: '.$role);}
 $checks++;
}
$headerUser=['role_key'=>'marketing_sales'];$isEssDashboard=true;eval($setup);
if($employeeSidebarUpgrade)throw new RuntimeException('Already migrated page activated twice');$checks++;
$isEssDashboard=false;$_SERVER['SCRIPT_NAME']='/apps/hr-portal/index.php';eval($setup);
if($employeeSidebarUpgrade)throw new RuntimeException('HR shell unexpectedly changed');$checks++;
echo "$checks sidebar activation/render checks passed. Fixture only.\n";
