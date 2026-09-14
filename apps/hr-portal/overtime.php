<?php
require_once __DIR__ . '/config.php';
requireAdmin();
require_once __DIR__ . '/includes/email.php';
$user = currentUser();
$db   = db();

// ── Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' || $action === 'reject') {
        $id     = (int)($_POST['ot_id'] ?? 0);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        if ($id) {
            $db->prepare("UPDATE overtime SET status=?, approved_by=?, approved_at=NOW() WHERE id=?")
               ->execute([$status, $user['id'], $id]);
            $otContact = $db->prepare("SELECT ot.*, u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM overtime ot JOIN employees e ON e.id=ot.employee_id LEFT JOIN users u ON u.employee_id=e.id WHERE ot.id=? LIMIT 1");
            $otContact->execute([$id]); $otContact = $otContact->fetch();
            if ($otContact && $otContact['user_id']) {
                $title = $status === 'approved' ? 'Overtime Approved' : 'Overtime Rejected';
                $message = $status === 'approved'
                    ? 'Your overtime for '.date('d M Y', strtotime($otContact['ot_date'])).' has been approved.'
                    : 'Your overtime for '.date('d M Y', strtotime($otContact['ot_date'])).' was not approved.';
                $type = $status === 'approved' ? 'success' : 'error';
                $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
                   ->execute([$otContact['user_id'], $title, $message, $type]);
            }
            if ($otContact) {
                $toEmail = trim((string)($otContact['user_email'] ?: $otContact['employee_email']));
                $toName = $otContact['user_name'] ?: $otContact['employee_name'];
                if ($toEmail !== '') {
                    if ($status === 'approved') {
                        emailOvertimeApproved($toEmail, $toName, $otContact['ot_date'], $otContact['hours'], $otContact['amount']);
                    } else {
                        emailOvertimeRejected($toEmail, $toName, $otContact['ot_date'], $otContact['hours']);
                    }
                }
            }
        }
        header('Location: overtime.php?msg='.$action.'d'); exit;
    }

    if ($action === 'back_capture_ot') {
        $emp_id    = (int)($_POST['bc_employee'] ?? 0);
        $ot_date   = $_POST['bc_date']      ?? '';
        $start_time= $_POST['bc_start']     ?? '17:00';
        $end_time  = $_POST['bc_end']       ?? '18:00';
        $day_type  = $_POST['bc_day_type']  ?? 'weekday';
        $notes     = clean($_POST['bc_notes'] ?? '');
        if ($emp_id && $ot_date) {
            $s = strtotime($ot_date.' '.$start_time);
            $e = strtotime($ot_date.' '.$end_time);
            if ($e <= $s) $e += 86400;
            $hours  = round(($e - $s) / 3600, 2);
            $rate   = ($day_type === 'sunday' || $day_type === 'public_holiday') ? 2.0 : 1.5;
            $emp    = $db->prepare("SELECT hourly_rate FROM employees WHERE id=?");
            $emp->execute([$emp_id]); $emp = $emp->fetch();
            $hourly = $emp ? (float)$emp['hourly_rate'] : 0;
            $amount = round($hours * $rate * $hourly, 2);
            $db->prepare("INSERT INTO overtime (employee_id,ot_date,start_time,end_time,hours,day_type,rate,hourly_rate,amount,notes,status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,'approved',?,NOW())")
               ->execute([$emp_id,$ot_date,$start_time,$end_time,$hours,$day_type,$rate,$hourly,$amount,$notes,$user['id']]);
        }
        header('Location: overtime.php?msg=bc_added'); exit;
    }

    if ($action === 'add_ot') {
        $emp_id     = (int)($_POST['ot_employee'] ?? 0);
        $ot_date    = $_POST['ot_date']       ?? '';
        $start_time = $_POST['ot_start']      ?? '';
        $end_time   = $_POST['ot_end']        ?? '';
        $day_type   = $_POST['ot_day_type']   ?? 'weekday';
        $notes      = clean($_POST['ot_notes'] ?? '');

        if ($emp_id && $ot_date && $start_time && $end_time) {
            // Calculate hours
            $s = strtotime($ot_date.' '.$start_time);
            $e = strtotime($ot_date.' '.$end_time);
            if ($e <= $s) $e += 86400; // past midnight
            $hours = round(($e - $s) / 3600, 2);

            // Get rate
            $rate = ($day_type === 'sunday' || $day_type === 'public_holiday') ? 2.0 : 1.5;

            // Get employee hourly rate
            $emp = $db->prepare("SELECT hourly_rate FROM employees WHERE id=?");
            $emp->execute([$emp_id]); $emp = $emp->fetch();
            $hourly = $emp ? (float)$emp['hourly_rate'] : 0;
            $amount = round($hours * $rate * $hourly, 2);

            $db->prepare("INSERT INTO overtime (employee_id,ot_date,start_time,end_time,hours,day_type,rate,hourly_rate,amount,notes,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')")
               ->execute([$emp_id,$ot_date,$start_time,$end_time,$hours,$day_type,$rate,$hourly,$amount,$notes]);
        }
        header('Location: overtime.php?msg=added'); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────
$pending   = $db->query("SELECT ot.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.avatar_color FROM overtime ot JOIN employees e ON e.id=ot.employee_id WHERE ot.status='pending' ORDER BY ot.ot_date ASC")->fetchAll();
$all       = $db->query("SELECT ot.*, CONCAT(e.first_name,' ',e.last_name) as emp_name FROM overtime ot JOIN employees e ON e.id=ot.employee_id ORDER BY ot.ot_date DESC LIMIT 100")->fetchAll();
$employees = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name, hourly_rate FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();
$pendingLeave = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();

// Summary stats
$approvedOT  = $db->query("SELECT SUM(amount) FROM overtime WHERE status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE())")->fetchColumn() ?? 0;
$totalHours  = $db->query("SELECT SUM(hours) FROM overtime WHERE status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE())")->fetchColumn() ?? 0;

$msg = $_GET['msg'] ?? '';

// Namibia public holidays current year
$publicHolidays = [
    date('Y').'-01-01', date('Y').'-03-21', date('Y').'-05-01',
    date('Y').'-05-04', date('Y').'-05-25', date('Y').'-08-26',
    date('Y').'-09-10', date('Y').'-12-10', date('Y').'-12-25', date('Y').'-12-26',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Overtime — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Overtime</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-secondary" onclick="openModal('backCaptureOTModal')"><i class="fa-solid fa-clock-rotate-left"></i> Back-Capture OT</button>
      <button class="btn btn-primary" onclick="openModal('addOTModal')"><i class="fa-solid fa-plus"></i> Log Overtime</button>
    </div>
  </div>

  <div class="content" id="overtimeContent">
    <?php if ($msg === 'bc_added'): ?><div class="toast"><i class="fa-solid fa-check"></i> Past overtime captured and approved.</div>
    <?php elseif ($msg === 'approved'): ?><div class="toast"><i class="fa-solid fa-check"></i> Overtime approved.</div>
    <?php elseif ($msg === 'rejected'): ?><div class="toast error"><i class="fa-solid fa-xmark"></i> Overtime rejected.</div>
    <?php elseif ($msg === 'added'): ?><div class="toast"><i class="fa-solid fa-check"></i> Overtime logged successfully.</div>
    <?php endif ?>

    <!-- Stats -->
    <div class="grid-4">
      <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-hourglass-half"></i></div><div class="stat-value"><?=count($pending)?></div><div class="stat-label">Pending Approval</div></div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fa-regular fa-clock"></i></div><div class="stat-value"><?=number_format((float)$totalHours,1)?>h</div><div class="stat-label">Approved Hours This Month</div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-money-bill-wave"></i></div><div class="stat-value">N$<?=number_format((float)$approvedOT,0)?></div><div class="stat-label">OT Pay This Month</div></div>
      <div class="stat-card"><div class="stat-icon teal"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="stat-value"><?=count(array_filter($all,function($r){return $r['day_type']==='public_holiday'&&$r['status']==='approved';}))?></div>
        <div class="stat-label">Public Holiday OT</div>
      </div>
    </div>

    <!-- Pending -->
    <?php if (!empty($pending)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-hourglass-half" style="color:var(--amber)"></i> Pending Approval</div>
        <span class="badge badge-amber"><?=count($pending)?> Pending</span>
      </div>
      <table>
        <thead><tr><th>Employee</th><th>Date</th><th>Time</th><th>Hours</th><th>Type</th><th>Rate</th><th>Amount</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r):
          $ini = strtoupper(implode('',array_map(function($w){return isset($w[0])?$w[0]:'';},array_filter(explode(' ',trim($r['emp_name']))))));
          $dt = $r['day_type'];
          if ($dt === 'public_holiday') $typeLabel = 'Public Holiday';
          elseif ($dt === 'sunday') $typeLabel = 'Sunday';
          elseif ($dt === 'saturday') $typeLabel = 'Saturday';
          else $typeLabel = 'Weekday';
          $typeClass = in_array($r['day_type'],['public_holiday','sunday']) ? 'badge-red' : 'badge-amber';
        ?>
        <tr>
          <td><div class="emp-cell"><div class="emp-avatar" style="background:<?=$r['avatar_color']?>"><?=$ini?></div><?=htmlspecialchars($r['emp_name'])?></div></td>
          <td><?=date('d M Y',strtotime($r['ot_date']))?></td>
          <td style="font-size:12px;color:var(--text-mid)"><?=substr($r['start_time'],0,5)?> – <?=substr($r['end_time'],0,5)?></td>
          <td><strong><?=$r['hours']?>h</strong></td>
          <td><span class="badge <?=$typeClass?>"><?=$typeLabel?></span></td>
          <td style="font-weight:700"><?=$r['rate']?>×</td>
          <td style="font-family:monospace;font-weight:700;color:var(--green)">N$ <?=number_format((float)$r['amount'],2)?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="ot_id" value="<?=$r['id']?>">
              <button class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i> Approve</button>
            </form>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="ot_id" value="<?=$r['id']?>">
              <button class="btn btn-danger btn-sm"><i class="fa-solid fa-xmark"></i> Reject</button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <!-- OT per Employee Summary -->
    <?php if (!empty($employees)): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--green)"></i> OT Summary — <?=date('F Y')?></div></div>
      <table>
        <thead><tr><th>Employee</th><th>Approved Hours</th><th>Weekday (1.5×)</th><th>Weekend/Holiday (2×)</th><th>Total OT Pay</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $emp):
          $empOT = $db->prepare("SELECT day_type, SUM(hours) as hrs, SUM(amount) as amt FROM overtime WHERE employee_id=? AND status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE()) GROUP BY day_type");
          $empOT->execute([$emp['id']]); $empOT = $empOT->fetchAll();
          $wdHrs=0; $whHrs=0; $total=0;
          foreach ($empOT as $o) {
            if (in_array($o['day_type'],['sunday','public_holiday'])) $whHrs+=$o['hrs'];
            else $wdHrs+=$o['hrs'];
            $total+=$o['amt'];
          }
        ?>
        <tr>
          <td style="font-weight:600"><?=htmlspecialchars($emp['name'])?></td>
          <td><?=number_format($wdHrs+$whHrs,1)?>h</td>
          <td><?=number_format($wdHrs,1)?>h</td>
          <td><?=number_format($whHrs,1)?>h</td>
          <td style="font-family:monospace;font-weight:700;color:<?=($total>0)?'var(--green)':'var(--text-mid)'?>">N$ <?=number_format($total,2)?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <!-- OT Log -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-list" style="color:var(--blue)"></i> Overtime Log</div></div>
      <?php if (empty($all)): ?>
        <div class="empty-state"><i class="fa-regular fa-clock"></i><div>No overtime logged yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employee</th><th>Date</th><th>Hours</th><th>Type</th><th>Rate</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($all as $r):
          $sc = $r['status']==='approved' ? 'badge-green' : ($r['status']==='rejected' ? 'badge-red' : 'badge-amber');
          $dt = $r['day_type'];
          if ($dt === 'public_holiday') $typeLabel = 'Public Holiday';
          elseif ($dt === 'sunday') $typeLabel = 'Sunday';
          elseif ($dt === 'saturday') $typeLabel = 'Saturday';
          else $typeLabel = 'Weekday';
        ?>
        <tr>
          <td><?=htmlspecialchars($r['emp_name'])?></td>
          <td><?=date('d M Y',strtotime($r['ot_date']))?></td>
          <td><?=$r['hours']?>h</td>
          <td><span class="badge <?=in_array($r['day_type'],['public_holiday','sunday'])?'badge-red':'badge-amber'?>"><?=$typeLabel?></span></td>
          <td><?=$r['rate']?>×</td>
          <td style="font-family:monospace">N$ <?=number_format((float)$r['amount'],2)?></td>
          <td><span class="badge <?=$sc?>"><?=ucfirst($r['status'])?></span></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
  </div>
