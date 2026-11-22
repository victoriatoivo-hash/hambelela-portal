<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/OwnerDriverView.php';
use Hambelela\Delivery\OwnerDriverView;
header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('Vary: Cookie');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');
$json=isset($_GET['mode']);
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true))throw new RuntimeException('Owner observation is read-only.',405);
    if(!(defined('OWNER_VIEW_LAB')&&OWNER_VIEW_LAB)){
        if(empty($_SESSION['user'])||!portal_validate_authenticated_session())throw new RuntimeException('Session expired. Sign in again.',401);
        refresh_logged_in_user();if(empty($_SESSION['user']))throw new RuntimeException('Session expired. Sign in again.',401);
        if(!empty($_SESSION['must_change_access_code']))throw new RuntimeException('Update your access code first.',403);
    }
    $owner=(int)(current_user()['id']??0);if(!$owner)throw new RuntimeException('Sign in again.',401);
    $service=new OwnerDriverView(db(),$owner);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD'){http_response_code(200);return;}
    $mode=$_GET['mode']??'page';if(!is_string($mode))throw new RuntimeException('Invalid mode.',422);
    $allowed=['mode','driver','cap','op','params','screen','id','key'];if(array_diff(array_keys($_GET),$allowed))throw new RuntimeException('Unsupported request.',422);
    if($mode==='drivers'){$result=$service->drivers();}
    elseif($mode==='open'){
        $id=OwnerDriverView::id($_GET['driver']??null);$service->authorize($id);$data=$service->summary($id);
        $_SESSION['owner_driver_views']=array_filter($_SESSION['owner_driver_views']??[],static fn($v)=>$v['expires']>=time());
        if(count($_SESSION['owner_driver_views'])>=8)array_shift($_SESSION['owner_driver_views']);
        $cap=bin2hex(random_bytes(32));$_SESSION['owner_driver_views'][$cap]=['driver'=>$id,'owner'=>$owner,'expires'=>time()+900];
        $result=['cap'=>$cap,'expires'=>time()+900,'summary'=>$data];
    }elseif(in_array($mode,['summary','read','phone','evidence'],true)){
        $cap=$_GET['cap']??'';$v=is_string($cap)?($_SESSION['owner_driver_views'][$cap]??null):null;
        if(!$v||$v['owner']!==$owner||$v['expires']<time())throw new RuntimeException('Viewer access expired. Reopen the selected driver.',403);
        $id=OwnerDriverView::id($_GET['driver']??null);if($id!==$v['driver'])throw new RuntimeException('Viewer driver scope changed.',403);$service->authorize($id);
        if($mode==='summary')$result=$service->summary($id);
        if($mode==='read'){
            if(!is_string($_GET['params']??'')||strlen($_GET['params']??'')>2500||!is_string($_GET['op']??null))throw new RuntimeException('Invalid read.',422);
            $p=json_decode($_GET['params']??'{}',true,8,JSON_THROW_ON_ERROR);if(!is_array($p))throw new RuntimeException('Invalid parameters.',422);
            $result=$service->read($id,$_GET['op'],$p);
        }
        if($mode==='phone'){
            if(!is_string($_GET['screen']??null))throw new RuntimeException('Screen required.',422);
            $result=['html'=>$service->render($id,$_GET['screen'],isset($_GET['id'])?OwnerDriverView::id($_GET['id']):null)];
            if($_GET['screen']==='map')$result['route']=$service->read($id,'map');
        }
        if($mode==='evidence'){
            $job=OwnerDriverView::id($_GET['id']??null);$key=$_GET['key']??'';
            if(!is_string($key)||!preg_match('/^[a-f0-9]{48}$/D',$key))throw new RuntimeException('Invalid evidence.',422);
            $file=$service->evidence($id,$job,$key);header('Content-Type: image/png');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($file['path']);return;
        }
    }elseif($mode==='page'){
        $base=rtrim(BASE_URL,'/');$pageTitle='View as Driver';$activeApp='operations';$isEssDashboard=true;$pageUsesPortalSidebar=false;
        if(defined('OWNER_VIEW_LAB')&&OWNER_VIEW_LAB){require BASE_PATH.'/tests/owner-view/shell-top.php';}
        else{
            require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Driver Operations';$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';
            $extraStylesheets=[['path'=>'assets/css/ess-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')],['path'=>'assets/css/driver-admin-view-as-driver.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/driver-admin-view-as-driver.css')]];
            require BASE_PATH.'/shared/header.php';require BASE_PATH.'/shared/ess-sidebar.php';echo '<main id="ess-main" class="workspace ess-dashboard-main">';require BASE_PATH.'/shared/ess-topbar.php';
        }
        require __DIR__.'/OwnerDriverViewContent.php';
        if(defined('OWNER_VIEW_LAB')&&OWNER_VIEW_LAB)echo '</main></div></body></html>';
        else{echo '</main>';require BASE_PATH.'/shared/ess-mobile-navigation.php';echo '<script defer src="'.$base.'/assets/js/ess-dashboard.js"></script>';require BASE_PATH.'/shared/footer.php';}return;
    }else throw new RuntimeException('Unsupported read-only request.',405);
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP);
}catch(Throwable $e){
    $status=$e instanceof OutOfBoundsException?404:($e instanceof JsonException?422:(in_array($e->getCode(),[401,403,404,405,422,503],true)?$e->getCode():503));http_response_code($status);
    if($status===405)header('Allow: GET, HEAD');
    $message=$status===503?'Viewer unavailable. No driver records were changed. Please retry.':$e->getMessage();if($status===503)error_log('Owner viewer: '.get_class($e));
    if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$message]);}
    else echo '<!doctype html><meta name="viewport" content="width=device-width"><h1>View as Driver</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
}
