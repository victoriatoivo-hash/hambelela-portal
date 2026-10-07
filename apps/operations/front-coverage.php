<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_login();require_role('owner_admin','front_desk_admin','marketing_sales');
require_once BASE_PATH.'/shared/epi/HrAbsenceEvidence.php';
require_once BASE_PATH.'/shared/epi/FrontDeskCoverage.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(empty($_SESSION['front_coverage_csrf'])) $_SESSION['front_coverage_csrf']=bin2hex(random_bytes(32));
try {
    $service=new \Hambelela\EPI\FrontDeskCoverage(db(),ops_hr_db());
    $actor=(int)ops_current_employee_id();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['front_coverage_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Reload and try again.');
        $action=(string)($_POST['action']??'');
        $service->command($actor,$action,$_POST);
        $status=$service->status($actor);
        $plan=$status['plan']??null;
        $recipients=[];
        if($action==='plan') $recipients=[(int)($plan['coverage_employee_id']??0)];
        elseif(in_array($action,['decline','exception'],true)) $recipients=notifications_role_recipients(['owner_admin']);
        elseif(in_array($action,['accept','start','resume','absence_cover'],true)) $recipients=array_values(array_diff([(int)$status['primary'],(int)($plan['coverage_employee_id']??0)],[$actor,0]));
        $notificationWarning=null;
        if($recipients) {
            $notificationId=notifications_create(['title'=>'Front Desk coverage: '.str_replace('_',' ',$action),
                'message'=>'Please review today’s lunch and Front Desk coverage plan. Responsibility changes only at a confirmed handover.',
                'module'=>'system','related_type'=>'front_coverage','related_id'=>$plan['id']??null,
                'required_delivery'=>1,'action_link'=>BASE_URL.'/apps/operations/index.php'], $recipients);
            if(!$notificationId) $notificationWarning='Plan saved, but the notification could not be delivered. Please tell the other employee directly.';
        }
    } elseif($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405);exit; }
    echo json_encode(['ok'=>true,'csrf'=>$_SESSION['front_coverage_csrf'],'data'=>$service->status($actor),'warning'=>$notificationWarning??null]);
} catch(Throwable $e) {
    http_response_code(400);
    $message=$e instanceof PDOException?'Coverage is unavailable; contact the owner.':$e->getMessage();
    echo json_encode(['ok'=>false,'error'=>$message]);
}
