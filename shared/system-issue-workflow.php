<?php
declare(strict_types=1);

function siw_stages(): array {return [
 'reported'=>['label'=>'Reported','employee'=>'reported','step'=>1,'next'=>'AI triage is waiting to start.'],
 'ai_processing'=>['label'=>'AI review in progress','employee'=>'reported','step'=>2,'next'=>'AI is reviewing this report.'],
 'needs_information'=>['label'=>'Needs information','employee'=>'needs_information','step'=>2,'next'=>'The requested employee-observable information is required.'],
 'ai_failed'=>['label'=>'AI review failed','employee'=>'reported','step'=>2,'next'=>'Retry triage or continue with manual engineering review.'],
 'brief_ready'=>['label'=>'Brief ready','employee'=>'under_review','step'=>2,'next'=>'Complete the risk decision.'],
 'awaiting_owner_approval'=>['label'=>'Owner approval required','employee'=>'under_review','step'=>2,'next'=>'Review the technical brief and approve, request information, or defer.'],
 'approved'=>['label'=>'Repair approved','employee'=>'under_review','step'=>3,'next'=>'The durable repair job is waiting to be processed.'],
 'codex_queued'=>['label'=>'Repair queued','employee'=>'fix_in_progress','step'=>3,'next'=>'The repair will start from the protected queue.'],
 'codex_running'=>['label'=>'Repair in progress','employee'=>'fix_in_progress','step'=>3,'next'=>'The controlled repair is in progress.'],
 'repair_failed'=>['label'=>'Repair failed','employee'=>'fix_in_progress','step'=>3,'next'=>'Review the failure and retry or defer.'],
 'pr_ready'=>['label'=>'Repair ready','employee'=>'fix_in_progress','step'=>3,'next'=>'Start the required test suite.'],
 'testing'=>['label'=>'Testing','employee'=>'testing','step'=>4,'next'=>'Required tests are running.'],
 'tests_failed'=>['label'=>'Tests failed','employee'=>'testing','step'=>4,'next'=>'Repair the failure before deployment.'],
 'ready_to_deploy'=>['label'=>'Ready to deploy','employee'=>'testing','step'=>4,'next'=>'Owner deployment approval is required.'],
 'awaiting_deployment_approval'=>['label'=>'Deployment approval required','employee'=>'testing','step'=>4,'next'=>'Approve deployment or defer.'],
 'deploying'=>['label'=>'Deploying','employee'=>'testing','step'=>4,'next'=>'Deployment is in progress.'],
 'deployment_failed'=>['label'=>'Deployment failed','employee'=>'reopened','step'=>4,'next'=>'Review the failure and retry safely.'],
 'verification_pending'=>['label'=>'Live verification required','employee'=>'testing','step'=>5,'next'=>'Confirm whether the original problem is fixed on the live portal.'],
 'verification_failed'=>['label'=>'Live verification failed','employee'=>'reopened','step'=>5,'next'=>'The issue remains open for further repair.'],
 'done'=>['label'=>'Done','employee'=>'done','step'=>5,'next'=>'The repair passed deployment and live verification.'],
 'deferred'=>['label'=>'Deferred','employee'=>'deferred','step'=>2,'next'=>'Resume review when the issue is ready.'],
 'duplicate'=>['label'=>'Duplicate','employee'=>'under_review','step'=>2,'next'=>'Review the linked issue.'],
 'manual_engineering_required'=>['label'=>'Manual engineering required','employee'=>'deferred','step'=>3,'next'=>'Manual intervention is required.'],
 'reopened'=>['label'=>'Reopened','employee'=>'reopened','step'=>3,'next'=>'Review the new evidence and restart the repair.'],
];}

function siw_normalise_stage(array $issue): string {$raw=(string)($issue['workflow_stage']??'');$map=['awaiting_approval'=>'awaiting_owner_approval','owner_approval_required'=>'awaiting_owner_approval','approved_for_repair'=>'approved','repair_queued'=>'codex_queued','repair_in_progress'=>'codex_running','pr_open'=>'pr_ready','tests_passed'=>'ready_to_deploy','deployed'=>'verification_pending','verified'=>empty($issue['verified_at'])?'verification_pending':'done'];$stage=$map[$raw]??$raw;if(isset(siw_stages()[$stage]))return $stage;$legacy=(string)($issue['internal_status']??'reported');return $map[$legacy]??(isset(siw_stages()[$legacy])?$legacy:'reported');}

