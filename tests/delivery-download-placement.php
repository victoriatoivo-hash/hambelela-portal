<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
$owner=false;function user_has_role(string $role):bool{global $owner;return $owner&&$role==='owner_admin';}
ob_start();require BASE_PATH.'/shared/delivery/AppDownload.php';$driver=ob_get_clean();
if(trim($driver)!=='')throw new RuntimeException('Download visible to non-Owner');
$owner=true;ob_start();require BASE_PATH.'/shared/delivery/AppDownload.php';$profile=ob_get_clean();
if(strpos($profile,'Download Android GPS app')===false)throw new RuntimeException('Owner link missing');
$shell=file_get_contents(BASE_PATH.'/shared/delivery/PortalShell.php');
if(strpos($shell,'delivery-gps-install')!==false)throw new RuntimeException('Driver banner still present');
$tracking=file_get_contents(BASE_PATH.'/apps/delivery/tracking.php');
if(strpos($tracking,'download>')!==false)throw new RuntimeException('Tracking still contains a download link');
$css=file_get_contents(BASE_PATH.'/assets/css/delivery-mobile-notice.css');
foreach(['body[data-delivery-ui] .portal-sound-permission','92px + env(safe-area-inset-bottom','overflow-y: auto','min-height:44px'] as $rule)if(strpos($css,$rule)===false)throw new RuntimeException('Missing mobile prompt safeguard');
echo "PASS: Owner-only profile card, no Driver banner, no Tracking download, scoped mobile safe area.\n";
