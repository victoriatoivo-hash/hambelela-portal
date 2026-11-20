<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true){http_response_code(503);exit('Delivery is not enabled.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
require_once BASE_PATH.'/shared/delivery/ErrandManagementAccess.php';
header('Cache-Control: no-store');
try {
    $deliveryActor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));
    if(!\Hambelela\Delivery\ErrandManagementAccess::allowed($deliveryActor))throw new DomainException();
} catch(DomainException $e) {
    http_response_code(403);
    exit('Front Operations access denied.');
}
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
// Retire the former delivery list, retaining old bookmarks without copying errand data.
header('Location: '.BASE_URL.'/apps/delivery/index.php?errands=1',true,302);
exit;