</div>

<!-- LOG OT MODAL -->
<div class="overlay" id="addOTModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-clock"></i> Log Overtime</div>
      <button class="modal-close" onclick="closeModal('addOTModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_ot">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="ot_employee" id="otEmployee" required onchange="updateRate()">
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?=$e['id']?>" data-rate="<?=$e['hourly_rate']?>"><?=htmlspecialchars($e['name'])?> — N$<?=number_format((float)$e['hourly_rate'],2)?>/hr</option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date</label>
            <input class="form-input" type="date" name="ot_date" id="otDate" required onchange="checkHoliday()">
          </div>
          <div class="form-group">
            <label class="form-label">Day Type</label>
            <select class="form-select" name="ot_day_type" id="otDayType" onchange="calcOT()">
              <option value="weekday">Weekday / Saturday (1.5×)</option>
              <option value="sunday">Sunday (2×)</option>
              <option value="public_holiday">Public Holiday (2×)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Start Time</label>
            <input class="form-input" type="time" name="ot_start" id="otStart" required onchange="calcOT()">
          </div>
          <div class="form-group">
            <label class="form-label">End Time</label>
            <input class="form-input" type="time" name="ot_end" id="otEnd" required onchange="calcOT()">
          </div>
          <div class="form-group full">
            <label class="form-label">Notes (optional)</label>
            <input class="form-input" name="ot_notes" placeholder="e.g. Stock take, delivery run...">
          </div>
        </div>

        <!-- Live Calculator -->
        <div id="otCalc" style="display:none;margin-top:18px;background:#f0f9f4;border:1px solid var(--green-mid);border-radius:10px;padding:16px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-mid);margin-bottom:8px">OT Calculation</div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:13px;color:var(--text-mid)" id="otBreakdown">—</div>
            <div style="font-size:22px;font-weight:800;color:var(--green);font-family:monospace" id="otAmount">N$ 0.00</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addOTModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Overtime</button>
      </div>
    </form>
  </div>
