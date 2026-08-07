<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_once BASE_PATH.'/shared/system-issue-workflow.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Invalid request.');
    if(!system_issue_is_owner()){http_response_code(403);throw new RuntimeException('Owner access is required.');}
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    system_issue_verify_csrf((string)($_POST['csrf']??''));
    $issueId=max(1,(int)($_POST['issue_id']??0));$version=max(1,(int)($_POST['brief_version']??0));$expectedVersion=(int)($_POST['expected_version']??$_GET['expected_version']??0);
    db()->beginTransaction();
    $issue=ops_rows('SELECT * FROM system_issues WHERE id=? FOR UPDATE',[$issueId])[0]??null;if(!$issue)throw new RuntimeException('Issue not found.');
    if($expectedVersion<1)throw new RuntimeException('stale_workflow');
    if((int)($issue['workflow_version']??0)!==$expectedVersion)throw new RuntimeException('stale_workflow');
    if(siw_normalise_stage($issue)!=='approved_for_codex'&&siw_normalise_stage($issue)!=='fix_in_progress')throw new RuntimeException('Approve the current brief before copying it.');if((int)($issue['approved_brief_version']??0)!==$version)throw new RuntimeException('Only the immutable approved brief may be copied.');$brief=ops_rows('SELECT * FROM system_issue_ai_briefs WHERE id=? AND issue_id=? AND version_number=? AND ai_brief_json IS NOT NULL LIMIT 1',[(int)$issue['approved_brief_id'],$issueId,$version])[0]??null;if(!$brief)throw new RuntimeException('Approved technical brief not found.');
    $attachments=ops_rows('SELECT original_name,mime_type FROM system_issue_attachments WHERE issue_id=? ORDER BY id',[$issueId]);$plain=system_issue_brief_plain_text($issue,$brief,$attachments);
    $prefix="Implement only this approved System Issue.\n\nDo not modify unrelated modules.\nDo not change the operational Error Log.\nDo not change KPI, Performance, payroll, bonus or employee calculations.\nDo not make unrelated visual redesigns.\nPreserve the existing global JavaScript.\nInspect the existing implementation before editing.\nUse a separate feature branch.\nNever write directly to main.\nRun the relevant PHP, JavaScript and project tests.\nCommit and push the completed change.\nReport files changed, tests, commit hash and deployment requirements.\nStop and report a blocker if the fix cannot be completed safely.\n\n";$updated=db()->prepare('UPDATE system_issues SET brief_copied_at=COALESCE(brief_copied_at,NOW()),brief_copied_by=COALESCE(brief_copied_by,?) WHERE id=? AND brief_copied_at IS NULL');$updated->execute([(int)(current_user()['id']??0),$issueId]);if($updated->rowCount()===1)system_issue_event($issueId,'codex_brief_copied','approved_for_codex','approved_for_codex','Codex brief copied. Paste this brief into Codex to begin the repair.',['brief_version'=>$version,'authenticated_actor_user_id'=>(int)(current_user()['id']??0)]);
    db()->commit();
    echo json_encode(['ok'=>true,'brief'=>$prefix.$plain,'version'=>$version,'message'=>'Codex brief copied. Paste this brief into Codex to begin the repair.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    if(db()->inTransaction())db()->rollBack();
    if(http_response_code()<400)http_response_code(400);
    if($e->getMessage()==='stale_workflow')http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()==='stale_workflow'?'This workflow changed elsewhere. Refresh and try again.':($e instanceof RuntimeException?$e->getMessage():'Unable to copy the brief. Please try again.')],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
