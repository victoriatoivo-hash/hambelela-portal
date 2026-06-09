<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db    = db();
$empId = (int)($user['emp_id'] ?? 0);

$loans = $db->prepare("SELECT l.*, (SELECT COALESCE(SUM(amount),0) FROM loan_repayments WHERE loan_id=l.id) as total_repaid FROM loans l WHERE l.employee_id=? ORDER BY l.status ASC, l.created_at DESC");
$loans->execute([$empId]);
$loans = $loans->fetchAll();

$currentPage = 'my-loans.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Loans — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/emp-sidebar.php'; ?>
<div class="main">
  <div class="topbar"><div class="topbar-title">My Loans &amp; Advances</div></div>
  <div class="content">

    <?php if (empty($loans)): ?>
    <div class="empty-state" style="margin-top:60px">
      <i class="fa-solid fa-hand-holding-dollar" style="font-size:40px;color:var(--green)"></i>
      <div style="font-size:16px;font-weight:600;margin-top:12px">No Loans or Advances</div>
      <div style="color:var(--text-mid);margin-top:4px">You have no active or past loans recorded.</div>
    </div>
    <?php else: ?>

    <?php foreach ($loans as $l):
      $pct    = $l['amount']>0 ? min(100,round((1-$l['balance']/$l['amount'])*100)) : 100;
      $sc     = $l['status']==='settled' ? 'badge-green' : 'badge-amber';
    ?>
    <div class="card" style="margin-bottom:16px">
      <div style="padding:20px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
          <div>
            <div style="font-size:16px;font-weight:700">Loan / Advance</div>
            <div style="font-size:12px;color:var(--text-mid);margin-top:2px"><?=date('d F Y',strtotime($l['loan_date']))?></div>
            <?php if ($l['notes']): ?><div style="font-size:12px;color:var(--text-mid);margin-top:2px"><?=htmlspecialchars($l['notes'])?></div><?php endif ?>
          </div>
          <span class="badge <?=$sc?>"><?=ucfirst($l['status'])?></span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px">
          <div style="text-align:center;padding:14px;background:var(--bg);border-radius:8px">
            <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--text-mid);margin-bottom:4px">Original Amount</div>
            <div style="font-size:20px;font-weight:800;font-family:monospace">N$ <?=number_format((float)$l['amount'],2)?></div>
          </div>
          <div style="text-align:center;padding:14px;background:var(--bg);border-radius:8px">
            <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--text-mid);margin-bottom:4px">Balance Remaining</div>
            <div style="font-size:20px;font-weight:800;font-family:monospace;color:<?=$l['status']==='settled'?'var(--green)':'var(--red)'?>">N$ <?=number_format((float)$l['balance'],2)?></div>
          </div>
          <div style="text-align:center;padding:14px;background:var(--bg);border-radius:8px">
            <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--text-mid);margin-bottom:4px">Monthly Deduction</div>
            <div style="font-size:20px;font-weight:800;font-family:monospace;color:var(--amber)">N$ <?=number_format((float)$l['repayment_amount'],2)?></div>
          </div>
        </div>

        <!-- Progress bar -->
        <div style="margin-bottom:8px;display:flex;justify-content:space-between;font-size:12px;color:var(--text-mid)">
          <span>Repaid: N$ <?=number_format((float)$l['total_repaid'],2)?></span>
          <span><?=$pct?>% complete</span>
        </div>
        <div style="height:8px;background:var(--border);border-radius:4px">
          <div style="height:8px;background:var(--green);border-radius:4px;width:<?=$pct?>%;transition:width .3s"></div>
        </div>

        <div style="margin-top:12px;font-size:12px;color:var(--text-mid)">
          Repayment method: <strong><?=ucwords(str_replace('_',' ',$l['repayment_method']))?></strong>
          <?php if ($l['status']==='active' && (float)$l['repayment_amount']>0): ?>
          &nbsp;&mdash;&nbsp;This amount will be deducted automatically from your monthly payslip.
          <?php endif ?>
        </div>
      </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

  </div>
</div>
</body>
</html>