</div>

<script>
const publicHolidays = <?= json_encode($publicHolidays) ?>;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target===o) o.classList.remove('open'); });
});

function checkHoliday() {
  const date = document.getElementById('otDate').value;
  const sel  = document.getElementById('otDayType');
  if (!date) return;
  const d = new Date(date);
  const day = d.getDay(); // 0=Sun, 6=Sat
  if (publicHolidays.includes(date)) {
    sel.value = 'public_holiday';
  } else if (day === 0) {
    sel.value = 'sunday';
  } else {
    sel.value = 'weekday';
  }
  calcOT();
}

function updateRate() { calcOT(); }

function calcOT() {
  const empSel  = document.getElementById('otEmployee');
  const start   = document.getElementById('otStart').value;
  const end     = document.getElementById('otEnd').value;
  const dayType = document.getElementById('otDayType').value;
  const calc    = document.getElementById('otCalc');

  if (!empSel.value || !start || !end) { calc.style.display='none'; return; }

  const opt      = empSel.options[empSel.selectedIndex];
  const hourly   = parseFloat(opt.dataset.rate) || 0;
  const rate     = (dayType==='sunday'||dayType==='public_holiday') ? 2.0 : 1.5;

  let [sh,sm] = start.split(':').map(Number);
  let [eh,em] = end.split(':').map(Number);
  let hours = (eh*60+em - sh*60-sm) / 60;
  if (hours <= 0) hours += 24;
  hours = Math.round(hours * 100) / 100;

  const amount = Math.round(hours * rate * hourly * 100) / 100;

  document.getElementById('otBreakdown').textContent =
    `N$${hourly.toFixed(2)}/hr × ${hours}h × ${rate}×`;
  document.getElementById('otAmount').textContent = `N$ ${amount.toFixed(2)}`;
  calc.style.display = 'block';
}

