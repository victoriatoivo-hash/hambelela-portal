<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
header('Content-Type: application/json; charset=utf-8');
function callback_response(int $status,array $body): never {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')callback_response(405,['error'=>'POST required']);
if(!defined('SYSTEM_ISSUES_WORKFLOW_SECRET')||SYSTEM_ISSUES_WORKFLOW_SECRET==='')callback_response(503,['error'=>'Workflow callback is not configured']);
$raw=(string)file_get_contents('php://input');$provided=(string)($_SERVER['HTTP_X_HAMBELELA_SIGNATURE']??'');$expected='sha256='.hash_hmac('sha256',$raw,SYSTEM_ISSUES_WORKFLOW_SECRET);
if($provided===''||!hash_equals($expected,$provided))callback_response(401,['error'=>'Invalid signature']);
$payload=json_decode($raw,true);if(!is_array($payload))callback_response(400,['error'=>'Invalid JSON']);
$key=trim((string)($payload['issue_id']??''));$event=trim((string)($payload['event']??''));if(!preg_match('/^SYS-\d{4,}$/',$key)||$event==='')callback_response(422,['error'=>'issue_id and event are required']);
if(!system_issues_schema_ready())callback_response(503,['error'=>'System Issues storage unavailable']);
$issue=ops_rows('SELECT * FROM system_issues WHERE issue_key=?',[$key])[0]??null;if(!$issue)callback_response(404,['error'=>'Issue not found']);
$integration=ops_rows('SELECT * FROM system_issue_integrations WHERE issue_id=? ORDER BY id DESC LIMIT 1',[(int)$issue['id']])[0]??null;if(!$integration)callback_response(409,['error'=>'No controlled workflow exists']);
$allowed=['github_issue','branch_created','codex_running','pr_open','tests_passed','merged','deployed','verified','failed'];if(!in_array($event,$allowed,true))callback_response(422,['error'=>'Unsupported event']);
$now=date('Y-m-d H:i:s');$id=(int)$integration['id'];$issueId=(int)$issue['id'];$from=(string)$issue['internal_status'];$externalId=mb_substr((string)($payload['external_id']??''),0,190);$externalUrl=mb_substr((string)($payload['external_url']??''),0,500);
try {
    if($event==='github_issue')db()->prepare("UPDATE system_issue_integrations SET provider='github',external_id=?,external_url=?,status='github_issue' WHERE id=?")->execute([$externalId?:null,$externalUrl?:null,$id]);
    elseif($event==='branch_created')db()->prepare("UPDATE system_issue_integrations SET branch_name=?,status='branch_created' WHERE id=?")->execute([mb_substr((string)($payload['branch']??$integration['branch_name']),0,190),$id]);
    elseif($event==='codex_running'){db()->prepare("UPDATE system_issue_integrations SET status='codex_running' WHERE id=?")->execute([$id]);db()->prepare("UPDATE system_issues SET internal_status='codex_running',employee_status='fix_in_progress',codex_status='running' WHERE id=?")->execute([$issueId]);}
    elseif($event==='pr_open'){db()->prepare("UPDATE system_issue_integrations SET pull_request_number=?,external_url=?,status='pr_open' WHERE id=?")->execute([(int)($payload['pull_request_number']??0)?:null,$externalUrl?:null,$id]);db()->prepare("UPDATE system_issues SET internal_status='pr_open',employee_status='fix_in_progress' WHERE id=?")->execute([$issueId]);}
    elseif($event==='tests_passed'){db()->prepare("UPDATE system_issue_integrations SET tests_passed_at=?,status='tests_passed' WHERE id=?")->execute([$now,$id]);db()->prepare("UPDATE system_issues SET internal_status='tests_passed',employee_status='testing' WHERE id=?")->execute([$issueId]);}
    elseif($event==='merged'){if(empty($integration['tests_passed_at']))throw new RuntimeException('Tests must pass before merge.');db()->prepare("UPDATE system_issue_integrations SET merged_at=?,status='merged' WHERE id=?")->execute([$now,$id]);db()->prepare("UPDATE system_issues SET internal_status='merged',employee_status='testing' WHERE id=?")->execute([$issueId]);}
    elseif($event==='deployed'){if(empty($integration['merged_at']))throw new RuntimeException('Merge is required before deployment.');db()->prepare("UPDATE system_issue_integrations SET deployed_at=?,status='deployed' WHERE id=?")->execute([$now,$id]);db()->prepare("UPDATE system_issues SET internal_status='deployed',employee_status='testing' WHERE id=?")->execute([$issueId]);}
    elseif($event==='verified'){if(empty($integration['deployed_at']))throw new RuntimeException('Deployment is required before live verification.');db()->prepare("UPDATE system_issue_integrations SET live_verified_at=?,status='verified' WHERE id=?")->execute([$now,$id]);db()->prepare("UPDATE system_issues SET internal_status='done',employee_status='done',codex_status='complete',verified_at=? WHERE id=?")->execute([$now,$issueId]);}
    else {db()->prepare("UPDATE system_issue_integrations SET status='failed',last_error=? WHERE id=?")->execute([mb_substr((string)($payload['error']??'Workflow failed.'),0,1000),$id]);db()->prepare("UPDATE system_issues SET internal_status='reopened',employee_status='reopened',codex_status='failed' WHERE id=?")->execute([$issueId]);}
    system_issue_event($issueId,'workflow_'.$event,$from,$event,mb_substr((string)($payload['message']??str_replace('_',' ',$event)),0,1000),['external_id'=>$externalId,'external_url'=>$externalUrl]);
    $updated=ops_rows('SELECT employee_status FROM system_issues WHERE id=?',[$issueId])[0]??[];system_issue_notify(['title'=>$key.' · '.system_issue_status_label((string)($updated['employee_status']??'reported')),'message'=>mb_substr((string)($payload['message']??'Your system issue has been updated.'),0,500),'action_link'=>BASE_URL.'/apps/operations/system-issues.php?issue='.$issueId],[(int)$issue['reporter_employee_id']]);
} catch(Throwable $e){callback_response(409,['error'=>$e->getMessage()]);}
callback_response(200,['ok'=>true,'issue_id'=>$key,'event'=>$event]);
