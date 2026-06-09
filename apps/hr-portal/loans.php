<?php
require_once __DIR__ . '/config.php';
requireAdmin();
$user = currentUser();
$db   = db();

$db->exec("CREATE TABLE IF NOT EXISTS loans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  balance DECIMAL(10,2) NOT NULL,
  repayment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  repayment_method ENUM('salary_deduction','cash','other') DEFAULT 'salary_deduction',
  loan_date DATE NOT NULL,
  notes TEXT,
  status ENUM('active','settled') DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS loan_repayments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_loan') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $repay  = (float)($_POST['repayment_amount'] ?? 0);
        $method = $_POST['repayment_method'] ?? 'salary_deduction';
        $date   = $_POST['loan_date'] ?? date('Y-m-d');
        $notes  = clean($_POST['notes'] ?? '');
        if ($emp_id && $amount > 0) {
            $db->prepare("INSERT INTO loans (employee_id,amount,balance,repayment_amount,repayment_method,loan_date,notes,status,created_by) VALUES (?,?,?,?,?,?,?,'active',?)")
               ->execute([$emp_id,$amount,$amount,$repay,$method,$date,$notes,$user['id']]);
            $eu = $db->prepare("SELECT u.id, e.first_name FROM users u JOIN employees e ON e.id=u.employee_id WHERE u.employee_id=?");
            $eu->execute([$emp_id]); $eu = $eu->fetch();
            if ($eu) {
                $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                   ->execute([$eu['id'],'Loan / Advance Recorded','A loan/advance of N$'.number_format($amount,2).' has been recorded. Monthly deduction: N$'.number_format($repay,2).'.']);
            }
        }
        header('Location: loans.php?msg=added'); exit;
    }

    if ($action === 'add_repayment') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $amount  = (float)($_POST['repay_amount'] ?? 0);
        $notes   = clean($_POST['repay_notes'] ?? '');
        if ($loan_id && $amount > 0) {
            $db->prepare("INSERT INTO loan_repayments (loan_id,amount,notes) VALUES (?,?,?)")->execute([$loan_id,$amount,$notes]);
            $db->prepare("UPDATE loans SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount,$loan_id]);
            $loan = $db->prepare("SELECT balance FROM loans WHERE id=?"); $loan->execute([$loan_id]); $loan = $loan->fetch();
            if ($loan && (float)$loan['balance'] <= 0) {
                $db->prepare("UPDATE loans SET status='settled',balance=0 WHERE id=?")->execute([$loan_id]);
            }
        }
        header('Location: loans.php?msg=repaid'); exit;
    }

    if ($action === 'settle_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        if ($loan_id) $db->prepare("UPDATE loans SET status='settled',balance=0 WHERE id=?")->execute([$loan_id]);
        header('Location: loans.php?msg=settled'); exit;
    }
}

