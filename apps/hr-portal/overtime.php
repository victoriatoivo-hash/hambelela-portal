<?php
require_once __DIR__ . '/config.php';
requireAdmin();
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/overtime-review.php';
$user = currentUser();
$db   = db();
hrEnsureOvertimeReviewSchema($db);

// ── Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['approve','adjust_approve','reject'], true)) {
        $id     = (int)($_POST['ot_id'] ?? 0);
        $status = $action === 'reject' ? 'rejected' : 'approved';
        if ($id) {
            $db->beginTransaction();
            $locked = $db->prepare("SELECT * FROM overtime WHERE id=? FOR UPDATE");
            $locked->execute([$id]);
            $before = $locked->fetch();
            if (!$before) {
                $db->rollBack();
                header('Location: overtime.php?msg=missing'); exit;
            }
            if (!empty($before['payroll_run_id']) || !empty($before['payroll_processed_at'])) {
                $db->rollBack();
                header('Location: overtime.php?msg=processed'); exit;
            }
            $reason = trim(clean($_POST['adjustment_reason'] ?? ''));
            if ($action === 'reject') {
                if ($reason === '') {
                    $db->rollBack();
                    header('Location: overtime.php?msg=reason_required'); exit;
                }
                $db->prepare("UPDATE overtime SET status='rejected', approved_start_time=NULL, approved_end_time=NULL, approved_hours=NULL, approved_amount=NULL, review_outcome='rejected', adjustment_reason=?, approved_by=?, approved_at=NOW() WHERE id=?")
                   ->execute([$reason, $user['id'], $id]);
            } else {
                $approvedStart = $action === 'adjust_approve' ? trim((string)($_POST['approved_start_time'] ?? '')) : $before['start_time'];
                $approvedEnd = $action === 'adjust_approve' ? trim((string)($_POST['approved_end_time'] ?? '')) : $before['end_time'];
                if ($action === 'adjust_approve' && $reason === '') {
                    $db->rollBack();
                    header('Location: overtime.php?msg=reason_required'); exit;
                }
                $approvedHours = hrOvertimeDuration($before['ot_date'], $approvedStart, $approvedEnd);
                $approvedAmount = round($approvedHours * (float)$before['rate'] * (float)$before['hourly_rate'], 2);
                $outcome = $action === 'adjust_approve' ? 'adjusted_approved' : 'approved_as_submitted';
                $db->prepare("UPDATE overtime SET status='approved', approved_start_time=?, approved_end_time=?, approved_hours=?, approved_amount=?, review_outcome=?, adjustment_reason=?, approved_by=?, approved_at=NOW() WHERE id=?")
                   ->execute([$approvedStart,$approvedEnd,$approvedHours,$approvedAmount,$outcome,$reason ?: null,$user['id'],$id]);
            }
            $afterStmt = $db->prepare("SELECT * FROM overtime WHERE id=?");
            $afterStmt->execute([$id]);
            $after = $afterStmt->fetch();
            hrLogOvertimeReview($db, $id, $action, hrOvertimeSnapshot($before), hrOvertimeSnapshot($after), $reason ?: null, (int)$user['id']);
            $db->commit();
            $otContact = $db->prepare("SELECT ot.*, u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM overtime ot JOIN employees e ON e.id=ot.employee_id LEFT JOIN users u ON u.employee_id=e.id WHERE ot.id=? LIMIT 1");
            $otContact->execute([$id]); $otContact = $otContact->fetch();
            if ($otContact && $otContact['user_id']) {
                $title = $status === 'approved' ? 'Overtime Approved' : 'Overtime Rejected';
                $message = $status === 'approved'
                    ? 'Your overtime for '.date('d M Y', strtotime($otContact['ot_date'])).' has been '.($otContact['review_outcome']==='adjusted_approved'?'adjusted and approved':'approved').'.'
                    : 'Your overtime for '.date('d M Y', strtotime($otContact['ot_date'])).' was not approved. Reason: '.$reason;
                $type = $status === 'approved' ? 'success' : 'error';
                $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
                   ->execute([$otContact['user_id'], $title, $message, $type]);
            }
            if ($otContact) {
                $toEmail = trim((string)($otContact['user_email'] ?: $otContact['employee_email']));
                $toName = $otContact['user_name'] ?: $otContact['employee_name'];
                if ($toEmail !== '') {
                    if ($status === 'approved') {
                        emailOvertimeApproved($toEmail, $toName, $otContact['ot_date'], $otContact['approved_hours'], $otContact['approved_amount']);
                    } else {
                        emailOvertimeRejected($toEmail, $toName, $otContact['ot_date'], $otContact['hours']);
                    }
                }
            }
        }
        header('Location: overtime.php?msg='.($action==='adjust_approve'?'adjusted':$action.'d')); exit;
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
            $db->prepare("INSERT INTO overtime (employee_id,ot_date,start_time,end_time,approved_start_time,approved_end_time,hours,approved_hours,day_type,rate,hourly_rate,amount,approved_amount,notes,status,review_outcome,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved','approved_as_submitted',?,NOW())")
               ->execute([$emp_id,$ot_date,$start_time,$end_time,$start_time,$end_time,$hours,$hours,$day_type,$rate,$hourly,$amount,$amount,$notes,$user['id']]);
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
$all       = $db->query("SELECT ot.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, rv.name AS reviewer_name FROM overtime ot JOIN employees e ON e.id=ot.employee_id LEFT JOIN users rv ON rv.id=ot.approved_by ORDER BY ot.ot_date DESC LIMIT 100")->fetchAll();
$auditByOvertime = [];
if ($all) {
    $ids = array_map('intval', array_column($all, 'id'));
    $auditStmt = $db->query("SELECT a.*, u.name AS actor_name FROM overtime_review_audit a LEFT JOIN users u ON u.id=a.performed_by WHERE a.overtime_id IN (".implode(',', $ids).") ORDER BY a.created_at DESC, a.id DESC");
    foreach ($auditStmt->fetchAll() as $auditRow) $auditByOvertime[(int)$auditRow['overtime_id']][] = $auditRow;
}
$employees = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name, hourly_rate FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();
$pendingLeave = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();

