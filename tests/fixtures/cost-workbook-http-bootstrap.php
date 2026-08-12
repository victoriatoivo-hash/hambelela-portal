<?php
declare(strict_types=1);
if (getenv('CW_TEST_ENV') !== '1') return;
$remote=(string)($_SERVER['REMOTE_ADDR']??'');if(!in_array($remote,['127.0.0.1','::1'],true)){http_response_code(403);exit('test_loopback_only');}
$identity=(string)(getenv('CW_TEST_IDENTITY')?:'logged_out');if($identity==='logged_out')return;
$users=['owner'=>[901,'Synthetic Owner','owner_admin'],'admin'=>[902,'Synthetic Admin','owner_admin'],'supervisor'=>[905,'Synthetic Supervisor','supervisor_manager'],'employee'=>[903,'Synthetic Employee','front_desk'],'capabilityless'=>[904,'Synthetic Limited User','test_no_financial_capability']];
if(!isset($users[$identity])){http_response_code(500);exit('invalid_test_identity');}
if(session_status()!==PHP_SESSION_ACTIVE)session_start();[$id,$name,$role]=$users[$identity];$_SESSION['user']=['id'=>$id,'name'=>$name,'role_key'=>$role];$_SESSION['authenticated_at']=date(DATE_ATOM);$_SESSION['absolute_expires_at']=time()+3600;$_SESSION['last_activity_at']=date(DATE_ATOM);$_SESSION['login_date']=date('Y-m-d');$_SESSION['session_user_id']=$id;$_SESSION['session_identifier']=hash('sha256',session_id());
