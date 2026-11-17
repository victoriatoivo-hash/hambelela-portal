<?php
use Hambelela\Acknowledgments\Service as S;
putenv('ACK_CONTACT_OWNER_ID=1');
foreach(['chat','assistance'] as $kind){
    $named=$kind==='chat';
    if($named)$testId=$chatService->start(1,['employee'=>2,'message'=>'Delete fixture','request_key'=>S::uuid()])['id'];
    else{$box->send(2,['message'=>'Delete fixture','new_topic'=>true,'request_key'=>S::uuid()]);$testId=(int)$box->assistanceRows(1)[0]['topic'];}
    $action=fn($who,$op=[])=>$named?$chatService->thread($who,$op+['id'=>$testId]):$box->dialog($who,$op+['id'=>$testId]);
    $present=fn()=>$named?in_array($testId,array_map('intval',array_column($chatService->rows(1),'id')),true):in_array($testId,array_map('intval',array_column($box->assistanceRows(1),'topic')),true);
    $a=$action(1);$e=$action(2);
    check($a['can_remove']&&!$e['can_remove'],"$kind: delete control is owner only");
    $hide=['op'=>'delete_for_me','expected'=>$a['last']];
    denied(fn()=>$action(2,$hide),"$kind: employee cannot delete");
    denied(fn()=>$action(5,$hide),"$kind: different admin cannot delete");
    denied(fn()=>$action(1,['op'=>'delete_for_me','expected'=>0]),"$kind: stale delete rejected");
    check($action(1,$hide)['removed']&&$action(1,$hide)['removed']&&!$present(),"$kind: removed only from owner inbox; retry safe");
    check($action(2)['messages']===$e['messages'],"$kind: employee history intact");
    $action(2,['op'=>'reply','message'=>'New reply','request_key'=>S::uuid()]);
    check($present()&&count($action(1)['messages'])===2,"$kind: new reply restores original conversation");
}
putenv('ACK_CONTACT_OWNER_ID');
