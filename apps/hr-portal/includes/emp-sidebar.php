<?php
// Employee sidebar — included on every employee page
$empUnread = 0;
$empPolicyPending = array();
$empPolicyPopup = null;
if (isset($user['id'])) {
    $nr = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $nr->execute([$user['id']]); $empUnread = (int)$nr->fetchColumn();
    try {
        require_once __DIR__ . '/policy-system.php';
        hrPolicyEnsureSchema(db());
        hrPolicyAssignCurrent(db());
        $empPolicyPending = hrPolicyPending(db(), hrPolicyEmployeeId($user));
        $empPolicyPopup = hrPolicyPopupForUser(db(), (int)$user['id']);
        if($empPolicyPopup)db()->prepare("UPDATE hr_policy_notifications SET delivered_at=COALESCE(delivered_at,NOW()) WHERE id=?")->execute(array($empPolicyPopup['notification_requirement_id']));
    } catch (Throwable $ignored) {
        $empPolicyPending = array();
    }
}
$currentPage = isset($currentPage) ? $currentPage : basename($_SERVER['PHP_SELF']);
$showBusinessPortalLink = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/apps/hr-portal/') !== false;
function empNavItem($href, $icon, $label, $badge=0, $current='') {
    $active = (basename($href) === $current) ? ' active' : '';
    $b = $badge > 0 ? "<span class='nav-badge'>$badge</span>" : '';
    return "<a href='$href' class='nav-item$active'><i class='$icon'></i> ".htmlspecialchars($label)."$b</a>";
}
?>
<link rel="stylesheet" href="includes/styles.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/styles.css')) ?>">
<link rel="stylesheet" href="../../assets/css/hr-sidebar-theme.css?v=<?= filemtime(__DIR__.'/../../../assets/css/hr-sidebar-theme.css') ?>">
<link rel="stylesheet" href="../../assets/css/portal-date-picker.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/../../../assets/css/portal-date-picker.css')) ?>">
<script defer src="../../assets/js/portal-date-picker.js?v=<?= rawurlencode((string) filemtime(__DIR__ . '/../../../assets/js/portal-date-picker.js')) ?>"></script>
<button type="button" class="hr-mobile-menu-toggle" data-hr-menu-open aria-label="Open HR navigation" aria-controls="hrPortalSidebar" aria-expanded="false">
  <span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>
</button>
<nav class="sidebar" id="hrPortalSidebar" data-hr-sidebar aria-label="HR Portal navigation">
  <div class="hr-sidebar-mobile-header">
    <span>HR Portal</span>
    <button type="button" class="hr-sidebar-close" data-hr-menu-close aria-label="Close HR navigation">&times;</button>
  </div>
  <div class="sidebar-logo">
    <img src="assets/letter/hambelela-logo.jpg" alt="Hambelela Organic" style="width:160px;height:auto;display:block;filter:invert(1);mix-blend-mode:screen;">
    <div style="font-size:9px;color:rgba(255,255,255,0.28);margin-top:6px;letter-spacing:.1em;font-family:Jost,sans-serif;text-transform:uppercase">HR Portal</div>
  </div>

  <div class="sidebar-user">
    <div class="avatar-sm"><?= strtoupper(substr($user['name'],0,1).(strrchr($user['name'],' ') ? substr(strrchr($user['name'],' '),1,1) : '')) ?></div>
    <div>
      <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
      <span class="role-badge employee">Employee</span>
    </div>
  </div>

  <div style="flex:1;overflow-y:auto">
    <div class="nav-section">My Portal</div>
    <?= empNavItem('../../index.php','fa-solid fa-arrow-left','Back to Portal',0,$currentPage) ?>
    <?= empNavItem('self-service.php','fa-solid fa-house','My Dashboard',0,$currentPage) ?>
    <?= empNavItem('my-notifications.php','fa-regular fa-bell','Notifications',$empUnread,$currentPage) ?>
    <div class="nav-section">Requests</div>
    <?= empNavItem('my-leave.php','fa-solid fa-calendar-xmark','My Leave',0,$currentPage) ?>
    <?= empNavItem('my-overtime.php','fa-regular fa-clock','My Overtime',0,$currentPage) ?>
    <div class="nav-section">Documents</div>
    <?= empNavItem('my-payslips.php','fa-solid fa-file-lines','My Payslips',0,$currentPage) ?>
    <?= empNavItem('my-documents.php','fa-solid fa-folder-open','My Documents',0,$currentPage) ?>
    <?= empNavItem('my-loans.php','fa-solid fa-hand-holding-dollar','My Loans',0,$currentPage) ?>
    <?= empNavItem('policies.php','fa-solid fa-book-open','Company Policies',count($empPolicyPending),$currentPage) ?>
    <?php if ($empPolicyPending): $policyReminder = $empPolicyPending[0]; ?>
      <a href="policy-view.php?id=<?=(int)$policyReminder['id']?>" style="display:block;margin:12px;padding:12px;border-radius:10px;background:#fff7d6;color:#4b3b00;text-decoration:none;font-size:11px;line-height:1.45">
        <strong style="display:block">Policy signature required</strong>
        <?=htmlspecialchars($policyReminder['policy_title'])?><br>
        Version <?=htmlspecialchars($policyReminder['version_number'])?> · Due <?=date('j M Y', strtotime($policyReminder['acknowledgement_deadline']))?>
      </a>
    <?php endif; ?>
  </div>

  <div class="sidebar-footer">
    <form method="POST" action="logout.php">
      <button type="submit" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</button>
    </form>
  </div>
