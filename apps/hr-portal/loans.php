<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/loan-agreements.php';
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
loanAgreementEnsureSchema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    loanAgreementRequireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_agreement') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $stmt = $db->prepare("SELECT l.* FROM loans l WHERE l.id=?");
        $stmt->execute([$loanId]); $loan = $stmt->fetch();
        if (!$loan) { http_response_code(404); exit('Loan not found.'); }
        $versionStmt = $db->prepare("SELECT COALESCE(MAX(version_no),0)+1 FROM loan_agreements WHERE loan_id=?");
        $versionStmt->execute([$loanId]); $version = (int)$versionStmt->fetchColumn();
        $repay = (float)$loan['repayment_amount'];
        $count = $repay > 0 ? (int)ceil((float)$loan['amount'] / $repay) : 0;
        $final = $count > 0 ? round((float)$loan['amount'] - ($repay * max(0,$count-1)),2) : (float)$loan['amount'];
        $first = date('Y-m-t', strtotime('first day of next month'));
        $db->prepare("INSERT INTO loan_agreements (loan_id,version_no,status,agreement_date,first_deduction_date,deduction_day,instalment_amount,number_of_instalments,final_instalment_amount,purpose,repayment_method,created_by) VALUES (?,?,'draft',CURDATE(),?,?,?,?,?,?,?,?)")
           ->execute([$loanId,$version,$first,(int)date('j',strtotime($first)),$repay,$count,$final,$loan['notes'],$loan['repayment_method'],$user['id']]);
        $agreementId=(int)$db->lastInsertId(); loanAgreementEvent($db,$agreementId,$loanId,'agreement_created',$user,['source'=>'existing_loan']);
        header('Location: loan-view.php?loan_id='.$loanId.'&tab=agreement&msg=created'); exit;
    }

    if ($action === 'add_loan') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $repay  = (float)($_POST['repayment_amount'] ?? 0);
        $method = $_POST['repayment_method'] ?? 'salary_deduction';
        $date   = $_POST['loan_date'] ?? date('Y-m-d');
        $notes  = clean($_POST['notes'] ?? '');
        $firstDeduction = $_POST['first_deduction_date'] ?? '';
        $agreementDate = $_POST['agreement_date'] ?? date('Y-m-d');
        $legalNotes = trim((string)($_POST['legal_notes'] ?? ''));
        $employeeSalaryStmt = $db->prepare("SELECT basic_salary FROM employees WHERE id=? AND status='active'");
        $employeeSalaryStmt->execute([$emp_id]);
        $basicSalary = (float)$employeeSalaryStmt->fetchColumn();
        $oneThirdLimit = round($basicSalary / 3, 2);
        if (!$emp_id || $amount <= 0 || $repay <= 0 || !$firstDeduction) {
            header('Location: loans.php?error=required'); exit;
        }
        if ($method === 'salary_deduction' && $basicSalary > 0 && $repay > $oneThirdLimit) {
            header('Location: loans.php?error=one_third'); exit;
        }
        if ($emp_id && $amount > 0) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO loans (employee_id,amount,balance,repayment_amount,repayment_method,loan_date,notes,status,created_by) VALUES (?,?,?,?,?,?,?,'active',?)")
               ->execute([$emp_id,$amount,$amount,$repay,$method,$date,$notes,$user['id']]);
            $loanId = (int)$db->lastInsertId();
            $instalments = (int)ceil($amount / $repay);
            $finalInstalment = round($amount - ($repay * max(0, $instalments - 1)), 2);
            $db->prepare("INSERT INTO loan_agreements (loan_id,version_no,status,agreement_date,first_deduction_date,deduction_day,instalment_amount,number_of_instalments,final_instalment_amount,purpose,repayment_method,legal_notes,created_by) VALUES (?,1,'draft',?,?,?,?,?,?,?,?,?,?)")
               ->execute([$loanId,$agreementDate,$firstDeduction,(int)date('j',strtotime($firstDeduction)),$repay,$instalments,$finalInstalment,$notes,$method,$legalNotes,$user['id']]);
            $agreementId = (int)$db->lastInsertId();
            loanAgreementEvent($db,$agreementId,$loanId,'agreement_created',$user,['one_third_limit'=>$oneThirdLimit]);
            $db->commit();
        }
        header('Location: loans.php?msg=added'); exit;
    }

    if ($action === 'send_agreement') {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        $stmt = $db->prepare("SELECT a.*,l.employee_id,l.amount,l.id AS loan_id,e.first_name,e.last_name,e.emp_number FROM loan_agreements a JOIN loans l ON l.id=a.loan_id JOIN employees e ON e.id=l.employee_id WHERE a.id=? AND a.status='draft'");
        $stmt->execute([$agreementId]); $agreement = $stmt->fetch();
        if ($agreement) {
            $hash = loanAgreementHash($agreement,$agreement,$agreement);
            $snapshot = json_encode(loanAgreementCanonical($agreement,$agreement,$agreement), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $db->prepare("UPDATE loan_agreements SET status='employee_pending',sent_at=NOW(),snapshot_json=?,document_hash=? WHERE id=? AND status='draft'")
               ->execute([$snapshot,$hash,$agreementId]);
            loanAgreementEvent($db,$agreementId,(int)$agreement['loan_id'],'sent_to_employee',$user);
            loanAgreementNotify($db,(int)$agreement['employee_id'],'Loan Agreement requires your signature.','Review and sign your employee loan agreement.','info','my-loans.php?loan_id='.(int)$agreement['loan_id'].'&tab=agreement');
        }
        header('Location: loans.php?msg=sent'); exit;
    }

    if ($action === 'owner_sign') {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        $typedName = trim((string)($_POST['owner_name'] ?? ''));
        $signature = trim((string)($_POST['owner_signature'] ?? ''));
        $stmt = $db->prepare("SELECT a.*,l.employee_id,l.amount,l.id AS loan_id,e.first_name,e.last_name,e.emp_number FROM loan_agreements a JOIN loans l ON l.id=a.loan_id JOIN employees e ON e.id=l.employee_id WHERE a.id=? AND a.status IN ('employee_pending','employee_signed')");
        $stmt->execute([$agreementId]); $agreement = $stmt->fetch();
        if ($agreement && $typedName !== '' && $signature !== '') {
            $hash = $agreement['document_hash'] ?: loanAgreementHash($agreement,$agreement,$agreement);
            $db->prepare("INSERT INTO loan_agreement_signatures (agreement_id,signer_role,signer_user_id,signer_name,signature_data,document_hash,ip_address,user_agent) VALUES (?,'owner',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE signer_name=VALUES(signer_name),signature_data=VALUES(signature_data),document_hash=VALUES(document_hash),signed_at=CURRENT_TIMESTAMP")
               ->execute([$agreementId,$user['id'],$typedName,$signature,$hash,loanAgreementClientIp(),loanAgreementUserAgent()]);
            $hasEmployee = (bool)$db->query("SELECT 1 FROM loan_agreement_signatures WHERE agreement_id=".$agreementId." AND signer_role='employee' LIMIT 1")->fetchColumn();
            $newStatus = $hasEmployee ? 'fully_signed' : 'owner_signed';
            $sql = $hasEmployee ? "UPDATE loan_agreements SET status=?,owner_signed_at=NOW(),fully_signed_at=NOW() WHERE id=?" : "UPDATE loan_agreements SET status=?,owner_signed_at=NOW() WHERE id=?";
            $db->prepare($sql)->execute([$newStatus,$agreementId]);
            if ($hasEmployee) loanAgreementSchedule($db,$agreementId,$agreement['first_deduction_date'],(float)$agreement['amount'],(float)$agreement['instalment_amount']);
            loanAgreementEvent($db,$agreementId,(int)$agreement['loan_id'],'owner_signed',$user,['fully_signed'=>$hasEmployee]);
            loanAgreementNotify($db,(int)$agreement['employee_id'],$hasEmployee?'Loan Agreement Fully Signed':'Owner Signed Loan Agreement',$hasEmployee?'Your loan agreement is fully signed and active.':'The owner has signed your loan agreement.','info','my-loans.php?loan_id='.(int)$agreement['loan_id'].'&tab=agreement');
        }
        header('Location: loans.php?msg=owner_signed'); exit;
    }

    if ($action === 'add_repayment') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $amount  = (float)($_POST['repay_amount'] ?? 0);
        $notes   = clean($_POST['repay_notes'] ?? '');
        if ($loan_id && $amount > 0) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO loan_repayments (loan_id,amount,notes) VALUES (?,?,?)")->execute([$loan_id,$amount,$notes]);
            $db->prepare("UPDATE loans SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount,$loan_id]);
            loanAgreementApplyRepayment($db,$loan_id,$amount);
            $loan = $db->prepare("SELECT balance FROM loans WHERE id=?"); $loan->execute([$loan_id]); $loan = $loan->fetch();
            if ($loan && (float)$loan['balance'] <= 0) {
                $db->prepare("UPDATE loans SET status='settled',balance=0 WHERE id=?")->execute([$loan_id]);
            }
            $agreement = $db->prepare("SELECT id FROM loan_agreements WHERE loan_id=? ORDER BY version_no DESC LIMIT 1"); $agreement->execute([$loan_id]); $agreementId=(int)$agreement->fetchColumn();
            if ($agreementId) loanAgreementEvent($db,$agreementId,$loan_id,'repayment_recorded',$user,['amount'=>$amount,'notes'=>$notes]);
            $db->commit();
        }
        header('Location: loans.php?msg=repaid'); exit;
    }

    if ($action === 'settle_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        if ($loan_id) $db->prepare("UPDATE loans SET status='settled',balance=0 WHERE id=?")->execute([$loan_id]);
        header('Location: loans.php?msg=settled'); exit;
    }
}

