<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function sil_workflow_json(int $status,array $payload): void {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function sil_workflow_reject(string $message,int $status): void {throw new RuntimeException($message,$status);}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')sil_workflow_json(405,['success'=>false,'message'=>'Invalid request.']);
    if(!system_issue_is_owner())sil_workflow_json(403,['success'=>false,'message'=>'You do not have permission to update this workflow.']);
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    try {system_issue_verify_csrf((string)($_POST['csrf']??''));}catch(Throwable $error){sil_workflow_json(403,['success'=>false,'message'=>'Your session expired. Refresh the page and try again.']);}
    $issueId=(int)($_POST['issue_id']??0);if($issueId<1)sil_workflow_reject('Select a valid system issue.',400);
    $requested=mb_substr(trim((string)($_POST['workflow_stage']??'')),0,40);
    $submittedStage=mb_substr(trim((string)($_POST['current_stage']??'')),0,40);
    $confirmed=filter_var($_POST['confirmation']??false,FILTER_VALIDATE_BOOL);
    $note=mb_substr(trim((string)($_POST['note']??'')),0,4000);
    $definitions=system_issue_workflow_definitions();
    if(!isset($definitions[$requested]))sil_workflow_reject('The selected workflow stage is no longer available. Refresh the issue and try again.',400);
    $allowed=system_issue_workflow_allowed();
    db()->beginTransaction();
    $issue=ops_rows('SELECT * FROM system_issues WHERE id=? FOR UPDATE',[$issueId])[0]??null;
    if(!$issue)sil_workflow_reject('This system issue is unavailable.',404);
    $fromStage=system_issue_workflow_stage($issue);
    if($submittedStage!==''&&$submittedStage!==$fromStage)sil_workflow_reject('This workflow was updated elsewhere. Refresh the issue to load the latest status.',409);
    if($requested===$fromStage)sil_workflow_reject('No workflow change was selected.',409);
    if(!in_array($requested,$allowed[$fromStage]??[],true)){
        if($requested==='done')sil_workflow_reject('This issue cannot be marked Done yet. Owner approval, repair, testing, deployment and live verification must be completed first.',422);
        sil_workflow_reject('The selected workflow stage is no longer available. Refresh the issue and try again.',409);
    }
    if($fromStage==='awaiting_approval'&&$requested==='approved'&&ops_rows("SELECT id FROM system_issue_information_requests WHERE issue_id=? AND status='pending' LIMIT 1 FOR UPDATE",[$issueId]))sil_workflow_reject('This repair cannot be approved while employee information is still requested.',422);
    $integration=ops_rows('SELECT * FROM system_issue_integrations WHERE issue_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$issueId])[0]??null;
    if(!$integration){$s=db()->prepare("INSERT INTO system_issue_integrations(issue_id,provider,status,requires_approval,approved_by,approved_at) VALUES(?,'manual','manual',1,?,NOW())");$s->execute([$issueId,(int)(current_user()['id']??0)]);$integration=ops_rows('SELECT * FROM system_issue_integrations WHERE id=?',[(int)db()->lastInsertId()])[0];}
    $now=date('Y-m-d H:i:s');$actor=(int)(current_user()['id']??0);
    $eventType='workflow_stage_updated';$eventMessage='The controlled repair workflow was updated.';
    if(in_array($requested,['tests_passed','deployed','verified'],true)&&!$confirmed)throw new RuntimeException('Confirm this completed stage before saving it.');
    if($requested==='approved'){$eventType='repair_approved';$eventMessage=(string)(current_user()['name']??'The owner').' approved this issue for controlled repair.';db()->prepare("UPDATE system_issue_integrations SET status='approved',approved_by=?,approved_at=? WHERE id=?")->execute([$actor,$now,$integration['id']]);}
    elseif($requested==='tests_passed'){$eventType='tests_confirmed';$eventMessage='The required repair tests were confirmed as passed.';db()->prepare("UPDATE system_issue_integrations SET status='tests_passed',tests_passed_at=?,tests_confirmed_by_user_id=?,test_confirmation_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['tests_passed_at']=$now;}
    elseif($requested==='tests_failed'){$eventType='tests_failed';$eventMessage='The repair tests were recorded as failed.';db()->prepare("UPDATE system_issue_integrations SET status='tests_failed',last_error=? WHERE id=?")->execute([$note?:'Tests failed.',$integration['id']]);}
    elseif($requested==='deployed'){$eventType='deployment_confirmed';$eventMessage='Deployment was confirmed successfully. Live verification is still required.';if(empty($integration['tests_passed_at']))throw new RuntimeException('Tests must be confirmed before deployment.');db()->prepare("UPDATE system_issue_integrations SET status='deployed',deployed_at=?,deployment_confirmed_by_user_id=?,deployment_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['deployed_at']=$now;}
    elseif($requested==='deployment_failed'){$eventType='deployment_failed';$eventMessage='The deployment was recorded as failed.';db()->prepare("UPDATE system_issue_integrations SET status='deployment_failed',last_error=? WHERE id=?")->execute([$note?:'Deployment failed.',$integration['id']]);}
    elseif($requested==='verified'){$eventType='live_verification_confirmed';$eventMessage='The repair was checked successfully on the live portal.';if(empty($integration['deployed_at']))throw new RuntimeException('Deployment must be confirmed before live verification.');db()->prepare("UPDATE system_issue_integrations SET status='verified',live_verified_at=?,verification_confirmed_by_user_id=?,verification_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['live_verified_at']=$now;}
    elseif($requested==='done'){$missing=[];if(empty($integration['tests_passed_at']))$missing[]='tests';if(empty($integration['deployed_at']))$missing[]='deployment';if(empty($integration['live_verified_at']))$missing[]='live verification';if(!empty($integration['rollback_active']))$missing[]='active rollback clearance';if($missing)throw new RuntimeException('This issue cannot be marked Done yet. Outstanding: '.implode(', ',$missing).'.');$eventType='issue_completed';$eventMessage='The repair was verified successfully on the live portal.';}
    elseif($requested==='reopened'){$eventType='issue_reopened';$eventMessage='The issue was reopened for further work.';}
    $savedStage=$requested==='verified'?'done':$requested;$resolved=$definitions[$savedStage];$verifiedAt=in_array($requested,['verified','done'],true)?(string)$integration['live_verified_at']:($issue['verified_at']??null);
    db()->prepare('UPDATE system_issues SET workflow_stage=?,internal_status=?,employee_status=?,verified_at=?,verified_by=? WHERE id=?')->execute([$savedStage,$resolved['internal'],$resolved['employee'],$verifiedAt,in_array($requested,['verified','done'],true)?$actor:($issue['verified_by']??null),$issueId]);
    $meta=['previous_workflow_stage'=>$fromStage,'new_workflow_stage'=>$savedStage,'requested_workflow_stage'=>$requested,'previous_internal_status'=>$issue['internal_status'],'new_internal_status'=>$resolved['internal'],'previous_employee_status'=>$issue['employee_status'],'new_employee_status'=>$resolved['employee'],'authenticated_actor_user_id'=>$actor,'note'=>$note];
    system_issue_event($issueId,$eventType,$fromStage,$savedStage,$eventMessage,$meta);
    db()->commit();
    if((string)$issue['employee_status']!==$resolved['employee'])system_issue_notify(['title'=>$issue['issue_key'].' · '.system_issue_status_label($resolved['employee']),'message'=>'Your reported system issue has moved to '.system_issue_status_label($resolved['employee']).'.','action_link'=>BASE_URL.'/apps/operations/system-issues.php?issue='.$issueId],[system_issue_reporter_id($issue)]);
    sil_workflow_json(200,['success'=>true,'issue_id'=>$issueId,'workflow_stage'=>$savedStage,'workflow_label'=>$resolved['label'],'workflow_badge'=>$resolved['badge'],'internal_status'=>$resolved['internal'],'employee_status'=>$resolved['employee'],'employee_status_label'=>system_issue_status_label($resolved['employee']),'verified_at_display'=>$verifiedAt?date('j M Y · H:i',strtotime($verifiedAt)):null,'event'=>['type'=>$eventType,'title'=>ucwords(str_replace('_',' ',$eventType)),'message'=>$eventMessage,'created_at_display'=>date('j M Y · H:i'),'actor_display'=>(string)(current_user()['name']??'Owner')],'can_mark_done'=>false,'next_required_action'=>system_issue_workflow_next_action($savedStage),'permitted_transitions'=>system_issue_workflow_permitted($savedStage)]);
} catch(Throwable $error) {
    if(db()->inTransaction())db()->rollBack();error_log('System issue workflow update failed: '.$error->getMessage());
    $status=$error instanceof RuntimeException&&in_array((int)$error->getCode(),[400,401,403,404,409,422],true)?(int)$error->getCode():($error instanceof RuntimeException?422:500);
    sil_workflow_json($status,['success'=>false,'message'=>$error instanceof RuntimeException?$error->getMessage():'The workflow could not be updated. Please try again.']);
}
