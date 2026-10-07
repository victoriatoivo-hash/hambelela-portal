<?php
declare(strict_types=1);
require_once BASE_PATH . '/apps/operations/operations.php';
require_once BASE_PATH . '/shared/hr-access.php';
require_once BASE_PATH . '/apps/hr-portal/includes/policy-system.php';

function portal_policy_requirement(PDO $portal, PDO $hr, array $person): ?array {
    hrPolicyAssignCurrent($hr);
    if (($person['role_key'] ?? '') === 'owner_admin') return null;
    $health=hr_access_health($portal,$hr,(int)($person['id'] ?? 0));
    if ($health['state'] !== 'ready') return null;
    // Mandatory main-portal prompt remains pending until the existing signature is complete.
    $q=$hr->prepare("SELECT v.id version_id FROM hr_policy_assignments s
        JOIN hr_policy_versions v ON v.id=s.version_id
        JOIN hr_policies p ON p.current_version_id=v.id AND p.id=v.policy_id
        LEFT JOIN hr_policy_acknowledgements a ON a.version_id=v.id AND a.employee_id=s.employee_id
        WHERE s.user_id=? AND s.employee_id=? AND v.status='published'
          AND v.acknowledgement_required=1 AND a.signed_at IS NULL
        ORDER BY v.acknowledgement_deadline IS NULL,v.acknowledgement_deadline,v.id LIMIT 1");
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
<style>
.main-policy-popup{box-sizing:border-box;width:calc(100% - 32px);max-width:440px;padding:30px;border:1px solid #d3dac6;border-radius:18px;background:#faf9f3;color:#303c27;font-family:Jost,sans-serif;box-shadow:0 20px 70px #0003}
.main-policy-popup::backdrop{background:#24311db3}.main-policy-popup .policy-label{font-size:11px;font-weight:600;letter-spacing:.12em;color:#59694a}.main-policy-popup h2{font:600 24px/1.25 Jost,sans-serif;margin:14px 0}.main-policy-popup p{font-size:14px;line-height:1.6;color:#626a59;margin:0 0 24px}.main-policy-popup a{display:inline-flex;justify-content:center;align-items:center;min-height:44px;padding:0 18px;border-radius:9px;background:#59694a;color:#fff;text-decoration:none;font-weight:500;font-size:14px}.main-policy-popup a:focus-visible{outline:3px solid #aab991;outline-offset:4px}
</style>
<dialog class="main-policy-popup" id="mainPolicyPopup" aria-labelledby="mainPolicyTitle" aria-describedby="mainPolicyDescription">
<span class="policy-label">HR POLICY</span>
<h2 id="mainPolicyTitle">Company Policy Requires Your Acknowledgement</h2>
<p id="mainPolicyDescription">Please read and acknowledge the current company policy.</p>
<a href="<?=htmlspecialchars($policyBridgeUrl,ENT_QUOTES,'UTF-8')?>">Read &amp; Acknowledge</a>
</dialog>
<script>(function(){function showPolicy(){var popup=document.getElementById('mainPolicyPopup');if(!popup||popup.open)return;popup.addEventListener('cancel',function(event){event.preventDefault()});popup.showModal()}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',showPolicy,{once:true});else showPolicy()})();</script>
<?php endif; ?>