$loans     = $db->query("SELECT l.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.emp_number,e.basic_salary,a.id AS agreement_id,a.version_no,a.status AS agreement_status,a.first_deduction_date,a.number_of_instalments,a.final_instalment_amount,a.document_hash,a.fully_signed_at FROM loans l JOIN employees e ON e.id=l.employee_id LEFT JOIN loan_agreements a ON a.loan_id=l.id AND a.version_no=(SELECT MAX(a2.version_no) FROM loan_agreements a2 WHERE a2.loan_id=l.id) ORDER BY l.status ASC, l.created_at DESC")->fetchAll();
$employees = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name, emp_number FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();
$totalActive = array_sum(array_column(array_filter($loans, function($l){ return $l['status']==='active'; }), 'balance'));
$totalLoans  = count(array_filter($loans, function($l){ return $l['status']==='active'; }));
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
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
    <?php elseif ($msg==='sent'): ?><div class="toast"><i class="fa-solid fa-paper-plane"></i> Agreement sent to the employee for signature.</div>
    <?php elseif ($msg==='owner_signed'): ?><div class="toast"><i class="fa-solid fa-signature"></i> Owner signature recorded.</div>
    <?php endif ?>
    <?php if ($error==='required'): ?><div class="toast error">Complete the employee, amount, repayment and first deduction date.</div>
    <?php elseif ($error==='one_third'): ?><div class="toast error">The proposed monthly deduction exceeds one-third of the employee's basic salary.</div><?php endif ?>

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
        <thead><tr><th>Employee</th><th>Loan Amount</th><th>Balance</th><th>Monthly Deduction</th><th>Agreement</th><th>Status</th><th>Actions</th></tr></thead>
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
          <td>
            <span class="badge <?=($l['agreement_status']==='fully_signed'?'badge-green':($l['agreement_status']==='draft'?'badge-gray':'badge-amber'))?>"><?=htmlspecialchars(loanAgreementStatusLabel((string)($l['agreement_status'] ?: 'draft')))?></span>
            <div style="font-size:10px;color:var(--text-mid);margin-top:4px">Version <?=intval($l['version_no'] ?: 1)?> · first deduction <?= $l['first_deduction_date'] ? date('d M Y',strtotime($l['first_deduction_date'])) : 'not set' ?></div>
          </td>
          <td><span class="badge <?=$sc?>"><?=ucfirst($l['status'])?></span></td>
          <td>
            <a class="btn btn-secondary btn-sm" href="loan-view.php?loan_id=<?=$l['id']?>"><i class="fa-solid fa-eye"></i> Open loan</a>
            <a class="btn btn-secondary btn-sm" href="loan-view.php?loan_id=<?=$l['id']?>&amp;tab=agreement"><i class="fa-solid fa-file-signature"></i> Loan Agreement</a>
            <?php if ($l['status']==='active'): ?>
            <?php if ($l['agreement_status']==='draft'): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>"><input type="hidden" name="action" value="send_agreement"><input type="hidden" name="agreement_id" value="<?=$l['agreement_id']?>"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Send</button></form>
            <?php elseif (in_array($l['agreement_status'],['employee_pending','employee_signed'],true)): ?>
            <button class="btn btn-amber btn-sm" type="button" onclick="openOwnerSign(<?=$l['agreement_id']?>,'<?=htmlspecialchars($l['emp_name'],ENT_QUOTES)?>')"><i class="fa-solid fa-signature"></i> Owner sign</button>
            <?php elseif ($l['agreement_status']==='fully_signed'): ?>
            <a class="btn btn-secondary btn-sm" href="loan-agreement.php?loan_id=<?=$l['id']?>&download=1"><i class="fa-solid fa-file-pdf"></i> PDF</a>
            <?php endif ?>
            <?php if (!$l['agreement_id'] || $l['agreement_status']==='legacy_active'): ?><form method="POST" style="display:inline"><input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>"><input type="hidden" name="action" value="create_agreement"><input type="hidden" name="loan_id" value="<?=$l['id']?>"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-file-circle-plus"></i> Create Loan Agreement</button></form><?php endif ?>
            <button class="btn btn-secondary btn-sm" onclick="openRepay(<?=$l['id']?>,'<?=htmlspecialchars($l['emp_name'])?>',<?=$l['balance']?>,<?=$l['repayment_amount']?>)"><i class="fa-solid fa-money-bill"></i> Repay</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Mark as settled?')">
              <input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>">
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
      <input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>">
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
          <div class="form-group"><label class="form-label">Agreement Date</label><input class="form-input" type="date" name="agreement_date" value="<?=date('Y-m-d')?>" required></div>
          <div class="form-group"><label class="form-label">First Payroll Deduction</label><input class="form-input" type="date" name="first_deduction_date" required></div>
          <div class="form-group">
            <label class="form-label">Repayment Method</label>
            <select class="form-select" name="repayment_method">
              <option value="salary_deduction">Salary Deduction</option>
              <option value="cash">Cash</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group full"><label class="form-label">Notes (optional)</label><textarea class="form-textarea" name="notes" placeholder="e.g. March advance..."></textarea></div>
          <div class="form-group full"><label class="form-label">Additional Legal / HR Notes</label><textarea class="form-textarea" name="legal_notes" placeholder="Optional agreement-specific terms. Standard lawful deduction, early repayment, termination and variation clauses are included automatically."></textarea></div>
          <div class="form-group full"><div class="loan-safeguard"><strong>One-third deduction safeguard</strong><span>The portal checks the proposed monthly deduction against one-third of the employee's recorded basic salary before the draft is created.</span></div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addLoanModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Loan</button>
      </div>
    </form>
  </div>
