<?php
// Synthetic notification harness. Never deployed; loopback only.
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/api/notifications.php'){
 session_start();if(isset($_GET['reset_fixture']))unset($_SESSION['fixture_claimed_purple'],$_SESSION['fixture_snoozed']);header('Content-Type: application/json');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??'';
  if($action==='notification_claim'){$claimed=empty($_SESSION['fixture_claimed_purple']);$_SESSION['fixture_claimed_purple']=true;echo json_encode(['claimed'=>$claimed]);}
  elseif($action==='marketing_remind'){$_SESSION['fixture_snoozed']=true;echo '{"ok":true}';}
  else echo '{"ok":true}';
 }else{
  $notification=['id'=>999999,'title'=>'Synthetic product promotion','message'=>'You have been assigned new Marketing content.','related_id'=>999999,'related_type'=>'marketing_assignment','channels'=>'Instagram · TikTok','due_at'=>'14 October 2026, 15:00','priority'=>'normal','created_at'=>'2026-10-01 12:00:00','sound_key'=>'assigned'];
  echo json_encode(['ok'=>true,'preferences'=>['sound_enabled'=>1,'sound_prompt_seen'=>1],'latest'=>[],'marketing_popup_csrf'=>'fixture','marketing_popups'=>empty($_SESSION['fixture_claimed_purple'])?[$notification]:[]]);
 }
 return true;
}
return false;
