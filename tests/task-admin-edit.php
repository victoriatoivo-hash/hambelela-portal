<?php
require __DIR__.'/task-admin-fixture.php';
$pdo=task_test_db();$editor=['id'=>3,'name'=>'Authenticated Owner'];
$detail=task_admin_handle($pdo,'task_admin_detail',true,'fixture-csrf',['task_id'=>10,'csrf_token'=>'fixture-csrf'],$editor);
$input=task_test_input($detail['task']);$saved=task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);
task_test_assert($saved['task']['completed_by']===2,'Actual completer recorded separately from editor');
task_test_assert($saved['task']['completed_at']==='2026-10-08 16:00:00','Actual completion yesterday, not correction time');
task_test_assert($saved['task']['started_at']===null,'Opening/editing/management completion never invents a timer');
task_test_assert($saved['original']['employee_id']===1,'Original assignment preserved after reassignment');
task_test_assert($saved['timing']['outcome']==='On time','Due display uses actual completion time');
$audit=$saved['audits'][0];task_test_assert((int)$audit['editor_id']===3&&$audit['recorded_at']!==$saved['task']['completed_at'],'Authenticated Owner and correction timestamp retained');
task_test_assert(json_decode($audit['before_json'],true)['assigned_employee_id']===1&&json_decode($audit['after_json'],true)['assigned_employee_id']===2,'Permanent old and new responsibility audit');
$again=task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);task_test_assert(!empty($again['replayed'])&&count($again['audits'])===1,'Retry is idempotent, no duplicate correction');
$input['request_key']='fixture-save-00002';task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Concurrent stale revision rejected');
$before=task_admin_snapshot($saved['task']);$input['revision']=$saved['revision'];$input['actual_completed_at']='2099-01-01T12:00';task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Future completion rejected');
task_test_assert((int)$pdo->query('SELECT COUNT(*) FROM ops_task_management_audits')->fetchColumn()===1,'Failed validation writes no audit or task changes');
$input['actual_completed_at']='2026-10-08T16:00';$input['assigned_employee_id']=4;task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'New assignment to inactive employee rejected');
$input['assigned_employee_id']=2;$input['mark_complete']='';$input['status']='new';task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Reopening uses preserved existing correction workflow');
$pdo=task_test_db();task_admin_schema($pdo);$pdo->exec("UPDATE ops_checklist_tasks SET checklist_items='[\"Required step\"]'");$task=$pdo->query('SELECT * FROM ops_checklist_tasks')->fetch(PDO::FETCH_ASSOC);$input=task_test_input($task);
task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Required checklist preserved');
$input['checked_items']=['Required step'];$pdo->exec('UPDATE ops_checklist_tasks SET completion_evidence_required=1');$input['revision']=task_admin_revision($pdo->query('SELECT * FROM ops_checklist_tasks')->fetch(PDO::FETCH_ASSOC));
task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Required uploaded proof preserved');
$pdo->exec('INSERT INTO ops_checklist_attachments VALUES(1,10,NULL)');$saved=task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);task_test_assert($saved['task']['status']==='complete','On behalf completion works with required checklist, note and proof');

$input['revision']=$saved['revision'];$input['request_key']='fixture-save-00003';$again=task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);task_test_assert(!empty($again['unchanged'])&&count($again['audits'])===1,'Unchanged repeat save creates no duplicate audit');
$input['task_name']='Second correction';$input['editor_id']=1;$updated=task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);task_test_assert(count($updated['audits'])===2&&(int)$updated['audits'][0]['editor_id']===3,'Success revision supports another edit and ignores forged editor');
$input['revision']=$updated['revision'];$input['request_key']='fixture-save-00004';$input['task_name']='Failure fixture';$pdo->exec("CREATE TRIGGER test_fail_update BEFORE UPDATE ON ops_checklist_tasks BEGIN SELECT RAISE(ABORT,'fixture update failure'); END");task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Database failure rolls back correction');task_test_assert((int)$pdo->query('SELECT COUNT(*) FROM ops_task_management_audits')->fetchColumn()===2&&$pdo->query('SELECT task_name FROM ops_checklist_tasks')->fetchColumn()==='Second correction','Task and immutable audit commit together');
