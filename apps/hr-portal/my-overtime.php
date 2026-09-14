<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db    = db();
$empId = (int)($user['emp_id'] ?? 0);
$emp   = $db->prepare("SELECT * FROM employees WHERE id=?");
$emp->execute([$empId]);
$emp = $emp->fetch();
$submitError = '';

function overtimeJsonResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function overtimeIsAjax(): bool {
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ot_date    = $_POST['ot_date']     ?? '';
    $start_time = $_POST['ot_start']    ?? '';
    $end_time   = $_POST['ot_end']      ?? '';
    $day_type   = $_POST['ot_day_type'] ?? 'weekday';
    $notes      = clean($_POST['ot_notes'] ?? '');

    try {
        if (!$empId || !$emp || ($emp['status'] ?? '') !== 'active') {
            throw new RuntimeException('Your employee account is not linked to an active HR employee record. Please contact the owner.');
        }
        if (!$ot_date) {
            throw new RuntimeException('Overtime date is required.');
        }
        if (!$start_time) {
            throw new RuntimeException('Start time is required.');
        }
        if (!$end_time) {
            throw new RuntimeException('End time is required.');
        }
        if (!in_array($day_type, ['weekday', 'saturday', 'sunday', 'public_holiday'], true)) {
            throw new RuntimeException('Select a valid overtime day type.');
        }

        $s = strtotime($ot_date . ' ' . $start_time);
        $e = strtotime($ot_date . ' ' . $end_time);
        if ($s === false || $e === false) {
            throw new RuntimeException('Enter a valid overtime date and time range.');
        }
        if ($e <= $s) { $e += 86400; }
        $hours  = round(($e - $s) / 3600, 2);
        if ($hours <= 0 || $hours > 24) {
            throw new RuntimeException('Overtime must be greater than 0 hours and no more than 24 hours.');
        }
        $rate   = ($day_type === 'sunday' || $day_type === 'public_holiday') ? 2.0 : 1.5;
        $hourly = $emp ? (float)$emp['hourly_rate'] : 0;
        $amount = round($hours * $rate * $hourly, 2);

        $db->beginTransaction();
        $duplicate = $db->prepare("SELECT id FROM overtime WHERE employee_id=? AND ot_date=? AND start_time=? AND end_time=? AND status IN ('pending','approved') LIMIT 1 FOR UPDATE");
        $duplicate->execute([$empId, $ot_date, $start_time, $end_time]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('This overtime request has already been submitted.');
        }

        $insert = $db->prepare("INSERT INTO overtime (employee_id,ot_date,start_time,end_time,hours,day_type,rate,hourly_rate,amount,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
        $insert->execute([$empId,$ot_date,$start_time,$end_time,$hours,$day_type,$rate,$hourly,$amount,$notes]);
        $overtimeId = (int)$db->lastInsertId();
        $db->commit();

        try {
            $adminUsers = $db->query("SELECT id FROM users WHERE role='admin' AND active=1")->fetchAll();
            foreach ($adminUsers as $admin) {
                $exists = $db->prepare("SELECT id FROM notifications WHERE user_id=? AND title='New Overtime Logged' AND message LIKE ? LIMIT 1");
                $notificationNeedle = '%' . $user['name'] . '%overtime on ' . date('d M Y', strtotime($ot_date)) . '%';
                $exists->execute([$admin['id'], $notificationNeedle]);
                if (!$exists->fetchColumn()) {
                    $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                       ->execute([$admin['id'], 'New Overtime Logged', $user['name'] . ' logged ' . $hours . ' hours overtime on ' . date('d M Y', strtotime($ot_date)) . '.']);
                }
            }
            emailHRNotice(
                'New Overtime Logged - ' . $user['name'],
                '<p>' . htmlspecialchars($user['name']) . ' logged overtime for approval.</p>
                <div class="highlight">
                  Date: ' . date('d F Y', strtotime($ot_date)) . '<br>
                  Hours: ' . number_format($hours,2) . 'h<br>
                  Amount: N$ ' . number_format((float)$amount,2) . '
                </div>
                <a href="' . SITE_URL . '/overtime.php" class="btn">Review Overtime</a>'
            );
        } catch (Throwable $notificationError) {
            // The durable overtime record is authoritative; notification failure must not undo it.
        }

        if (overtimeIsAjax()) {
            overtimeJsonResponse([
                'ok' => true,
                'message' => 'Overtime request submitted successfully.',
                'overtime_id' => $overtimeId,
                'status' => 'pending',
            ]);
        }
        header('Location: my-overtime.php?msg=submitted');
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $submitError = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The overtime request could not be saved. Please try again or contact the owner.';
        if (overtimeIsAjax()) {
            overtimeJsonResponse(['ok' => false, 'error' => $submitError], 422);
        }
    }
}

$history = $db->prepare("SELECT * FROM overtime WHERE employee_id=? ORDER BY ot_date DESC");
$history->execute([$empId]);
$history = $history->fetchAll();

$currentPage = 'my-overtime.php';
$publicHolidays = array(
    date('Y').'-01-01',
    date('Y').'-03-21',
    date('Y').'-05-01',
    date('Y').'-05-04',
    date('Y').'-05-25',
    date('Y').'-08-26',
    date('Y').'-09-10',
    date('Y').'-12-10',
    date('Y').'-12-25',
    date('Y').'-12-26'
);
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My Overtime - Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/emp-sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">My Overtime</div>
    <button class="btn btn-primary" onclick="document.getElementById('otModal').classList.add('open')">
      <i class="fa-solid fa-plus"></i> Log Overtime
    </button>
  </div>
  <div class="content">

    <?php if ($msg === 'submitted'): ?>
    <div class="toast"><i class="fa-solid fa-check"></i> Overtime request submitted successfully.</div>
    <?php endif ?>
    <?php if ($submitError !== ''): ?>
    <div class="toast error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($submitError); ?></div>
    <?php endif ?>
    <div id="overtimeSubmitMessage" aria-live="polite"></div>

    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-regular fa-clock"></i> My Overtime History</div>
      </div>
      <?php if (empty($history)): ?>
      <div class="empty-state">
        <i class="fa-regular fa-clock"></i>
        <div>No overtime logged yet.</div>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Hours</th>
            <th>Type</th>
            <th>Rate</th>
            <th>Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $r):
          $sc = 'badge-amber';
          if ($r['status'] === 'approved') { $sc = 'badge-green'; }
          if ($r['status'] === 'rejected') { $sc = 'badge-red'; }
          $dt = $r['day_type'];
          if ($dt === 'public_holiday') { $tl = 'Public Holiday'; }
          elseif ($dt === 'sunday') { $tl = 'Sunday'; }
          elseif ($dt === 'saturday') { $tl = 'Saturday'; }
          else { $tl = 'Weekday'; }
          $badgeCol = ($dt === 'public_holiday' || $dt === 'sunday') ? 'badge-red' : 'badge-amber';
        ?>
        <tr>
          <td><?php echo date('d M Y', strtotime($r['ot_date'])); ?></td>
          <td style="font-size:12px;color:var(--text-mid)">
            <?php echo substr($r['start_time'],0,5); ?> &ndash; <?php echo substr($r['end_time'],0,5); ?>
          </td>
          <td><strong><?php echo $r['hours']; ?>h</strong></td>
          <td><span class="badge <?php echo $badgeCol; ?>"><?php echo $tl; ?></span></td>
          <td><?php echo $r['rate']; ?>&times;</td>
          <td style="font-family:monospace">N$ <?php echo number_format((float)$r['amount'],2); ?></td>
          <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($r['status']); ?></span></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>

  </div>
