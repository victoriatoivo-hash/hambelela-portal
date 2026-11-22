<?php
declare(strict_types=1);
if(!defined('BASE_PATH')){http_response_code(404);exit;}
require_once __DIR__.'/OwnerProofViewer.php';
use Hambelela\Delivery\{OwnerProofViewer,FrontSession,ProofStorage,NotificationOutbox};
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');
$post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';$json=$post||isset($_GET['data']);$download=null;$attachment=null;
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))throw new RuntimeException('Method not allowed.',405);
    if(!(defined('OWNER_PROOF_LAB')&&OWNER_PROOF_LAB)){
        if(empty($_SESSION['user'])||!portal_validate_authenticated_session())throw new RuntimeException('Session expired. Please sign in again.',401);
        refresh_logged_in_user();
        if(empty($_SESSION['user']))throw new RuntimeException('Session expired. Please sign in again.',401);
        if(!empty($_SESSION['must_change_access_code']))throw new RuntimeException('Update your access code before reviewing delivery proof.',403);
    }
    $user=current_user();if(empty($user['id']))throw new RuntimeException('Session expired. Please sign in again.',401);
    $viewer=new OwnerProofViewer(db(),(int)$user['id']);
    if(!is_string($_GET['delivery_id']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['delivery_id']))throw new RuntimeException('Delivery not found.',404);
    $id=(int)$_GET['delivery_id'];
    if($post){
        foreach($_POST as $v)if(!is_string($v))throw new RuntimeException('Invalid form input.',422);
        try{FrontSession::csrf($_POST['csrf']??'');}catch(DomainException $e){throw new RuntimeException('Session verification failed. Reload and retry.',403);}
        if(in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'',['cross-site'],true))throw new RuntimeException('Cross-site request refused.',403);
        $origin=$_SERVER['HTTP_ORIGIN']??null;$expected=parse_url(BASE_URL);
        if(empty($expected['host'])){$https=function_exists('portal_request_is_https')?portal_request_is_https():(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');$expected=parse_url(($https?'https':'http').'://'.($_SERVER['HTTP_HOST']??''));}
        if($origin!==null){$o=parse_url($origin);if(!$o||strtolower($o['host']??'')!==strtolower($expected['host']??'')||($o['scheme']??'')!==($expected['scheme']??'')||($o['port']??null)!==($expected['port']??null))throw new RuntimeException('Cross-site request refused.',403);}
        if(!empty($_FILES['attachment'])&&($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($_POST['action']??'')!=='discrepancy')throw new RuntimeException('Attachment not permitted.',422);
            $viewer->read($id);
            try{$attachment=ProofStorage::configured()->prepare($_FILES['attachment'],'photo');}catch(DomainException $e){throw new RuntimeException($e->getMessage(),422);}
        }
        try{$result=$viewer->action($id,$_POST,$attachment);}catch(Throwable $e){if($attachment)ProofStorage::configured()->discard([$attachment]);throw $e;}
        if(($_POST['action']??'')==='discrepancy')NotificationOutbox::attempt(db());header('Content-Type: application/json');echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);return;
    }
    if(isset($_GET['evidence'])){
        if(!is_string($_GET['evidence']))throw new RuntimeException('Evidence not found.',404);
        $f=$viewer->file($id,$_GET['evidence']);if(isset($_GET['thumbnail'])&&!extension_loaded('gd'))throw new RuntimeException('Image thumbnail service unavailable.',503);header('Content-Type: image/png');header('Content-Disposition: inline; filename="delivery-evidence.png"');header("Content-Security-Policy: default-src 'none'; sandbox");
        if(isset($_GET['thumbnail'])&&extension_loaded('gd')){
            $im=imagecreatefrompng($f['path']);$w=imagesx($im);$h=imagesy($im);$scale=min(1,800/$w,600/$h);$thumb=imagecreatetruecolor(max(1,(int)($w*$scale)),max(1,(int)($h*$scale)));imagecopyresampled($thumb,$im,0,0,0,0,imagesx($thumb),imagesy($thumb),$w,$h);imagepng($thumb);imagedestroy($thumb);imagedestroy($im);
        }else readfile($f['path']);return;
    }
    $data=$viewer->read($id);
    if(isset($_GET['download'])){
        if(!$data['proof']['files'])throw new RuntimeException('No proof is recorded for this delivery.',422);
        if(!class_exists('ZipArchive'))throw new RuntimeException('Proof export requires the server ZIP extension.',503);
        $download=tempnam(ProofStorage::directory(),'proof-export-');if(!$download)throw new RuntimeException('Export temporarily unavailable.',503);chmod($download,0600);
        $zip=new ZipArchive();if($zip->open($download,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Export temporarily unavailable.',503);
        $manifest=['format'=>'Hambelela proof bundle v1','reference'=>$data['reference'],'delivery_id'=>$id,'source_order_id'=>$data['job']['order_id'],'status'=>$data['job']['status'],'evidence_version'=>$data['version'],'timezone'=>'Africa/Windhoek','receipts'=>$data['proof']['receipts'],'warnings'=>$data['proof']['warnings'],'files'=>[]];
        foreach($data['proof']['files'] as $n=>$file){try{$f=$viewer->file($id,$file['id']);$name=($n+1).'-'.$file['kind'].'.png';$zip->addFile($f['path'],$name);$manifest['files'][]=$file+['filename'=>$name,'integrity'=>'SHA-256 verified'];}catch(RuntimeException $e){$manifest['warnings'][]='Evidence '.$file['id'].' unavailable / integrity check failed.';}}
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $zip->addFromString('README.txt',"Authorized proof bundle. manifest.json records the canonical delivery, source order, actual status, recipients, capture events and SHA-256 hashes. PNG files are re-encoded private evidence (EXIF stripped). Missing evidence is explicitly listed. Owner-only notes are excluded. This bundle is not a payment receipt or verification of recipient identity. Event database times are UTC; display timezone is Africa/Windhoek.\n");$zip->close();
        $viewer->authorize();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="delivery-'.$id.'-proof.zip"');header('Content-Length: '.filesize($download));readfile($download);return;
    }
    if($json){header('Content-Type: application/json');echo json_encode(['ok'=>true,'version'=>$data['version'],'review'=>$data['review'],'open_discrepancies'=>count($data['open'])],JSON_THROW_ON_ERROR);return;}
    if(empty($_SESSION['delivery_front_csrf']))$_SESSION['delivery_front_csrf']=bin2hex(random_bytes(32));
    $base=rtrim(BASE_URL,'/');$endpoint=$base.'/apps/operations/driver-delivery-details.php?delivery_id='.$id;
    $pageTitle=$data['reference'].' · Delivery Details & Proof Viewer';$activeApp='delivery';$isEssDashboard=true;$pageUsesPortalSidebar=false;
    if(defined('OWNER_PROOF_LAB')&&OWNER_PROOF_LAB){require BASE_PATH.'/tests/owner-proof/shell-top.php';}
    else{
        require_once BASE_PATH.'/shared/ess-navigation.php';$essShellApps=ess_shell_apps();$essActiveModule='Delivery';$essHeadingPartial=BASE_PATH.'/shared/delivery/PortalHeading.php';
        $extraStylesheets=[['path'=>'assets/css/ess-dashboard.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')],['path'=>'assets/css/driver-admin-proof-viewer.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/driver-admin-proof-viewer.css')]];
        require BASE_PATH.'/shared/header.php';require BASE_PATH.'/shared/ess-sidebar.php';echo '<main id="ess-main" class="workspace ess-dashboard-main">';require BASE_PATH.'/shared/ess-topbar.php';
    }
    require __DIR__.'/OwnerProofContent.php';
    if(defined('OWNER_PROOF_LAB')&&OWNER_PROOF_LAB){echo '</main></div></body></html>';}
    else{echo '</main>';require BASE_PATH.'/shared/ess-mobile-navigation.php';echo '<script defer src="'.$base.'/assets/js/ess-dashboard.js"></script>';require BASE_PATH.'/shared/footer.php';}
}catch(Throwable $e){
    $status=in_array($e->getCode(),[400,401,403,404,405,409,422,429,503],true)?$e->getCode():500;http_response_code($status);
    $message=$status===500?'Delivery proof viewer is temporarily unavailable. No changes were made.':$e->getMessage();if($status===500)error_log('Owner proof viewer: '.get_class($e));
    if($json){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$message],JSON_THROW_ON_ERROR);}
    else{header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Delivery proof</title><h1>Delivery proof</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';if($status===401)echo '<a href="'.htmlspecialchars(BASE_URL,ENT_QUOTES).'/login.php">Sign in</a>';}
}finally{if($download&&is_file($download))unlink($download);}