var overtimeRefreshInFlight = false;
function refreshOvertimeContent() {
  if (overtimeRefreshInFlight || document.hidden || document.querySelector('.overlay.open')) return;
  overtimeRefreshInFlight = true;
  fetch('overtime.php?refresh=' + Date.now(), {
    credentials: 'same-origin',
    headers: {'X-Requested-With': 'XMLHttpRequest'}
  }).then(function(response) {
    if (!response.ok) throw new Error('Overtime refresh failed');
    return response.text();
  }).then(function(html) {
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    var fresh = parsed.getElementById('overtimeContent');
    var current = document.getElementById('overtimeContent');
    if (fresh && current) current.replaceWith(fresh);
  }).catch(function() {
    // Keep the current owner view intact; the next interval/manual refresh can retry.
  }).then(function() {
    overtimeRefreshInFlight = false;
  });
}

setInterval(refreshOvertimeContent, 45000);
document.addEventListener('visibilitychange', function() {
  if (!document.hidden) refreshOvertimeContent();
});
</script>
<!-- BACK CAPTURE OT MODAL -->
<div class="overlay" id="backCaptureOTModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-clock-rotate-left"></i> Back-Capture Past Overtime</div>
      <button class="modal-close" onclick="closeModal('backCaptureOTModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="back_capture_ot">
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-mid);margin-bottom:16px">Record overtime that was already worked before the system was set up. It will be saved as Approved immediately.</p>
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="bc_employee" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> — N$<?=number_format((float)$e['hourly_rate'],2)?>/hr</option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date</label>
            <input class="form-input" type="date" name="bc_date" required>
          </div>
          <div class="form-group">
            <label class="form-label">Day Type</label>
            <select class="form-select" name="bc_day_type">
              <option value="weekday">Weekday / Saturday (1.5×)</option>
              <option value="sunday">Sunday (2×)</option>
              <option value="public_holiday">Public Holiday (2×)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Start Time</label>
            <input class="form-input" type="time" name="bc_start" value="17:00" required>
          </div>
          <div class="form-group">
            <label class="form-label">End Time</label>
            <input class="form-input" type="time" name="bc_end" value="19:00" required>
          </div>
          <div class="form-group full">
            <label class="form-label">Notes (optional)</label>
            <input class="form-input" name="bc_notes" placeholder="e.g. Stock take January 2026">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('backCaptureOTModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save as Approved</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
