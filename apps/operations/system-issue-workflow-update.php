<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function sil_workflow_json(int $status,array $payload): never {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')sil_workflow_json(405,['success'=>false,'message'=>'Invalid request.']);
    if(!system_issue_is_owner())sil_workflow_json(403,['success'=>false,'message'=>'Owner access is required.']);
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    system_issue_verify_csrf((string)($_POST['csrf']??''));
    $issueId=max(1,(int)($_POST['issue_id']??0));
    $requested=mb_substr(trim((string)($_POST['workflow_stage']??'')),0,40);
    $confirmed=filter_var($_POST['confirmation']??false,FILTER_VALIDATE_BOOL);
    $note=mb_substr(trim((string)($_POST['note']??'')),0,4000);
    $definitions=system_issue_workflow_definitions();
    if(!isset($definitions[$requested]))throw new RuntimeException('Select a valid workflow stage.');
    $allowed=[
        'awaiting_approval'=>['approved','deferred'],'approved'=>['repair_queued','repair_in_progress','deferred'],
        'repair_queued'=>['repair_in_progress','testing','tests_failed','deferred'],'repair_in_progress'=>['pr_open','testing','tests_failed','deferred'],
        'pr_open'=>['testing','tests_failed','deferred'],'testing'=>['tests_passed','tests_failed'],
        'tests_passed'=>['deploying','deployed'],'tests_failed'=>['repair_in_progress','deferred'],
        'deploying'=>['deployed','deployment_failed'],'deployed'=>['verified','deployment_failed'],
        'deployment_failed'=>['deploying','repair_in_progress','deferred'],'verified'=>['done','reopened'],
        'done'=>['reopened'],'reopened'=>['approved','repair_in_progress','deferred'],'deferred'=>['reopened'],
    ];
    db()->beginTransaction();
    $issue=ops_rows('SELECT * FROM system_issues WHERE id=? FOR UPDATE',[$issueId])[0]??null;
    if(!$issue)throw new RuntimeException('Issue not found.');
    $fromStage=system_issue_workflow_stage($issue);
    if($requested===$fromStage)throw new RuntimeException('That workflow stage is already saved.');
    if(!in_array($requested,$allowed[$fromStage]??[],true))throw new RuntimeException('Invalid workflow transition from '.$definitions[$fromStage]['label'].' to '.$definitions[$requested]['label'].'.');
    $integration=ops_rows('SELECT * FROM system_issue_integrations WHERE issue_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$issueId])[0]??null;
    if(!$integration){$s=db()->prepare("INSERT INTO system_issue_integrations(issue_id,provider,status,requires_approval,approved_by,approved_at) VALUES(?,'manual','manual',1,?,NOW())");$s->execute([$issueId,(int)(current_user()['id']??0)]);$integration=ops_rows('SELECT * FROM system_issue_integrations WHERE id=?',[(int)db()->lastInsertId()])[0];}
    $now=date('Y-m-d H:i:s');$actor=(int)(current_user()['id']??0);
    $eventType='workflow_stage_updated';$eventMessage='The controlled repair workflow was updated.';
    if(in_array($requested,['tests_passed','deployed','verified'],true)&&!$confirmed)throw new RuntimeException('Confirm this completed stage before saving it.');
    if($requested==='tests_passed'){$eventType='tests_confirmed';$eventMessage='The required repair tests were confirmed as passed.';db()->prepare("UPDATE system_issue_integrations SET status='tests_passed',tests_passed_at=?,tests_confirmed_by_user_id=?,test_confirmation_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['tests_passed_at']=$now;}
    elseif($requested==='tests_failed'){$eventType='tests_failed';$eventMessage='The repair tests were recorded as failed.';db()->prepare("UPDATE system_issue_integrations SET status='tests_failed',last_error=? WHERE id=?")->execute([$note?:'Tests failed.',$integration['id']]);}
    elseif($requested==='deployed'){$eventType='deployment_confirmed';$eventMessage='Deployment was confirmed successfully. Live verification is still required.';if(empty($integration['tests_passed_at']))throw new RuntimeException('Tests must be confirmed before deployment.');db()->prepare("UPDATE system_issue_integrations SET status='deployed',deployed_at=?,deployment_confirmed_by_user_id=?,deployment_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['deployed_at']=$now;}
    elseif($requested==='deployment_failed'){$eventType='deployment_failed';$eventMessage='The deployment was recorded as failed.';db()->prepare("UPDATE system_issue_integrations SET status='deployment_failed',last_error=? WHERE id=?")->execute([$note?:'Deployment failed.',$integration['id']]);}
    elseif($requested==='verified'){$eventType='live_verification_confirmed';$eventMessage='The repair was checked successfully on the live portal.';if(empty($integration['deployed_at']))throw new RuntimeException('Deployment must be confirmed before live verification.');db()->prepare("UPDATE system_issue_integrations SET status='verified',live_verified_at=?,verification_confirmed_by_user_id=?,verification_note=? WHERE id=?")->execute([$now,$actor,$note?:null,$integration['id']]);$integration['live_verified_at']=$now;}
    elseif($requested==='done'){$missing=[];if(empty($integration['tests_passed_at']))$missing[]='tests';if(empty($integration['deployed_at']))$missing[]='deployment';if(empty($integration['live_verified_at']))$missing[]='live verification';if(!empty($integration['rollback_active']))$missing[]='active rollback clearance';if($missing)throw new RuntimeException('This issue cannot be marked Done yet. Outstanding: '.implode(', ',$missing).'.');$eventType='issue_completed';$eventMessage='The repair was verified successfully on the live portal.';}
    elseif($requested==='reopened'){$eventType='issue_reopened';$eventMessage='The issue was reopened for further work.';}
    $resolved=$definitions[$requested];$verifiedAt=in_array($requested,['verified','done'],true)?(string)$integration['live_verified_at']:($issue['verified_at']??null);
    db()->prepare('UPDATE system_issues SET workflow_stage=?,internal_status=?,employee_status=?,verified_at=?,verified_by=? WHERE id=?')->execute([$requested,$resolved['internal'],$resolved['employee'],$verifiedAt,in_array($requested,['verified','done'],true)?$actor:($issue['verified_by']??null),$issueId]);
    $meta=['previous_workflow_stage'=>$fromStage,'new_workflow_stage'=>$requested,'previous_internal_status'=>$issue['internal_status'],'new_internal_status'=>$resolved['internal'],'previous_employee_status'=>$issue['employee_status'],'new_employee_status'=>$resolved['employee'],'authenticated_actor_user_id'=>$actor,'note'=>$note];
    system_issue_event($issueId,$eventType,$fromStage,$requested,$eventMessage,$meta);
    db()->commit();
    if((string)$issue['employee_status']!==$resolved['employee'])system_issue_notify(['title'=>$issue['issue_key'].' · '.system_issue_status_label($resolved['employee']),'message'=>'Your reported system issue has moved to '.system_issue_status_label($resolved['employee']).'.','action_link'=>BASE_URL.'/apps/operations/system-issues.php?issue='.$issueId],[system_issue_reporter_id($issue)]);
    sil_workflow_json(200,['success'=>true,'issue_id'=>$issueId,'workflow_stage'=>$requested,'workflow_label'=>$resolved['label'],'workflow_badge'=>$resolved['badge'],'internal_status'=>$resolved['internal'],'employee_status'=>$resolved['employee'],'employee_status_label'=>system_issue_status_label($resolved['employee']),'verified_at_display'=>$verifiedAt?date('j M Y · H:i',strtotime($verifiedAt)):null,'event'=>['type'=>$eventType,'title'=>ucwords(str_replace('_',' ',$eventType)),'message'=>$eventMessage,'created_at_display'=>date('j M Y · H:i'),'actor_display'=>(string)(current_user()['name']??'Owner')],'can_mark_done'=>!empty($integration['tests_passed_at'])&&!empty($integration['deployed_at'])&&!empty($integration['live_verified_at'])&&empty($integration['rollback_active']),'next_required_action'=>system_issue_workflow_next_action($requested)]);
} catch(Throwable $error) {
    if(db()->inTransaction())db()->rollBack();error_log('System issue workflow update failed: '.$error->getMessage());
    sil_workflow_json($error instanceof RuntimeException?422:500,['success'=>false,'message'=>$error instanceof RuntimeException?$error->getMessage():'The workflow could not be updated. Please try again.']);
}
