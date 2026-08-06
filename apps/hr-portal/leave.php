<?php
require_once __DIR__ . '/config.php';
requireAdmin();
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/leave-reserve.php';
require_once __DIR__ . '/includes/leave-balance-service.php';
$user = currentUser();
$db   = db();
ensureLeaveShutdownSchema($db);
$leaveCsrfToken = $_SESSION['leave_csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['leave_csrf_token'] = $leaveCsrfToken;

// ── Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['approve', 'reject', 'delete_leave', 'back_capture', 'adjust_balance'], true) && hrLeaveRecoveryLocked($db)) {
        $wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($wantsJson) {
            jsonResponse(['success' => false, 'message' => 'This leave-balance action is temporarily unavailable while the HR records are being verified.'], 423);
        }
        header('Location: leave.php?msg=recovery_locked');
        exit;
    }

    if (in_array($action, ['approve', 'delete_leave', 'back_capture', 'adjust_balance'], true)) {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($leaveCsrfToken, $csrf)) {
            header('Location: leave.php?msg=session_expired');
            exit;
        }
    }


    if ($action === 'delete_leave') {
        $did = (int)($_POST['delete_id'] ?? 0);
        if ($did) {
            try {
                $db->beginTransaction();
                $lr = $db->prepare("SELECT id,employee_id,leave_type,status FROM leave_requests WHERE id=? FOR UPDATE");
                $lr->execute([$did]);
                $lr = $lr->fetch();
                if (!$lr) {
                    $db->rollBack();
                    header('Location: leave.php?msg=leave_not_found');
                    exit;
                }
                if ($lr['status'] === 'approved') {
                    $db->rollBack();
                    header('Location: leave.php?msg=approved_delete_blocked');
                    exit;
                }
                $db->prepare("DELETE FROM leave_requests WHERE id=? AND status<>'approved'")->execute([$did]);
                $db->prepare("INSERT INTO audit_log (user_id,action,description,ip_address) VALUES (?,'leave_deleted',?,?)")
                   ->execute([(int)$user['id'], 'Non-approved leave request #' . $did . ' deleted.', (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
                header('Location: leave.php?msg=leave_deleted');
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Leave deletion failed: ' . $e->getMessage());
                header('Location: leave.php?msg=leave_delete_failed');
                exit;
            }
        }
    }

    // Reject through the existing leave decision path, with a mandatory employee-visible reason.
    if ($action === 'reject') {
        $wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        $respond = static function (array $payload, int $status = 200) use ($wantsJson): void {
            if ($wantsJson) {
                jsonResponse($payload, $status);
            }
            if (!empty($payload['success'])) {
                header('Location: leave.php?msg=rejected');
            } else {
                header('Location: leave.php?msg=reject_error&error=' . rawurlencode((string)($payload['message'] ?? 'Could not reject leave request.')));
            }
            exit;
        };

        $id = (int)($_POST['request_id'] ?? 0);
        $reason = trim(strip_tags((string)($_POST['reject_reason'] ?? '')));
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        $csrf = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($leaveCsrfToken, $csrf)) {
            $respond(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
        }
        if ($id < 1) {
            $respond(['success' => false, 'message' => 'The leave request could not be found.'], 404);
        }
        if ($reason === '') {
            $respond(['success' => false, 'message' => 'Please enter a reason for rejecting this leave request.'], 422);
        }
        if ($reasonLength > 1000) {
            $respond(['success' => false, 'message' => 'The rejection reason may not exceed 1,000 characters.'], 422);
        }

        try {
            $db->beginTransaction();
            $reqStmt = $db->prepare("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=? FOR UPDATE");
            $reqStmt->execute([$id]);
            $req = $reqStmt->fetch();
            if (!$req) {
                $db->rollBack();
                $respond(['success' => false, 'message' => 'The leave request could not be found.'], 404);
            }
            if ($req['status'] !== 'pending') {
                $db->rollBack();
                $respond(['success' => false, 'message' => 'This leave request has already been reviewed.'], 409);
            }
            if ((int)$req['employee_id'] === (int)($user['emp_id'] ?? 0) && !empty($user['emp_id'])) {
                $db->rollBack();
                $respond(['success' => false, 'message' => 'You cannot reject your own leave request.'], 403);
            }

            $update = $db->prepare("UPDATE leave_requests SET status='rejected', approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=? AND status='pending'");
            $update->execute([(int)$user['id'], $reason, $id]);
            if ($update->rowCount() !== 1) {
                $db->rollBack();
                $respond(['success' => false, 'message' => 'This leave request has already been reviewed.'], 409);
            }

            $empContact = $db->prepare("SELECT u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1");
            $empContact->execute([$req['employee_id']]);
            $empContact = $empContact->fetch();
            if ($empContact && $empContact['user_id']) {
                $dates = date('d M Y', strtotime($req['start_date'])) . ' - ' . date('d M Y', strtotime($req['end_date']));
                $db->prepare("INSERT INTO notifications (user_id,title,message,type,action_url) VALUES (?,?,?,'error',?)")
                   ->execute([(int)$empContact['user_id'], 'Leave request rejected', 'Your ' . $req['leave_type'] . ' request for ' . $dates . ' was rejected. View the reason.', 'my-leave.php?leave_request=' . $id . '#leave-request-' . $id]);
            }

            $auditDescription = 'Leave request #' . $id . ' rejected for ' . $req['employee_name'] . '. Reason: ' . $reason;
            $db->prepare("INSERT INTO audit_log (user_id,action,description,ip_address) VALUES (?,'leave_rejected',?,?)")
               ->execute([(int)$user['id'], $auditDescription, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
            $db->commit();

            if ($empContact) {
                $toEmail = trim((string)($empContact['user_email'] ?: $empContact['employee_email']));
                $toName = $empContact['user_name'] ?: $empContact['employee_name'];
                if ($toEmail !== '') {
                    emailLeaveRejected($toEmail, $toName, $req['leave_type'], $reason);
                }
            }

            $respond([
                'success' => true,
                'message' => 'Leave request rejected and the employee has been notified.',
                'leave_request' => [
                    'id' => $id,
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'rejected_at' => date(DATE_ATOM),
                    'rejected_by' => ['id' => (int)$user['id'], 'name' => 'HR Administration'],
                ],
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Leave rejection failed: ' . $e->getMessage());
            $respond(['success' => false, 'message' => 'Could not reject the leave request. Please try again.'], 500);
        }
    }

    // Approve once, atomically, and synchronize usage from authoritative requests.
    if ($action === 'approve') {
        $id = (int)($_POST['request_id'] ?? 0);
        if ($id < 1) {
            header('Location: leave.php?msg=leave_not_found');
            exit;
        }
        $empContact = null;
        try {
            $db->beginTransaction();
            $reqStmt = $db->prepare("SELECT * FROM leave_requests WHERE id=? FOR UPDATE");
            $reqStmt->execute([$id]);
            $req = $reqStmt->fetch();
            if (!$req) {
                $db->rollBack();
                header('Location: leave.php?msg=leave_not_found');
                exit;
            }
            if ($req['status'] !== 'pending') {
                $db->rollBack();
                header('Location: leave.php?msg=already_reviewed');
                exit;
            }

            $update = $db->prepare("UPDATE leave_requests SET status='approved',approved_by=?,approved_at=NOW(),reject_reason='' WHERE id=? AND status='pending'");
            $update->execute([(int)$user['id'], $id]);
            if ($update->rowCount() !== 1) {
                $db->rollBack();
                header('Location: leave.php?msg=already_reviewed');
                exit;
            }

            $year = (int)date('Y', strtotime($req['start_date']));
            $used = hrRefreshUsedLeave($db, (int)$req['employee_id'], (string)$req['leave_type'], $year);
            $empContactStmt = $db->prepare("SELECT u.id AS user_id,u.email AS user_email,u.name AS user_name,e.email AS employee_email,CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1");
            $empContactStmt->execute([$req['employee_id']]);
            $empContact = $empContactStmt->fetch();
            if ($empContact && $empContact['user_id']) {
                $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'success')")
                   ->execute([$empContact['user_id'], 'Leave Approved', 'Your ' . $req['leave_type'] . ' request for ' . $req['days'] . ' day(s) has been approved.']);
            }
            $db->prepare("INSERT INTO audit_log (user_id,action,description,ip_address) VALUES (?,'leave_approved',?,?)")
               ->execute([(int)$user['id'], 'Leave request #' . $id . ' approved once; authoritative used total is ' . number_format($used, 1) . ' day(s).', (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Leave approval failed: ' . $e->getMessage());
            header('Location: leave.php?msg=approval_failed');
            exit;
        }

        if ($empContact) {
            $toEmail = trim((string)($empContact['user_email'] ?: $empContact['employee_email']));
            $toName = $empContact['user_name'] ?: $empContact['employee_name'];
            if ($toEmail !== '') {
                emailLeaveApproved($toEmail, $toName, $req['leave_type'], $req['days'], $req['start_date'], $req['end_date']);
            }
        }
        header('Location: leave.php?msg=approved'); exit;
    }

    // Back-capture
    if ($action === 'back_capture') {
        $emp_id     = (int)($_POST['bc_employee'] ?? 0);
        $leave_type = clean($_POST['bc_leave_type'] ?? '');
        $start      = $_POST['bc_start_date'] ?? '';
        $end        = $_POST['bc_end_date']   ?? '';
        $days       = (float)($_POST['bc_days'] ?? 0);
        if ($emp_id && $leave_type && $start && $end && $days > 0) {
            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO leave_requests (employee_id,leave_type,start_date,end_date,days,status,back_capture,approved_by,approved_at) VALUES (?,?,?,?,?,'approved',1,?,NOW())")
                   ->execute([$emp_id,$leave_type,$start,$end,$days,$user['id']]);
                $requestId = (int)$db->lastInsertId();
                $year = (int)date('Y',strtotime($start));
                $used = hrRefreshUsedLeave($db, $emp_id, $leave_type, $year);
                $db->prepare("INSERT INTO audit_log (user_id,action,description,ip_address) VALUES (?,'leave_back_captured',?,?)")
                   ->execute([(int)$user['id'], 'Leave request #' . $requestId . ' back-captured; authoritative used total is ' . number_format($used, 1) . ' day(s).', (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Leave back-capture failed: ' . $e->getMessage());
                header('Location: leave.php?msg=back_capture_failed');
                exit;
            }
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
            try {
                $db->beginTransaction();
                $before = $db->prepare("SELECT balance_days,used_days FROM leave_balances WHERE employee_id=? AND leave_type=? AND year=? FOR UPDATE");
                $before->execute([$emp_id,$leave_type,$year]);
                $before = $before->fetch();
                $used = hrApprovedLeaveUsed($db, $emp_id, $leave_type, $year);
                $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE balance_days=VALUES(balance_days),used_days=VALUES(used_days)")
                   ->execute([$emp_id,$leave_type,$new_bal,$used,$year]);
                $description = sprintf(
                    'Leave entitlement adjusted for employee %d, %s, %d: balance %.1f to %.1f; authoritative used %.1f.',
                    $emp_id,
                    $leave_type,
                    $year,
                    $before ? (float)$before['balance_days'] : 0.0,
                    $new_bal,
                    $used
                );
                $db->prepare("INSERT INTO audit_log (user_id,action,description,ip_address) VALUES (?,'leave_balance_adjusted',?,?)")
                   ->execute([(int)$user['id'], $description, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Leave balance adjustment failed: ' . $e->getMessage());
                header('Location: leave.php?msg=adjustment_failed');
                exit;
            }
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Leave Management — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css?v=20260729-1">
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
    <?php elseif ($msg === 'leave_deleted'): ?><div class="toast no-print"><i class="fa-solid fa-trash"></i> Non-approved leave request deleted.</div>
    <?php elseif ($msg === 'approved_delete_blocked'): ?><div class="toast no-print error"><i class="fa-solid fa-lock"></i> Approved leave is protected and cannot be deleted. Use an audited reversal workflow.</div>
    <?php elseif ($msg === 'recovery_locked'): ?><div class="toast no-print error"><i class="fa-solid fa-lock"></i> This leave-balance action is temporarily unavailable while the HR records are being verified.</div>
    <?php elseif ($msg === 'already_reviewed'): ?><div class="toast no-print error"><i class="fa-solid fa-circle-info"></i> This leave request has already been reviewed. No balance was changed.</div>
    <?php elseif ($msg === 'session_expired'): ?><div class="toast no-print error"><i class="fa-solid fa-shield-halved"></i> Your session expired. Refresh and try again.</div>
    <?php elseif (in_array($msg, ['approval_failed','leave_delete_failed','back_capture_failed','adjustment_failed'], true)): ?><div class="toast no-print error"><i class="fa-solid fa-triangle-exclamation"></i> The leave action failed. No partial balance change was saved.</div>
    <?php elseif ($msg === 'rejected'): ?><div class="toast"><i class="fa-solid fa-check"></i> Leave request rejected and the employee has been notified.</div>
    <?php elseif ($msg === 'reject_error'): ?><div class="toast error"><i class="fa-solid fa-xmark"></i> <?=htmlspecialchars((string)($_GET['error'] ?? 'Could not reject the leave request.'), ENT_QUOTES, 'UTF-8')?></div>
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
              <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>">
              <button class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i> Approve</button>
            </form>
            <button
              type="button"
              class="btn btn-danger btn-sm js-reject-leave"
              data-request-id="<?=$r['id']?>"
              data-employee="<?=htmlspecialchars($r['emp_name'], ENT_QUOTES, 'UTF-8')?>"
              data-leave-type="<?=htmlspecialchars($r['leave_type'], ENT_QUOTES, 'UTF-8')?>"
              data-dates="<?=date('d M Y',strtotime($r['start_date']))?> - <?=date('d M Y',strtotime($r['end_date']))?>"
              data-days="<?=number_format((float)$r['days'],1)?>"
            ><i class="fa-solid fa-xmark"></i> Reject</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this pending leave request?')"><input type="hidden" name="action" value="delete_leave"><input type="hidden" name="delete_id" value="<?=$r['id']?>"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>"><button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
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
          <td><?=$r['back_capture'] ? '<span class="badge badge-gray">Back-Captured</span>' : '—'?></td>
          <td style="white-space:nowrap">
            <?php if($r['status']!=='approved'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this <?=strtolower($r['status'])?> leave request? This cannot be undone.')">
              <input type="hidden" name="action" value="delete_leave">
              <input type="hidden" name="delete_id" value="<?=$r['id']?>">
              <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>">
              <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                <i class="fa-solid fa-trash"></i> Delete
              </button>
            </form>
            <?php else: ?><span style="font-size:11px;color:var(--text-mid)"><i class="fa-solid fa-lock"></i> Approved record protected</span><?php endif ?>
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
<div class="overlay leave-rejection-overlay" id="rejectModal" role="dialog" aria-modal="true" aria-labelledby="reject-leave-title" hidden>
  <div class="modal leave-rejection-modal">
    <div class="modal-header">
      <div><div class="leave-rejection-eyebrow">Leave management</div><div class="modal-title" id="reject-leave-title">Reject Leave Request</div></div>
      <button type="button" class="modal-close" data-close-rejection-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="rejectLeaveForm" novalidate>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="request_id" id="rejectId">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>">
      <div class="modal-body">
        <div class="leave-rejection-summary">
          <div><span>Employee</span><strong data-rejection-employee></strong></div>
          <div><span>Leave type</span><strong data-rejection-type></strong></div>
          <div><span>Dates</span><strong data-rejection-dates></strong></div>
          <div><span>Days requested</span><strong data-rejection-days></strong></div>
        </div>
        <div class="form-group">
          <label class="form-label" for="leaveRejectionReason">Reason for rejection <span aria-hidden="true">*</span></label>
          <textarea class="form-textarea" id="leaveRejectionReason" name="reject_reason" rows="5" maxlength="1000" required placeholder="Explain why this leave request is being rejected."></textarea>
          <div class="leave-rejection-field-footer">
            <span class="leave-rejection-error" data-rejection-error role="alert"></span>
            <span class="leave-rejection-counter"><span data-rejection-character-count>0</span>/1000</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-rejection-modal>Cancel</button>
        <button type="submit" class="btn btn-danger" data-confirm-leave-rejection><i class="fa-solid fa-xmark"></i> Reject Leave</button>
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
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>">
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
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($leaveCsrfToken, ENT_QUOTES, 'UTF-8')?>">
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
const rejectModal = document.getElementById('rejectModal');
const rejectForm = document.getElementById('rejectLeaveForm');
const rejectReason = document.getElementById('leaveRejectionReason');
let rejectingLeave = false;

function setRejectModalOpen(open, trigger) {
  if (!rejectModal || (rejectingLeave && !open)) return;
  rejectModal.hidden = !open;
  rejectModal.classList.toggle('open', open);
  if (open && trigger) {
    document.getElementById('rejectId').value = trigger.dataset.requestId || '';
    rejectModal.querySelector('[data-rejection-employee]').textContent = trigger.dataset.employee || '—';
    rejectModal.querySelector('[data-rejection-type]').textContent = trigger.dataset.leaveType || '—';
    rejectModal.querySelector('[data-rejection-dates]').textContent = trigger.dataset.dates || '—';
    rejectModal.querySelector('[data-rejection-days]').textContent = trigger.dataset.days || '—';
    rejectReason.value = '';
    rejectReason.setAttribute('aria-invalid', 'false');
    rejectModal.querySelector('[data-rejection-error]').textContent = '';
    rejectModal.querySelector('[data-rejection-character-count]').textContent = '0';
    requestAnimationFrame(() => rejectReason.focus());
  }
}

document.querySelectorAll('.js-reject-leave').forEach(button => button.addEventListener('click', () => setRejectModalOpen(true, button)));
document.querySelectorAll('[data-close-rejection-modal]').forEach(button => button.addEventListener('click', () => setRejectModalOpen(false)));
rejectReason?.addEventListener('input', () => {
  rejectModal.querySelector('[data-rejection-character-count]').textContent = String(rejectReason.value.length);
  if (rejectReason.value.trim()) {
    rejectReason.setAttribute('aria-invalid', 'false');
    rejectModal.querySelector('[data-rejection-error]').textContent = '';
  }
});
rejectForm?.addEventListener('submit', async event => {
  event.preventDefault();
  if (rejectingLeave) return;
  const reason = rejectReason.value.trim();
  const error = rejectModal.querySelector('[data-rejection-error]');
  if (!reason) {
    rejectReason.setAttribute('aria-invalid', 'true');
    error.textContent = 'Please enter a reason for rejecting this leave request.';
    rejectReason.focus();
    return;
  }
  rejectingLeave = true;
  const submit = rejectForm.querySelector('[data-confirm-leave-rejection]');
  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');
  const originalHtml = submit.innerHTML;
  submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting…';
  try {
    const response = await fetch('leave.php', {method:'POST', body:new FormData(rejectForm), headers:{Accept:'application/json'}});
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not reject the leave request.');
    setRejectModalOpen(false);
    window.location.assign('leave.php?msg=rejected');
  } catch (requestError) {
    error.textContent = requestError.message || 'Could not reject the leave request. Please try again.';
  } finally {
    rejectingLeave = false;
    submit.disabled = false;
    submit.removeAttribute('aria-busy');
    submit.innerHTML = originalHtml;
  }
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && rejectModal?.classList.contains('open')) setRejectModalOpen(false);
});
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target===o && o !== rejectModal) o.classList.remove('open'); });
});
rejectModal?.addEventListener('click', event => { if (event.target === rejectModal) setRejectModalOpen(false); });
</script>
<script src="includes/leave-reason-popover.js?v=20260729-1"></script>
</body>
</html>
