<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_once BASE_PATH.'/shared/system-issue-workflow.php';
header('Content-Type: application/json; charset=utf-8');
function siw_status_json(int $status,bool $success,array $data): void {http_response_code($status);echo json_encode(['success'=>$success,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET')siw_status_json(400,false,['message'=>'Invalid request.']);
if(!isset($_SESSION['user'])||!is_array($_SESSION['user'])||!portal_validate_authenticated_session())siw_status_json(401,false,['message'=>'Sign in before viewing this workflow.']);
refresh_logged_in_user();$userId=(int)(current_user()['id']??0);$owner=system_issue_is_owner();$issueId=(int)($_GET['issue_id']??0);$issue=system_issue_find_visible($issueId,$userId,$owner);if(!$issue)siw_status_json(404,false,['message'=>'This system issue is unavailable.']);$data=siw_view($issue);if(!$owner){$data['permitted_actions']=[];unset($data['queue_job_id'],$data['queue_attempts'],$data['queue_last_error']);}siw_status_json(200,true,$data);
