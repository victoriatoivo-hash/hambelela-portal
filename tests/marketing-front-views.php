<?php
declare(strict_types=1);
require __DIR__.'/marketing-assistant-fixture.php';
require BASE_PATH.'/shared/ess-navigation.php';
$GLOBALS['fixtureRole']='marketing_sales';
foreach(['Orders','Packing List','Marketing'] as$name)if(!in_array($name,array_column(ess_shell_apps(),'name'),true))throw new RuntimeException('Missing '.$name);
if(!portal_user_can_access_feature('packing_list')||!portal_user_can_access_feature('orders'))throw new RuntimeException('Feature permission missing');
if(portal_user_can_access_feature('settings')||portal_user_can_access_feature('hr'))throw new RuntimeException('Unrelated access expanded');
$source=file_get_contents(BASE_PATH.'/shared/auth.php');
$start=strpos($source,'function portal_post_login_destination');$end=strpos($source,'function portal_expire_session',$start);
eval(substr($source,$start,$end-$start));
if(portal_post_login_destination(['role_key'=>'marketing_sales'],'/apps/marketing/index.php')!==BASE_URL.'/index.php')throw new RuntimeException('Wrong landing page');
$source=file_get_contents(BASE_PATH.'/apps/operations/packing-list-data.php');
preg_match('/\$canViewAllPackingItems = .*?;/',$source,$match);
$currentRoleKey='marketing_sales';eval($match[0]);if(!$canViewAllPackingItems)throw new RuntimeException('Packing visibility not enabled');
echo "PASS: Marketing Orders/Packing navigation, feature access, dashboard landing, all-item visibility; HR/settings unchanged.\n";
