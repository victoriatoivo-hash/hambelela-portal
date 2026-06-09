<?php
// Shared sidebar — included on every admin page
$pendingLeave = db()->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$pendingOT    = db()->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();
$currentPage  = basename($_SERVER['PHP_SELF']);
$showBusinessPortalLink = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/apps/hr-portal/') !== false;
function navItem($href, $icon, $label, $badge=0, $current='') {
    $active = (basename($href) === $current) ? ' active' : '';
    $b = $badge > 0 ? "<span class='nav-badge'>$badge</span>" : '';
    return "<a href='$href' class='nav-item$active'><i class='$icon'></i> ".htmlspecialchars($label)."$b</a>";
}
?>
<nav class="sidebar">
  <div class="sidebar-logo">
    <img src="data:image/jpeg;base64,YOUR_EXISTING_BASE64_HERE" alt="Hambelela Organic" style="width:160px;height:auto;display:block;filter:invert(1) brightness(2);">
    <div style="font-size:9px;color:rgba(255,255,255,0.28);margin-top:6px;letter-spacing:.1em;font-family:'Century Gothic','Futura',Arial,sans-serif;text-transform:uppercase">HR Portal</div>
  </div>

  <div class="sidebar-user">
    <div class="avatar-sm"><?= strtoupper(substr($user['name'],0,1).(strrchr($user['name'],' ') ? substr(strrchr($user['name'],' '),1,1) : '')) ?></div>
    <div>
      <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
      <span class="role-badge"><?= ucfirst($user['role']) ?></span>
    </div>
  </div>

  <div style="flex:1;overflow-y:auto">
    <div class="nav-section">Overview</div>
    <?php if ($showBusinessPortalLink): ?>
      <?= navItem('../../index.php','fa-solid fa-arrow-left','Business Portal',0,$currentPage) ?>
    <?php endif ?>
    <?= navItem('dashboard.php','fa-solid fa-house','Dashboard',0,$currentPage) ?>
    <div class="nav-section">HR Management</div>
    <?= navItem('employees.php','fa-solid fa-users','Employees',0,$currentPage) ?>
    <?= navItem('leave.php','fa-solid fa-calendar-xmark','Leave Management',(int)$pendingLeave,$currentPage) ?>
    <?= navItem('leave-calendar.php','fa-solid fa-calendar-days','Leave Calendar',0,$currentPage) ?>
    <?= navItem('overtime.php','fa-regular fa-clock','Overtime',(int)$pendingOT,$currentPage) ?>
    <?= navItem('payroll.php','fa-solid fa-money-bill-wave','Payroll & Payslips',0,$currentPage) ?>
    <?= navItem('documents.php','fa-solid fa-folder-open','Documents',0,$currentPage) ?>
    <?= navItem('policies.php','fa-solid fa-file-shield','Company Policies',0,$currentPage) ?>
    <?= navItem('loans.php','fa-solid fa-hand-holding-dollar','Loans',0,$currentPage) ?>
    <div class="nav-section">System</div>
    <?= navItem('settings.php','fa-solid fa-gear','Settings',0,$currentPage) ?>
  </div>

  <div class="sidebar-footer">
    <form method="POST" action="logout.php">
      <button type="submit" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</button>
    </form>
  </div>
</nav>
