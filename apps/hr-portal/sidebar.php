<?php
// Shared sidebar — included on every admin page
try {
    $pendingLeave = db()->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
} catch(Exception $e) { $pendingLeave = 0; }
try {
    $pendingOT = db()->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();
} catch(Exception $e) { $pendingOT = 0; }

$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('navItem')) {
    function navItem($href, $icon, $label, $badge=0, $current='') {
        $active = (basename($href) === $current) ? ' active' : '';
        $b = $badge > 0 ? "<span class='nav-badge'>$badge</span>" : '';
        return "<a href='$href' class='nav-item$active'><i class='$icon'></i> ".htmlspecialchars($label)."$b</a>";
    }
}

$sidebarName = isset($user['name']) ? $user['name'] : '';
$sidebarRole = isset($user['role']) ? $user['role'] : '';
$initials = '';
if ($sidebarName) {
    $parts = explode(' ', trim($sidebarName));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
}

$logoPath = __DIR__ . '/../uploads/logo_white.jpg';
$logoSrc  = file_exists($logoPath) ? '../uploads/logo_white.jpg' : '';
?>
<nav class="sidebar">
  <div class="sidebar-logo">
    <?php if ($logoSrc): ?>
    <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Hambelela Organic" style="width:160px;height:auto;display:block;filter:invert(1) brightness(2);">
    <?php else: ?>
    <div style="font-size:16px;font-weight:800;color:#fff;letter-spacing:.05em;font-family:'Century Gothic','Futura',Arial,sans-serif;">HAMBELELA<br><span style="font-weight:300;font-size:11px;letter-spacing:.2em">ORGANIC</span></div>
    <?php endif; ?>
    <div style="font-size:9px;color:rgba(255,255,255,0.28);margin-top:6px;letter-spacing:.1em;font-family:'Century Gothic','Futura',Arial,sans-serif;text-transform:uppercase">HR Portal</div>
  </div>

  <div class="sidebar-user">
    <div class="avatar-sm"><?= htmlspecialchars($initials ?: '?') ?></div>
    <div>
      <div class="user-name"><?= htmlspecialchars($sidebarName) ?></div>
      <span class="role-badge"><?= htmlspecialchars(ucfirst($sidebarRole)) ?></span>
    </div>
  </div>

  <div style="flex:1;overflow-y:auto">
    <div class="nav-section">Overview</div>
    <?= navItem('../../index.php','fa-solid fa-arrow-left','Back to Portal',0,$currentPage) ?>
    <?= navItem('dashboard.php','fa-solid fa-house','Dashboard',0,$currentPage) ?>
    <div class="nav-section">HR Management</div>
    <?= navItem('employees.php','fa-solid fa-users','Employees',0,$currentPage) ?>
    <?= navItem('leave.php','fa-solid fa-calendar-xmark','Leave Management',(int)$pendingLeave,$currentPage) ?>
    <?= navItem('overtime.php','fa-regular fa-clock','Overtime',(int)$pendingOT,$currentPage) ?>
    <?= navItem('payroll.php','fa-solid fa-money-bill-wave','Payroll & Payslips',0,$currentPage) ?>
    <?= navItem('loans.php','fa-solid fa-hand-holding-dollar','Loans & Advances',0,$currentPage) ?>
    <?= navItem('documents.php','fa-solid fa-folder-open','Documents',0,$currentPage) ?>
    <div class="nav-section">System</div>
    <?= navItem('settings.php','fa-solid fa-gear','Settings',0,$currentPage) ?>
  </div>

  <div class="sidebar-footer">
    <form method="POST" action="logout.php">
      <button type="submit" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</button>
    </form>
  </div>
</nav>