</div>

<!-- LOG OT MODAL -->
<div class="overlay" id="otModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-clock"></i> Log Overtime</div>
      <button class="modal-close" onclick="document.getElementById('otModal').classList.remove('open')">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <form method="POST" id="overtimeRequestForm">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Date</label>
            <input class="form-input" type="date" name="ot_date" id="otDate" required onchange="checkHoliday()">
          </div>
          <div class="form-group">
            <label class="form-label">Day Type</label>
            <select class="form-select" name="ot_day_type" id="otDayType" onchange="calcOT()">
              <option value="weekday">Weekday / Saturday (1.5x)</option>
              <option value="sunday">Sunday (2x)</option>
              <option value="public_holiday">Public Holiday (2x)</option>
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
        <div id="otCalc" style="display:none;margin-top:14px;background:#f0f9f4;border:1px solid var(--green-mid);border-radius:8px;padding:14px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-mid);margin-bottom:6px">Estimated OT Pay</div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:12px;color:var(--text-mid)" id="otBreakdown">-</div>
            <div style="font-size:20px;font-weight:800;color:var(--green);font-family:monospace" id="otAmount">N$ 0.00</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('otModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="overtimeSubmitButton"><i class="fa-solid fa-paper-plane"></i> Submit for Approval</button>
      </div>
    </form>
  </div>
</div>

<script>
var publicHolidays = <?php echo json_encode($publicHolidays); ?>;
var hourlyRate = <?php echo $emp ? (float)$emp['hourly_rate'] : 0; ?>;
var overtimeForm = document.getElementById('overtimeRequestForm');
var overtimeSubmitButton = document.getElementById('overtimeSubmitButton');
var overtimeSubmitMessage = document.getElementById('overtimeSubmitMessage');

if (overtimeForm && window.fetch && window.FormData) {
    overtimeForm.addEventListener('submit', function(event) {
        event.preventDefault();
        if (overtimeSubmitButton.disabled) { return; }
        overtimeSubmitButton.disabled = true;
        overtimeSubmitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
        overtimeSubmitMessage.innerHTML = '';

        fetch('my-overtime.php', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new FormData(overtimeForm),
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json().then(function(payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'The overtime request could not be saved.');
                }
                return payload;
            });
        }).then(function() {
            window.location.href = 'my-overtime.php?msg=submitted';
        }).catch(function(error) {
            overtimeSubmitMessage.innerHTML = '<div class="toast error"><i class="fa-solid fa-triangle-exclamation"></i> ' +
                String(error.message || error).replace(/[&<>"']/g, function(character) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
                }) + '</div>';
            document.getElementById('otModal').classList.add('open');
            overtimeSubmitButton.disabled = false;
            overtimeSubmitButton.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Approval';
        });
    });
}