function siw_command_registry(): array {return [
 'approve_repair'=>['from'=>['awaiting_owner_approval'],'to'=>'approved','label'=>'Approve Repair','pending'=>'Approving…','event'=>'repair_approved','message'=>'approved the issue for controlled repair.','primary'=>true],
 'request_information'=>['from'=>['awaiting_owner_approval','brief_ready'],'to'=>'needs_information','label'=>'Request Information','pending'=>'Opening request…','event'=>'information_requested','message'=>'requested more employee-observable information.'],
 'defer_issue'=>['from'=>['awaiting_owner_approval','repair_failed','tests_failed','awaiting_deployment_approval','reopened'],'to'=>'deferred','label'=>'Defer Issue','pending'=>'Deferring…','event'=>'issue_deferred','message'=>'deferred the issue.'],
 'resume_review'=>['from'=>['deferred'],'to'=>'awaiting_owner_approval','label'=>'Resume Review','pending'=>'Resuming…','event'=>'review_resumed','message'=>'resumed owner review.','primary'=>true],
 'retry_repair'=>['from'=>['repair_failed','tests_failed','verification_failed','reopened'],'to'=>'codex_queued','label'=>'Retry Repair','pending'=>'Queueing…','event'=>'repair_requeued','message'=>'queued another controlled repair attempt.','primary'=>true,'outbox'=>true],
 'approve_deployment'=>['from'=>['awaiting_deployment_approval'],'to'=>'deploying','label'=>'Approve Deployment','pending'=>'Approving…','event'=>'deployment_approved','message'=>'approved the tested repair for deployment.','primary'=>true,'outbox'=>true],
 'confirm_fixed'=>['from'=>['verification_pending'],'to'=>'done','label'=>'Confirm Fixed on Live Site','pending'=>'Confirming…','event'=>'issue_completed','message'=>'confirmed the repair on the live portal.','primary'=>true,'requires_verification'=>true],
 'still_not_fixed'=>['from'=>['verification_pending'],'to'=>'verification_failed','label'=>'Still Not Fixed','pending'=>'Saving…','event'=>'verification_failed','message'=>'reported that the live issue is still not fixed.'],
];}

function siw_permitted_actions(string $stage): array {$result=[];foreach(siw_command_registry() as $command=>$rule)if(in_array($stage,$rule['from'],true))$result[]=['command'=>$command,'label'=>$rule['label'],'pending_label'=>$rule['pending'],'primary'=>(bool)($rule['primary']??false)];return $result;}
function siw_has_blocking_employee_request(array $issue,bool $lock=false): bool {
 if(array_key_exists('blocking_request_count',$issue))return (int)$issue['blocking_request_count']>0;
 $issueId=(int)($issue['id']??0);if($issueId<1)return !empty($issue['has_blocking_employee_request']);
 $suffix=$lock?' FOR UPDATE':'';
 return (bool)ops_rows("SELECT id FROM system_issue_information_requests WHERE issue_id=? AND audience='employee' AND is_blocking=1 AND status='pending' LIMIT 1".$suffix,[$issueId]);
}
function siw_view(array $issue): array {
 $stage=siw_normalise_stage($issue);$blocking=siw_has_blocking_employee_request($issue);
 if($blocking&&in_array($stage,['reported','ai_processing','needs_information','brief_ready','awaiting_owner_approval'],true)){$stage='needs_information';}
 elseif(!$blocking&&$stage==='needs_information'){$stage='awaiting_owner_approval';}
 $definition=siw_stages()[$stage];$actions=$blocking?array_values(array_filter(siw_permitted_actions($stage),fn(array $action):bool=>$action['command']!=='approve_repair')):siw_permitted_actions($stage);
 return ['issue_id'=>(int)($issue['id']??0),'internal_status'=>$blocking?'needs_information':($stage==='awaiting_owner_approval'?'brief_ready':$stage),'workflow_stage'=>$stage,'workflow_version'=>(int)($issue['workflow_version']??1),'workflow_label'=>$blocking?'Waiting for employee information':$definition['label'],'employee_status'=>$blocking?'needs_information':$definition['employee'],'employee_status_label'=>system_issue_status_label($blocking?'needs_information':$definition['employee']),'approval_allowed'=>!$blocking&&$stage==='awaiting_owner_approval','message'=>'Workflow state loaded.','next_step'=>$blocking?'Approval becomes available after the required information is received.':$definition['next'],'next_required_action'=>$blocking?'Wait for the requested employee information.':$definition['next'],'progress_step'=>$definition['step'],'permitted_actions'=>$actions,'blocking_employee_request'=>$blocking];
}