// Summary stats
$approvedOT  = $db->query("SELECT SUM(approved_amount) FROM overtime WHERE status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE())")->fetchColumn() ?? 0;
$totalHours  = $db->query("SELECT SUM(approved_hours) FROM overtime WHERE status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE())")->fetchColumn() ?? 0;

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
    <?php elseif ($msg === 'adjusted'): ?><div class="toast"><i class="fa-solid fa-sliders"></i> Overtime adjusted and approved.</div>
    <?php elseif ($msg === 'rejected'): ?><div class="toast error"><i class="fa-solid fa-xmark"></i> Overtime rejected.</div>
    <?php elseif ($msg === 'reason_required'): ?><div class="toast error"><i class="fa-solid fa-triangle-exclamation"></i> A reason is required for adjustments and rejections.</div>
    <?php elseif ($msg === 'processed'): ?><div class="toast error"><i class="fa-solid fa-lock"></i> This overtime is already included in payroll and cannot be changed.</div>
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
            <button type="button" class="btn btn-success btn-sm" onclick='openReview(<?=json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT)?>,"approve")'><i class="fa-solid fa-check"></i> Approve</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick='openReview(<?=json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT)?>,"adjust")'><i class="fa-solid fa-sliders"></i> Adjust</button>
            <button type="button" class="btn btn-danger btn-sm" onclick='openReview(<?=json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT)?>,"reject")'><i class="fa-solid fa-xmark"></i> Reject</button>
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
          $empOT = $db->prepare("SELECT day_type, SUM(approved_hours) as hrs, SUM(approved_amount) as amt FROM overtime WHERE employee_id=? AND status='approved' AND MONTH(ot_date)=MONTH(CURDATE()) AND YEAR(ot_date)=YEAR(CURDATE()) GROUP BY day_type");
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
        <thead><tr><th>Employee</th><th>Date</th><th>Submitted</th><th>Approved</th><th>Type</th><th>Amount</th><th>Status</th><th>Review</th></tr></thead>
        <tbody>
        <?php foreach ($all as $r):
          $sc = $r['status']==='approved' ? 'badge-green' : ($r['status']==='rejected' ? 'badge-red' : 'badge-amber');
          $statusLabel = $r['status']==='approved' && $r['review_outcome']==='adjusted_approved' ? 'Adjusted & approved' : ucfirst($r['status']);
          if (!empty($r['payroll_run_id'])) $statusLabel = 'Payroll processed';
          $dt = $r['day_type'];
          if ($dt === 'public_holiday') $typeLabel = 'Public Holiday';
          elseif ($dt === 'sunday') $typeLabel = 'Sunday';
          elseif ($dt === 'saturday') $typeLabel = 'Saturday';
          else $typeLabel = 'Weekday';
        ?>
        <tr>
          <td><?=htmlspecialchars($r['emp_name'])?></td>
          <td><?=date('d M Y',strtotime($r['ot_date']))?></td>
          <td><?=hrOvertimeDisplayHours($r['hours'])?><br><span style="font-size:11px;color:var(--text-mid)"><?=substr($r['start_time'],0,5)?>–<?=substr($r['end_time'],0,5)?></span></td>
          <td><?=hrOvertimeDisplayHours($r['approved_hours'])?><?php if($r['approved_start_time']): ?><br><span style="font-size:11px;color:var(--text-mid)"><?=substr($r['approved_start_time'],0,5)?>–<?=substr($r['approved_end_time'],0,5)?></span><?php endif ?></td>
          <td><span class="badge <?=in_array($r['day_type'],['public_holiday','sunday'])?'badge-red':'badge-amber'?>"><?=$typeLabel?></span></td>
          <td style="font-family:monospace">N$ <?=number_format((float)($r['approved_amount'] ?? $r['amount']),2)?></td>
          <td><span class="badge <?=$sc?>"><?=htmlspecialchars($statusLabel)?></span></td>
          <td>
            <?php if($r['status']==='approved' && empty($r['payroll_run_id'])): ?><button type="button" class="btn btn-secondary btn-sm" onclick='openReview(<?=json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT)?>,"adjust")'><i class="fa-solid fa-pen"></i> Re-edit</button><?php endif ?>
            <?php if(!empty($r['adjustment_reason'])): ?><div style="font-size:11px;margin-top:5px"><i class="fa-regular fa-note-sticky"></i> <?=htmlspecialchars($r['adjustment_reason'])?></div><?php endif ?>
            <?php if(!empty($auditByOvertime[(int)$r['id']])): ?>
              <details style="font-size:11px;margin-top:6px"><summary>Audit history</summary>
                <?php foreach($auditByOvertime[(int)$r['id']] as $event): $newValues=json_decode($event['new_values_json'] ?: '{}',true) ?: []; ?>
                  <div style="padding:6px 0;border-top:1px solid var(--border)"><strong><?=htmlspecialchars(str_replace('_',' ',ucfirst($event['action'])))?></strong><br><?=htmlspecialchars($event['actor_name'] ?: 'Management')?> · <?=date('d M Y H:i',strtotime($event['created_at']))?><?php if(isset($newValues['approved_hours']) && $newValues['approved_hours']!==null): ?><br><?=hrOvertimeDisplayHours($newValues['approved_hours'])?> approved<?php endif ?></div>
                <?php endforeach ?>
              </details>
            <?php elseif(empty($r['adjustment_reason']) && !($r['status']==='approved' && empty($r['payroll_run_id']))): ?>—<?php endif ?>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
  </div>
