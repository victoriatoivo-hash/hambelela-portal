<?php
declare(strict_types=1);
ob_start();
require __DIR__.'/marketing-assistant-fixture.php';
$passed=0;
function check(bool $ok,string $name): void { if(!$ok)throw new RuntimeException('FAIL: '.$name);$GLOBALS['passed']++;echo 'PASS: '.$name.PHP_EOL; }
function rejects(callable $callback,string $message): void { try{$callback();}catch(Throwable $e){check(str_contains($e->getMessage(),$message),$message);return;}throw new RuntimeException('Expected rejection: '.$message); }
check(marketing_employee_view_allowed('social')&&!marketing_employee_view_allowed('reports')&&!marketing_employee_view_allowed('analytics')&&!marketing_employee_view_allowed('performance'),'employee route allowlist');
foreach(['save','status','product_save','phase3_campaign_save','phase3_metric_save'] as $action)check(!marketing_employee_action_allowed($action),'employee cannot perform '.$action);
check(marketing_employee_action_allowed('upload'),'assigned asset upload allowed');
check(!marketing_work_matches_app(['status'=>'ready_for_review'],'tasks'),'owner-review queue excluded from employee action badges');
check(marketing_execution_item(1)['assigned_employee_id']===20,'assigned item accessible');
rejects(fn()=>marketing_execution_item(2),'Assigned work not found');
check(count(marketing_employee_work())===2,'current-user query excludes other employee');
check(marketing_work_matches_app(array_column(marketing_employee_work(),null,'id')[1],'whatsapp'),'multi-channel work appears in WhatsApp regardless of content type');
rejects(fn()=>marketing_execution_save(['id'=>1,'execution_status'=>'published','channels'=>['instagram'=>['completed'=>1]]]),'Complete every required channel');
check(marketing_execution_progress(marketing_execution_rows(marketing_execution_item(1)))['complete']===0,'failed completion rolls back all channel changes');
rejects(fn()=>marketing_execution_save(['id'=>1,'execution_status'=>'approved','channels'=>['unknown'=>[]]]),'requirements changed');
rejects(fn()=>marketing_execution_save(['id'=>3,'execution_status'=>'approved']),'Choose an execution status');
rejects(fn()=>marketing_execution_save(['id'=>3,'channels'=>['newsletter'=>['completed'=>1]]]),'Owner approval is required');
rejects(fn()=>marketing_execution_save(['id'=>1,'channels'=>['instagram'=>['proof_url'=>'javascript:alert(1)']]]),'valid web link');
marketing_execution_save(['id'=>1,'channels'=>['instagram'=>['completed'=>1,'proof_url'=>'https://example.com/post','note'=>'Posted']]]);
check(marketing_execution_item(1)['status']==='approved','partial save preserves approved state');
check(marketing_execution_progress(marketing_execution_rows(marketing_execution_item(1)))['percent']===33,'three-channel progress denominator');
marketing_execution_save(['id'=>1,'channels'=>['whatsapp'=>['prepared'=>1]]]);
$rows=array_column(marketing_execution_rows(marketing_execution_item(1)),null,'channel_key');
check($rows['instagram']['completed']===1&&$rows['instagram']['proof_url']==='https://example.com/post','omitted channel proof and completion retained');
check(marketing_work_matches_app(array_column(marketing_employee_work(),null,'id')[1],'social')===false,'completed Social channel no longer contributes to pending badge');
$fixtureRole='owner_admin';$fixtureUserId=1;
marketing_execution_requirements(1,['instagram','whatsapp'],['instagram'=>'Revised brief']);
check(count(marketing_execution_rows(marketing_execution_item(1)))===2,'owner can remove active channel requirement');
check((int)db()->query("SELECT COUNT(*) FROM marketing_channel_execution WHERE item_id=1 AND channel_key='website'")->fetchColumn()===1,'deselected channel is preserved');
marketing_execution_requirements(1,['instagram','whatsapp','website'],[]);
check(marketing_execution_progress(marketing_execution_rows(marketing_execution_item(1)))['complete']===1,'reselecting channels preserves progress');
rejects(fn()=>marketing_execution_requirements(1,[],[]),'Select at least one');
check(marketing_execution_item(2)['assigned_employee_id']===21,'owner retains cross-employee access');
marketing_execution_save(['id'=>3,'execution_status'=>'published']);
check(marketing_execution_item(3)['status']==='published','owner can override incomplete channel work');
$fixtureRole='marketing_sales';$fixtureUserId=20;
$fixtureNoticeFail=true;
marketing_execution_save(['id'=>1,'execution_status'=>'published','channels'=>['whatsapp'=>['completed'=>1],'website'=>['completed'=>1]]]);
check(marketing_execution_item(1)['status']==='published','all-channel completion commits despite notification outage');
check(!marketing_work_matches_app(marketing_execution_item(1),'tasks'),'completed items excluded from outstanding badges');
check(!portal_role_can_access_feature('marketing_sales','hr'),'HR permission unchanged');
check(portal_role_can_access_feature('front_desk_admin','courier')&&portal_role_can_access_feature('marketing_sales','courier'),'Front and Marketing retain Courier entry access');
require_once BASE_PATH.'/shared/ess-navigation.php';
check(!in_array('Employee Performance',array_column(ess_shell_apps(),'name'),true),'employee sidebar excludes performance');
check(in_array('Courier',array_column(ess_shell_apps(),'name'),true),'employee sidebar includes Courier');
$fixtureRole='owner_admin';
check(in_array('Employee Performance',array_column(ess_shell_apps(),'name'),true),'owner sidebar retains performance');
// Execute existing Task scope/access helpers against real fixture rows.
function ops_current_employee_id(): ?int { return marketing_employee_id(); }
function ops_row(string $sql,array $params): ?array { $q=db()->prepare($sql);$q->execute($params);return $q->fetch()?:null; }
$ops=file_get_contents(BASE_PATH.'/apps/operations/operations.php');
$start=strpos($ops,'function ops_task_scope_for_current_user');$end=strpos($ops,'function ops_can_update_order_paid_status',$start);
eval(substr($ops,$start,$end-$start));
db()->exec('CREATE TABLE ops_checklist_tasks(id INTEGER PRIMARY KEY,assigned_employee_id INTEGER,employee_visible INTEGER,scheduled_at TEXT,released_at TEXT,deleted_at TEXT)');
db()->exec("INSERT INTO ops_checklist_tasks VALUES(1,20,1,NULL,NULL,NULL),(2,21,1,NULL,NULL,NULL),(3,20,0,NULL,NULL,NULL),(4,20,1,'2026-12-01',NULL,NULL)");
$fixtureRole='marketing_sales';$fixtureUserId=20;
check(ops_current_user_can_access_task(1),'own task detail accessible');
check(!ops_current_user_can_access_task(2),'other employee task denied');
check(!ops_current_user_can_access_task(3)&&!ops_current_user_can_access_task(4),'hidden and unreleased tasks denied');
$fixtureRole='owner_admin';check(ops_task_scope_for_current_user()['type']==='all','owner task scope unchanged');
$fixtureRole='supervisor_manager';check(ops_task_scope_for_current_user()['type']==='assigned','existing supervisor task policy unchanged');
$courier=file_get_contents(BASE_PATH.'/apps/operations/courier.php');
$start=strpos($courier,'$roleKey = current_role_key();');$end=strpos($courier,'$historyDateFrom',$start);$policy=substr($courier,$start,$end-$start);$currentEmployeeId=20;
$fixtureRole='front_desk_admin';eval($policy);$front=[$canSendWaybills,$canUploadWaybills,$canExportWaybills,$canManageWaybills,$canDeleteWaybillsForever,$showOrderAssignment];
$fixtureRole='marketing_sales';eval($policy);check($front===[$canSendWaybills,$canUploadWaybills,$canExportWaybills,$canManageWaybills,$canDeleteWaybillsForever,$showOrderAssignment],'all six Courier permission flags match Front exactly');
echo "\n$passed checks passed. SQLite fixture only; no live database or network.\n";
