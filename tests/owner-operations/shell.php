<?php
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');$role='owner_admin';function current_role_key(){global $role;return $role;}
require BASE_PATH.'/shared/ess-navigation.php';$n=0;function ok($b,$s){global $n;if(!$b)throw new RuntimeException('FAIL '.$s);echo 'PASS '.$s,"\n";$n++;}
$links=ess_navigation_children('Operations');ok(count(array_filter($links,static fn($l)=>$l['label']==='Driver Operations'))===1,'single owner operations navigation entry');
$role='delivery_driver';ok(!array_filter(ess_navigation_children('Operations'),static fn($l)=>$l['label']==='Driver Operations'),'no staff navigation entry');
$role='owner_admin';$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';ob_start();require BASE_PATH.'/shared/ess-topbar.php';$old=ob_get_clean();ok(strpos($old,'data-ess-search')!==false&&strpos($old,'data-owner-operations-search')===false,'other screens retain module search');
$essOwnerOperationsSearch=true;ob_start();require BASE_PATH.'/shared/ess-topbar.php';$new=ob_get_clean();ok(strpos($new,'data-owner-operations-search')!==false&&strpos($new,'data-ess-search')===false,'dashboard topbar searches records');ok(strpos($new,'data-portal-header-status-target')!==false,'existing account and notifications slot preserved');
ok(strpos(file_get_contents(BASE_PATH.'/shared/delivery/OwnerOperationsController.php'),'refresh_logged_in_user()')!==false,'live controller rechecks account');
echo "TOTAL $n shared shell checks passed\n";
