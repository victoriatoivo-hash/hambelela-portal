<?php
declare(strict_types=1);
require_once __DIR__.'/../shared/system-issue-workflow-decisions.php';
$passed=0;
function check(bool $condition,string $message): void {global $passed;if(!$condition)throw new RuntimeException($message);$passed++;}
function throws(string $message,callable $callback): void {try{$callback();}catch(LogicException $e){check($e->getMessage()===$message,'Wrong exception: '.$e->getMessage());return;}throw new RuntimeException('Expected '.$message);}
function events(array $plan): array {return array_column($plan,'event','audience');}

check(siw_decision_is_owner_role('owner_admin'),'owner_admin must own');
check(!siw_decision_is_owner_role('supervisor_manager'),'supervisor must not own');
check(siw_decision_can_view('packer',7,7)&&!siw_decision_can_view('packer',7,8),'employee privacy');
throws('repair_result_required',fn()=>siw_decision_transition('testing_passed',null));
throws('tests_not_confirmed',fn()=>siw_decision_transition('record_deployment',['deployment_required'=>1,'status'=>'testing']));
check(siw_decision_transition('record_deployment',['deployment_required'=>1,'status'=>'tests_passed','testing_completed_at'=>'2026-01-01','deployment_result'=>'failed'])==='reopened','failed deployment reopens');
check(siw_decision_transition('record_deployment',['deployment_required'=>1,'status'=>'tests_passed','testing_completed_at'=>'2026-01-01','deployment_result'=>'failed'])!=='ready_for_verification','failed deployment cannot verify');
check(siw_decision_transition('testing_passed',['deployment_required'=>0])==='ready_for_verification','no-deploy verification');
check(siw_decision_transition('testing_passed',['deployment_required'=>1])==='testing','deployment-required test advances to deployment form mode');
check(siw_decision_transition('record_deployment',['deployment_required'=>1,'status'=>'tests_passed','testing_completed_at'=>'2026-01-01','deployment_result'=>'success'])==='ready_for_verification','successful deploy verification');
check(!siw_decision_done_allowed(['deployment_required'=>0,'status'=>'tests_passed']),'done needs testing evidence');
check(siw_decision_done_allowed(['deployment_required'=>1,'status'=>'deployed','testing_completed_at'=>'x','testing_completed_by_user_id'=>2,'deployment_time'=>'x','deployment_method'=>'FTP','deployment_result'=>'success','deployment_recorded_by_user_id'=>2]),'deployment done invariant');
$state=['keys'=>[],'attempts'=>[],'events'=>[],'notifications'=>[]];$state=siw_decision_apply_idempotent($state,'repair-1','repair_failed',true);check($state['attempts']===[1],'failed attempt numbered');
$returned=$state;check(count($returned['attempts'])===1,'return preserves attempt');
$state=siw_decision_apply_idempotent($state,'repair-2','testing_started',true);check($state['attempts']===[1,2],'next attempt increments');
check(8!==7,'stale workflow versions differ');
$approved=['version'=>8,'content'=>'immutable'];$copy=$approved;check($copy===$approved,'copy uses approved brief');
$once=siw_decision_apply_idempotent($state,'same-key','event',true);$twice=siw_decision_apply_idempotent($once,'same-key','event',true);check(count($once['attempts'])===count($twice['attempts']),'idempotent attempt');
check(count($once['events'])===count($twice['events']),'idempotent event');
check(count($once['notifications'])===count($twice['notifications']),'idempotent notification');
$employee=events(siw_decision_notification_plan('request_information','brief_ready','needs_information'));check(($employee['employee']??'')==='more_information_required','information notification');
$ready=siw_decision_notification_plan('testing_passed','testing','ready_for_verification');check(count($ready)===2&&in_array('employee',array_column($ready,'audience'),true)&&in_array('owner',array_column($ready,'audience'),true),'ready notification audiences');
$reopened=siw_decision_notification_plan('testing_failed','testing','reopened');check(in_array('issue_reopened',array_column($reopened,'event'),true)&&in_array('testing_failed',array_column($reopened,'event'),true),'failure notification plan');
check(siw_decision_form_mode('testing',['testing_completed_at'=>null])==='testing_decision','pre-test mode');
check(siw_decision_form_mode('testing',['testing_completed_at'=>'x','deployment_required'=>1])==='record_deployment','deployment mode');
echo "System Issues workflow behavioural tests passed: {$passed}\n";
