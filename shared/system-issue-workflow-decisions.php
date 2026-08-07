<?php
declare(strict_types=1);

function siw_decision_is_owner_role(string $role): bool { return $role === 'owner_admin'; }
function siw_decision_can_view(string $role, int $viewerId, int $reporterId): bool { return siw_decision_is_owner_role($role) || ($viewerId > 0 && $viewerId === $reporterId); }
function siw_decision_form_mode(string $stage, ?array $attempt): string {
    if ($stage === 'fix_in_progress') return 'record_codex_result';
    if ($stage === 'deployment') return 'record_deployment';
    if ($stage === 'ready_for_verification') return 'verification';
    if ($stage === 'reopened') return 'reopened';
    if ($stage === 'testing') return 'testing_decision';
    return 'none';
}
function siw_decision_transition(string $command, ?array $attempt): string {
    if ($command === 'testing_passed') {
        if (!$attempt) throw new LogicException('repair_result_required');
        if (!empty($attempt['testing_completed_at'])) throw new LogicException('invalid_transition');
        return (int)($attempt['deployment_required'] ?? 0) === 1 ? 'deployment' : 'ready_for_verification';
    }
    if ($command === 'record_deployment') {
        if (!$attempt || (int)($attempt['deployment_required'] ?? 0) !== 1) throw new LogicException('deployment_not_required');
        if (empty($attempt['testing_completed_at']) || ($attempt['status'] ?? '') !== 'tests_passed') throw new LogicException('tests_not_confirmed');
        $result=strtolower(trim((string)($attempt['deployment_result'] ?? '')));
        if (!in_array($result,['success','failed'],true)) throw new LogicException('invalid_deployment_result');
        return $result === 'success' ? 'ready_for_verification' : 'reopened';
    }
    return ['repair_failed'=>'reopened','testing_failed'=>'reopened','still_not_fixed'=>'reopened','confirm_fixed'=>'done'][$command] ?? '';
}
function siw_decision_done_allowed(?array $attempt): bool {
    if (!$attempt || empty($attempt['testing_completed_at']) || empty($attempt['testing_completed_by_user_id'])) return false;
    if ((int)($attempt['deployment_required'] ?? 0) !== 1) return ($attempt['status'] ?? '') === 'tests_passed';
    return ($attempt['status'] ?? '') === 'deployed' && !empty($attempt['deployment_time']) && !empty($attempt['deployment_method']) && ($attempt['deployment_result'] ?? '') === 'success' && !empty($attempt['deployment_recorded_by_user_id']);
}
function siw_decision_notification_plan(string $command,string $from,string $to): array {
    $plan=[];
    if($command==='request_information'&&$to==='needs_information')$plan[]=['audience'=>'employee','event'=>'more_information_required','message'=>'More information is required.'];
    if(in_array($command,['mark_sent_to_codex','return_to_codex'],true)&&$to==='fix_in_progress')$plan[]=['audience'=>'employee','event'=>'repair_started','message'=>'Repair started.'];
    if($to==='ready_for_verification'){$plan[]=['audience'=>'employee','event'=>'ready_for_verification','message'=>'The repair is ready for your verification.'];$plan[]=['audience'=>'owner','event'=>'ready_for_verification','message'=>'The issue is ready for verification.'];}
    if($to==='reopened'){
        $plan[]=['audience'=>'employee','event'=>'issue_reopened','message'=>'The issue was reopened.'];
        $ownerEvent=$command==='testing_failed'?'testing_failed':($command==='record_deployment'?'deployment_failed':'issue_reopened');
        $ownerMessage=$ownerEvent==='testing_failed'?'Testing failed.':($ownerEvent==='deployment_failed'?'Deployment failed.':'A repair or verification attempt reopened the issue.');
        $plan[]=['audience'=>'owner','event'=>$ownerEvent,'message'=>$ownerMessage];
    }
    if($command==='confirm_fixed'&&$to==='done')$plan[]=['audience'=>'employee','event'=>'issue_done','message'=>'The issue is Done.'];
    $unique=[];foreach($plan as$item)$unique[$item['audience'].':'.$item['event']]=$item;return array_values($unique);
}
function siw_decision_apply_idempotent(array $state,string $key,string $event,bool $createsAttempt): array {
    if(isset($state['keys'][$key]))return $state;$state['keys'][$key]=true;if($createsAttempt)$state['attempts'][]=count($state['attempts'])+1;$state['events'][]=$event;$state['notifications'][$key.':'.$event]=true;return $state;
}
