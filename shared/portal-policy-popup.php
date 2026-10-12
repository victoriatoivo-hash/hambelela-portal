<?php
declare(strict_types=1);
require_once BASE_PATH . '/apps/operations/operations.php';
require_once BASE_PATH . '/shared/hr-access.php';
require_once BASE_PATH . '/apps/hr-portal/includes/policy-system.php';
require_once __DIR__.'/portal-policy-notifications.php';

function portal_policy_requirement(PDO $portal, PDO $hr, array $person): ?array {
    hrPolicyAssignCurrent($hr);
    portal_policy_sync($portal,$hr);
    if(hrPolicySettings($hr)['policy_main_popup']!=='1') return null;
    if (($person['role_key'] ?? '') === 'owner_admin') return null;
    $health=hr_access_health($portal,$hr,(int)($person['id'] ?? 0));
    if ($health['state'] !== 'ready') return null;
    // Mandatory main-portal prompt remains pending until the existing signature is complete.
    $q=$hr->prepare("SELECT v.id version_id,s.id assignment_id,s.reminder_sequence,s.acknowledgement_deadline FROM hr_policy_assignments s
        JOIN hr_policy_versions v ON v.id=s.version_id
        JOIN hr_policies p ON p.current_version_id=v.id AND p.id=v.policy_id
        LEFT JOIN hr_policy_acknowledgements a ON a.version_id=v.id AND a.employee_id=s.employee_id
        WHERE s.user_id=? AND s.employee_id=? AND v.status='published'
          AND v.acknowledgement_required=1 AND a.signed_at IS NULL
        ORDER BY s.acknowledgement_deadline IS NULL,s.acknowledgement_deadline,v.id LIMIT 1");
    $q->execute(array($health['account']['id'],$health['profile']['id']));
    $pending=$q->fetch(PDO::FETCH_ASSOC);
    return $pending ?: null;
}

$mainPolicyRequirement=null;
try {
    $mainPolicyHr=ops_hr_db();
    if ($mainPolicyHr && !empty($user['id'])) $mainPolicyRequirement=portal_policy_requirement(db(),$mainPolicyHr,$user);
} catch (Throwable $error) {
    error_log('Main portal policy requirement unavailable: '.$error->getMessage());
}
if ($mainPolicyRequirement):
    $policyBridgeUrl=(BASE_URL ?: '').'/apps/hr-portal/portal-login.php?return='.rawurlencode('policy-view.php?id='.(int)$mainPolicyRequirement['version_id']);
?>

<dialog class="main-policy-popup hb-portal-notice hb-notice-modal" data-module="hr" id="mainPolicyPopup" aria-labelledby="mainPolicyTitle" aria-describedby="mainPolicyDescription">
<span class="policy-label">HR POLICY — ACTION REQUIRED</span>
<h2 id="mainPolicyTitle">Company Policy Requires Your Acknowledgement</h2>
<p id="mainPolicyDescription">You have been assigned the Hambelela Organic Employee Handbook &amp; Company Policy.<br>Please read and acknowledge it<?=$mainPolicyRequirement['acknowledgement_deadline']?' by '.htmlspecialchars(date('j F Y',strtotime($mainPolicyRequirement['acknowledgement_deadline']))):''?>.</p>
<a class="hb-notice-button hb-notice-primary" href="<?=htmlspecialchars($policyBridgeUrl,ENT_QUOTES,'UTF-8')?>">Read &amp; Acknowledge</a>
<button type="button" id="dismissMainPolicy" style="min-height:44px;margin-left:12px;border:0;background:transparent;color:#59694a;font:inherit;cursor:pointer">Later</button>
</dialog>
<script>(function(){var key=<?=json_encode('hr-policy:'.$mainPolicyRequirement['assignment_id'].':'.$mainPolicyRequirement['reminder_sequence'].':'.date('Y-m-d'))?>;function showPolicy(){var popup=document.getElementById('mainPolicyPopup');if(!popup||popup.open)return;function dismissed(){try{sessionStorage.setItem(key,'later')}catch(e){}}popup.addEventListener('cancel',dismissed);document.getElementById('dismissMainPolicy').addEventListener('click',function(){dismissed();popup.close();window.dispatchEvent(new Event('portal:notice-closed'))});try{if(sessionStorage.getItem(key))return}catch(e){}popup.showModal()}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',showPolicy,{once:true});else showPolicy()})();</script>
<?php endif; ?>
