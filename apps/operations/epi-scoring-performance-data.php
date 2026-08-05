<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
header('Content-Type: application/json; charset=utf-8');
$user=current_user();$viewer=(int)($user['id']??0);$owner=user_has_role('owner_admin');
if($viewer<=0){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
$service=new \Hambelela\EPI\PerformanceScore(db());
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$owner){http_response_code(403);throw new RuntimeException('Owner access required.');}
        $payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload))$payload=$_POST;
        $token=(string)($payload['csrf']??'');if(empty($_SESSION['epi_scoring_csrf'])||!hash_equals((string)$_SESSION['epi_scoring_csrf'],$token))throw new RuntimeException('Invalid request token.');
        $action=(string)($payload['action']??'');$employee=(int)($payload['employee_id']??0);$year=(int)($payload['year']??date('Y'));$month=(int)($payload['month']??date('n'));$data=null;
        if($action==='sync')$data=['created'=>$service->syncEvidenceEvents($employee,$year,$month)];
        elseif($action==='calculate')$data=$service->calculateMonthly($employee,$year,$month,$viewer,'owner_recalculation',(string)($payload['reason']??''));
        elseif($action==='review'){$service->reviewEvent((int)$payload['event_id'],(string)$payload['status'],$viewer,(string)($payload['note']??''));$data=['reviewed'=>true];}
        elseif($action==='reverse')$data=['reversal_event_id'=>$service->reverseEvent((int)$payload['event_id'],$viewer,(string)($payload['reason']??''))];
        elseif($action==='lock'){$service->lockMonth($employee,$year,$month,$viewer,(string)($payload['note']??''),!empty($payload['override']));$data=['locked'=>true];}
        elseif($action==='unlock'){$service->unlockMonth($employee,$year,$month,$viewer,(string)($payload['reason']??''));$data=['unlocked'=>true];}
        else throw new RuntimeException('Unknown scoring action.');
        echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    $employee=(int)($_GET['employee_id']??$viewer);if(!$owner&&$employee!==$viewer){http_response_code(403);throw new RuntimeException('You may view only your own approved performance.');}
    $kind=(string)($_GET['kind']??'monthly');$year=(int)($_GET['year']??date('Y'));$month=(int)($_GET['month']??date('n'));
    if($kind==='monthly')$data=$service->getMonthlyScore($employee,$year,$month);
    elseif($kind==='trend')$data=$service->getTrend($employee,(int)($_GET['months']??12));
    elseif($kind==='audit'){if(!$owner)throw new RuntimeException('Owner access required.');$data=$service->getAudit($employee,(int)($_GET['limit']??100));}
    elseif($kind==='rules'){if(!$owner)throw new RuntimeException('Owner access required.');$data=$service->rules();}
    elseif($kind==='scorecards'){if(!$owner)throw new RuntimeException('Owner access required.');$data=$service->scorecards();}
    elseif($kind==='aggregate')$data=$service->aggregate($employee,(string)($_GET['from']??$year.'-01-01'),(string)($_GET['to']??$year.'-12-31'));
    else throw new RuntimeException('Unknown data kind.');
    if(!$owner&&!empty($data)&&isset($data['locked'])&&(int)$data['locked']!==1)$data=[];
    echo json_encode(['ok'=>true,'kind'=>$kind,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable$e){if(http_response_code()<400)http_response_code(400);error_log('EPI scoring API: '.$e->getMessage());echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
