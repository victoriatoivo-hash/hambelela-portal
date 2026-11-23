<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/OwnerNavigation.php';
require_once __DIR__.'/OwnerOperations.php';
use Hambelela\Delivery\OwnerOperations;
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');
$json=isset($_GET['data'])||isset($_GET['export']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new RuntimeException('Method not allowed. This dashboard is read-only.',405);
    if(!(defined('OWNER_OPERATIONS_LAB')&&OWNER_OPERATIONS_LAB)){
        if(empty($_SESSION['user'])||!portal_validate_authenticated_session())throw new RuntimeException('Session expired. Sign in again.',401);
        refresh_logged_in_user();if(empty($_SESSION['user']))throw new RuntimeException('Session expired. Sign in again.',401);
        if(!empty($_SESSION['must_change_access_code']))throw new RuntimeException('Update your access code first.',403);
    }
    $user=current_user();if(empty($user['id']))throw new RuntimeException('Sign in again.',401);
    $service=new OwnerOperations(db(),(int)$user['id']);
    if(isset($_GET['export'])){$csv=$service->export($_GET);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="driver-operations.csv"');echo $csv;return;}
    if($json){$data=$service->read($_GET);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);return;}
    if(\Hambelela\Delivery\OwnerNavigation::redirectLegacy('driver-operations.php'))return;
    $base=rtrim(BASE_URL,'/');$pageTitle='Driver Operations Dashboard';$activeApp='delivery';$isEssDashboard=true;$pageUsesPortalSidebar=false;
    if(defined('OWNER_OPERATIONS_LAB')&&OWNER_OPERATIONS_LAB){require BASE_PATH.'/tests/owner-operations/shell-top.php';}
    else{
        require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Delivery';$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';$essOwnerOperationsSearch=true;
        $extraStylesheets=\Hambelela\Delivery\OwnerNavigation::styles('operations');
        require BASE_PATH.'/shared/header.php';require BASE_PATH.'/shared/ess-sidebar.php';echo '<main id="ess-main" class="workspace ess-dashboard-main">';require BASE_PATH.'/shared/ess-topbar.php';
    }
    require __DIR__.'/OwnerOperationsContent.php';
    if(defined('OWNER_OPERATIONS_LAB')&&OWNER_OPERATIONS_LAB){echo '</main></div></body></html>';}
    else{echo '</main>';require BASE_PATH.'/shared/ess-mobile-navigation.php';echo '<script defer src="'.$base.'/assets/js/ess-dashboard.js?v='.filemtime(BASE_PATH.'/assets/js/ess-dashboard.js').'"></script>';require BASE_PATH.'/shared/footer.php';}
}catch(Throwable $e){
    $status=in_array($e->getCode(),[401,403,404,405,422,503],true)?$e->getCode():503;http_response_code($status);
    $message=$status===503?'Driver Operations is unavailable. No records were changed. Please retry.':$e->getMessage();if($status===503)error_log('Owner operations: '.get_class($e));
    if($json){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$message],JSON_THROW_ON_ERROR);}
    else{header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Driver Operations</title><h1>Driver Operations</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';if($status===401)echo '<a href="'.htmlspecialchars(BASE_URL,ENT_QUOTES).'/login.php">Sign in</a>';}
}
