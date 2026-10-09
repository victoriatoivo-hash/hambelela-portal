<?php
require __DIR__.'/task-admin-fixture.php';
$pdo=task_test_db();$editor=['id'=>3,'name'=>'Owner'];$task=$pdo->query('SELECT * FROM ops_checklist_tasks')->fetch(PDO::FETCH_ASSOC);$input=task_test_input($task);
foreach(['task_admin_detail','admin_update_task']as$action)task_test_reject(function()use($pdo,$input,$editor,$action){task_admin_handle($pdo,$action,false,'fixture-csrf',$input,$editor);},'Employee cannot access '.$action);
$input['csrf_token']='invalid';task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'CSRF mismatch rejected');
task_test_assert(!ops_table_exists('ops_task_management_audits'),'Unauthorized requests do not create schema or records');
$input['csrf_token']='fixture-csrf';$input['task_id']=999;task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'admin_update_task',true,'fixture-csrf',$input,$editor);},'Missing task rejected');
$pdo->exec("UPDATE ops_checklist_tasks SET deleted_at='2026-10-09 08:00:00'");$input['task_id']=10;task_test_reject(function()use($pdo,$input,$editor){task_admin_handle($pdo,'task_admin_detail',true,'fixture-csrf',$input,$editor);},'Deleted task cannot be edited');
