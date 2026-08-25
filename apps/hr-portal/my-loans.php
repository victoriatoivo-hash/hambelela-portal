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
    $accepted = isset($_POST['ack_read'],$_POST['ack_principal'],$_POST['ack_instalment'],$_POST['ack_start'],$_POST['ack_deduction'],$_POST['ack_termination'],$_POST['ack_questions']);
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
    $notify = $db->prepare("INSERT INTO notifications (user_id,title,message,type,action_url) VALUES (?,?,?,'info',?)");
    foreach ($ownerIds as $ownerId) $notify->execute([(int)$ownerId,'Employee Signed Loan Agreement',$expectedName.' signed loan agreement #'.$agreementId.'.','loan-view.php?loan_id='.(int)$agreement['loan_id'].'&tab=agreement']);
    header('Location: my-loans.php?loan_id='.(int)$agreement['loan_id'].'&tab=agreement&msg=signed'); exit;
}

$loans = $db->prepare("SELECT l.*,a.id AS agreement_id,a.version_no,a.status AS agreement_status,a.agreement_date,a.first_deduction_date,a.instalment_amount,a.number_of_instalments,a.final_instalment_amount,a.purpose,a.legal_notes,a.snapshot_json,a.document_hash,a.employee_signed_at,a.owner_signed_at,a.fully_signed_at,(SELECT COALESCE(SUM(amount),0) FROM loan_repayments WHERE loan_id=l.id) as total_repaid FROM loans l LEFT JOIN loan_agreements a ON a.loan_id=l.id AND a.version_no=(SELECT MAX(a2.version_no) FROM loan_agreements a2 WHERE a2.loan_id=l.id) WHERE l.employee_id=? ORDER BY l.status ASC, l.created_at DESC");
$loans->execute([$empId]);
$loans = $loans->fetchAll();
$requestedLoanId=(int)($_GET['loan_id']??0); $requestedTab=(string)($_GET['tab']??'overview');
if ($requestedLoanId) { $loans=array_values(array_filter($loans,function($row)use($requestedLoanId){return (int)$row['id']===$requestedLoanId;})); if (!$loans){http_response_code(403);exit('You do not have access to this loan.');} }
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
<link rel="stylesheet" href="includes/loan-agreement-document.css">
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

        <nav class="loan-tabs" aria-label="Loan sections"><a href="my-loans.php?loan_id=<?=$l['id']?>&amp;tab=overview">Overview</a><a href="my-loans.php?loan_id=<?=$l['id']?>&amp;tab=schedule">Repayment Schedule</a><a href="my-loans.php?loan_id=<?=$l['id']?>&amp;tab=agreement">Loan Agreement</a><a href="my-loans.php?loan_id=<?=$l['id']?>&amp;tab=history">History</a></nav>
        <?php if (!$requestedLoanId): ?><a class="btn btn-secondary btn-sm" href="my-loans.php?loan_id=<?=$l['id']?>&amp;tab=agreement"><i class="fa-solid fa-file-signature"></i> View Agreement</a><?php endif ?>

        <?php if ($l['agreement_id'] && $l['agreement_status'] !== 'draft'): ?>
        <section class="loan-agreement-panel">
          <div class="loan-agreement-heading"><div><span class="loan-eyebrow">Loan agreement · Version <?=intval($l['version_no'])?></span><h2><?=htmlspecialchars(loanAgreementStatusLabel((string)$l['agreement_status']))?></h2></div><span class="badge <?=($l['agreement_status']==='fully_signed'?'badge-green':'badge-amber')?>"><?=htmlspecialchars(loanAgreementStatusLabel((string)$l['agreement_status']))?></span></div>
          <div class="loan-progress" aria-label="Agreement progress">
            <?php foreach ([['Draft',true],['Sent',!in_array($l['agreement_status'],['draft'],true)],['Employee signed',!empty($l['employee_signed_at'])],['Owner signed',!empty($l['owner_signed_at'])],['Active',$l['agreement_status']==='fully_signed']] as $step): ?>
            <div class="<?= $step[1] ? 'is-complete' : '' ?>"><span><i class="fa-solid <?= $step[1] ? 'fa-check' : 'fa-circle' ?>"></i></span><small><?=htmlspecialchars($step[0])?></small></div>
            <?php endforeach ?>
          </div>
          <?php $employeeAgreementData=loanAgreementDocumentData($l,$l,array_merge($employee,['job_title'=>$employee['job_title']??'Employee'])); loanAgreementRenderDocument($employeeAgreementData,$signatures,['document_hash'=>$l['document_hash']]); ?>

          <?php if (in_array($l['agreement_status'],['employee_pending','owner_signed'],true)): ?>
          <form method="POST" class="loan-sign-form" data-signature-form><input type="hidden" name="loan_agreement_csrf" value="<?=htmlspecialchars(loanAgreementCsrfToken())?>"><input type="hidden" name="action" value="employee_sign"><input type="hidden" name="agreement_id" value="<?=$l['agreement_id']?>"><h3>Employee acknowledgement &amp; signature</h3><label class="loan-check"><input type="checkbox" name="ack_read" required><span>I have read and understood this Employee Loan Agreement.</span></label><label class="loan-check"><input type="checkbox" name="ack_principal" required><span>I understand that the principal amount of my loan is N$<?=number_format((float)$l['amount'],2)?>.</span></label><label class="loan-check"><input type="checkbox" name="ack_instalment" required><span>I understand that my scheduled instalment is N$<?=number_format((float)$l['instalment_amount'],2)?>.</span></label><label class="loan-check"><input type="checkbox" name="ack_start" required><span>I understand that deductions are scheduled to begin on <?=loanAgreementFormatDate((string)$l['first_deduction_date'])?>.</span></label><label class="loan-check"><input type="checkbox" name="ack_deduction" required><span>I voluntarily authorise the agreed payroll deductions, subject to applicable Namibian law.</span></label><label class="loan-check"><input type="checkbox" name="ack_termination" required><span>I understand that an outstanding balance remains payable if my employment ends before the loan has been fully repaid.</span></label><label class="loan-check"><input type="checkbox" name="ack_questions" required><span>I have had the opportunity to ask questions about this Agreement before signing.</span></label><div class="form-grid"><div class="form-group"><label class="form-label">Full Legal Name</label><input class="form-input" name="employee_name" autocomplete="name" required placeholder="<?=htmlspecialchars($employee['emp_name'] ?? '')?>"></div><div class="form-group"><label class="form-label">Signature Pad</label><canvas class="loan-signature-pad" width="500" height="150" tabindex="0" aria-label="Draw your signature"></canvas><input type="hidden" name="employee_signature" required><button class="loan-clear-signature" type="button">Clear Signature</button></div></div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock"></i> Sign Agreement</button></form>
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
.loan-signature-pad{display:block;width:100%;height:90px;touch-action:none;background:#fff;border:1.5px solid var(--border);border-radius:8px}.loan-signature-pad:focus-visible{outline:2px solid var(--green-light);outline-offset:1px}.loan-clear-signature{align-self:flex-start;padding:3px 0;color:var(--green);font-size:10px;font-weight:700;background:transparent;border:0;cursor:pointer}
.loan-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0}.loan-tabs a{padding:7px 10px;border:1px solid var(--border);border-radius:7px;color:var(--green);font-size:11px;font-weight:700;text-decoration:none}.loan-tabs a:hover{background:var(--green-pale)}
</style>
<script>
document.querySelectorAll('[data-signature-form]').forEach(function(form){
  var canvas=form.querySelector('.loan-signature-pad'),input=form.querySelector('[name="employee_signature"]'),clear=form.querySelector('.loan-clear-signature'),ctx=canvas.getContext('2d'),drawing=false,hasInk=false;
  ctx.lineWidth=2.2;ctx.lineCap='round';ctx.strokeStyle='#1A2E22';
  function point(event){var r=canvas.getBoundingClientRect();return{x:(event.clientX-r.left)*(canvas.width/r.width),y:(event.clientY-r.top)*(canvas.height/r.height)}}
  canvas.addEventListener('pointerdown',function(event){drawing=true;hasInk=true;canvas.setPointerCapture(event.pointerId);var p=point(event);ctx.beginPath();ctx.moveTo(p.x,p.y);event.preventDefault()});
  canvas.addEventListener('pointermove',function(event){if(!drawing)return;var p=point(event);ctx.lineTo(p.x,p.y);ctx.stroke();event.preventDefault()});
  canvas.addEventListener('pointerup',function(){if(!drawing)return;drawing=false;input.value=canvas.toDataURL('image/png')});
  clear.addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);input.value='';hasInk=false});
  form.addEventListener('submit',function(event){if(!hasInk){event.preventDefault();canvas.focus();alert('Draw your signature before signing.')}});
});
</script>
</body>
</html>
