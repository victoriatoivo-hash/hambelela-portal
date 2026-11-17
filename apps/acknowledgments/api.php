<?php
declare(strict_types=1);
header('Content-Type: application/json');header('Cache-Control: no-store');
try{
    require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_once BASE_PATH.'/shared/database.php';
    if (!portal_validate_authenticated_session()) throw new DomainException('Your session expired. Please sign in again.');
    require_login();
    require_once BASE_PATH.'/shared/acknowledgments/integration.php';
    $employee=(int)(current_user()['id']??0);$service=new \Hambelela\Acknowledgments\Service(db());$service->actor($employee);
    if(!acknowledgments_ready())throw new DomainException('Acknowledgments is not activated yet.');
    $_SESSION['ack_csrf']=$_SESSION['ack_csrf']??bin2hex(random_bytes(32));$action=(string)($_GET['action']??'list');
    if(in_array($action,['register','chat_list','chat_start','chat_thread','clarification_poll'],true)){
        require_once BASE_PATH.'/shared/acknowledgments/ResponseChats.php';$chats=new \Hambelela\Acknowledgments\ResponseChats(db());
    }
    if($action==='unnamed_report' && $_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"Use the reporting form."}';exit;}
    if(in_array($action,['register','assistance_thread','report_thread','report_alert','inbox','message','unnamed_report','popup','private_claim','private_seen','private_item','private_queue','private_act'],true))require_once BASE_PATH.'/shared/acknowledgments/Mailbox.php';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['ack_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new DomainException('Session verification failed. Refresh the page.');
        $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new DomainException('Invalid request.');
        if($action==='chat_start'||$action==='chat_thread'){echo json_encode($action==='chat_start'?$chats->start($employee,$body):$chats->thread($employee,$body),JSON_THROW_ON_ERROR);exit;}
        if($action==='popup_alternative'){if(!is_array($body['snoozed']??null))throw new DomainException('Invalid deferred notices.');echo json_encode(['notice'=>$service->popup($employee,$body['snoozed'],(int)($body['prefer']??0))],JSON_THROW_ON_ERROR);exit;}
        if($action==='unnamed_report'){echo json_encode(\Hambelela\Acknowledgments\Mailbox::forPortal(db())->unnamedReport($employee,$body),JSON_THROW_ON_ERROR);exit;}
        if($action==='message'){\Hambelela\Acknowledgments\Mailbox::forPortal(db())->send($employee,$body);echo '{"ok":true}';exit;}
        if($action==='assistance_thread'){echo json_encode(\Hambelela\Acknowledgments\Mailbox::forPortal(db())->assistanceThread($employee,$body),JSON_THROW_ON_ERROR);exit;}
        if($action==='report_thread'){echo json_encode(\Hambelela\Acknowledgments\Mailbox::forPortal(db())->reportAction($employee,$body),JSON_THROW_ON_ERROR);exit;}
        if($action==='register_seen'){$service->registerSeen($employee,(int)$body['id'],(int)$body['event'],!empty($body['alert_only']));echo '{"ok":true}';exit;}
        if($action==='private_act'){\Hambelela\Acknowledgments\Mailbox::forPortal(db())->promptAction($employee,(int)($body['id']??0),(string)($body['choice']??''),$body);echo '{"ok":true}';exit;}
        if($action==='private_claim'||$action==='private_seen'){
            $box=\Hambelela\Acknowledgments\Mailbox::forPortal(db());$mid=(int)($body['id']??0);
            if($action==='private_seen'){$box->dismissPrompt($employee,$mid);echo '{"ok":true}';}
            else echo json_encode(['claimed'=>$box->claimPrompt($employee,$mid,'notifications_create',BASE_URL)]);
            exit;
        }
        if($action==='send')$result=$service->send($employee,$body);
        elseif($action==='schedule')$result=$service->schedule($employee,$body);
        elseif($action==='withdrawal')$result=$service->withdrawal($employee,$body);
        elseif($action==='respond'){$service->respond($employee,$body,session_id());$result=['ok'=>true];}
        else throw new DomainException('Unsupported action.');
        acknowledgments_deliver();echo json_encode($result,JSON_THROW_ON_ERROR);exit;
    }
    if($action==='register'){$info=array_column($service->registerInfo($employee),null,'id');$result=$service->listing($employee);foreach($result['records'] as &$row)$row+=$info[$row['id']]??[];unset($row);$box=\Hambelela\Acknowledgments\Mailbox::forPortal(db());$result['reports']=$box->registerReports($employee);$result['assistance']=$box->assistanceRows($employee);$result['csrf']=$_SESSION['ack_csrf'];}
    elseif($action==='report_alert')$result=['report'=>\Hambelela\Acknowledgments\Mailbox::forPortal(db())->reportAlert($employee),'csrf'=>$_SESSION['ack_csrf']];
    elseif($action==='clarification_poll'){
        $result=['turn'=>$service->threadPrompt($employee,(int)($_GET['event']??0)),'csrf'=>$_SESSION['ack_csrf']];
        if(!$result['turn']&&empty($_GET['event']))$result['chat']=$chats->rows($employee,true)[0]??null;
        if(!$result['turn']&&empty($_GET['event'])&&(current_user()['role_key']??'')==='owner_admin'){
            require_once BASE_PATH.'/shared/acknowledgments/Mailbox.php';
            $result['report']=\Hambelela\Acknowledgments\Mailbox::forPortal(db())->reportAlert($employee);
            if(!$result['report'])$result['assistance']=\Hambelela\Acknowledgments\Mailbox::forPortal(db())->assistanceRows($employee,true)[0]??null;
            if(empty($result['assistance'])&&!$result['report'])$result['answer']=$service->answerAlert($employee);
        }
    }
    elseif($action==='chat_list')$result=['chats'=>$chats->rows($employee),'csrf'=>$_SESSION['ack_csrf']];
    elseif($action==='private_item')$result=\Hambelela\Acknowledgments\Mailbox::forPortal(db())->incoming($employee,(int)($_GET['id']??0))+['csrf'=>$_SESSION['ack_csrf']];
    elseif($action==='private_queue')$result=['items'=>\Hambelela\Acknowledgments\Mailbox::forPortal(db())->awaitingReply($employee)];
    elseif($action==='inbox')$result=\Hambelela\Acknowledgments\Mailbox::forPortal(db())->inbox($employee,(int)($_GET['id']??0))+['csrf'=>$_SESSION['ack_csrf']];
    elseif($action==='detail')$result=$service->detail($employee,(int)($_GET['id']??0));
    elseif($action==='popup'){$result=['csrf'=>$_SESSION['ack_csrf'],'notice'=>$service->popup($employee),'pending'=>acknowledgments_pending($employee),'session'=>hash('sha256',session_id().':'.$employee)];acknowledgments_deliver();
        try{$box=\Hambelela\Acknowledgments\Mailbox::forPortal(db());$result['attention']=$box->attention($employee);}catch(Throwable $e){error_log('Private attention counts unavailable.');}
        try{if(isset($box)&&($p=$box->pendingPrompt($employee))&&!$p['owner'])$result['private_prompt']=$p;}catch(Throwable $e){error_log('Private message prompts unavailable.');}}
    else $result=$service->listing($employee)+['csrf'=>$_SESSION['ack_csrf']];
    if($action==='register')$result['chats']=$chats->rows($employee);
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Acknowledgments API: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Could not complete this action. Your form has been kept; please retry.']);}
