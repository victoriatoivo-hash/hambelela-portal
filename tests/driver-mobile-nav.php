<?php
// Exercise the production navigation template without a login or customer records.
define('BASE_URL','');
function current_role_key(){return $GLOBALS['testRole'];}
function ess_render_navigation($apps,$module){}
$essActiveModule='Delivery';
$_SERVER['SCRIPT_NAME']='/apps/delivery/index.php';
foreach ([['delivery_driver','/apps/delivery/index.php',1],['delivery_driver','/profile.php',1],['owner_admin','/profile.php',1],['packing','/profile.php',0]] as [$testRole,$first,$expected]) {
    $essShellApps=[['name'=>'My work','icon'=>'box','href'=>$first]];
    ob_start();include __DIR__.'/../shared/ess-mobile-navigation.php';$html=ob_get_clean();
    $nav=explode('</nav>',$html)[0];
    if(substr_count($nav,'href="/apps/delivery/index.php"')!==$expected || substr_count($nav,'<span>Deliveries</span>')!==$expected)throw new Exception('Wrong delivery shortcut count for '.$testRole);
    foreach(['Dashboard','Notifications','More'] as $label)if(strpos($nav,$label)===false)throw new Exception('Missing original navigation');
}
echo "PASS dashboard and driver navigation scopes and deduplication\n";
