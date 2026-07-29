<?php
require_once __DIR__ . '/config.php';
requireAdmin();
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/leave-reserve.php';
$user = currentUser();
$db   = db();
ensureLeaveShutdownSchema($db);

// ── Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';


    if ($action === 'delete_leave') {
        $did = (int)($_POST['delete_id'] ?? 0);
        if ($did) {
            $lr = $db->prepare("SELECT employee_id,leave_type,days,YEAR(start_date) as yr FROM leave_requests WHERE id=?");
            $lr->execute([$did]); $lr = $lr->fetch();
            if ($lr) {
                $db->prepare("DELETE FROM leave_requests WHERE id=?")->execute([$did]);
                $db->prepare("UPDATE leave_balances SET used_days=GREATEST(0,used_days-?),balance_days=balance_days+? WHERE employee_id=? AND leave_type=? AND year=?")
                   ->execute([(float)$lr['days'],(float)$lr['days'],$lr['employee_id'],$lr['leave_type'],$lr['yr']]);
                $msg = 'leave_deleted';
            }
        }
    }

    // Approve / Reject
    if ($action === 'approve' || $action === 'reject') {
        $id     = (int)($_POST['request_id'] ?? 0);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $reason = clean($_POST['reject_reason'] ?? '');
        if ($id) {
            $req = $db->prepare("SELECT * FROM leave_requests WHERE id=?");
            $req->execute([$id]); $req = $req->fetch();
            if ($req) {
                $db->prepare("UPDATE leave_requests SET status=?, approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=?")
                   ->execute([$status, $user['id'], $reason, $id]);
                // Deduct from balance if approved
                if ($status === 'approved') {
                    $year = date('Y', strtotime($req['start_date']));
                    $db->prepare("UPDATE leave_balances SET used_days=used_days+? WHERE employee_id=? AND leave_type=? AND year=?")
                       ->execute([$req['days'], $req['employee_id'], $req['leave_type'], $year]);
                    // Notify employee in the portal and by email.
                    $empContact = $db->prepare("SELECT u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1");
                    $empContact->execute([$req['employee_id']]); $empContact = $empContact->fetch();
                    if ($empContact && $empContact['user_id']) {
                        $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'success')")
                           ->execute([$empContact['user_id'],'Leave Approved','Your '.$req['leave_type'].' request for '.$req['days'].' day(s) has been approved.']);
                    }
                    if ($empContact) {
                        $toEmail = trim((string)($empContact['user_email'] ?: $empContact['employee_email']));
                        $toName = $empContact['user_name'] ?: $empContact['employee_name'];
                        if ($toEmail !== '') emailLeaveApproved($toEmail, $toName, $req['leave_type'], $req['days'], $req['start_date'], $req['end_date']);
                    }
                } else {
                    // Notify rejection
                    $empContact = $db->prepare("SELECT u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1");
                    $empContact->execute([$req['employee_id']]); $empContact = $empContact->fetch();
                    if ($empContact && $empContact['user_id']) {
                        $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'error')")
                           ->execute([$empContact['user_id'],'Leave Request Rejected','Your '.$req['leave_type'].' request has been rejected.'.($reason?' Reason: '.$reason:'')]);
                    }
                    if ($empContact) {
                        $toEmail = trim((string)($empContact['user_email'] ?: $empContact['employee_email']));
                        $toName = $empContact['user_name'] ?: $empContact['employee_name'];
                        if ($toEmail !== '') emailLeaveRejected($toEmail, $toName, $req['leave_type'], $reason);
                    }
                }
            }
        }
        header('Location: leave.php?msg='.$action.'d'); exit;
    }

    // Back-capture
    if ($action === 'back_capture') {
        $emp_id     = (int)($_POST['bc_employee'] ?? 0);
        $leave_type = clean($_POST['bc_leave_type'] ?? '');
        $start      = $_POST['bc_start_date'] ?? '';
        $end        = $_POST['bc_end_date']   ?? '';
        $days       = (float)($_POST['bc_days'] ?? 0);
        if ($emp_id && $leave_type && $start && $end && $days > 0) {
            $db->prepare("INSERT INTO leave_requests (employee_id,leave_type,start_date,end_date,days,status,back_capture,approved_by,approved_at) VALUES (?,?,?,?,?,'approved',1,?,NOW())")
               ->execute([$emp_id,$leave_type,$start,$end,$days,$user['id']]);
            $year = date('Y',strtotime($start));
            $db->prepare("UPDATE leave_balances SET used_days=used_days+? WHERE employee_id=? AND leave_type=? AND year=?")
               ->execute([$days,$emp_id,$leave_type,$year]);
        }
        header('Location: leave.php?msg=captured'); exit;
    }

    // Adjust balance
    if ($action === 'adjust_balance') {
        $emp_id     = (int)($_POST['adj_employee'] ?? 0);
        $leave_type = clean($_POST['adj_leave_type'] ?? '');
        $new_bal    = (float)($_POST['adj_balance'] ?? 0);
        $year       = (int)($_POST['adj_year'] ?? date('Y'));
        if ($emp_id && $leave_type) {
            $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,?,?,0,?) ON DUPLICATE KEY UPDATE balance_days=?")
               ->execute([$emp_id,$leave_type,$new_bal,$year,$new_bal]);
        }
        header('Location: leave.php?msg=adjusted'); exit;
    }

    if ($action === 'save_shutdown_plan') {
        $empId = (int)($_POST['shutdown_employee'] ?? 0);
        $year = (int)($_POST['shutdown_year'] ?? date('Y'));
        $handling = clean($_POST['shutdown_handling'] ?? '');
        $notes = clean($_POST['shutdown_notes'] ?? '');
        if ($empId && in_array($handling, ['unpaid','borrow','exception'], true)) {
            $db->prepare("INSERT INTO shutdown_leave_plans (employee_id,year,handling,notes,updated_by,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE handling=?,notes=?,updated_by=?,updated_at=NOW()")
               ->execute([$empId,$year,$handling,$notes,$user['id'],$handling,$notes,$user['id']]);
        }
        header('Location: leave.php?msg=shutdown_saved'); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────
$pending   = $db->query("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.avatar_color FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.status='pending' ORDER BY lr.created_at ASC")->fetchAll();
$all       = $db->query("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, u.name AS reviewer_name
                         FROM leave_requests lr
                         JOIN employees e ON e.id=lr.employee_id
                         LEFT JOIN users u ON u.id=lr.approved_by
                         ORDER BY lr.created_at DESC LIMIT 100")->fetchAll();
$employees = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();
$pendingOT = $db->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();

$leaveTypes = ['Annual Leave','Sick Leave','Compassionate Leave','Maternity Leave','Unpaid Leave'];
$shutdownSettings = shutdownSettings($db);
$reservePreview = annualLeaveMetrics($db, 0, (int)date('Y'));
$shutdownYear = (int)date('Y');
$shutdownPlans = [];
$planRows = $db->prepare("SELECT * FROM shutdown_leave_plans WHERE year=?");
$planRows->execute([$shutdownYear]);
foreach ($planRows->fetchAll() as $p) $shutdownPlans[(int)$p['employee_id']] = $p;
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Management — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Leave Management</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-secondary" onclick="var m=document.getElementById('backCaptureModal');if(m){m.style.display='flex';m.classList.add('open');}void(0)"><i class="fa-solid fa-clock-rotate-left"></i> Back-Capture Leave</button>
      <button class="btn btn-secondary" onclick="var m=document.getElementById('adjustModal');if(m){m.style.display='flex';m.classList.add('open');}void(0)"><i class="fa-solid fa-sliders"></i> Adjust Balance</button>
    </div>
  </div>

  <div class="content">
    <?php if ($msg === 'approved'): ?><div class="toast"><i class="fa-solid fa-check"></i> Leave request approved.</div>
    <?php elseif ($msg === 'leave_deleted'): ?><div class="toast no-print error"><i class="fa-solid fa-trash"></i> Leave deleted, balance restored.</div>
    <?php elseif ($msg === 'rejected'): ?><div class="toast error"><i class="fa-solid fa-xmark"></i> Leave request rejected.</div>
    <?php elseif ($msg === 'captured'): ?><div class="toast"><i class="fa-solid fa-check"></i> Past leave captured successfully.</div>
    <?php elseif ($msg === 'adjusted'): ?><div class="toast"><i class="fa-solid fa-check"></i> Leave balance adjusted.</div>
    <?php elseif ($msg === 'shutdown_saved'): ?><div class="toast"><i class="fa-solid fa-check"></i> Shutdown shortfall handling saved.</div>
    <?php endif ?>

    <!-- Accrual Info Banner -->
    <?php
    $currentMonth = (int)date('n');
    $accrualDays  = min($currentMonth * 2, 24);
    $nextMonth    = date('F', mktime(0,0,0,date('n')+1,1));
    ?>
    <div style="background:var(--green-pale);border:1px solid var(--green-mid);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;font-size:13px">
      <div><i class="fa-solid fa-circle-info" style="color:var(--green);margin-right:8px"></i><strong>Annual Leave Accrual:</strong> Employees accumulate <strong>2 days per month</strong> from 1 January. Current month (<?=date('F')?>) = <strong><?=$accrualDays?> days available</strong>.</div>
      <div style="font-size:12px;color:var(--text-mid)">Next accrual: 1 <?=$nextMonth?> (+2 days)</div>
    </div>
    <!-- Stats -->
    <div class="grid-4">
      <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-hourglass-half"></i></div><div class="stat-value"><?=count($pending)?></div><div class="stat-label">Pending Requests</div></div>
      <?php
      $approved = array_filter($all, function($r) { return $r['status']==='approved'; });
      $rejected = array_filter($all, function($r) { return $r['status']==='rejected'; });
      $thisMonth = array_filter($approved, function($r) { return date('Y-m',strtotime($r['start_date'])) === date('Y-m'); });
      ?>
      <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-calendar-check"></i></div><div class="stat-value"><?=count($approved)?></div><div class="stat-label">Approved This Year</div></div>
      <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-calendar-xmark"></i></div><div class="stat-value"><?=count($rejected)?></div><div class="stat-label">Rejected</div></div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fa-regular fa-calendar"></i></div><div class="stat-value"><?=count($thisMonth)?></div><div class="stat-label">On Leave This Month</div></div>
    </div>

    <!-- Pending Requests -->
    <?php if (!empty($pending)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-hourglass-half" style="color:var(--amber)"></i> Pending Requests</div>
        <span class="badge badge-amber"><?=count($pending)?> Pending</span>
      </div>
      <table>
        <thead><tr><th>Employee</th><th>Leave Type</th><th>Dates</th><th>Days</th><th>Certificate</th><th>Submitted</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r):
          $ini = strtoupper(implode('',array_map(function($w){return $w[0];},explode(' ',trim($r['emp_name'])))));
        ?>
        <tr>
          <td><div class="emp-cell"><div class="emp-avatar" style="background:<?=$r['avatar_color']?>"><?=$ini?></div><?=htmlspecialchars($r['emp_name'])?></div></td>
          <td><span class="badge badge-blue"><?=htmlspecialchars($r['leave_type'])?></span></td>
          <td><?=date('d M',strtotime($r['start_date']))?> – <?=date('d M Y',strtotime($r['end_date']))?></td>
          <td><strong><?=$r['days']?></strong></td>
          <td>
            <?php if (($r['leave_type']==='Sick Leave' || $r['leave_type']==='Unpaid Leave') && $r['certificate']): ?>
              <a href="download-certificate.php?file=<?php echo urlencode(basename($r['certificate'])); ?>" target="_blank" style="color:#2c5f2d;text-decoration:none;font-size:12px">
                <i class="fa-solid fa-file-pdf"></i> View
              </a>
            <?php elseif (($r['leave_type']==='Sick Leave' || $r['leave_type']==='Unpaid Leave') && !$r['certificate']): ?>
              <span style="color:#999;font-size:12px">Not uploaded</span>
            <?php else: ?>
              <span style="color:#999;font-size:12px">—</span>
            <?php endif ?>
          </td>
          <td style="font-size:12px;color:var(--text-mid)"><?=date('d M Y',strtotime($r['created_at']))?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="request_id" value="<?=$r['id']?>">
              <button class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i> Approve</button>
            </form>
            <button class="btn btn-danger btn-sm" onclick="openReject(<?=$r['id']?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this leave permanently?')"><input type="hidden" name="action" value="delete_leave"><input type="hidden" name="delete_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:13px;display:flex;justify-content:space-between;gap:16px;align-items:center">
      <div>
        <strong><i class="fa-solid fa-circle-info" style="color:var(--text-mid);margin-right:8px"></i>December Shutdown Reserve</strong>
        <div style="font-size:12px;color:var(--text-mid);margin-top:3px">Reserve starts accumulating on 1 August. Shutdown period: <?=$shutdownSettings['start_md']?> to <?=$shutdownSettings['end_md']?>.</div>
      </div>
      <div style="font-family:monospace;font-weight:800;color:var(--text-mid);white-space:nowrap">Current reserve: <?=number_format($reservePreview['reserve_accumulated'],1)?> / <?=number_format($reservePreview['projected_reserve'],1)?> days</div>
    </div>

    <!-- Leave Balances per Employee -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--green)"></i> Leave Balances — <?=date('Y')?></div>
      </div>
      <?php if (empty($employees)): ?>
        <div class="empty-state"><i class="fa-solid fa-users"></i><div>No employees found. Add employees first.</div></div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Employee</th>
            <?php foreach ($leaveTypes as $lt): ?><th style="text-align:center"><?=htmlspecialchars($lt)?></th><?php endforeach ?>
            <th style="text-align:center">Reserve Leave</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $year = date('Y');
        foreach ($employees as $emp):
          $balances = $db->prepare("SELECT leave_type,balance_days,used_days FROM leave_balances WHERE employee_id=? AND year=?");
          $balances->execute([$emp['id'],$year]);
          $balMap = [];
          foreach ($balances->fetchAll() as $b) $balMap[$b['leave_type']] = $b;
        ?>
        <tr>
          <td style="font-weight:600"><?=htmlspecialchars($emp['name'])?></td>
          <?php foreach ($leaveTypes as $lt):
            $bal  = $balMap[$lt] ?? null;
            $used = $bal ? (float)$bal['used_days'] : 0;
            $tot  = $bal ? (float)$bal['balance_days'] : 0;
            $rem  = max(0, $tot - $used);
            $pct  = $tot > 0 ? min(100, round($used/$tot*100)) : 0;
            $col  = $rem <= 2 ? 'var(--red)' : ($rem <= 5 ? 'var(--amber)' : 'var(--green)');
          ?>
          <td style="text-align:center">
            <div style="font-size:13px;font-weight:700;color:<?=$col?>"><?=number_format($rem,1)?> <span style="font-weight:400;color:var(--text-mid);font-size:11px">/ <?=number_format($tot,1)?></span></div>
            <div style="height:3px;background:var(--border);border-radius:2px;margin-top:4px;width:60px;margin-inline:auto">
              <div style="height:3px;background:<?=$col?>;border-radius:2px;width:<?=$pct?>%"></div>
            </div>
          </td>
          <?php endforeach ?>
          <?php
            $annualInfo = annualLeaveMetrics($db, (int)$emp['id'], $year);
            $reserveCurrent = (float)$annualInfo['reserve_accumulated'];
            $reserveTotal = max(0.1, (float)$annualInfo['projected_reserve']);
            $reservePct = min(100, round($reserveCurrent / $reserveTotal * 100));
            $reserveCol = $reserveCurrent <= 0 ? 'var(--text-light)' : 'var(--green)';
          ?>
          <td style="text-align:center;background:#f8fafc">
            <div style="font-size:13px;font-weight:700;color:<?=$reserveCol?>"><?=number_format($reserveCurrent,1)?> <span style="font-weight:400;color:var(--text-mid);font-size:11px">/ <?=number_format($annualInfo['projected_reserve'],1)?></span></div>
            <div style="height:3px;background:var(--border);border-radius:2px;margin-top:4px;width:60px;margin-inline:auto">
              <div style="height:3px;background:<?=$reserveCol?>;border-radius:2px;width:<?=$reservePct?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      </div>
      <?php endif ?>
    </div>

    <!-- All Leave History -->
    <div class="card" data-leave-history>
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-list" style="color:var(--blue)"></i> Leave History</div></div>
      <?php if (empty($all)): ?>
        <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><div>No leave requests yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employee</th><th>Leave Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Back-Capture</th><th style="width:120px">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($all as $r):
          $sc = $r['status']==='approved' ? 'badge-green' : ($r['status']==='rejected' ? 'badge-red' : 'badge-amber');
        ?>
        <tr>
          <td><?=htmlspecialchars($r['emp_name'])?></td>
          <td><?=htmlspecialchars($r['leave_type'])?></td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($r['start_date']))?> – <?=date('d M Y',strtotime($r['end_date']))?></td>
          <td><?=$r['days']?></td>
          <td>
            <div class="leave-status-cell">
              <span class="badge <?=$sc?>"><?=ucfirst($r['status'])?></span>
              <?php if($r['status']==='rejected' && $r['reject_reason']): ?>
              <button type="button" class="leave-reason-trigger" data-leave-reason-trigger
                data-reason="<?=htmlspecialchars($r['reject_reason'], ENT_QUOTES, 'UTF-8')?>"
                data-decision-date="<?=htmlspecialchars($r['approved_at'] ? date('d F Y \a\t H:i', strtotime($r['approved_at'])) : 'Not recorded', ENT_QUOTES, 'UTF-8')?>"
                data-reviewed-by="<?=htmlspecialchars($r['reviewer_name'] ?: 'HR Administration', ENT_QUOTES, 'UTF-8')?>"
                aria-expanded="false" aria-haspopup="dialog" aria-controls="leave-reason-popover">
                <span>View reason</span>
                <svg class="leave-reason-trigger__arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7.5L10 12l4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <?php endif ?>
            </div>
          </td>
          <td><?=$r['back_capture'] ? '<span class="badge badge-gray">Back-Captured</span>' : '—'?></td>
          <td style="white-space:nowrap">
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this <?=strtolower($r['status'])?> leave request? This cannot be undone.')">
              <input type="hidden" name="action" value="delete_leave">
              <input type="hidden" name="delete_id" value="<?=$r['id']?>">
              <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                <i class="fa-solid fa-trash"></i> Delete
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
  </div>
