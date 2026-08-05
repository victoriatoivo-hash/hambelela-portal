<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
header('Content-Type: application/json; charset=utf-8');
if(current_role_key()==='guest'){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
if(!user_has_role('owner_admin')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Owner access required.']);exit;}
try{
    $service=new \Hambelela\EPI\AttendancePerformance(db());
    $filters=['period'=>(string)($_GET['period']??'previous_month'),'date_from'=>(string)($_GET['date_from']??''),'date_to'=>(string)($_GET['date_to']??''),'employee_id'=>(string)($_GET['employee_id']??''),'role'=>(string)($_GET['role']??''),'department'=>(string)($_GET['department']??''),'status'=>(string)($_GET['status']??''),'review_status'=>(string)($_GET['review_status']??'')];
    $kind=(string)($_GET['kind']??'summary');$map=['summary'=>'getSummary','employee'=>'getEmployeeSummary','daily'=>'getDailyAttendance','sessions'=>'getSessions','online'=>'getOnlineEmployees','arrival'=>'getArrivalPerformance','activity'=>'getPortalActivity','notifications'=>'getNotificationResponse','issues'=>'getCurrentIssues','evidence'=>'getEvidence','timeline'=>'getTimeline'];
    if(!isset($map[$kind]))throw new RuntimeException('Unknown data kind.');
    $data=in_array($kind,['evidence','timeline'],true)?$service->{$map[$kind]}($filters,(int)($_GET['limit']??250)):$service->{$map[$kind]}($filters);
    echo json_encode(['ok'=>true,'kind'=>$kind,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable$error){http_response_code(500);error_log('EPI Attendance API: '.$error->getMessage());echo json_encode(['ok'=>false,'error'=>'Unable to load EPI Attendance verification data.']);}
