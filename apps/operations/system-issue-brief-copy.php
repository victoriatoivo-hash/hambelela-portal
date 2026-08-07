<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Invalid request.');
    if(!user_has_role('owner_admin','supervisor_manager')){http_response_code(403);throw new RuntimeException('Owner access is required.');}
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    system_issue_verify_csrf((string)($_POST['csrf']??''));
    $issueId=max(1,(int)($_POST['issue_id']??0));$version=max(1,(int)($_POST['brief_version']??0));
    $issue=ops_rows('SELECT * FROM system_issues WHERE id=?',[$issueId])[0]??null;if(!$issue)throw new RuntimeException('Issue not found.');
    $brief=ops_rows('SELECT * FROM system_issue_ai_briefs WHERE issue_id=? AND version_number=? AND ai_brief_json IS NOT NULL LIMIT 1',[$issueId,$version])[0]??null;if(!$brief)throw new RuntimeException('Technical brief version not found.');
    $attachments=ops_rows('SELECT original_name,mime_type FROM system_issue_attachments WHERE issue_id=? ORDER BY id',[$issueId]);$plain=system_issue_brief_plain_text($issue,$brief,$attachments);
    system_issue_event($issueId,'brief_copied',null,null,'AI Technical Brief Version '.$version.' copied for Codex.',['brief_version'=>$version]);
    echo json_encode(['ok'=>true,'brief'=>$plain,'version'=>$version],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Unable to copy the brief. Please try again.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
