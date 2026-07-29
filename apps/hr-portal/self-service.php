<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/leave-reserve.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db   = db();
$empId = (int)($user['emp_id'] ?? 0);
ensureLeaveShutdownSchema($db);
hrEnsureMedicalAidSchemaSafe($db);
$medicalAidProfiles = hrMedicalAidMap($db);

// Get employee details
$emp = $db->prepare("SELECT * FROM employees WHERE id=?");
$emp->execute([$empId]); $emp = $emp->fetch();
if (!$emp) { header('Location: ' . SITE_URL . '/logout.php'); exit; }
$medicalAidProfile = hrApplyMedicalAidToEmployee($emp, $medicalAidProfiles);
$currentMedicalAidActive = hrMedicalAidEffectiveForPeriod($medicalAidProfile, (int)date('n'), (int)date('Y'));

// Leave balances
$year = date('Y');
$balances = $db->prepare("SELECT leave_type,balance_days,used_days FROM leave_balances WHERE employee_id=? AND year=?");
$balances->execute([$empId,$year]); $balances = $balances->fetchAll();
$balMap = [];
foreach ($balances as $b) $balMap[$b['leave_type']] = $b;
$annualMetrics = annualLeaveMetrics($db, $empId, $year);
$shutdownSettings = shutdownSettings($db);
$reserveCurrent = (float)$annualMetrics['reserve_accumulated'];
$reserveTotal = (float)$annualMetrics['projected_reserve'];
$reserveStatusText = $annualMetrics['reserve_active'] ? 'Accumulating for December Shutdown' : 'Not Active Yet';
$shutdownStart = date('d F', strtotime(date('Y') . '-' . $shutdownSettings['start_md']));
$shutdownEndYear = substr($shutdownSettings['end_md'], 0, 2) === '01' ? (int)date('Y') + 1 : (int)date('Y');
$shutdownEnd = date('d F', strtotime($shutdownEndYear . '-' . $shutdownSettings['end_md']));

// Latest payslip
$latestPS = $db->prepare("SELECT ps.*, r.period_label, r.period_month, r.period_year FROM payslips ps JOIN payroll_runs r ON r.id=ps.run_id WHERE ps.employee_id=? ORDER BY r.period_year DESC, r.period_month DESC LIMIT 1");
$latestPS->execute([$empId]); $latestPS = $latestPS->fetch();
$latestSSF = $latestPS ? (float)$latestPS['ssf'] : 0;
$latestMedicalAidFund = trim((string)($medicalAidProfile['medical_aid_fund'] ?? 'Medical Aid'));
$latestMedicalAidTotal = 0;
$latestMedicalAidCompany = 0;
$latestMedicalAidEmployee = 0;
if ($latestPS) {
  $latestMedicalAidProfile = hrApplyMedicalAidToEmployee(['id' => $empId], $medicalAidProfiles);
  if (hrMedicalAidEffectiveForPeriod($latestMedicalAidProfile, (int)$latestPS['period_month'], (int)$latestPS['period_year'])) {
    $latestMedicalAidFund = trim((string)($latestPS['medical_aid_fund'] ?? '')) ?: (string)$latestMedicalAidProfile['medical_aid_fund'];
    $latestMedicalAidTotal = (float)($latestPS['medical_aid_total'] ?? 0);
    $latestMedicalAidCompany = (float)($latestPS['medical_aid_company'] ?? 0);
    $latestMedicalAidEmployee = (float)($latestPS['medical_aid_employee'] ?? 0);
    if ($latestMedicalAidTotal <= 0) $latestMedicalAidTotal = (float)$latestMedicalAidProfile['medical_aid_total'];
    if ($latestMedicalAidCompany <= 0) $latestMedicalAidCompany = (float)$latestMedicalAidProfile['medical_aid_company'];
    if ($latestMedicalAidEmployee <= 0) $latestMedicalAidEmployee = (float)$latestMedicalAidProfile['medical_aid_employee'];
  }
}
$latestTotalDeductions = $latestPS
  ? (float)$latestPS['paye'] + $latestSSF + (float)($latestPS['lwop_deduction'] ?? 0) + (float)($latestPS['other_deductions'] ?? 0) + (float)($latestPS['loan_deduction'] ?? 0) + $latestMedicalAidEmployee
  : 0;
$latestGross = $latestPS ? (float)$latestPS['basic_salary'] + (float)$latestPS['ot_pay'] : 0;
$latestNetPay = $latestPS ? round($latestGross - $latestTotalDeductions, 2) : 0;

// Unread notifications
$notifs = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$notifs->execute([$user['id']]); $notifs = $notifs->fetchAll();
$unreadCount = count(array_filter($notifs, function($n){ return !$n['is_read']; }));