document.querySelectorAll('.overlay').forEach(function(o) {
    o.addEventListener('click', function(e) {
        if (e.target === o) { o.classList.remove('open'); }
    });
});

function checkHoliday() {
    var date = document.getElementById('otDate').value;
    var sel  = document.getElementById('otDayType');
    if (!date) { return; }
    var d = new Date(date);
    if (publicHolidays.indexOf(date) !== -1) {
        sel.value = 'public_holiday';
    } else if (d.getDay() === 0) {
        sel.value = 'sunday';
    } else {
        sel.value = 'weekday';
    }
    calcOT();
}

function calcOT() {
    var start = document.getElementById('otStart').value;
    var end   = document.getElementById('otEnd').value;
    var dt    = document.getElementById('otDayType').value;
    var calc  = document.getElementById('otCalc');
    if (!start || !end) { calc.style.display = 'none'; return; }
    var rate = (dt === 'sunday' || dt === 'public_holiday') ? 2.0 : 1.5;
    var sh = parseInt(start.split(':')[0]);
    var sm = parseInt(start.split(':')[1]);
    var eh = parseInt(end.split(':')[0]);
    var em = parseInt(end.split(':')[1]);
    var hours = (eh * 60 + em - sh * 60 - sm) / 60;
    if (hours <= 0) { hours += 24; }
    hours = Math.round(hours * 100) / 100;
    var amount = Math.round(hours * rate * hourlyRate * 100) / 100;
    document.getElementById('otBreakdown').textContent = 'N$' + hourlyRate.toFixed(2) + '/hr x ' + hours + 'h x ' + rate;
    document.getElementById('otAmount').textContent = 'N$ ' + amount.toFixed(2);
    calc.style.display = 'block';
}
</script>
</body>
</html>
