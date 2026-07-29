<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/leave-reserve.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db    = db();
$empId = (int)($user['emp_id'] ?? 0);
ensureLeaveShutdownSchema($db);

$leaveTypes = ['Annual Leave','Sick Leave','Compassionate Leave','Maternity Leave','Unpaid Leave'];
$year = date('Y');

// Get balances
$bals = $db->prepare("SELECT leave_type,balance_days,used_days FROM leave_balances WHERE employee_id=? AND year=?");
$bals->execute([$empId,$year]); $bals = $bals->fetchAll();
$balMap = [];
foreach ($bals as $b) $balMap[$b['leave_type']] = $b;
$annualMetrics = annualLeaveMetrics($db, $empId, $year);
$shutdownSettings = shutdownSettings($db);

// ── Handle leave submission ───────────────────────────────────
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = clean($_POST['leave_type'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date   = $_POST['end_date']   ?? '';
    $reason     = clean($_POST['reason'] ?? '');
    $days       = (float)($_POST['days'] ?? 0);

    if ($leave_type && $start_date && $end_date && $days > 0) {

        // ── Enforce balance rules ────────────────────────────
        $reserveWarning = 0;
        if ($leave_type !== 'Unpaid Leave' && $leave_type !== 'Maternity Leave') {
            $bal       = $balMap[$leave_type] ?? null;
            $accrued   = $bal ? (float)$bal['balance_days'] : 0;
            $used      = $bal ? (float)$bal['used_days'] : 0;
            $available = max(0, $accrued - $used);

            if ($leave_type === 'Annual Leave') {
                $available = $annualMetrics['total'];
                if ($days > $annualMetrics['available_now']) $reserveWarning = 1;
            }

            if ($days > $available) {
                $formError = "You only have <strong>".number_format($available,1)." day(s)</strong> of $leave_type available. You cannot request $days day(s).";
            }
        }

        if (!$formError) {
            // Handle certificate upload
            $certPath = null;
            if (($leave_type === 'Sick Leave' || $leave_type === 'Unpaid Leave') && isset($_FILES['certificate']) && $_FILES['certificate']['error'] === 0) {
                $ext     = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
                    $certDir = __DIR__ . '/uploads/certificates/';
                    if (!is_dir($certDir)) mkdir($certDir, 0755, true);
                    $certFile = 'cert_'.$empId.'_'.time().'.'.$ext;
                    move_uploaded_file($_FILES['certificate']['tmp_name'], $certDir.$certFile);
                    $certPath = 'uploads/certificates/'.$certFile;
                }
            }

            $db->prepare("INSERT INTO leave_requests (employee_id,leave_type,start_date,end_date,days,reason,certificate,reserve_warning,status) VALUES (?,?,?,?,?,?,?,?,'pending')")
               ->execute([$empId,$leave_type,$start_date,$end_date,$days,$reason,$certPath,$reserveWarning]);

            // Notify admin
            $admins = $db->query("SELECT id FROM users WHERE role='admin'")->fetchAll();
            foreach ($admins as $a) {
                $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                   ->execute([$a['id'],'New Leave Request', $user['name']." submitted $leave_type for ".number_format($days,1)." day(s).".($reserveWarning ? " This request uses part of the December shutdown reserve." : "")]);
            }
            emailHRNotice(
                'New Leave Request - ' . $user['name'],
                '<p>' . htmlspecialchars($user['name']) . ' submitted a leave request.</p>
                <div class="highlight">
                  Type: ' . htmlspecialchars($leave_type) . '<br>
                  Days: ' . number_format($days,1) . '<br>
                  Dates: ' . date('d F Y', strtotime($start_date)) . ' to ' . date('d F Y', strtotime($end_date)) . ($reserveWarning ? '<br><strong>Reserve Warning:</strong> This request will reduce the annual leave balance below the December shutdown reserve.' : '') . '
                </div>
                <a href="' . SITE_URL . '/leave.php" class="btn">Review Leave</a>'
            );
            header('Location: my-leave.php?msg=submitted'); exit;
        }
    }
}