</div>

<!-- REJECT MODAL -->
<div class="overlay" id="rejectModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><div class="modal-title">Reject Leave Request</div><button class="modal-close" onclick="var m=document.getElementById('rejectModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="request_id" id="rejectId">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Reason for Rejection (optional)</label>
          <textarea class="form-textarea" name="reject_reason" placeholder="Let the employee know why..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="var m=document.getElementById('rejectModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-xmark"></i> Confirm Reject</button>
      </div>
    </form>
  </div>
</div>

<!-- BACK CAPTURE MODAL -->
<div class="overlay" id="backCaptureModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-clock-rotate-left"></i> Back-Capture Past Leave</div><button class="modal-close" onclick="var m=document.getElementById('backCaptureModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="back_capture">
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-mid);margin-bottom:18px">Record leave that was already taken before the system was set up. This will be saved as Approved and deducted from the employee's balance.</p>
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="bc_employee" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">Leave Type</label>
            <select class="form-select" name="bc_leave_type" required>
              <?php foreach ($leaveTypes as $lt): ?><option value="<?=htmlspecialchars($lt)?>"><?=htmlspecialchars($lt)?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Start Date</label><input class="form-input" type="date" name="bc_start_date" required></div>
          <div class="form-group"><label class="form-label">End Date</label><input class="form-input" type="date" name="bc_end_date" required></div>
          <div class="form-group full"><label class="form-label">Number of Days</label><input class="form-input" type="number" step="0.5" min="0.5" name="bc_days" placeholder="e.g. 3" required></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="var m=document.getElementById('backCaptureModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Past Leave</button>
      </div>
    </form>
  </div>
</div>

<!-- ADJUST BALANCE MODAL -->
<div class="overlay" id="adjustModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-sliders"></i> Adjust Leave Balance</div><button class="modal-close" onclick="var m=document.getElementById('adjustModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="adjust_balance">
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-mid);margin-bottom:18px">Manually set a leave balance for an employee. Use this to correct balances or add carried-over days.</p>
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="adj_employee" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">Leave Type</label>
            <select class="form-select" name="adj_leave_type" required>
              <?php foreach ($leaveTypes as $lt): ?><option value="<?=htmlspecialchars($lt)?>"><?=htmlspecialchars($lt)?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">New Balance (days)</label><input class="form-input" type="number" step="0.5" name="adj_balance" required placeholder="e.g. 15"></div>
          <div class="form-group"><label class="form-label">Year</label><input class="form-input" type="number" name="adj_year" value="<?=date('Y')?>" required></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="var m=document.getElementById('adjustModal');if(m){m.style.display='none';m.classList.remove('open');}void(0)">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Adjustment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openReject(id) { document.getElementById('rejectId').value = id; var m=document.getElementById('rejectModal'); if(m){m.style.display='flex';m.classList.add('open');} }
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target===o) o.classList.remove('open'); });
});
</script>
<script src="includes/leave-reason-popover.js"></script>
</body>
</html>
