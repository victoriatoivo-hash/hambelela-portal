<?php
declare(strict_types=1);
require_once __DIR__.'/system-issue-workflow-decisions.php';

function siw_stages(): array {
    return [
        'reported'=>['label'=>'Reported','employee'=>'reported','step'=>1,'next'=>'The report is waiting for technical review.'],
        'ai_processing'=>['label'=>'Under Review','employee'=>'reported','step'=>1,'next'=>'The report is being converted into a technical brief.'],
        'needs_information'=>['label'=>'Needs Information','employee'=>'needs_information','step'=>1,'next'=>'The requested information must be supplied before approval.'],
        'under_review'=>['label'=>'Under Review','employee'=>'under_review','step'=>2,'next'=>'The technical brief is being reviewed.'],
        'brief_ready'=>['label'=>'Technical Brief Ready','employee'=>'under_review','step'=>2,'next'=>'Review and approve the current technical brief.'],
        'approved_for_codex'=>['label'=>'Approved for Codex','employee'=>'under_review','step'=>2,'next'=>'Copy the approved Codex brief and submit it manually.'],
        'fix_in_progress'=>['label'=>'Fix in Progress','employee'=>'fix_in_progress','step'=>3,'next'=>'Record Codex’s result when the repair is complete.'],
        'testing'=>['label'=>'Testing','employee'=>'testing','step'=>4,'next'=>'Explicitly confirm whether testing passed or failed.'],
        'deployment'=>['label'=>'Deployment','employee'=>'testing','step'=>5,'next'=>'Record the production deployment result.'],
        'ready_for_verification'=>['label'=>'Ready for Verification','employee'=>'testing','step'=>6,'next'=>'Repeat the action that failed and confirm the result.'],
        'done'=>['label'=>'Done','employee'=>'done','step'=>6,'next'=>'The owner verified that the problem is fixed.'],
        'reopened'=>['label'=>'Reopened','employee'=>'reopened','step'=>3,'next'=>'Review the failure and return the issue to Codex when ready.'],
        'deferred'=>['label'=>'Deferred','employee'=>'deferred','step'=>2,'next'=>'Resume owner review when appropriate.'],
    ];
}

function siw_normalise_stage(array $issue): string {
    $map=['awaiting_approval'=>'brief_ready','awaiting_owner_approval'=>'brief_ready','owner_approval_required'=>'brief_ready','approved'=>'approved_for_codex','approved_for_repair'=>'approved_for_codex','repair_queue_failed'=>'approved_for_codex','codex_queued'=>'approved_for_codex','codex_running'=>'fix_in_progress','repair_in_progress'=>'fix_in_progress','repair_failed'=>'reopened','pr_ready'=>'fix_in_progress','pr_open'=>'fix_in_progress','tests_failed'=>'reopened','ready_to_deploy'=>'deployment','awaiting_deployment_approval'=>'deployment','deploying'=>'deployment','deployment_failed'=>'reopened','verification_pending'=>'ready_for_verification','verification_failed'=>'reopened','verified'=>empty($issue['verified_at'])?'ready_for_verification':'done','ai_failed'=>'under_review','duplicate'=>'under_review','manual_engineering_required'=>'deferred'];
    $raw=(string)($issue['workflow_stage']??'');$stage=$map[$raw]??$raw;
    if(isset(siw_stages()[$stage]))return $stage;
    $legacy=(string)($issue['internal_status']??'reported');
    return $map[$legacy]??(isset(siw_stages()[$legacy])?$legacy:'reported');
}