</div>

<!-- REVIEW OVERTIME MODAL -->
<div class="overlay" id="reviewOTModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-user-clock"></i> Review Overtime</div>
      <button class="modal-close" type="button" onclick="closeModal('reviewOTModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="reviewOTForm">
      <input type="hidden" name="action" id="reviewAction" value="approve">
      <input type="hidden" name="ot_id" id="reviewOtId">
      <div class="modal-body">
        <div style="background:#f7f4ea;border:1px solid #ded8c5;border-radius:12px;padding:14px;margin-bottom:16px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-mid)">Employee submission</div>
          <div id="reviewEmployee" style="font-weight:700;margin:5px 0"></div>
          <div id="reviewSubmitted" style="font-size:13px"></div>
        </div>
        <div id="approvedFields" class="form-grid" style="display:none">
          <div class="form-group">
            <label class="form-label">Approved start</label>
            <input class="form-input" type="time" name="approved_start_time" id="approvedStart">
          </div>
          <div class="form-group">
            <label class="form-label">Approved end</label>
            <input class="form-input" type="time" name="approved_end_time" id="approvedEnd">
          </div>
          <div class="form-group full" style="background:#eef3df;border:1px solid #cfdbad;border-radius:10px;padding:12px">
            <span style="font-size:12px;color:var(--text-mid)">Approved duration and pay</span>
            <strong id="approvedPreview" style="display:block;margin-top:3px"></strong>
          </div>
        </div>
        <div class="form-group" id="reasonField" style="display:none;margin-top:14px">
          <label class="form-label" id="reasonLabel">Reason</label>
          <textarea class="form-input" name="adjustment_reason" id="reviewReason" rows="3" placeholder="Explain the adjustment or rejection"></textarea>
        </div>
        <p id="reviewHelp" style="font-size:12px;color:var(--text-mid);margin-top:12px"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('reviewOTModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="reviewSubmit"><i class="fa-solid fa-check"></i> Approve as submitted</button>
      </div>
    </form>
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
let reviewRecord = null;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openReview(record, mode) {
  reviewRecord = record;
  document.getElementById('reviewOtId').value = record.id;
  document.getElementById('reviewEmployee').textContent = record.emp_name + ' · ' + record.ot_date;
  document.getElementById('reviewSubmitted').textContent = record.start_time.slice(0,5) + '–' + record.end_time.slice(0,5) + ' · ' + humanHours(record.hours) + ' · N$ ' + Number(record.amount).toFixed(2);
  document.getElementById('approvedStart').value = (record.approved_start_time || record.start_time).slice(0,5);
  document.getElementById('approvedEnd').value = (record.approved_end_time || record.end_time).slice(0,5);
  document.getElementById('reviewReason').value = record.adjustment_reason || '';
  const adjusting = mode === 'adjust';
  const rejecting = mode === 'reject';
  document.getElementById('reviewAction').value = adjusting ? 'adjust_approve' : (rejecting ? 'reject' : 'approve');
  document.getElementById('approvedFields').style.display = adjusting ? 'grid' : 'none';
  document.getElementById('reasonField').style.display = (adjusting || rejecting) ? 'block' : 'none';
  document.getElementById('reviewReason').required = adjusting || rejecting;
  document.getElementById('reasonLabel').textContent = rejecting ? 'Rejection reason' : 'Adjustment reason';
  const submit = document.getElementById('reviewSubmit');
  submit.className = 'btn ' + (rejecting ? 'btn-danger' : 'btn-primary');
  submit.innerHTML = rejecting ? '<i class="fa-solid fa-xmark"></i> Reject overtime' : (adjusting ? '<i class="fa-solid fa-check"></i> Save adjustment & approve' : '<i class="fa-solid fa-check"></i> Approve as submitted');
  document.getElementById('reviewHelp').textContent = rejecting ? 'Rejected overtime is excluded from payroll.' : (adjusting ? 'The employee’s submitted times remain unchanged. Payroll will use the approved values.' : 'This creates a separate approved snapshot for payroll.');
  updateApprovedPreview();
  openModal('reviewOTModal');
}

function humanHours(hours) {
  const mins = Math.round(Number(hours) * 60);
  return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
}

function updateApprovedPreview() {
  if (!reviewRecord) return;
  const start = document.getElementById('approvedStart').value;
  const end = document.getElementById('approvedEnd').value;
  if (!start || !end) return;
  const sm = Number(start.slice(0,2)) * 60 + Number(start.slice(3,5));
  let em = Number(end.slice(0,2)) * 60 + Number(end.slice(3,5));
  if (em <= sm) em += 1440;
  const hours = Math.round(((em-sm)/60) * 100) / 100;
  const amount = Math.round(hours * Number(reviewRecord.rate) * Number(reviewRecord.hourly_rate) * 100) / 100;
  document.getElementById('approvedPreview').textContent = humanHours(hours) + ' · N$ ' + amount.toFixed(2);
}
document.getElementById('approvedStart').addEventListener('input', updateApprovedPreview);
document.getElementById('approvedEnd').addEventListener('input', updateApprovedPreview);

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
