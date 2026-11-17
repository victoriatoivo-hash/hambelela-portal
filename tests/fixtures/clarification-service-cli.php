<?php
// Only callable from the synthetic browser test. Never part of the deployment.
if(PHP_SAPI!=='cli'||getenv('ACK_TEST_DSN')!=='mysql:host=127.0.0.1;port=13317;dbname=acknowledgments_test')exit(2);
require_once __DIR__.'/../../shared/acknowledgments/Service.php';
require_once __DIR__.'/../../shared/acknowledgments/ResponseChats.php';
require_once __DIR__.'/../../shared/acknowledgments/Mailbox.php';
use Hambelela\Acknowledgments\Service;
$db=new PDO(getenv('ACK_TEST_DSN'),'root',getenv('ACK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$svc=new Service($db);$actor=(int)$argv[1];$action=$argv[2];$b=json_decode(stream_get_contents(STDIN),true)??[];
if(getenv('NOTICE_ASSETS')&&in_array($actor,[71,72],true))$actor+=1000;
try{
    if($action==='seed'){
        $db->prepare("INSERT INTO ops_employees(id,role_id,full_name,status) VALUES(?,1,'Fixture Admin','active'),(?,2,'Fixture Employee','active')")->execute([$actor,$actor+1]);
        $svc->send($actor,['title'=>'Opening checklist','message_html'=>'Check the blue tray.','request_key'=>Service::uuid(),'recipients'=>[$actor+1],'notify_immediately'=>true,'required'=>true]);
        $out=$svc->popup($actor+1);
    }elseif($action==='popup')$out=['notice'=>$svc->popup($actor),'session'=>'fixture-'.$actor,'csrf'=>'fixture'];
    elseif($action==='list')$out=['csrf'=>'fixture'];
    elseif($action==='detail')$out=$svc->detail($actor,(int)$b['id']);
    elseif($action==='clarification_poll'){
        $out=['turn'=>$svc->threadPrompt($actor,(int)($b['event']??0)),'chat'=>(new \Hambelela\Acknowledgments\ResponseChats($db))->rows($actor,true)[0]??null,'csrf'=>'fixture'];
        $box=new \Hambelela\Acknowledgments\Mailbox($db,getenv('NOTICE_ASSETS')?1071:71);$out['closure']=$svc->closurePrompt($actor);if($actor===(getenv('NOTICE_ASSETS')?1071:71))$out['report']=$box->reportAlert($actor);$out['assistance']=$actor===(getenv('NOTICE_ASSETS')?1071:71)?($box->assistanceRows($actor,true)[0]??null):$box->pendingPrompt($actor);
    }
    elseif($action==='closure_seen'){$svc->seenClosure($actor,(int)$b['event']);$out=['ok'=>true];}
    elseif($action==='unnamed_report')$out=(new \Hambelela\Acknowledgments\Mailbox($db,getenv('NOTICE_ASSETS')?1071:71))->unnamedReport($actor,$b);
    elseif($action==='report_thread')$out=(new \Hambelela\Acknowledgments\Mailbox($db,getenv('NOTICE_ASSETS')?1071:71))->reportAction($actor,$b);
    elseif($action==='assistance_dialog')$out=(new \Hambelela\Acknowledgments\Mailbox($db,getenv('NOTICE_ASSETS')?1071:71))->dialog($actor,$b);
    elseif($action==='assistance_start'){(new \Hambelela\Acknowledgments\Mailbox($db,getenv('NOTICE_ASSETS')?1071:71))->send($actor,$b+['new_topic'=>true]);$out=['ok'=>true];}
    elseif($action==='chat_start')$out=(new \Hambelela\Acknowledgments\ResponseChats($db))->start($actor,$b);
    elseif($action==='chat_thread')$out=(new \Hambelela\Acknowledgments\ResponseChats($db))->thread($actor,$b);
    elseif($action==='chat_list')$out=['chats'=>(new \Hambelela\Acknowledgments\ResponseChats($db))->rows($actor),'csrf'=>'fixture'];
    elseif($action==='respond'){$svc->respond($actor,$b,'fixture-'.$actor);$out=['ok'=>true];}
    else throw new LogicException('Unexpected fixture request');
    echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(DomainException $e){echo json_encode(['error'=>$e->getMessage()]);}