function siw_command_registry(): array {
    return [
        'approve_brief'=>['from'=>['brief_ready'],'to'=>'approved_for_codex','label'=>'Approve Brief','pending'=>'Approving…','event'=>'brief_approved','message'=>'approved the current technical brief.','primary'=>true],
        'request_information'=>['from'=>['brief_ready','under_review'],'to'=>'needs_information','label'=>'Request More Information','pending'=>'Opening request…','event'=>'information_requested','message'=>'requested more information.'],
        'defer_issue'=>['from'=>['brief_ready','under_review','approved_for_codex','reopened'],'to'=>'deferred','label'=>'Defer Issue','pending'=>'Deferring…','event'=>'issue_deferred','message'=>'deferred the issue.'],
        'resume_review'=>['from'=>['deferred'],'to'=>'brief_ready','label'=>'Resume Review','pending'=>'Resuming…','event'=>'review_resumed','message'=>'resumed technical-brief review.','primary'=>true],
        'mark_sent_to_codex'=>['from'=>['approved_for_codex'],'to'=>'fix_in_progress','label'=>'I Have Sent This to Codex','pending'=>'Saving…','event'=>'repair_manually_started','message'=>'confirmed manual Codex handoff.','primary'=>true],
        'record_codex_result'=>['from'=>['fix_in_progress'],'to'=>'testing','label'=>'Move to Testing','pending'=>'Recording…','event'=>'testing_started','message'=>'recorded the Codex result.','primary'=>true],
        'repair_failed'=>['from'=>['fix_in_progress'],'to'=>'reopened','label'=>'Repair Failed','pending'=>'Saving…','event'=>'repair_failed','message'=>'recorded a failed repair attempt.'],
        'return_to_codex'=>['from'=>['reopened'],'to'=>'fix_in_progress','label'=>'Return to Codex','pending'=>'Saving…','event'=>'repair_returned_to_codex','message'=>'returned the preserved attempt to Codex.','primary'=>true],
        'testing_passed'=>['from'=>['testing'],'to'=>'deployment','label'=>'Testing Passed','pending'=>'Saving…','event'=>'testing_passed','message'=>'explicitly confirmed testing passed.','primary'=>true],
        'record_deployment'=>['from'=>['deployment'],'to'=>'deployment','label'=>'Record Deployment Result','pending'=>'Saving…','event'=>'deployment_recorded','message'=>'recorded the deployment result.','primary'=>true],
        'testing_failed'=>['from'=>['testing'],'to'=>'reopened','label'=>'Testing Failed','pending'=>'Saving…','event'=>'testing_failed','message'=>'recorded failed testing.'],
        'confirm_fixed'=>['from'=>['ready_for_verification'],'to'=>'done','label'=>'The Problem Is Fixed','pending'=>'Confirming…','event'=>'verification_passed','message'=>'verified the original problem is fixed.','primary'=>true],
        'still_not_fixed'=>['from'=>['ready_for_verification'],'to'=>'reopened','label'=>'The Problem Still Happens','pending'=>'Saving…','event'=>'verification_failed','message'=>'confirmed the problem still happens.'],
        'unable_to_test'=>['from'=>['ready_for_verification'],'to'=>'ready_for_verification','label'=>'Unable to Test','pending'=>'Saving…','event'=>'verification_unavailable','message'=>'recorded verification is unavailable.'],
    ];
}