// Pending leave requests
$pendingLeave = $db->prepare("SELECT * FROM leave_requests WHERE employee_id=? AND status='pending' ORDER BY created_at DESC LIMIT 5");
$pendingLeave->execute([$empId]); $pendingLeave = $pendingLeave->fetchAll();
$latestRejectedLeave = $db->prepare("SELECT * FROM leave_requests WHERE employee_id=? AND status='rejected' ORDER BY approved_at DESC, created_at DESC LIMIT 1");
$latestRejectedLeave->execute([$empId]); $latestRejectedLeave = $latestRejectedLeave->fetch();

// Upcoming Namibia public holidays
$today = date('Y-m-d');
$currentYear = date('Y');
$holidays = [
    ['date' => $currentYear.'-01-01', 'name' => "New Year's Day"],
    ['date' => $currentYear.'-03-21', 'name' => 'Independence Day'],
    ['date' => $currentYear.'-04-18', 'name' => 'Good Friday'],
    ['date' => $currentYear.'-04-21', 'name' => 'Easter Monday'],
    ['date' => $currentYear.'-05-01', 'name' => "Workers' Day"],
    ['date' => $currentYear.'-05-04', 'name' => 'Cassinga Day'],
    ['date' => $currentYear.'-05-25', 'name' => 'Africa Day'],
    ['date' => $currentYear.'-08-26', 'name' => "Heroes' Day"],
    ['date' => $currentYear.'-09-10', 'name' => 'Day of the Namibian Women'],
    ['date' => $currentYear.'-12-10', 'name' => 'Human Rights Day'],
    ['date' => $currentYear.'-12-25', 'name' => 'Christmas Day'],
    ['date' => $currentYear.'-12-26', 'name' => 'Family Day'],
];
$upcoming = array_filter($holidays, function($h) use ($today){ return $h['date'] >= $today; });
$upcoming = array_slice(array_values($upcoming), 0, 5);
$currentPage = 'self-service.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My Dashboard — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/emp-sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">My Dashboard</div>
    <div style="font-size:13px;color:#6b8070"><?=date('l, d F Y')?></div>
  </div>
  <div class="content">
    <div class="section-title">Welcome back, <?=htmlspecialchars($emp['first_name'])?></div>
    <div class="section-sub" style="margin-bottom:22px">Here is your HR overview for <?=date('F Y')?></div>

    <!-- Quick stats -->
    <div class="grid-4" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
      <?php
      $annualBal     = $balMap['Annual Leave'] ?? null;
      $annualRemain  = $annualMetrics['available_now'];
      $sickBal       = $balMap['Sick Leave'] ?? null;
      $sickRemain    = $sickBal ? max(0,(float)$sickBal['balance_days']-(float)$sickBal['used_days']) : 0;
      ?>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-value"><?=number_format($annualRemain,1)?></div>
        <div class="stat-label">Available Annual Leave</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon amber"><i class="fa-solid fa-briefcase-medical"></i></div>
        <div class="stat-value"><?=number_format($sickRemain,1)?></div>
        <div class="stat-label">Sick Leave Remaining</div>
      </div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-money-bill-wave"></i></div><div class="stat-value" style="font-size:18px"><?=$latestPS ? 'N$'.number_format($latestNetPay,0) : '—'?></div><div class="stat-label">Last Net Pay</div></div>
      <div class="stat-card"><div class="stat-icon teal"><i class="fa-solid fa-shield-heart"></i></div><div class="stat-value" style="font-size:18px"><?=$latestPS ? 'N$'.number_format($latestSSF,2) : '—'?></div><div class="stat-label">Social Security Deduction</div></div>
      <?php if ($currentMedicalAidActive): ?>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fa-solid fa-kit-medical"></i></div>
        <div class="stat-value" style="font-size:16px;line-height:1.2"><?=htmlspecialchars($medicalAidProfile['medical_aid_fund'])?></div>
        <div class="stat-label">Medical Aid</div>
        <div style="margin-top:8px;display:grid;gap:4px;font-size:10.5px;color:var(--text-mid);text-align:left">
          <div style="display:flex;justify-content:space-between;gap:8px"><span>Total Fund</span><strong style="color:var(--text-dark);font-family:monospace">N$<?=number_format((float)$medicalAidProfile['medical_aid_total'],2)?></strong></div>
          <div style="display:flex;justify-content:space-between;gap:8px"><span>Company Portion</span><strong style="color:var(--green);font-family:monospace">N$<?=number_format((float)$medicalAidProfile['medical_aid_company'],2)?></strong></div>
          <div style="display:flex;justify-content:space-between;gap:8px"><span>Employee Portion</span><strong style="color:var(--red);font-family:monospace">N$<?=number_format((float)$medicalAidProfile['medical_aid_employee'],2)?></strong></div>
          <div style="display:flex;justify-content:space-between;gap:8px"><span>Status</span><strong style="color:var(--green)">Active</strong></div>
        </div>
      </div>
      <?php endif ?>
      <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-calendar-days"></i></div><div class="stat-value" style="font-size:18px"><?=number_format($reserveCurrent,0)?> / <?=number_format($reserveTotal,0)?> Days</div><div class="stat-label">December Shutdown Reserve</div><div style="font-size:10px;color:var(--text-mid);margin-top:3px"><?=$reserveStatusText?></div></div>
      <div class="stat-card"><div class="stat-icon <?=$unreadCount>0?'red':'teal'?>"><i class="fa-regular fa-bell"></i></div><div class="stat-value"><?=$unreadCount?></div><div class="stat-label">Unread Notifications</div></div>
    </div>

    <div class="grid-2">
      <!-- Leave Balances -->
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--green)"></i> My Leave Balances — <?=$year?></div></div>
        <div style="padding:16px 20px">
          <?php
          $leaveTypes = ['Annual Leave','Sick Leave','Compassionate Leave','Maternity Leave','Unpaid Leave'];
          foreach ($leaveTypes as $lt):
            $bal     = $balMap[$lt] ?? null;
            $accrued = $bal ? (float)$bal['balance_days'] : 0;
            $used    = $bal ? (float)$bal['used_days'] : 0;
            $remain  = max(0, $accrued - $used);
            $col     = $remain <= 1 ? 'var(--red)' : ($remain <= 3 ? 'var(--amber)' : 'var(--green)');
            if ($lt === 'Annual Leave') {
              $remain = $annualMetrics['available_now'];
              $col = shutdownStatusColor($annualMetrics['status']);
            }
            $pct     = $accrued > 0 ? min(100, round($remain/$accrued*100)) : 0;
          ?>
          <div style="margin-bottom:15px">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:13px">
              <span style="font-weight:500"><?=htmlspecialchars($lt)?></span>
              <div style="text-align:right">
                <span style="font-weight:700;color:<?=$col?>"><?=number_format($remain,1)?> <?=$lt==='Annual Leave'?'available':'days remaining'?></span>
                <?php if($used > 0): ?>
                <span style="font-size:11px;color:var(--text-mid);margin-left:6px">(<?=number_format($used,1)?> taken)</span>
                <?php endif ?>
              </div>
            </div>
            <?php if ($lt === 'Annual Leave'): ?>
            <div style="font-size:11px;color:var(--text-mid);margin-bottom:6px">Accrued: <?=number_format($annualMetrics['current_accrued'],1)?> days | Taken: <?=number_format($annualMetrics['leave_taken'],1)?> days</div>
            <?php endif ?>
            <?php if ($accrued > 0): ?>
            <div style="height:5px;background:var(--border);border-radius:3px">
              <div style="height:5px;background:<?=$col?>;border-radius:3px;width:<?=$pct?>%;transition:width .3s"></div>
            </div>
            <?php endif ?>
          </div>
          <?php endforeach ?>
          <?php
            $reservePct = $reserveTotal > 0 ? min(100, round($reserveCurrent / $reserveTotal * 100)) : 0;
            $reserveCol = $reserveCurrent > 0 ? 'var(--green)' : 'var(--text-light)';
          ?>
          <div style="margin-bottom:15px;background:#f8fafc;border-radius:8px;padding:10px 12px">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:13px">
              <span style="font-weight:500">Reserve Leave</span>
              <div style="text-align:right">
                <span style="font-weight:700;color:<?=$reserveCol?>"><?=number_format($reserveCurrent,1)?> / <?=number_format($reserveTotal,1)?> days</span>
              </div>
            </div>
            <div style="font-size:11px;color:var(--text-mid);margin-bottom:6px"><?=$reserveStatusText?></div>
            <div style="height:5px;background:var(--border);border-radius:3px">
              <div style="height:5px;background:<?=$reserveCol?>;border-radius:3px;width:<?=$reservePct?>%;transition:width .3s"></div>
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:16px">
      <!-- Upcoming Public Holidays -->
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-flag" style="color:var(--amber)"></i> Upcoming Namibian Public Holidays</div></div>
        <div style="padding:0 20px 8px">
          <?php if (empty($upcoming)): ?>
            <div style="padding:20px 0;font-size:13px;color:var(--text-mid);text-align:center">No more public holidays this year.</div>
          <?php else: ?>
          <?php foreach ($upcoming as $h):
            $daysAway = (int)round((strtotime($h['date']) - strtotime($today)) / 86400);
            $dayName = date('l', strtotime($h['date']));
          ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--border)">
            <div>
              <div style="font-weight:600;font-size:13px"><?=htmlspecialchars($h['name'])?></div>
              <div style="font-size:11px;color:var(--text-mid)"><?=$dayName?>, <?=date('d F Y',strtotime($h['date']))?></div>
            </div>
            <span class="badge <?=$daysAway<=7?'badge-amber':'badge-green'?>"><?=$daysAway===0?'Today':($daysAway===1?'Tomorrow':'In '.$daysAway.' days')?></span>
          </div>
          <?php endforeach ?>
          <?php endif ?>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-calendar-days" style="color:var(--green)"></i> December Shutdown</div></div>
        <div style="padding:16px 20px;font-size:13px;display:grid;gap:10px">
          <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:2px">Shutdown Period</div><div style="font-weight:700"><?=$shutdownStart?> - <?=$shutdownEnd?></div></div>
          <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:2px">Reserve Status</div><div style="font-weight:800;font-family:monospace"><?=number_format($reserveCurrent,1)?> / <?=number_format($reserveTotal,1)?> days</div></div>
          <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:2px">Reserve Starts</div><div style="font-weight:700">1 August</div></div>
          <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:2px">Purpose</div><div style="color:var(--text-mid)">Reserved leave for annual company shutdown.</div></div>
        </div>
      </div>
      </div>
    </div>

    <!-- Pending Leave Requests -->
    <?php if (!empty($pendingLeave)): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-hourglass-half" style="color:var(--amber)"></i> My Pending Leave Requests</div></div>
      <table>
        <thead><tr><th>Leave Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($pendingLeave as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['leave_type'])?></td>
          <td><?=date('d M Y',strtotime($r['start_date']))?></td>
          <td><?=date('d M Y',strtotime($r['end_date']))?></td>
          <td><?=$r['days']?></td>
          <td><span class="badge badge-amber">Pending</span></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <?php if ($latestRejectedLeave): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-calendar-xmark" style="color:var(--red)"></i> Latest Leave Decision</div><a class="btn btn-secondary btn-sm" href="my-leave.php?leave_request=<?=$latestRejectedLeave['id']?>#leave-request-<?=$latestRejectedLeave['id']?>">View request</a></div>
      <div style="padding:16px 20px">
        <div style="font-size:13px;font-weight:600"><?=htmlspecialchars($latestRejectedLeave['leave_type'])?> · <?=date('d M Y',strtotime($latestRejectedLeave['start_date']))?> - <?=date('d M Y',strtotime($latestRejectedLeave['end_date']))?> <span class="badge badge-red">Rejected</span></div>
        <section class="leave-decision leave-decision--rejected">
          <div class="leave-decision__heading"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><h3>Reason for rejection</h3></div>
          <p class="leave-decision__reason"><?=htmlspecialchars(trim((string)($latestRejectedLeave['reject_reason'] ?? '')) !== '' ? $latestRejectedLeave['reject_reason'] : 'No rejection reason was recorded for this request.', ENT_QUOTES, 'UTF-8')?></p>
          <div class="leave-decision__meta"><span>Decision date: <?=$latestRejectedLeave['approved_at'] ? date('d F Y \a\t H:i',strtotime($latestRejectedLeave['approved_at'])) : 'Not recorded'?></span><span>Reviewed by: HR Administration</span></div>
        </section>
      </div>
    </div>
    <?php endif ?>

    <!-- Latest Payslip preview -->
    <?php if ($latestPS): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-file-lines" style="color:var(--green)"></i> Latest Payslip — <?=htmlspecialchars($latestPS['period_label'])?></div>
        <a href="my-payslips.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View All</a>
      </div>
      <div style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;font-size:13px">
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Basic Salary</div><div style="font-weight:700;font-family:monospace">N$ <?=number_format((float)$latestPS['basic_salary'],2)?></div></div>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Overtime</div><div style="font-weight:700;font-family:monospace;color:var(--green)">+ N$ <?=number_format((float)$latestPS['ot_pay'],2)?></div></div>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Social Security</div><div style="font-weight:700;font-family:monospace;color:var(--red)">- N$ <?=number_format($latestSSF,2)?></div></div>
        <?php if ($latestMedicalAidEmployee > 0): ?>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Medical Aid Employee</div><div style="font-weight:700;font-family:monospace;color:var(--red)">- N$ <?=number_format($latestMedicalAidEmployee,2)?></div></div>
        <?php endif ?>
        <?php if ($latestMedicalAidCompany > 0): ?>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Medical Aid Employer</div><div style="font-weight:700;font-family:monospace;color:var(--green)">N$ <?=number_format($latestMedicalAidCompany,2)?></div></div>
        <?php endif ?>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Total Deductions</div><div style="font-weight:700;font-family:monospace;color:var(--red)">- N$ <?=number_format($latestTotalDeductions,2)?></div></div>
        <div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid);margin-bottom:3px">Net Pay</div><div style="font-weight:800;font-family:monospace;font-size:16px">N$ <?=number_format($latestNetPay,2)?></div></div>
      </div>
    </div>
    <?php endif ?>
  </div>
</div>
</body>
</html>
