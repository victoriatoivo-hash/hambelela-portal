<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/acknowledgments/integration.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try{
    if (!portal_validate_authenticated_session()) throw new DomainException('Your session expired. Please sign in again.');
    require_login();
    $employee=(int)(current_user()['id']??0);$service=new \Hambelela\Acknowledgments\Service(db());$service->actor($employee);
    if(!acknowledgments_ready())throw new DomainException('Acknowledgments is not activated yet.');
    $_SESSION['ack_csrf']=$_SESSION['ack_csrf']??bin2hex(random_bytes(32));$action=(string)($_GET['action']??'list');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['ack_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new DomainException('Session verification failed. Refresh the page.');
        $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new DomainException('Invalid request.');
        if($action==='send')$result=$service->send($employee,$body);
        elseif($action==='respond'){$service->respond($employee,$body,session_id());$result=['ok'=>true];}
        else throw new DomainException('Unsupported action.');
        acknowledgments_deliver();echo json_encode($result,JSON_THROW_ON_ERROR);exit;
    }
    if($action==='detail')$result=$service->detail($employee,(int)($_GET['id']??0));
    elseif($action==='popup'){$result=['notice'=>$service->popup($employee),'pending'=>acknowledgments_pending($employee),'session'=>hash('sha256',session_id().':'.$employee)];acknowledgments_deliver();}
    else $result=$service->listing($employee)+['csrf'=>$_SESSION['ack_csrf']];
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Acknowledgments API: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Could not complete this action. Your form has been kept; please retry.']);}