function siw_execute(int $issueId,string $command,string $expectedStage,int $expectedVersion,string $idempotencyKey,string $note=''): array {
 if(!isset(siw_command_registry()[$command]))throw new DomainException('invalid_command');if(!preg_match('/^[A-Za-z0-9._:-]{16,80}$/',$idempotencyKey))throw new InvalidArgumentException('invalid_idempotency_key');
 $actor=(int)(current_user()['id']??0);$actorName=(string)(current_user()['name']??'Owner');$rule=siw_command_registry()[$command];db()->beginTransaction();try{
  $existing=ops_rows('SELECT response_json FROM system_issue_workflow_actions WHERE issue_id=? AND idempotency_key=? FOR UPDATE',[$issueId,$idempotencyKey])[0]??null;if($existing&&$existing['response_json']){db()->commit();return (array)json_decode((string)$existing['response_json'],true);}
  $issue=ops_rows('SELECT * FROM system_issues WHERE id=? FOR UPDATE',[$issueId])[0]??null;if(!$issue)throw new OutOfBoundsException('issue_not_found');$stage=siw_normalise_stage($issue);$version=(int)($issue['workflow_version']??1);
  if($stage!==$expectedStage||$version!==$expectedVersion)throw new UnexpectedValueException('stale_workflow');if(!in_array($stage,$rule['from'],true))throw new DomainException('invalid_transition');
  if($command==='approve_repair'&&siw_has_blocking_employee_request($issue,true))throw new LogicException('pending_information');
  if($command==='confirm_fixed'){$integration=ops_rows('SELECT * FROM system_issue_integrations WHERE issue_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$issueId])[0]??null;if(!$integration||empty($integration['tests_passed_at'])||empty($integration['deployed_at'])||!empty($integration['rollback_active']))throw new LogicException('verification_prerequisite');}
  $to=$rule['to'];$target=siw_stages()[$to];$newVersion=$version+1;$now=date('Y-m-d H:i:s');$approvedAt=$command==='approve_repair'?$now:($issue['approved_at']??null);$approvedBy=$command==='approve_repair'?$actor:($issue['approved_by']??null);$verifiedAt=$command==='confirm_fixed'?$now:($issue['verified_at']??null);$verifiedBy=$command==='confirm_fixed'?$actor:($issue['verified_by']??null);$doneAt=$command==='confirm_fixed'?$now:($issue['done_at']??null);
  db()->prepare('UPDATE system_issues SET workflow_stage=?,workflow_version=?,employee_status=?,internal_status=?,approved_at=?,approved_by=?,verified_at=?,verified_by=?,done_at=? WHERE id=?')->execute([$to,$newVersion,$target['employee'],$to,$approvedAt,$approvedBy,$verifiedAt,$verifiedBy,$doneAt,$issueId]);
  $eventMessage=$actorName.' '.$rule['message'];system_issue_event($issueId,$rule['event'],$stage,$to,$eventMessage,['command'=>$command,'workflow_version'=>$newVersion,'authenticated_actor_user_id'=>$actor,'note'=>$note]);
  if($command==='approve_repair'||!empty($rule['outbox']))db()->prepare("INSERT INTO system_issue_workflow_outbox(issue_id,event_type,payload_json,status) VALUES(?,?,?,'pending')")->execute([$issueId,$command,json_encode(['issue_id'=>$issueId,'command'=>$command,'workflow_version'=>$newVersion],JSON_UNESCAPED_SLASHES)]);
  $fresh=$issue;$fresh['workflow_stage']=$to;$fresh['workflow_version']=$newVersion;$result=siw_view($fresh);$result['message']=$command==='approve_repair'?'The repair was approved successfully.':$target['label'].' saved successfully.';$result['activity_event']=['title'=>ucwords(str_replace('_',' ',$rule['event'])),'message'=>$eventMessage,'created_at_display'=>date('j M Y · H:i')];$encoded=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);db()->prepare('INSERT INTO system_issue_workflow_actions(issue_id,idempotency_key,command,response_json) VALUES(?,?,?,?)')->execute([$issueId,$idempotencyKey,$command,$encoded]);db()->commit();return $result;
 }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
