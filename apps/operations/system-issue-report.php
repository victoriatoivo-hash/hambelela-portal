<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/system-issues.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function sir_json(array $payload,int $status=200): void {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function sir_field(string $key,int $max): string {$value=trim((string)($_POST[$key]??''));return mb_substr($value,0,$max);}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
    if(!ops_database_ready()||!system_issues_schema_ready())throw new RuntimeException('System Issues is temporarily unavailable.');
    system_issue_verify_csrf((string)($_POST['csrf']??''));
    $action=sir_field('action',40);
    $employeeId=(int)(ops_current_employee_id()??0);$userId=(int)(current_user()['id']??0);
    if($action==='delete_issue'){
        if(!system_issue_is_owner())sir_json(['ok'=>false,'error'=>'Only an owner or administrator can delete a System Issue.'],403);
        $id=max(1,(int)($_POST['issue_id']??0));$reason=sir_field('deletion_reason',500);
        $issue=ops_rows('SELECT * FROM system_issues WHERE id=? AND deleted_at IS NULL LIMIT 1',[$id])[0]??null;
        if(!$issue)sir_json(['ok'=>false,'error'=>'This System Issue no longer exists or was already deleted.'],404);
        db()->beginTransaction();
        try {system_issue_event($id,'issue_deleted',(string)$issue['internal_status'],'deleted',$reason!==''?$reason:'Owner removed a duplicate, test or invalid System Issue.',['soft_delete'=>true]);$s=db()->prepare('UPDATE system_issues SET deleted_at=NOW(),deleted_by=?,deletion_reason=? WHERE id=? AND deleted_at IS NULL');$s->execute([$userId,$reason!==''?$reason:null,$id]);db()->commit();}
        catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw$error;}
        sir_json(['ok'=>true,'issue_id'=>$id,'message'=>'System Issue deleted.']);
    }
    if($action!=='report'&&$action!=='attach_duplicate_evidence')throw new RuntimeException('Unsupported action.');
    if($action==='attach_duplicate_evidence'){
        $id=max(1,(int)($_POST['existing_issue_id']??0));$issue=system_issue_find_visible($id,$userId,system_issue_is_owner());if(!$issue)throw new RuntimeException('The existing System Issue is not available.');
        system_issue_uploads($id,$employeeId);system_issue_event($id,'duplicate_evidence_added',null,null,'Additional evidence was added from a duplicate report.');
        sir_json(['ok'=>true,'duplicate'=>true,'issue_id'=>$id,'issue_key'=>$issue['issue_key'],'message'=>'Evidence added to '.$issue['issue_key'].'.']);
    }
    $fields=[];foreach(['problem'=>1200,'location'=>255,'attempted_action'=>1200,'observed_behaviour'=>1800,'expected_behaviour'=>1200]as$key=>$max){$fields[$key]=sir_field($key,$max);if($fields[$key]==='')throw new RuntimeException('Please complete every problem-description field.');}
    $route=mb_substr((string)($_POST['route']??''),0,500);$signature=system_issue_report_signature($userId,$fields,$route);$token=preg_replace('/[^a-zA-Z0-9-]/','',(string)($_POST['submission_token']??''));if(strlen($token)<16)throw new RuntimeException('This submission expired. Close the form and try again.');
    $duplicate=system_issue_recent_duplicate($userId,$signature);
    if($duplicate)sir_json(['ok'=>true,'duplicate'=>true,'issue_id'=>(int)$duplicate['id'],'issue_key'=>$duplicate['issue_key'],'title'=>$duplicate['title'],'has_evidence'=>!empty($_FILES['evidence']['name']),'message'=>'This problem was already reported as '.$duplicate['issue_key'].'.']);
    if(empty($_POST['submit_anyway'])){$similar=system_issue_similar_recent($userId,$fields,$route);if($similar)sir_json(['ok'=>false,'possible_duplicate'=>true,'existing_issue'=>['id'=>(int)$similar['id'],'issue_key'=>$similar['issue_key'],'title'=>$similar['title']],'error'=>'A similar System Issue was reported recently. Review it before submitting another.'],409);}
    try {$s=db()->prepare("INSERT INTO system_issues(reporter_employee_id,reported_by_user_id,title,problem,location,attempted_action,observed_behaviour,expected_behaviour,route,internal_status,duplicate_signature,submission_token) VALUES(?,?,?,?,?,?,?,?,?,'ai_processing',?,?)");$s->execute([$employeeId,$userId,mb_substr($fields['problem'],0,90),$fields['problem'],$fields['location'],$fields['attempted_action'],$fields['observed_behaviour'],$fields['expected_behaviour'],$route,$signature,$token]);}
    catch(Throwable $error){$existing=ops_rows('SELECT id,issue_key,title FROM system_issues WHERE submission_token=? LIMIT 1',[$token])[0]??null;if($existing)sir_json(['ok'=>true,'duplicate'=>true,'issue_id'=>(int)$existing['id'],'issue_key'=>$existing['issue_key'],'title'=>$existing['title'],'message'=>'This submission was already saved as '.$existing['issue_key'].'.']);throw$error;}
    $id=(int)db()->lastInsertId();$key=system_issue_generate_key($id);system_issue_uploads($id,$employeeId);system_issue_event($id,'reported',null,'ai_processing','Employee submitted a system issue.');system_issue_notify(['title'=>'System issue received','message'=>$key.' was saved and is being reviewed.','action_link'=>BASE_URL.'/apps/operations/system-issues.php?issue='.$id],[$userId]);$result=system_issue_triage($id);$row=ops_rows('SELECT i.*,e.full_name reporter_name FROM system_issues i LEFT JOIN ops_employees e ON e.id=i.reported_by_user_id WHERE i.id=?',[$id])[0];$view=siw_view($row);
    sir_json(['ok'=>true,'duplicate'=>false,'issue'=>['id'=>$id,'issue_key'=>$key,'title'=>$row['title'],'location'=>$row['location'],'reporter_name'=>$row['reporter_name'],'created_at'=>date('d M Y · H:i',strtotime($row['created_at'])),'status'=>$view['employee_status'],'status_label'=>$view['employee_status_label']],'message'=>(string)($result['message']??'Problem reported successfully.')]);
}catch(Throwable $error){error_log('System Issue report endpoint: '.$error->getMessage());sir_json(['ok'=>false,'error'=>$error->getMessage()],400);}
