<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_once BASE_PATH.'/shared/system-issue-workflow.php';
header('Content-Type: application/json; charset=utf-8');
if(!isset($_SESSION['user'])||!is_array($_SESSION['user'])||!portal_validate_authenticated_session()){http_response_code(401);echo json_encode(['success'=>false,'data'=>['message'=>'Sign in before viewing reconciliation.']]);exit;}
refresh_logged_in_user();
if(!system_issue_is_owner()){http_response_code(403);echo json_encode(['success'=>false,'data'=>['message'=>'Owner access is required.']]);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'||($_GET['mode']??'')!=='dry-run'){http_response_code(400);echo json_encode(['success'=>false,'data'=>['message'=>'Only dry-run reconciliation is available.']]);exit;}
$rows=ops_rows("SELECT i.*,(SELECT COUNT(*) FROM system_issue_information_requests r WHERE r.issue_id=i.id AND r.audience='employee' AND r.is_blocking=1 AND r.status='pending') blocking_request_count FROM system_issues i ORDER BY i.id");
$report=[];
foreach($rows as $issue){$view=siw_view($issue);$change=(string)$issue['internal_status']!==$view['internal_status']||(string)$issue['employee_status']!==$view['employee_status']||siw_normalise_stage($issue)!==$view['workflow_stage'];$report[]=['issue_id'=>(int)$issue['id'],'current_internal_status'=>(string)$issue['internal_status'],'current_employee_status'=>(string)$issue['employee_status'],'current_workflow_stage'=>(string)$issue['workflow_stage'],'blocking_request_found'=>$view['blocking_employee_request'],'proposed_internal_status'=>$view['internal_status'],'proposed_employee_status'=>$view['employee_status'],'proposed_workflow_display'=>$view['workflow_label'],'database_change_required'=>$change,'reason'=>$view['blocking_employee_request']?'A structured open blocking employee request exists.':'No structured open blocking employee request exists.'];}
echo json_encode(['success'=>true,'data'=>['mode'=>'dry-run','changes_applied'=>false,'issues'=>$report]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
