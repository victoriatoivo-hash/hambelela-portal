<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/loan-agreements.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db    = db();
$empId = (int)($user['emp_id'] ?? 0);
loanAgreementEnsureSchema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'employee_sign') {
    loanAgreementRequireCsrf();
    $agreementId = (int)($_POST['agreement_id'] ?? 0);
    $typedName = trim((string)($_POST['employee_name'] ?? ''));
    $signature = trim((string)($_POST['employee_signature'] ?? ''));
    $accepted = isset($_POST['accept_terms']);
    $stmt = $db->prepare("SELECT a.*,l.id AS loan_id,l.employee_id,l.amount,e.first_name,e.last_name,e.emp_number FROM loan_agreements a JOIN loans l ON l.id=a.loan_id JOIN employees e ON e.id=l.employee_id WHERE a.id=? AND l.employee_id=? AND a.status IN ('employee_pending','owner_signed')");
    $stmt->execute([$agreementId,$empId]); $agreement = $stmt->fetch();
    $expectedName = $agreement ? trim($agreement['first_name'].' '.$agreement['last_name']) : '';
    if (!$agreement || !$accepted || $typedName === '' || $signature === '' || strcasecmp($typedName,$expectedName)!==0) {
        header('Location: my-loans.php?error=signature'); exit;
    }
    $hash = $agreement['document_hash'] ?: loanAgreementHash($agreement,$agreement,$agreement);
    $db->prepare("INSERT INTO loan_agreement_signatures (agreement_id,signer_role,signer_user_id,signer_name,signature_data,document_hash,ip_address,user_agent) VALUES (?,'employee',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE signer_name=VALUES(signer_name),signature_data=VALUES(signature_data),document_hash=VALUES(document_hash),signed_at=CURRENT_TIMESTAMP")
       ->execute([$agreementId,$user['id'],$typedName,$signature,$hash,loanAgreementClientIp(),loanAgreementUserAgent()]);
    $hasOwner = (bool)$db->query("SELECT 1 FROM loan_agreement_signatures WHERE agreement_id=".$agreementId." AND signer_role='owner' LIMIT 1")->fetchColumn();
    $newStatus = $hasOwner ? 'fully_signed' : 'employee_signed';
    $sql = $hasOwner ? "UPDATE loan_agreements SET status=?,employee_signed_at=NOW(),fully_signed_at=NOW() WHERE id=?" : "UPDATE loan_agreements SET status=?,employee_signed_at=NOW() WHERE id=?";
    $db->prepare($sql)->execute([$newStatus,$agreementId]);
    if ($hasOwner) loanAgreementSchedule($db,$agreementId,$agreement['first_deduction_date'],(float)$agreement['amount'],(float)$agreement['instalment_amount']);
    loanAgreementEvent($db,$agreementId,(int)$agreement['loan_id'],'employee_signed',$user,['fully_signed'=>$hasOwner]);
    $ownerIds = $db->query("SELECT id FROM users WHERE role='admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
    $notify = $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')");
    foreach ($ownerIds as $ownerId) $notify->execute([(int)$ownerId,'Employee Signed Loan Agreement',$expectedName.' signed loan agreement #'.$agreementId.'.']);
    header('Location: my-loans.php?msg=signed'); exit;
}

$loans = $db->prepare("SELECT l.*,a.id AS agreement_id,a.version_no,a.status AS agreement_status,a.agreement_date,a.first_deduction_date,a.instalment_amount,a.number_of_instalments,a.final_instalment_amount,a.purpose,a.legal_notes,a.document_hash,a.employee_signed_at,a.owner_signed_at,a.fully_signed_at,(SELECT COALESCE(SUM(amount),0) FROM loan_repayments WHERE loan_id=l.id) as total_repaid FROM loans l LEFT JOIN loan_agreements a ON a.loan_id=l.id AND a.version_no=(SELECT MAX(a2.version_no) FROM loan_agreements a2 WHERE a2.loan_id=l.id) WHERE l.employee_id=? ORDER BY l.status ASC, l.created_at DESC");
$loans->execute([$empId]);
$loans = $loans->fetchAll();
$employeeStmt = $db->prepare("SELECT *,CONCAT(first_name,' ',last_name) AS emp_name FROM employees WHERE id=?"); $employeeStmt->execute([$empId]); $employee = $employeeStmt->fetch();

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

    <?php if (($_GET['msg'] ?? '')==='signed'): ?><div class="toast"><i class="fa-solid fa-check"></i> Agreement signed successfully. The owner has been notified.</div><?php endif ?>
    <?php if (($_GET['error'] ?? '')==='signature'): ?><div class="toast error">Confirm the terms, type your full employee name exactly, and provide your signature.</div><?php endif ?>

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
    <?php
      $agreement = $l;
      $scheduleStmt = $db->prepare("SELECT * FROM loan_repayment_schedule WHERE agreement_id=? ORDER BY instalment_no");
      $scheduleStmt->execute([(int)$l['agreement_id']]); $schedule = $scheduleStmt->fetchAll();
      $signatureStmt = $db->prepare("SELECT signer_role,signer_name,signed_at FROM loan_agreement_signatures WHERE agreement_id=? ORDER BY signed_at");
      $signatureStmt->execute([(int)$l['agreement_id']]); $signatures = $signatureStmt->fetchAll();
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

        <?php if ($l['agreement_id']): ?>
        <section class="loan-agreement-panel">
          <div class="loan-agreement-heading"><div><span class="loan-eyebrow">Loan agreement · Version <?=intval($l['version_no'])?></span><h2><?=htmlspecialchars(loanAgreementStatusLabel((string)$l['agreement_status']))?></h2></div><span class="badge <?=($l['agreement_status']==='fully_signed'?'badge-green':'badge-amber')?>"><?=htmlspecialchars(loanAgreementStatusLabel((string)$l['agreement_status']))?></span></div>
          <div class="loan-progress" aria-label="Agreement progress">
            <?php foreach ([['Draft',true],['Sent',!in_array($l['agreement_status'],['draft'],true)],['Employee signed',!empty($l['employee_signed_at'])],['Owner signed',!empty($l['owner_signed_at'])],['Active',$l['agreement_status']==='fully_signed']] as $step): ?>
            <div class="<?= $step[1] ? 'is-complete' : '' ?>"><span><i class="fa-solid <?= $step[1] ? 'fa-check' : 'fa-circle' ?>"></i></span><small><?=htmlspecialchars($step[0])?></small></div>
            <?php endforeach ?>
          </div>
          <div class="loan-contract">
            <h3>Employee Loan Agreement</h3>
            <p>This agreement is between <strong>Hambelela Organic</strong> and <strong><?=htmlspecialchars($employee['emp_name'] ?? '')?></strong> (<?=htmlspecialchars($employee['emp_number'] ?? '')?>).</p>
            <dl><div><dt>Principal amount</dt><dd>N$ <?=number_format((float)$l['amount'],2)?></dd></div><div><dt>Agreement date</dt><dd><?=date('d F Y',strtotime($l['agreement_date']))?></dd></div><div><dt>First deduction</dt><dd><?=date('d F Y',strtotime($l['first_deduction_date']))?></dd></div><div><dt>Monthly instalment</dt><dd>N$ <?=number_format((float)$l['instalment_amount'],2)?></dd></div><div><dt>Instalments</dt><dd><?=intval($l['number_of_instalments'])?></dd></div><div><dt>Final instalment</dt><dd>N$ <?=number_format((float)$l['final_instalment_amount'],2)?></dd></div></dl>
            <h4>Terms and authorisation</h4>
            <ul><li>The loan is interest-free unless a signed variation states otherwise.</li><li>You authorise the agreed payroll deductions after both parties sign.</li><li>Early repayment is allowed without penalty and reduces the outstanding balance.</li><li>On termination, any balance remains due and deductions are limited to what the law and written authorisation permit.</li><li>Changes require a new version accepted by both parties.</li></ul>
            <?php if ($l['legal_notes']): ?><div class="loan-note"><strong>Additional terms</strong><?=nl2br(htmlspecialchars($l['legal_notes']))?></div><?php endif ?>
          </div>

          <?php if (in_array($l['agreement_status'],['employee_pending','owner_signed'],true)): ?>
          <form method="POST" class="loan-sign-form"><input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>"><input type="hidden" name="action" value="employee_sign"><input type="hidden" name="agreement_id" value="<?=$l['agreement_id']?>"><h3>Employee acknowledgement &amp; signature</h3><label class="loan-check"><input type="checkbox" name="accept_terms" required><span>I have read, understood and accept this loan agreement and payroll deduction authorisation.</span></label><div class="form-grid"><div class="form-group"><label class="form-label">Type full name exactly</label><input class="form-input" name="employee_name" autocomplete="name" required placeholder="<?=htmlspecialchars($employee['emp_name'] ?? '')?>"></div><div class="form-group"><label class="form-label">Draw or type signature</label><input class="form-input loan-signature-input" name="employee_signature" required placeholder="Signature"></div></div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock"></i> Sign Agreement</button></form>
          <?php endif ?>

          <?php if ($signatures): ?><div class="loan-signatures"><h3>Signature record</h3><?php foreach ($signatures as $sig): ?><div><i class="fa-solid fa-circle-check"></i><span><strong><?=ucfirst(htmlspecialchars($sig['signer_role']))?></strong> · <?=htmlspecialchars($sig['signer_name'])?><small><?=date('d M Y H:i',strtotime($sig['signed_at']))?></small></span></div><?php endforeach ?><p>Document SHA-256: <code><?=htmlspecialchars($l['document_hash'])?></code></p></div><?php endif ?>

          <?php if ($schedule): ?><details class="loan-schedule"><summary>Repayment schedule · <?=count($schedule)?> instalments</summary><div class="hr-table-viewport"><table><thead><tr><th>#</th><th>Due date</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach ($schedule as $row): ?><tr><td><?=intval($row['instalment_no'])?></td><td><?=date('d M Y',strtotime($row['due_date']))?></td><td>N$ <?=number_format((float)$row['amount'],2)?></td><td><span class="badge badge-gray"><?=htmlspecialchars(ucfirst($row['status']))?></span></td></tr><?php endforeach ?></tbody></table></div></details><?php endif ?>
        </section>
        <?php endif ?>
      </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

  </div>
</div>
<style>
.loan-agreement-panel{margin-top:22px;padding-top:20px;border-top:1px solid var(--border)}.loan-agreement-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.loan-eyebrow{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--green)}.loan-agreement-heading h2{margin-top:4px;font-size:18px}.loan-progress{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;margin:18px 0}.loan-progress div{display:grid;justify-items:center;gap:5px;color:var(--text-light);text-align:center}.loan-progress span{width:28px;height:28px;display:grid;place-items:center;border:2px solid var(--border);border-radius:50%;background:#fff;font-size:10px}.loan-progress .is-complete{color:var(--green)}.loan-progress .is-complete span{color:#fff;background:var(--green);border-color:var(--green)}.loan-progress small{font-size:9px}.loan-contract,.loan-sign-form,.loan-signatures,.loan-schedule{margin-top:14px;padding:16px;background:#f8faf9;border:1px solid var(--border);border-radius:var(--radius-sm)}.loan-contract h3,.loan-sign-form h3,.loan-signatures h3{font-size:14px;margin-bottom:8px}.loan-contract h4{font-size:12px;margin:14px 0 6px}.loan-contract p,.loan-contract li{font-size:12px;line-height:1.55}.loan-contract ul{padding-left:18px}.loan-contract dl{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}.loan-contract dl div{padding:10px;background:#fff;border-radius:7px}.loan-contract dt{font-size:9px;text-transform:uppercase;font-weight:700;color:var(--text-mid)}.loan-contract dd{margin-top:3px;font-size:12px;font-weight:700}.loan-note{display:grid;gap:4px;margin-top:12px;padding:10px;background:var(--amber-pale);font-size:11px;line-height:1.5;border-radius:7px}.loan-check{display:flex;gap:9px;align-items:flex-start;margin-bottom:14px;font-size:11px;line-height:1.5}.loan-check input{margin-top:2px;accent-color:var(--green)}.loan-sign-form .btn{margin-top:14px}.loan-signature-input{font-family:cursive;font-size:18px}.loan-signatures>div{display:flex;gap:9px;align-items:center;padding:7px 0;color:var(--green)}.loan-signatures span{display:flex;gap:5px;align-items:baseline;color:var(--text);font-size:11px}.loan-signatures small{color:var(--text-mid)}.loan-signatures p{margin-top:8px;font-size:9px;color:var(--text-mid);overflow-wrap:anywhere}.loan-schedule summary{font-size:12px;font-weight:700;cursor:pointer}.loan-schedule table{margin-top:12px;background:#fff}
@media(max-width:767px){.loan-contract dl{grid-template-columns:1fr 1fr}.loan-progress small{font-size:8px}.loan-signatures span{align-items:flex-start;flex-direction:column}.loan-agreement-heading{flex-direction:column}}
@media(max-width:430px){.loan-contract dl{grid-template-columns:1fr}.loan-progress{gap:2px}.loan-progress span{width:24px;height:24px}}
</style>
</body>
</html>