</nav>
<script defer src="includes/hr-responsive.js?v=<?= rawurlencode((string) filemtime(__DIR__ . '/hr-responsive.js')) ?>"></script>
<?php if($empPolicyPopup):$policyOverdue=date('Y-m-d')>$empPolicyPopup['acknowledgement_deadline'];?>
<div class="hr-policy-alert" data-hr-policy-alert role="alertdialog" aria-modal="true" aria-labelledby="hrPolicyAlertTitle" data-id="<?=(int)$empPolicyPopup['notification_requirement_id']?>" data-url="policy-view.php?id=<?=(int)$empPolicyPopup['version_id']?>">
  <div class="hr-policy-alert__backdrop" aria-hidden="true"></div><section class="hr-policy-alert__dialog">
    <div class="hr-policy-alert__icon" aria-hidden="true"><i class="fa-solid fa-file-shield"></i></div><span class="hr-policy-alert__eyebrow">HR PORTAL</span>
    <h2 id="hrPolicyAlertTitle"><?=$policyOverdue?'HR POLICY — OVERDUE':'POLICY ACKNOWLEDGEMENT REQUIRED'?></h2><h3><?=htmlspecialchars($empPolicyPopup['title'])?></h3>
    <dl><div><dt>Version</dt><dd><?=htmlspecialchars($empPolicyPopup['version_number'])?></dd></div><div><dt>Effective</dt><dd><?=date('j F Y',strtotime($empPolicyPopup['effective_date']))?></dd></div></dl>
    <div class="hr-policy-alert__deadline"><span>COMPLETE BY</span><strong><?=strtoupper(date('j F Y',strtotime($empPolicyPopup['acknowledgement_deadline'])))?></strong></div>
    <p><?=$policyOverdue?'Your acknowledgement is overdue.':'A new company policy has been published and requires your acknowledgement. Please read the full policy and complete the acknowledgement by the deadline above.'?></p>
    <div class="hr-policy-alert__actions"><?php if(!$policyOverdue):?><button type="button" class="hr-policy-alert__later" data-policy-remind>Remind Me Later</button><?php endif;?><button type="button" class="hr-policy-alert__view" data-policy-open><?=$policyOverdue?'View & Complete Now':'View & Acknowledge Policy'?></button></div>
  </section>
</div>
<script>document.addEventListener('DOMContentLoaded',function(){var root=document.querySelector('[data-hr-policy-alert]');if(!root)return;function send(action){return fetch('policy-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:<?=json_encode(hrPolicyCsrf())?>,action:'policy_notification',ajax:'1',notification_requirement_id:root.dataset.id,notification_action:action})});}root.querySelector('[data-policy-remind]')?.addEventListener('click',function(){send('remind').then(function(){root.remove()})});root.querySelector('[data-policy-open]').addEventListener('click',function(){send('open').finally(function(){location.href=root.dataset.url})});});</script>
<?php endif;?>
