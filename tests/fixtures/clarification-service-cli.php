<?php
// Only callable from the synthetic browser test. Never part of the deployment.
if(PHP_SAPI!=='cli'||getenv('ACK_TEST_DSN')!=='mysql:host=127.0.0.1;port=13317;dbname=acknowledgments_test')exit(2);
require __DIR__.'/../../shared/acknowledgments/Service.php';
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
    elseif($action==='clarification_poll')$out=['turn'=>$svc->threadPrompt($actor,(int)($b['event']??0)),'csrf'=>'fixture'];
    elseif($action==='respond'){$svc->respond($actor,$b,'fixture-'.$actor);$out=['ok'=>true];}
    else throw new LogicException('Unexpected fixture request');
    echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(DomainException $e){echo json_encode(['error'=>$e->getMessage()]);}