</div>

<div class="overlay" id="ownerSignModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-signature"></i> Owner Signature</div><button class="modal-close" type="button" onclick="closeModal('ownerSignModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>">
      <input type="hidden" name="action" value="owner_sign"><input type="hidden" name="agreement_id" id="ownerAgreementId">
      <div class="modal-body"><p class="loan-modal-copy">Signing confirms that you reviewed the agreement for <strong id="ownerSignEmployee"></strong>. The signed document hash is preserved in the audit trail.</p><div class="form-grid"><div class="form-group full"><label class="form-label">Type full name</label><input class="form-input" name="owner_name" required></div><div class="form-group full"><label class="form-label">Draw or type signature</label><input class="form-input loan-signature-input" name="owner_signature" required placeholder="Signature"></div></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('ownerSignModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa-solid fa-lock"></i> Sign Agreement</button></div>
    </form>
  </div>
</div>

<style>
.loan-safeguard{display:grid;gap:4px;padding:12px 14px;color:var(--text);background:var(--green-pale);border:1px solid var(--green-mid);border-radius:var(--radius-sm)}
.loan-safeguard strong{font-size:12px}.loan-safeguard span,.loan-modal-copy{font-size:11px;line-height:1.55;color:var(--text-mid)}
.loan-signature-input{font-family:cursive;font-size:19px}
@media(max-width:767px){.card{overflow-x:auto}.card table{min-width:1040px}}
</style>

<!-- REPAYMENT MODAL -->
<div class="overlay" id="repayModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-money-bill"></i> Record Repayment — <span id="repayName"></span></div><button class="modal-close" onclick="closeModal('repayModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>">
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
function openOwnerSign(id, name) {
  document.getElementById('ownerAgreementId').value = id;
  document.getElementById('ownerSignEmployee').textContent = name;
  openModal('ownerSignModal');
}
document.querySelectorAll('.overlay').forEach(function(o) {
  o.addEventListener('click', function(e) { if(e.target===o) o.classList.remove('open'); });
});
</script>
</body>
</html>