function siw_latest_attempt(int $issueId,bool $lock=false): ?array {return ops_rows('SELECT * FROM system_issue_repair_attempts WHERE issue_id=? ORDER BY attempt_number DESC,id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$issueId])[0]??null;}
function siw_has_blocking_employee_request(array $issue,bool $lock=false): bool {if(array_key_exists('blocking_request_count',$issue))return (int)$issue['blocking_request_count']>0;$id=(int)($issue['id']??0);return $id>0&&(bool)ops_rows("SELECT id FROM system_issue_information_requests WHERE issue_id=? AND audience='employee' AND is_blocking=1 AND status='pending' LIMIT 1".($lock?' FOR UPDATE':''),[$id]);}
function siw_require_text(array $details,string $key,string $message): string {$value=trim((string)($details[$key]??''));if($value==='')throw new LogicException($message);return $value;}

function siw_transition_summary(string $stage,string $formMode): string {
    $current=siw_stages()[$stage]['label']??ucwords(str_replace('_',' ',$stage));
    $labels=['record_codex_result'=>'Testing','testing_decision'=>'Testing decision','record_deployment'=>'Verification','verification'=>'Done','reopened'=>'Repair'];
    $next=$labels[$formMode]??$current;
    return 'Current stage: '.$current.' → Next stage: '.$next;
}

function siw_permitted_actions(string $stage,int $issueId=0): array {
    $allowed=[];
    foreach(siw_command_registry() as $command=>$rule)if(in_array($stage,$rule['from'],true))$allowed[$command]=$rule;
    if($stage==='testing'&&$issueId>0){$attempt=siw_latest_attempt($issueId);if($attempt&&$attempt['testing_completed_at'])unset($allowed['testing_passed']);}
    $result=[];foreach($allowed as $command=>$rule)$result[]=['command'=>$command,'label'=>$rule['label'],'pending_label'=>$rule['pending'],'primary'=>(bool)($rule['primary']??false)];
    if($stage==='approved_for_codex')array_unshift($result,['command'=>'copy_codex_brief','label'=>'Copy Codex Brief','pending_label'=>'Copying…','primary'=>true]);
    return $result;
}

function siw_view(array $issue): array {
    $stage=siw_normalise_stage($issue);$blocking=siw_has_blocking_employee_request($issue);
    if($blocking)$stage='needs_information';elseif($stage==='needs_information')$stage='brief_ready';$def=siw_stages()[$stage];
    $actions=siw_permitted_actions($stage,(int)($issue['id']??0));
    if($stage==='approved_for_codex'&&empty($issue['brief_copied_at']))$actions=array_values(array_filter($actions,fn($a)=>$a['command']!=='mark_sent_to_codex'));
    $attempt=(int)($issue['id']??0)>0?siw_latest_attempt((int)$issue['id']):null;
    $formMode=siw_decision_form_mode($stage,$attempt);
    return ['issue_id'=>(int)($issue['id']??0),'internal_status'=>$stage,'workflow_stage'=>$stage,'workflow_version'=>(int)($issue['workflow_version']??1),'workflow_label'=>$blocking?'Needs Information':$def['label'],'employee_status'=>$blocking?'needs_information':$def['employee'],'employee_status_label'=>system_issue_status_label($blocking?'needs_information':$def['employee']),'approval_allowed'=>!$blocking&&$stage==='brief_ready','message'=>'Workflow state loaded.','next_step'=>$blocking?'Wait for the requested information.':$def['next'],'next_required_action'=>$blocking?'Wait for the requested information.':$def['next'],'progress_step'=>$def['step'],'permitted_actions'=>$blocking?[]:$actions,'blocking_employee_request'=>$blocking,'brief_copied_at'=>$issue['brief_copied_at']??null,'form_mode'=>$formMode,'transition_summary'=>siw_transition_summary($stage,$formMode)];
}

function siw_notify_transition(array $issue,string $command,string $from,string $to,int $workflowVersion): void {
    $title=(string)($issue['issue_key']??'System issue');$link=BASE_URL.'/apps/operations/system-issues.php?issue='.(int)$issue['id'];
    foreach(siw_decision_notification_plan($command,$from,$to) as $notification){
        $audience=$notification['audience'];$ids=$audience==='employee'?[system_issue_reporter_id($issue)]:system_issue_owner_ids();
        system_issue_notify(['title'=>$title.' · '.ucwords(str_replace('_',' ',$notification['event'])),'message'=>$notification['message'],'related_id'=>(int)$issue['id'],'deduplication_key'=>'system-issue:'.(int)$issue['id'].':'.$workflowVersion.':'.$notification['event'].':'.$audience,'action_link'=>$link],$ids);
    }
}

function siw_execute(int $issueId,string $command,string $expectedStage,int $expectedVersion,string $idempotencyKey,string $note='',array $details=[]): array {
    $registry=siw_command_registry();if(!isset($registry[$command]))throw new DomainException('invalid_command');if(!preg_match('/^[A-Za-z0-9._:-]{16,80}$/',$idempotencyKey))throw new InvalidArgumentException('invalid_idempotency_key');
    $actor=(int)(current_user()['id']??0);$actorName=(string)(current_user()['name']??'Owner');$rule=$registry[$command];db()->beginTransaction();
    try{
        $existing=ops_rows('SELECT response_json FROM system_issue_workflow_actions WHERE issue_id=? AND idempotency_key=? FOR UPDATE',[$issueId,$idempotencyKey])[0]??null;if($existing&&$existing['response_json']){db()->commit();return (array)json_decode((string)$existing['response_json'],true);}
        $issue=ops_rows('SELECT * FROM system_issues WHERE id=? FOR UPDATE',[$issueId])[0]??null;if(!$issue)throw new OutOfBoundsException('issue_not_found');$stage=siw_normalise_stage($issue);$version=(int)($issue['workflow_version']??1);if($stage!==$expectedStage||$version!==$expectedVersion)throw new UnexpectedValueException('stale_workflow');if(!in_array($stage,$rule['from'],true))throw new DomainException('invalid_transition');
        if($command==='approve_brief'){if(siw_has_blocking_employee_request($issue,true))throw new LogicException('pending_information');$brief=ops_rows('SELECT * FROM system_issue_ai_briefs WHERE issue_id=? AND is_current=1 AND ai_brief_json IS NOT NULL ORDER BY version_number DESC,id DESC LIMIT 1 FOR UPDATE',[$issueId])[0]??null;if(!$brief)throw new LogicException('approved_brief_missing');db()->prepare('UPDATE system_issues SET approved_brief_id=?,approved_brief_version=?,approved_at=NOW(),approved_by=?,brief_copied_at=NULL,brief_copied_by=NULL WHERE id=?')->execute([(int)$brief['id'],(int)$brief['version_number'],$actor,$issueId]);}
        if($command==='mark_sent_to_codex'){if(empty($issue['brief_copied_at']))throw new LogicException('brief_not_copied');db()->prepare('UPDATE system_issues SET codex_sent_at=NOW(),codex_sent_by=? WHERE id=?')->execute([$actor,$issueId]);}
        if($command==='record_codex_result'){$summary=siw_require_text($details,'repair_summary','repair_summary_required');$tests=siw_require_text($details,'tests_performed','test_information_required');$passed=trim((string)($details['tests_passed']??''));$unavailable=trim((string)($details['tests_unavailable']??''));if($passed===''&&$unavailable==='')throw new LogicException('test_information_required');$next=(int)(ops_rows('SELECT COALESCE(MAX(attempt_number),0)+1 n FROM system_issue_repair_attempts WHERE issue_id=? FOR UPDATE',[$issueId])[0]['n']??1);db()->prepare('INSERT INTO system_issue_repair_attempts(issue_id,attempt_number,approved_brief_version,repair_summary,branch_name,commit_hash,pull_request_url,files_changed,tests_performed,tests_passed,tests_unavailable,known_limitations,deployment_required,recorded_by_user_id,recorded_at,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')->execute([$issueId,$next,(int)$issue['approved_brief_version'],$summary,trim((string)($details['branch_name']??''))?:null,trim((string)($details['commit_hash']??''))?:null,trim((string)($details['pull_request_url']??''))?:null,trim((string)($details['files_changed']??''))?:null,$tests,$passed?:null,$unavailable?:null,trim((string)($details['known_limitations']??''))?:null,!empty($details['deployment_required'])?1:0,$actor,'testing']);}
        if(in_array($command,['repair_failed','testing_failed','still_not_fixed'],true))siw_require_text($details,'reason','reason_required');
        if($command==='repair_failed'){$next=(int)(ops_rows('SELECT COALESCE(MAX(attempt_number),0)+1 n FROM system_issue_repair_attempts WHERE issue_id=? FOR UPDATE',[$issueId])[0]['n']??1);$reason=siw_require_text($details,'reason','reason_required');db()->prepare("INSERT INTO system_issue_repair_attempts(issue_id,attempt_number,approved_brief_version,repair_summary,branch_name,commit_hash,pull_request_url,files_changed,tests_performed,tests_passed,tests_unavailable,known_limitations,failure_reason,deployment_required,recorded_by_user_id,recorded_at,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'repair_failed')")->execute([$issueId,$next,(int)($issue['approved_brief_version']??0),trim((string)($details['repair_summary']??''))?:$reason,trim((string)($details['branch_name']??''))?:null,trim((string)($details['commit_hash']??''))?:null,trim((string)($details['pull_request_url']??''))?:null,trim((string)($details['files_changed']??''))?:null,trim((string)($details['tests_performed']??''))?:'Repair did not reach testing.',trim((string)($details['tests_passed']??''))?:null,trim((string)($details['tests_unavailable']??''))?:null,trim((string)($details['known_limitations']??''))?:null,$reason,!empty($details['deployment_required'])?1:0,$actor]);}
        $to=$rule['to'];
        if($command==='testing_passed'){$attempt=siw_latest_attempt($issueId,true);$to=siw_decision_transition($command,$attempt);db()->prepare("UPDATE system_issue_repair_attempts SET status='tests_passed',testing_completed_at=NOW(),testing_completed_by_user_id=? WHERE id=?")->execute([$actor,(int)$attempt['id']]);}
        if($command==='testing_failed'){$attempt=siw_latest_attempt($issueId,true);if(!$attempt)throw new LogicException('repair_result_required');db()->prepare("UPDATE system_issue_repair_attempts SET status='testing_failed',failure_reason=? WHERE id=?")->execute([$details['reason'],(int)$attempt['id']]);}
        if($command==='record_deployment'){$attempt=siw_latest_attempt($issueId,true);if(!$attempt||(int)$attempt['deployment_required']!==1)throw new LogicException('deployment_not_required');if(!$attempt['testing_completed_at']||$attempt['status']!=='tests_passed')throw new LogicException('tests_not_confirmed');$method=siw_require_text($details,'deployment_method','deployment_record_required');$result=strtolower(trim((string)($details['deployment_result']??'')));if(!in_array($result,['success','failed'],true))throw new LogicException('invalid_deployment_result');$reason=$result==='failed'?siw_require_text($details,'reason','reason_required'):null;db()->prepare('UPDATE system_issue_repair_attempts SET status=?,deployment_method=?,deployment_time=?,deployed_commit=?,deployment_result=?,deployment_notes=?,deployment_recorded_by_user_id=?,failure_reason=? WHERE id=?')->execute([$result==='success'?'deployed':'deployment_failed',$method,trim((string)($details['deployment_time']??''))?:date('Y-m-d H:i:s'),trim((string)($details['deployed_commit']??''))?:null,$result,trim((string)($details['deployment_notes']??''))?:null,$actor,$reason,(int)$attempt['id']]);$to=siw_decision_transition($command,array_merge($attempt,['deployment_result'=>$result]));}
        if($command==='confirm_fixed'){$attempt=siw_latest_attempt($issueId,true);if(!siw_decision_done_allowed($attempt))throw new LogicException('done_invariant_failed');}
        $target=siw_stages()[$to];$newVersion=$version+1;$now=date('Y-m-d H:i:s');$verifiedAt=$command==='confirm_fixed'?$now:($issue['verified_at']??null);$verifiedBy=$command==='confirm_fixed'?$actor:($issue['verified_by']??null);$doneAt=$command==='confirm_fixed'?$now:($issue['done_at']??null);$verificationStatus=$command==='confirm_fixed'?'passed':($command==='still_not_fixed'?'failed':($issue['verification_status']??null));db()->prepare('UPDATE system_issues SET workflow_stage=?,workflow_version=?,employee_status=?,internal_status=?,verified_at=?,verified_by=?,done_at=?,verification_status=? WHERE id=?')->execute([$to,$newVersion,$target['employee'],$to,$verifiedAt,$verifiedBy,$doneAt,$verificationStatus,$issueId]);
        $message=$actorName.' '.$rule['message'];system_issue_event($issueId,$rule['event'],$stage,$to,$message,['command'=>$command,'workflow_version'=>$newVersion,'authenticated_actor_user_id'=>$actor,'note'=>$note,'reason'=>$details['reason']??null]);if($command==='confirm_fixed')system_issue_event($issueId,'issue_completed','ready_for_verification','done','Issue completed after owner verification.',['authenticated_actor_user_id'=>$actor]);siw_notify_transition($issue,$command,$stage,$to,$newVersion);
        $fresh=$issue;$fresh['workflow_stage']=$to;$fresh['workflow_version']=$newVersion;$fresh['employee_status']=$target['employee'];$response=siw_view($fresh);$response['message']=$command==='testing_passed'?('Testing recorded — moved to '.($response['form_mode']==='record_deployment'?'Deployment':'Verification').'.'):($target['label'].' saved successfully.');$response['activity_event']=['title'=>ucwords(str_replace('_',' ',$rule['event'])),'message'=>$message,'created_at_display'=>date('j M Y · H:i')];$encoded=json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);db()->prepare('INSERT INTO system_issue_workflow_actions(issue_id,idempotency_key,command,response_json) VALUES(?,?,?,?)')->execute([$issueId,$idempotencyKey,$command,$encoded]);db()->commit();return $response;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