$loans     = $db->query("SELECT l.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.emp_number FROM loans l JOIN employees e ON e.id=l.employee_id ORDER BY l.status ASC, l.created_at DESC")->fetchAll();
$employees = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name, emp_number FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();
$totalActive = array_sum(array_column(array_filter($loans, function($l){ return $l['status']==='active'; }), 'balance'));
$totalLoans  = count(array_filter($loans, function($l){ return $l['status']==='active'; }));
$msg = $_GET['msg'] ?? '';
$currentPage = 'loans.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loans & Advances — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Loans &amp; Advances</div>
    <button class="btn btn-primary" onclick="openModal('addLoanModal')"><i class="fa-solid fa-plus"></i> Add Loan / Advance</button>
  </div>
  <div class="content">

    <?php if ($msg==='added'): ?><div class="toast"><i class="fa-solid fa-check"></i> Loan added successfully.</div>
    <?php elseif ($msg==='repaid'): ?><div class="toast"><i class="fa-solid fa-check"></i> Repayment recorded.</div>
    <?php elseif ($msg==='settled'): ?><div class="toast"><i class="fa-solid fa-check"></i> Loan marked as settled.</div>
    <?php endif ?>

    <div class="grid-3" style="margin-bottom:22px">
      <div class="stat-card"><div class="stat-icon red"><i class="fa-solid fa-hand-holding-dollar"></i></div><div class="stat-value"><?=$totalLoans?></div><div class="stat-label">Active Loans</div></div>
      <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-dollar-sign"></i></div><div class="stat-value" style="font-size:18px">N$<?=number_format($totalActive,2)?></div><div class="stat-label">Total Outstanding</div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div><div class="stat-value"><?=count(array_filter($loans,function($l){return $l['status']==='settled';}))?></div><div class="stat-label">Settled Loans</div></div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--amber)"></i> All Loans &amp; Advances</div></div>
      <?php if (empty($loans)): ?>
      <div class="empty-state"><i class="fa-solid fa-hand-holding-dollar"></i><div>No loans or advances recorded yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employee</th><th>Loan Amount</th><th>Balance</th><th>Monthly Deduction</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($loans as $l):
          $pct = $l['amount'] > 0 ? min(100, round((1 - $l['balance']/$l['amount'])*100)) : 100;
          $sc  = $l['status']==='settled' ? 'badge-green' : 'badge-amber';
        ?>
        <tr>
          <td><div style="font-weight:600"><?=htmlspecialchars($l['emp_name'])?></div><div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($l['emp_number'])?></div></td>
          <td style="font-family:monospace">N$ <?=number_format((float)$l['amount'],2)?></td>
          <td>
            <div style="font-family:monospace;font-weight:700;color:<?=$l['status']==='settled'?'var(--green)':'var(--red)'?>">N$ <?=number_format((float)$l['balance'],2)?></div>
            <div style="margin-top:4px;height:4px;background:var(--border);border-radius:2px"><div style="height:4px;background:var(--green);border-radius:2px;width:<?=$pct?>%"></div></div>
          </td>
          <td style="font-family:monospace">N$ <?=number_format((float)$l['repayment_amount'],2)?>/month</td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($l['loan_date']))?></td>
          <td><span class="badge <?=$sc?>"><?=ucfirst($l['status'])?></span></td>
          <td>
            <?php if ($l['status']==='active'): ?>
            <button class="btn btn-secondary btn-sm" onclick="openRepay(<?=$l['id']?>,'<?=htmlspecialchars($l['emp_name'])?>',<?=$l['balance']?>,<?=$l['repayment_amount']?>)"><i class="fa-solid fa-money-bill"></i> Repay</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Mark as settled?')">
              <input type="hidden" name="action" value="settle_loan">
              <input type="hidden" name="loan_id" value="<?=$l['id']?>">
              <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-check"></i> Settle</button>
            </form>
            <?php endif ?>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
  </div>
</div>

<!-- ADD LOAN MODAL -->
<div class="overlay" id="addLoanModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-hand-holding-dollar"></i> Add Loan / Advance</div><button class="modal-close" onclick="closeModal('addLoanModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_loan">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="employee_id" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?>
              <option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['emp_number'])?>)</option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Amount (N$)</label><input class="form-input" type="number" step="0.01" name="amount" required placeholder="0.00"></div>
          <div class="form-group"><label class="form-label">Date</label><input class="form-input" type="date" name="loan_date" value="<?=date('Y-m-d')?>" required></div>
          <div class="form-group"><label class="form-label">Monthly Repayment (N$)</label><input class="form-input" type="number" step="0.01" name="repayment_amount" placeholder="0.00" required></div>
          <div class="form-group">
            <label class="form-label">Repayment Method</label>
            <select class="form-select" name="repayment_method">
              <option value="salary_deduction">Salary Deduction</option>
              <option value="cash">Cash</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group full"><label class="form-label">Notes (optional)</label><textarea class="form-textarea" name="notes" placeholder="e.g. March advance..."></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addLoanModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Loan</button>
      </div>
    </form>
  </div>
</div>

<!-- REPAYMENT MODAL -->
<div class="overlay" id="repayModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-money-bill"></i> Record Repayment — <span id="repayName"></span></div><button class="modal-close" onclick="closeModal('repayModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_repayment">
      <input type="hidden" name="loan_id" id="repayLoanId">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Remaining Balance</label><div id="repayBalance" style="font-size:18px;font-weight:700;color:var(--red);font-family:monospace;padding:8px 0"></div></div>
          <div class="form-group full"><label class="form-label">Repayment Amount (N$)</label><input class="form-input" type="number" step="0.01" name="repay_amount" id="repayAmount" required></div>
          <div class="form-group full"><label class="form-label">Notes (optional)</label><input class="form-input" name="repay_notes" placeholder="e.g. April salary deduction"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('repayModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Record Repayment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openRepay(id, name, balance, monthly) {
  document.getElementById('repayLoanId').value = id;
  document.getElementById('repayName').textContent = name;
  document.getElementById('repayBalance').textContent = 'N$ ' + parseFloat(balance).toFixed(2);
  document.getElementById('repayAmount').value = parseFloat(monthly).toFixed(2);
  openModal('repayModal');
}
document.querySelectorAll('.overlay').forEach(function(o) {
  o.addEventListener('click', function(e) { if(e.target===o) o.classList.remove('open'); });
});
</script>
</body>
</html>
