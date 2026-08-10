<?php
declare(strict_types=1);

require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_once BASE_PATH.'/shared/system-issue-workflow.php';

header('Content-Type: application/json; charset=utf-8');

function siw_json(int $status, bool $success, array $data): void {
    http_response_code($status);
    echo json_encode(['success'=>$success, 'data'=>$data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') siw_json(400, false, ['code'=>'malformed_request', 'message'=>'Invalid request.']);
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || !portal_validate_authenticated_session()) siw_json(401, false, ['code'=>'unauthenticated', 'message'=>'Sign in before updating this workflow.']);
refresh_logged_in_user();
if (!system_issue_is_owner()) siw_json(403, false, ['code'=>'permission_denied', 'message'=>'You do not have permission to perform this action.']);

try {
    if (!ops_database_ready() || !system_issues_schema_ready()) throw new RuntimeException('schema_unavailable');
    system_issue_verify_csrf((string)($_POST['csrf'] ?? ''));
    $issueId = (int)($_POST['issue_id'] ?? 0);
    $command = mb_substr(trim((string)($_POST['command'] ?? '')), 0, 50);
    $expectedStage = mb_substr(trim((string)($_POST['expected_stage'] ?? '')), 0, 40);
    $expectedVersion = (int)($_POST['expected_version'] ?? 0);
    $key = mb_substr(trim((string)($_POST['idempotency_key'] ?? '')), 0, 80);
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 4000);
    if ($issueId < 1 || $command === '' || $expectedStage === '' || $expectedVersion < 1) siw_json(400, false, ['code'=>'malformed_request', 'message'=>'The workflow request is incomplete.']);
    $detailKeys = ['repair_summary','branch_name','commit_hash','pull_request_url','files_changed','tests_performed','tests_passed','tests_unavailable','known_limitations','deployment_required','deployment_method','deployment_time','deployed_commit','deployment_result','deployment_notes','reason'];
    $details = [];
    foreach ($detailKeys as $detailKey) $details[$detailKey] = mb_substr(trim((string)($_POST[$detailKey] ?? '')), 0, 12000);
    $result = siw_execute($issueId, $command, $expectedStage, $expectedVersion, $key, $note, $details);
    if ($command === 'approve_brief' && ($result['workflow_stage'] ?? '') === 'approved_for_codex') {
        $result['next_stage'] = 'fix_in_progress';
        $result['message'] = 'Technical brief approved — ready to copy and send to Codex.';
    }
    siw_json(200, true, $result);
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(6));
    error_log('system_issue_action reference='.$reference.' type='.get_class($error).' message='.$error->getMessage());
    $map = [
        'invalid_command'=>[400,'invalid_command','This workflow action is not recognised.'],
        'invalid_idempotency_key'=>[400,'malformed_request','Refresh the page and try again.'],
        'issue_not_found'=>[404,'unavailable_issue','This system issue is unavailable.'],
        'stale_workflow'=>[409,'stale_workflow','This workflow changed elsewhere. Refresh the issue and try again.'],
        'invalid_transition'=>[422,'invalid_transition','This action is not available at the current workflow stage.'],
        'pending_information'=>[422,'unmet_prerequisite','Answer or cancel the pending information request before approval.'],
        'approved_brief_missing'=>[422,'unmet_prerequisite','Generate a current technical brief before approval.'],
        'brief_not_copied'=>[422,'unmet_prerequisite','Copy the approved brief before confirming that it was sent to Codex.'],
        'repair_summary_required'=>[422,'unmet_prerequisite','Enter the Codex repair summary.'],
        'test_information_required'=>[422,'unmet_prerequisite','Record the tests performed and their passed or unavailable result.'],
        'reason_required'=>[422,'unmet_prerequisite','Enter the reason for this outcome.'],
        'repair_result_required'=>[422,'unmet_prerequisite','Record the Codex repair result before completing testing.'],
        'deployment_record_required'=>[422,'unmet_prerequisite','Record the successful deployment before owner verification.'],
        'deployment_not_required'=>[422,'unmet_prerequisite','This attempt does not require a deployment record.'],
        'tests_not_confirmed'=>[422,'unmet_prerequisite','Testing must be explicitly passed before deployment or verification.'],
        'invalid_deployment_result'=>[422,'unmet_prerequisite','Choose deployment success or failed.'],
        'done_invariant_failed'=>[422,'unmet_prerequisite','The latest repair attempt has not satisfied every testing and deployment requirement.'],
    ];
    [$status,$code,$message] = $map[$error->getMessage()] ?? ($error instanceof RuntimeException && $error->getMessage() === 'This form expired. Refresh and try again.' ? [403,'csrf_failed','Your session expired. Refresh the page and try again.'] : [500,'server_error','The workflow action could not be completed. Reference: '.$reference]);
    $current = [];
    if (isset($issueId) && $issueId > 0) {
        $row = ops_rows('SELECT * FROM system_issues WHERE id=? LIMIT 1', [$issueId])[0] ?? null;
        if ($row) $current = siw_view($row);
    }
    siw_json($status, false, array_merge(['code'=>$code, 'message'=>$message], $current));
}
