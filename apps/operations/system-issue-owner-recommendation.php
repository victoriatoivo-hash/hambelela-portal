<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function owner_recommendation_reply(array $payload,int $status=200): void {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Invalid request.');
    if(!system_issue_is_owner())owner_recommendation_reply(['ok'=>false,'saved'=>false,'error'=>'Owner access is required.'],403);
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    system_issue_verify_csrf((string)($_POST['csrf']??''));
    $issueId=max(1,(int)($_POST['issue_id']??0));
    $action=trim((string)($_POST['action']??''));
    if(!in_array($action,['save_owner_recommendation','update_ai_brief'],true))throw new RuntimeException('Invalid recommendation action.');
    $issue=system_issue_find_visible($issueId,(int)(current_user()['id']??0),true);
    if(!$issue)throw new RuntimeException('Issue not found.');
    $text=trim((string)($_POST['owner_recommendation']??''));

    // This write intentionally commits through autocommit before any external AI request.
    $recommendationId=system_issue_save_owner_recommendation($issueId,$text);
    $saved=ops_rows('SELECT r.*,e.full_name created_by_name FROM system_issue_owner_recommendations r LEFT JOIN ops_employees e ON e.id=r.created_by WHERE r.id=? AND r.issue_id=? LIMIT 1',[$recommendationId,$issueId])[0]??null;
    if(!$saved||trim((string)$saved['recommendation_text'])!==$text)throw new RuntimeException('The saved recommendation could not be confirmed.');
    if($action==='save_owner_recommendation')owner_recommendation_reply(['ok'=>true,'saved'=>true,'message'=>'Owner recommendation saved.','recommendation'=>$saved]);

    try {$result=system_issue_regenerate_brief($issueId);}catch(Throwable $aiError){
        owner_recommendation_reply(['ok'=>false,'saved'=>true,'message'=>'Recommendation saved, but AI brief could not be updated: '.$aiError->getMessage(),'error'=>$aiError->getMessage(),'recommendation'=>$saved],502);
    }
    if(empty($result['ok'])){$aiReason=(string)($result['error']??$result['message']??'Unknown AI error.');owner_recommendation_reply(['ok'=>false,'saved'=>true,'message'=>'Recommendation saved, but AI brief could not be updated: '.$aiReason,'error'=>$aiReason,'recommendation'=>$saved],502);}
    owner_recommendation_reply(['ok'=>true,'saved'=>true,'message'=>'Recommendation saved and AI brief updated successfully.','recommendation'=>$saved,'brief_version'=>(int)($result['version']??0),'redirect'=>BASE_URL.'/apps/operations/system-issues.php?issue='.$issueId.'&brief_version='.(int)($result['version']??0)]);
} catch(Throwable $e) {
    owner_recommendation_reply(['ok'=>false,'saved'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Unable to save the owner recommendation.','message'=>$e instanceof RuntimeException?$e->getMessage():'Unable to save the owner recommendation.'],400);
}