// Get leave history
$history = $db->prepare("SELECT lr.*, u.name AS reviewer_name
                         FROM leave_requests lr
                         LEFT JOIN users u ON u.id=lr.approved_by
                         WHERE lr.employee_id=?
                         ORDER BY lr.created_at DESC");
$history->execute([$empId]); $history = $history->fetchAll();

$msg = $_GET['msg'] ?? '';
$nextMonthName = date('F', mktime(0,0,0,date('n')+1,1));
$currentPage = 'my-leave.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My Leave — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css?v=20260729-1">
</head>
<body>
<?php include __DIR__ . '/includes/emp-sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">My Leave</div>
    <button class="btn btn-primary" onclick="document.getElementById('leaveModal').style.display='flex';document.getElementById('leaveModal').classList.add('open')"><i class="fa-solid fa-plus"></i> Request Leave</button>
  </div>
  <div class="content">

    <?php if ($msg === 'submitted'): ?>
    <div class="toast"><i class="fa-solid fa-check"></i> Leave request submitted successfully. Your manager will review it shortly.</div>
    <?php endif ?>

    <!-- Leave Balance Cards — remaining only -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px">
      <?php foreach ($leaveTypes as $lt):
        $bal     = $balMap[$lt] ?? null;
        $accrued = $bal ? (float)$bal['balance_days'] : 0;
        $used    = $bal ? (float)$bal['used_days'] : 0;
        $remain  = max(0, $accrued - $used);
        $col     = 'green';
        $cardColor = 'inherit';
        if ($lt === 'Sick Leave') $col = 'amber';
        elseif ($lt === 'Compassionate Leave') $col = 'blue';
        elseif ($lt === 'Maternity Leave') $col = 'teal';
        elseif ($lt === 'Unpaid Leave') $col = 'teal';
        $low = ($remain <= 1 && $accrued > 0);
        if ($lt === 'Annual Leave') {
          $col = $annualMetrics['status'];
          $cardColor = shutdownStatusColor($annualMetrics['status']);
        }
      ?>
      <div class="stat-card" style="cursor:default">
        <div class="stat-icon <?=$col?>"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="stat-value" style="font-size:22px;color:<?=$lt==='Annual Leave'?$cardColor:($low?'var(--red)':'inherit')?>"><?=number_format($lt==='Annual Leave'?$annualMetrics['available_now']:$remain,1)?></div>
        <div class="stat-label" style="font-size:11px"><?=htmlspecialchars($lt)?></div>
        <?php if ($lt === 'Annual Leave'): ?>
        <div style="font-size:10px;color:var(--text-light);margin-top:2px">Accrued: <?=number_format($annualMetrics['current_accrued'],1)?> | Taken: <?=number_format($annualMetrics['leave_taken'],1)?></div>
        <div style="font-size:10px;color:var(--text-light);margin-top:2px">Future reserve: <?=number_format($annualMetrics['projected_reserve'],1)?> | <?=$annualMetrics['reserve_status_text']?></div>
        <?php elseif ($used > 0): ?>
        <div style="font-size:10px;color:var(--text-light);margin-top:2px"><?=number_format($used,1)?> day(s) taken</div>
        <?php else: ?>
        <div style="font-size:10px;color:var(--text-light);margin-top:2px">No leave taken</div>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>

    <!-- Accrual notice -->
    <?php
    $annualBal    = $balMap['Annual Leave'] ?? null;
    $annualAccrued= $annualBal ? (float)$annualBal['balance_days'] : 0;
    $annualUsed   = $annualBal ? (float)$annualBal['used_days'] : 0;
    $annualRemain = max(0, $annualAccrued - $annualUsed);
    ?>
    <div style="background:var(--green-pale);border:1px solid var(--green-mid);border-radius:10px;padding:12px 18px;margin-bottom:22px;font-size:13px;display:flex;justify-content:space-between;align-items:center">
      <div><i class="fa-solid fa-rotate" style="color:var(--green);margin-right:8px"></i>
        Annual Leave accrues <strong>2 days on the 1st of every month</strong>. Current accrued leave: <strong><?=number_format($annualMetrics['current_accrued'],1)?></strong>, leave taken: <strong><?=number_format($annualMetrics['leave_taken'],1)?></strong>, available now: <strong><?=number_format($annualMetrics['available_now'],1)?></strong>. Future shutdown reserve: <strong><?=number_format($annualMetrics['projected_reserve'],1)?></strong> for <?=$annualMetrics['reserve_period']?>.
      </div>
      <div style="font-size:12px;color:var(--text-mid);white-space:nowrap;margin-left:16px">Next: +2 days on 1 <?=$nextMonthName?></div>
    </div>

    <!-- Leave History -->
    <div class="card" data-leave-history>
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-list"></i> My Leave History</div></div>
      <?php if (empty($history)): ?>
        <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><div>No leave requests yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Leave Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Certificate</th></tr></thead>
        <tbody>
        <?php foreach ($history as $r):
          $sc = $r['status']==='approved'?'badge-green':($r['status']==='rejected'?'badge-red':'badge-amber');
        ?>
        <tr id="leave-request-<?=$r['id']?>">
          <td><?=htmlspecialchars($r['leave_type'])?></td>
          <td><?=date('d M Y',strtotime($r['start_date']))?></td>
          <td><?=date('d M Y',strtotime($r['end_date']))?></td>
          <td><strong><?=number_format((float)$r['days'],1)?></strong></td>
          <td>
            <div class="leave-status-cell">
              <span class="badge <?=$sc?>"><?=ucfirst($r['status'])?></span>
            <?php if($r['status']==='rejected'): ?>
              <button type="button" class="leave-reason-trigger" data-leave-reason-trigger
                data-reason="<?=htmlspecialchars(trim((string)($r['reject_reason'] ?? '')) !== '' ? $r['reject_reason'] : 'No rejection reason was recorded for this request.', ENT_QUOTES, 'UTF-8')?>"
                data-decision-date="<?=htmlspecialchars($r['approved_at'] ? date('d F Y \a\t H:i', strtotime($r['approved_at'])) : 'Not recorded', ENT_QUOTES, 'UTF-8')?>"
                data-reviewed-by="<?=htmlspecialchars($r['reviewer_name'] ?: 'HR Administration', ENT_QUOTES, 'UTF-8')?>"
                aria-expanded="false" aria-haspopup="dialog" aria-controls="leave-reason-popover">
                <span>View reason</span>
                <svg class="leave-reason-trigger__arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7.5L10 12l4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            <?php endif ?>
            </div>
          </td>
          <td>
            <?php if ($r['certificate']): ?>
              <a href="view-my-certificate.php?file=<?php echo urlencode(basename($r['certificate'])); ?>" target="_blank" style="color:#2c5f2d;text-decoration:none;font-size:12px">
                <i class="fa-solid fa-file-pdf"></i> View Certificate
              </a>
            <?php else: ?>
              <span style="color:#999;font-size:12px">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>

  </div>
</div>

<!-- LEAVE REQUEST MODAL -->
<div class="overlay" id="leaveModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-calendar-plus"></i> Request Leave</div>
      <button class="modal-close" onclick="document.getElementById('leaveModal').style.display='none';document.getElementById('leaveModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="leaveForm" onsubmit="return confirmReserveWarning()">

      <?php if ($formError): ?>
      <div style="margin:16px 20px 0;padding:10px 14px;background:var(--red-pale);color:var(--red);border-radius:8px;font-size:13px">
        <i class="fa-solid fa-xmark"></i> <?=$formError?>
      </div>
      <?php endif ?>

      <div class="modal-body">
        <div class="form-grid">

          <div class="form-group full">
            <label class="form-label">Leave Type</label>
            <select class="form-select" name="leave_type" id="leaveType" onchange="updateAvailable();var c=document.getElementById('certupload');if(c)c.style.display=(this.value==='Sick Leave')?'block':'none';">
              <option value="">Select leave type...</option>
              <?php foreach ($leaveTypes as $lt):
                $b2    = $balMap[$lt] ?? null;
                $rem2  = $b2 ? max(0,(float)$b2['balance_days']-(float)$b2['used_days']) : 0;
                $avStr = '';
                if ($lt === 'Maternity Leave') $avStr = ' (12 weeks — SSF benefit, unpaid)';
                elseif ($lt === 'Unpaid Leave') $avStr = ' (unpaid)';
                elseif ($lt === 'Annual Leave') $avStr = ' - '.number_format($annualMetrics['available_now'],1).' day(s) available now';
                else $avStr = ' — '.number_format($rem2,1).' day(s) available';
              ?>
              <option value="<?=htmlspecialchars($lt)?>"
                      data-available="<?=$lt==='Annual Leave'?$annualMetrics['available_now']:$rem2?>"
                      data-total="<?=$lt==='Annual Leave'?$annualMetrics['total']:$rem2?>"
                      data-reserve="<?=$lt==='Annual Leave'?$annualMetrics['reserve']:0?>"
                      data-sick="<?=$lt==='Sick Leave'?'1':'0'?>"
                      data-maternity="<?=$lt==='Maternity Leave'?'1':'0'?>"
                      data-unpaid="<?=$lt==='Unpaid Leave'?'1':'0'?>">
                <?=htmlspecialchars($lt.$avStr)?>
              </option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- Available balance indicator -->
          <div class="form-group full" id="balanceRow" style="display:none">
            <div id="balanceIndicator" style="padding:10px 14px;background:var(--green-pale);border-radius:8px;font-size:13px;color:var(--green)">
            </div>
          </div>
          <div class="form-group full" id="reserveWarningRow" style="display:none">
            <div style="padding:10px 14px;background:var(--amber-pale);border-radius:8px;font-size:12px;color:var(--amber)">
              <i class="fa-solid fa-triangle-exclamation"></i> This request will reduce your leave balance below the required December shutdown reserve and requires management approval.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Start Date</label>
            <input class="form-input" type="date" name="start_date" id="startDate" required onchange="calcDays()">
          </div>
          <div class="form-group">
            <label class="form-label">End Date</label>
            <input class="form-input" type="date" name="end_date" id="endDate" required onchange="calcDays()">
          </div>
          <div class="form-group full">
            <label class="form-label">Working Days</label>
            <input class="form-input" type="number" step="0.5" name="days" id="daysField" min="0.5" required placeholder="Auto-calculated" oninput="checkReserveWarning()" onchange="checkReserveWarning()">
          </div>
          <div class="form-group full">
            <label class="form-label">Reason (optional)</label>
            <textarea class="form-textarea" name="reason" placeholder="Brief reason..."></textarea>
          </div>

          <!-- Medical certificate — shown for sick leave only -->
          <div class="form-group full" id="certUpload" style="display:none">
            <label class="form-label"><i class="fa-solid fa-file-medical" style="color:var(--amber);margin-right:4px"></i> Supporting Document (Sick Leave / Unpaid Leave)</label>
            <input class="form-input" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:11px;color:var(--text-mid);margin-top:4px"><i class="fa-solid fa-circle-info"></i> Upload supporting document (sick note, medical certificate, or other documentation). Accepted: PDF, JPG, PNG.</div>
          </div>

          <!-- Maternity notice -->
          <div class="form-group full" id="maternityNotice" style="display:none">
            <div style="padding:10px 14px;background:var(--amber-pale);border-radius:8px;font-size:12px;color:var(--amber)">
              <i class="fa-solid fa-triangle-exclamation"></i> <strong>Maternity leave is unpaid by Hambelela Organic.</strong> Your maternity benefit is paid through Social Security (SSF). This request will be recorded but will not affect your payslip.
            </div>
          </div>

        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('leaveModal').style.display='none';document.getElementById('leaveModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id){var e=document.getElementById(id);if(e){e.style.display="flex";e.classList.add("open");}}
function closeModal(id){var e=document.getElementById(id);if(e){e.style.display="none";e.classList.remove("open");}}
document.addEventListener("DOMContentLoaded",function(){
  document.querySelectorAll(".overlay").forEach(function(o){
    o.addEventListener("click",function(e){if(e.target===o){o.style.display="none";o.classList.remove("open");}});
  });
});
function updateAvailable(){
  var sel=document.getElementById("leaveType");
  if(!sel)return;var opt=sel.options[sel.selectedIndex];if(!opt||!opt.value)return;
  var avail=opt.getAttribute("data-available")||"0";
  var total=opt.getAttribute("data-total")||avail;
  var reserve=opt.getAttribute("data-reserve")||"0";
  var isSick=opt.getAttribute("data-sick")==="1";
  var isMat=opt.getAttribute("data-maternity")==="1";
  var balRow=document.getElementById("balanceRow");
  var balInd=document.getElementById("balanceIndicator");
  var certDiv=document.getElementById("certUpload");
  var matDiv=document.getElementById("maternityNotice");
  if(balRow)balRow.style.display="block";
  if(balInd)balInd.textContent=opt.value==="Annual Leave" ? ("Available now: "+avail+" day(s). Future shutdown reserve: "+reserve+" day(s), kept separate for August to December.") : (avail+" day(s) available");
  var isUnpaid=opt.getAttribute("data-unpaid")==="1";
  if(certDiv)certDiv.style.display=(isSick||isUnpaid)?"block":"none";
  if(matDiv)matDiv.style.display=isMat?"block":"none";
  checkReserveWarning();
}
function calcDays(){
  var s=document.getElementById("startDate"),e=document.getElementById("endDate"),f=document.getElementById("daysField");
  if(!s||!e||!f||!s.value||!e.value)return;
  var start=new Date(s.value),end=new Date(e.value),days=0,d=new Date(start);
  while(d<=end){if(d.getDay()!==0)days++;d.setDate(d.getDate()+1);}
  f.value=days||1;
  checkReserveWarning();
}
function checkReserveWarning(){
  var sel=document.getElementById("leaveType"), daysEl=document.getElementById("daysField"), row=document.getElementById("reserveWarningRow");
  if(!sel||!daysEl||!row){return false;}
  var opt=sel.options[sel.selectedIndex];
  var days=parseFloat(daysEl.value||"0");
  var available=parseFloat(opt ? (opt.getAttribute("data-available")||"0") : "0");
  var total=parseFloat(opt ? (opt.getAttribute("data-total")||"0") : "0");
  var show=opt&&opt.value==="Annual Leave"&&days>available&&days<=total;
  row.style.display=show?"block":"none";
  return show;
}
function confirmReserveWarning(){
  if(checkReserveWarning()){
    return confirm("This request will reduce your leave balance below the required December shutdown reserve and requires management approval. Submit anyway?");
  }
  return true;
}
</script>
<script src="includes/leave-reason-popover.js?v=20260729-1"></script>
</body>
</html>
