<?php
// Loopback-only synthetic fixture; never included in deployment.
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit;}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/api/notifications.php')return false;
session_start();header('Content-Type: application/json');
if(isset($_GET['reset_fixture']))unset($_SESSION['seen']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if((int)($_POST['notification_id']??0)===999998)$_SESSION['task']=true;
 if(($_POST['action']??'')==='marketing_seen')$_SESSION['seen']=true;
 echo json_encode(['ok'=>true,'claimed'=>true]);return true;
}
$n=['id'=>999999,'module'=>'marketing','title'=>'Showgrounds Promotional Reel','message'=>'You have been assigned new Marketing content.','related_id'=>999999,'related_type'=>'marketing_assignment','channels'=>'Instagram · TikTok · Facebook','due_at'=>'14 October, 15:00','priority'=>'normal','created_at'=>'2026-10-12 12:00:00','sound_key'=>'assigned'];
echo json_encode(['ok'=>true,'unread_count'=>5,'preferences'=>['sound_enabled'=>0,'sound_prompt_seen'=>1],'latest'=>[],'task_popups'=>empty($_SESSION['task'])?[]:[['id'=>999998,'related_id'=>999998,'delivered_at'=>'2026-10-12 12:00:00']],'marketing_popup_csrf'=>'fixture','marketing_popups'=>empty($_SESSION['seen'])?[$n]:[]]);
