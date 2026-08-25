<?php

function loanAgreementCsrfToken(): string {
    startSession();
    if (empty($_SESSION['loan_agreement_csrf'])) $_SESSION['loan_agreement_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['loan_agreement_csrf'];
}

function loanAgreementRequireCsrf(): void {
    $provided = (string)($_POST['loan_agreement_csrf'] ?? '');
    if ($provided === '' || !hash_equals(loanAgreementCsrfToken(), $provided)) {
        http_response_code(403); exit('The form expired. Refresh the page and try again.');
    }
}

function loanAgreementEnsureSchema(PDO $db): void {
    $notificationAction = $db->query("SHOW COLUMNS FROM notifications LIKE 'action_url'")->fetch();
    if (!$notificationAction) $db->exec("ALTER TABLE notifications ADD COLUMN action_url VARCHAR(255) NULL AFTER type");
    $db->exec("CREATE TABLE IF NOT EXISTS loan_agreements (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      loan_id INT UNSIGNED NOT NULL,
      version_no INT UNSIGNED NOT NULL DEFAULT 1,
      status VARCHAR(40) NOT NULL DEFAULT 'draft',
      agreement_date DATE NOT NULL,
      first_deduction_date DATE NULL,
      deduction_day TINYINT UNSIGNED NULL,
      instalment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      number_of_instalments INT UNSIGNED NOT NULL DEFAULT 0,
      final_instalment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      purpose TEXT NULL,
      repayment_method VARCHAR(40) NOT NULL DEFAULT 'salary_deduction',
      legal_notes TEXT NULL,
      snapshot_json MEDIUMTEXT NULL,
      document_hash CHAR(64) NULL,
      sent_at DATETIME NULL,
      employee_signed_at DATETIME NULL,
      owner_signed_at DATETIME NULL,
      fully_signed_at DATETIME NULL,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY loan_agreement_version (loan_id, version_no),
      KEY loan_agreement_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS loan_agreement_signatures (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      agreement_id INT UNSIGNED NOT NULL,
      signer_role VARCHAR(20) NOT NULL,
      signer_user_id INT UNSIGNED NULL,
      signer_name VARCHAR(180) NOT NULL,
      signature_data MEDIUMTEXT NOT NULL,
      document_hash CHAR(64) NOT NULL,
      ip_address VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY agreement_signer (agreement_id, signer_role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS loan_repayment_schedule (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      agreement_id INT UNSIGNED NOT NULL,
      instalment_no INT UNSIGNED NOT NULL,
      due_date DATE NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'scheduled',
      paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      paid_at DATETIME NULL,
      UNIQUE KEY agreement_instalment (agreement_id, instalment_no),
      KEY agreement_due_date (agreement_id, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS loan_agreement_events (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      agreement_id INT UNSIGNED NOT NULL,
      loan_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      actor_user_id INT UNSIGNED NULL,
      actor_role VARCHAR(30) NULL,
      metadata_json TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY loan_agreement_event (agreement_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Preserve payroll continuity for loans created before the agreement workflow existed.
    // These are clearly labelled legacy records; they are not presented as digitally signed.
    $db->exec("INSERT INTO loan_agreements (loan_id,version_no,status,agreement_date,first_deduction_date,deduction_day,instalment_amount,number_of_instalments,final_instalment_amount,purpose,repayment_method,created_by)
      SELECT l.id,1,'legacy_active',l.loan_date,l.loan_date,DAY(l.loan_date),l.repayment_amount,
             CASE WHEN l.repayment_amount>0 THEN CEIL(l.amount/l.repayment_amount) ELSE 0 END,
             CASE WHEN l.repayment_amount>0 THEN l.amount-(l.repayment_amount*GREATEST(0,CEIL(l.amount/l.repayment_amount)-1)) ELSE l.amount END,
             l.notes,l.repayment_method,l.created_by
      FROM loans l LEFT JOIN loan_agreements a ON a.loan_id=l.id WHERE a.id IS NULL");
}

function loanAgreementStatusLabel(string $status): string {
    $labels = [
        'draft' => 'Draft',
        'employee_pending' => 'Awaiting Employee Signature',
        'employee_signed' => 'Awaiting Owner Signature',
        'owner_signed' => 'Awaiting Employee Signature',
        'fully_signed' => 'Fully Signed / Active',
        'legacy_active' => 'No Agreement',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function loanAgreementNextDeductionDate(DateTimeImmutable $date, int $preferredDay, bool $monthEnd): DateTimeImmutable {
    $nextMonth = $date->modify('first day of next month');
    if ($monthEnd) return $nextMonth->modify('last day of this month');
    $day = min($preferredDay, (int)$nextMonth->format('t'));
    return $nextMonth->setDate((int)$nextMonth->format('Y'), (int)$nextMonth->format('m'), $day);
}

function loanAgreementCanonical(array $loan, array $agreement, array $employee): array {
    $principal = round((float)($loan['amount'] ?? $agreement['amount'] ?? 0), 2);
    $instalment = round((float)($agreement['instalment_amount'] ?? 0), 2);
    $count = (int)($agreement['number_of_instalments'] ?? 0);
    $firstDate = (string)($agreement['first_deduction_date'] ?? '');
    $schedule = [];
    $remaining = $principal;
    if ($principal > 0 && $instalment > 0 && $count > 0 && $firstDate !== '') {
        $date = new DateTimeImmutable($firstDate);
        $preferredDay = (int)$date->format('j');
        $monthEnd = $preferredDay === (int)$date->format('t');
        for ($i = 1; $i <= $count; $i++) {
            $amount = round(min($instalment, $remaining), 2);
            $remaining = round(max(0, $remaining - $amount), 2);
            $schedule[] = [
                'instalment_no' => $i,
                'due_date' => $date->format('Y-m-d'),
                'amount' => number_format($amount, 2, '.', ''),
                'balance_after' => number_format($remaining, 2, '.', ''),
            ];
            $date = loanAgreementNextDeductionDate($date, $preferredDay, $monthEnd);
        }
    }
    return [
        'agreement_id' => (int)($agreement['id'] ?? 0),
        'version' => (int)($agreement['version_no'] ?? 1),
        'employee_id' => (int)($loan['employee_id'] ?? 0),
        'employee_name' => trim((string)($employee['emp_name'] ?? (($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')))),
        'employee_number' => (string)($employee['emp_number'] ?? ''),
        'job_title' => trim((string)($employee['job_title'] ?? 'Employee')),
        'employer_name' => function_exists('getSetting') ? (string)getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : 'Hambelela Organic') : (defined('COMPANY_NAME') ? COMPANY_NAME : 'Hambelela Organic'),
        'agreement_number' => 'LA-' . (int)($loan['loan_record_id'] ?? $loan['loan_id'] ?? $agreement['loan_id'] ?? $loan['id'] ?? 0) . '-V' . (int)($agreement['version_no'] ?? 1),
        'loan_amount' => number_format($principal, 2, '.', ''),
        'interest_rate' => '0.00',
        'total_repayable' => number_format($principal, 2, '.', ''),
        'agreement_date' => (string)($agreement['agreement_date'] ?? ''),
        'draft_created_date' => (string)($agreement['agreement_date'] ?? ''),
        'sent_date' => !empty($agreement['sent_at']) ? substr((string)$agreement['sent_at'], 0, 10) : '',
        'effective_date' => !empty($agreement['fully_signed_at']) ? substr((string)$agreement['fully_signed_at'], 0, 10) : '',
        'first_deduction_date' => (string)($agreement['first_deduction_date'] ?? ''),
        'instalment_amount' => number_format((float)($agreement['instalment_amount'] ?? 0), 2, '.', ''),
        'number_of_instalments' => (int)($agreement['number_of_instalments'] ?? 0),
        'final_instalment_amount' => number_format((float)($agreement['final_instalment_amount'] ?? 0), 2, '.', ''),
        'repayment_method' => (string)($agreement['repayment_method'] ?? 'salary_deduction'),
        'purpose' => trim((string)($agreement['purpose'] ?? '')),
        'legal_notes' => trim((string)($agreement['legal_notes'] ?? '')),
        'schedule' => $schedule,
        'contract_revision' => 1,
    ];
}

function loanAgreementDocumentData(array $loan, array $agreement, array $employee): array {
    if (!empty($agreement['snapshot_json'])) {
        $snapshot = json_decode((string)$agreement['snapshot_json'], true);
        if (is_array($snapshot) && !empty($snapshot['agreement_number']) && !empty($snapshot['schedule'])) return $snapshot;
    }
    return loanAgreementCanonical($loan, $agreement, $employee);
}

function loanAgreementFormatDate(string $date): string {
    return $date !== '' && strtotime($date) ? date('d F Y', strtotime($date)) : '—';
}

function loanAgreementRenderDocument(array $data, array $signatures = [], array $options = []): void {
    $h = function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $money = function ($value): string { return 'N$' . number_format((float)$value, 2); };
    $signatureByRole = [];
    foreach ($signatures as $signature) $signatureByRole[(string)$signature['signer_role']] = $signature;
    $employeeSignature = $signatureByRole['employee'] ?? null;
    $ownerSignature = $signatureByRole['owner'] ?? null;
    $effectiveDate = ($employeeSignature && $ownerSignature) ? substr(max((string)$employeeSignature['signed_at'], (string)$ownerSignature['signed_at']), 0, 10) : (string)($data['effective_date'] ?? '');
    $first = loanAgreementFormatDate((string)$data['first_deduction_date']);
    $finalDate = !empty($data['schedule']) ? loanAgreementFormatDate((string)$data['schedule'][count($data['schedule']) - 1]['due_date']) : '—';
    ?>
    <article class="loan-document" data-agreement-number="<?=$h($data['agreement_number'])?>">
      <header class="loan-document-header">
        <span class="loan-document-kicker">Employee Loan Agreement</span>
        <h2>EMPLOYEE LOAN AGREEMENT</h2>
        <div class="loan-document-meta"><span><b>Agreement Number</b><?=$h($data['agreement_number'])?></span><span><b>Draft Created</b><?=loanAgreementFormatDate((string)($data['draft_created_date'] ?? $data['agreement_date']))?></span><span><b>Effective / Agreement Date</b><?=$effectiveDate !== '' ? loanAgreementFormatDate($effectiveDate) : 'Pending full signature'?></span></div>
      </header>
      <section class="loan-parties"><div><span>Employer</span><strong><?=$h($data['employer_name'])?></strong><small>(“the Employer”)</small></div><div><span>Employee</span><strong><?=$h($data['employee_name'])?></strong><small><?=$h($data['employee_number'])?> · <?=$h($data['job_title'])?></small></div></section>
      <p>This Employee Loan Agreement (“Agreement”) is entered into between <strong><?=$h($data['employer_name'])?></strong> (“the Employer”) and <strong><?=$h($data['employee_name'])?></strong>, employee number <?=$h($data['employee_number'])?>, employed as <?=$h($data['job_title'])?> (“the Employee”).</p>
      <section class="loan-document-summary"><div><span>Principal</span><strong><?=$money($data['loan_amount'])?></strong></div><div><span>Interest</span><strong>0%</strong></div><div><span>Monthly Instalment</span><strong><?=$money($data['instalment_amount'])?></strong></div><div><span>Term</span><strong><?=(int)$data['number_of_instalments']?> months</strong></div><div><span>First Deduction</span><strong><?=$first?></strong></div><div><span>Total Repayable</span><strong><?=$money($data['total_repayable'])?></strong></div></section>
      <section class="loan-clause"><h3>1. LOAN</h3><p>The Employer agrees to advance to the Employee an employee loan in the principal amount of <strong><?=$money($data['loan_amount'])?></strong>.</p><p>The Employee acknowledges the loan and agrees to repay the amount in accordance with the terms of this Agreement.</p><p>Unless otherwise expressly stated in a signed version of this Agreement, the loan is interest-free and no additional administration fee or penalty is charged for the granting of the loan.</p></section>
      <section class="loan-clause"><h3>2. REPAYMENT TERMS</h3><p>The parties agree to the following repayment terms:</p><dl class="loan-terms"><div><dt>Principal</dt><dd><?=$money($data['loan_amount'])?></dd></div><div><dt>Interest</dt><dd>0%</dd></div><div><dt>Total Repayable</dt><dd><?=$money($data['total_repayable'])?></dd></div><div><dt>Number of Instalments</dt><dd><?=(int)$data['number_of_instalments']?></dd></div><div><dt>Monthly Instalment</dt><dd><?=$money($data['instalment_amount'])?></dd></div><div><dt>First Deduction</dt><dd><?=$first?></dd></div><div><dt>Expected Final Deduction</dt><dd><?=$finalDate?></dd></div></dl><p>The Employee agrees that the loan will ordinarily be repaid through the agreed deductions from the Employee’s remuneration in accordance with the repayment schedule forming part of this Agreement.</p></section>
      <section class="loan-clause"><h3>3. PAYROLL DEDUCTION AUTHORISATION</h3><p>The Employee voluntarily and expressly authorises the Employer, in writing, to deduct the agreed loan instalments from the Employee’s remuneration for the purpose of repaying this employee loan.</p><p>All deductions must remain subject to the Labour Act, 2007 and any other applicable Namibian law, including applicable limitations on deductions from remuneration.</p><p>If an agreed instalment cannot lawfully be deducted in full during a particular payroll period, the Employer must not deduct more than the amount lawfully permitted. Any portion that cannot lawfully be deducted remains part of the outstanding loan balance.</p></section>
      <section class="loan-clause"><h3>4. REPAYMENT SCHEDULE</h3><p>The repayment schedule for this Agreement is:</p><div class="hr-table-viewport"><table class="loan-document-schedule"><thead><tr><th>Instalment</th><th>Payroll Month</th><th>Scheduled Deduction</th><th>Balance After Scheduled Payment</th></tr></thead><tbody><?php foreach ($data['schedule'] as $row): ?><tr><td><?=(int)$row['instalment_no']?></td><td><?=date('F Y', strtotime((string)$row['due_date']))?></td><td><?=$money($row['amount'])?></td><td><?=$money($row['balance_after'])?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="2">Total</th><th><?=$money($data['total_repayable'])?></th><th><?=$money(0)?></th></tr></tfoot></table></div><p>The actual loan balance must be reduced by payments or lawful deductions recorded against the loan. The final instalment must never exceed the actual outstanding balance.</p></section>
      <section class="loan-clause"><h3>5. EARLY REPAYMENT</h3><p>The Employee may repay all or part of the outstanding loan balance before the scheduled repayment date without an early-payment penalty.</p><p>Any early payment must reduce the outstanding loan balance and the remaining repayment schedule should be recalculated where necessary.</p></section>
      <section class="loan-clause"><h3>6. TERMINATION OF EMPLOYMENT</h3><p>If the Employee’s employment ends before the loan has been repaid in full, whether by resignation, dismissal, retrenchment, expiry of employment or another lawful form of termination, the outstanding loan balance remains due.</p><p>The Employee authorises the Employer, subject to applicable Namibian law and lawful deduction limits, to deduct from final remuneration or other amounts payable to the Employee any amount that may lawfully be deducted toward the outstanding loan. The Employer must not deduct an amount that is prohibited by applicable law.</p><p>If the full outstanding balance cannot lawfully be deducted from amounts payable on termination, the remaining balance remains payable by the Employee. The Employer and Employee may agree in writing to a further repayment arrangement for any remaining balance.</p></section>
      <section class="loan-clause"><h3>7. RECORD OF PAYMENTS</h3><p>The Employer will maintain a record of payments and deductions applied to the loan. The Employee may view the loan balance and repayment history through the HR Portal where that functionality is available.</p></section>
      <section class="loan-clause"><h3>8. CHANGES TO THIS AGREEMENT</h3><p>Any material change to the loan amount, interest, repayment period, instalment amount, deduction commencement date or other material repayment terms must be agreed to in writing by the parties.</p><p>Once an Agreement version has been signed, its contents may not be silently altered. Any material amendment must create a new Agreement version requiring the necessary approval and signatures.</p></section>
      <section class="loan-clause"><h3>9. ELECTRONIC AGREEMENT AND SIGNATURE</h3><p>The parties agree that this Agreement may be concluded and signed electronically to the extent permitted by applicable Namibian law.</p><p>By applying an electronic signature, each party confirms that the signature is intended to identify that party and indicate approval and acceptance of the Agreement version being signed. The portal must bind each electronic signature to the exact immutable version of the Agreement presented to the signer.</p></section>
      <section class="loan-clause"><h3>10. EMPLOYEE ACKNOWLEDGEMENT AND CONSENT</h3><ul class="loan-ack-preview"><li>☐ I have read and understood this Employee Loan Agreement.</li><li>☐ I understand that the principal amount of my loan is <?=$money($data['loan_amount'])?>.</li><li>☐ I understand that my scheduled instalment is <?=$money($data['instalment_amount'])?>.</li><li>☐ I understand that deductions are scheduled to begin on <?=$first?>.</li><li>☐ I voluntarily authorise the agreed payroll deductions, subject to applicable Namibian law.</li><li>☐ I understand that an outstanding balance remains payable if my employment ends before the loan has been fully repaid.</li><li>☐ I have had the opportunity to ask questions about this Agreement before signing.</li></ul></section>
      <section class="loan-clause"><h3>11. ENTIRE AGREEMENT</h3><p>This Agreement and its repayment schedule record the agreed terms governing this employee loan. No verbal change should alter the recorded loan terms unless the change is properly recorded and agreed in accordance with this Agreement.</p></section>
      <?php if (!empty($data['legal_notes'])): ?><section class="loan-clause loan-additional-terms"><h3>ADDITIONAL AGREEMENT-SPECIFIC TERMS</h3><p><?=nl2br($h($data['legal_notes']))?></p></section><?php endif; ?>
      <section class="loan-document-signatures"><h3>SIGNATURES</h3><div class="loan-signature-grid"><div><span>Employee</span><strong><?=$h($data['employee_name'])?></strong><small><?=$h($data['employee_number'])?></small><?php if ($employeeSignature): ?><b class="is-signed">SIGNED</b><em><?=$h($employeeSignature['signer_name'])?></em><small><?=loanAgreementFormatDate(substr((string)$employeeSignature['signed_at'], 0, 10))?> · <?=date('H:i', strtotime((string)$employeeSignature['signed_at']))?></small><?php else: ?><b>Awaiting Employee Signature</b><small>Signed: —</small><?php endif; ?></div><div><span>Employer</span><strong><?=$h($data['employer_name'])?></strong><small>Owner / Authorised Representative</small><?php if ($ownerSignature): ?><b class="is-signed">SIGNED</b><em><?=$h($ownerSignature['signer_name'])?></em><small><?=loanAgreementFormatDate(substr((string)$ownerSignature['signed_at'], 0, 10))?> · <?=date('H:i', strtotime((string)$ownerSignature['signed_at']))?></small><?php else: ?><b>Awaiting Employer Signature</b><small>Signed: —</small><?php endif; ?></div></div></section>
      <?php if (!empty($options['document_hash'])): ?><footer class="loan-document-verification"><b>Agreement verification reference</b><code><?=$h($options['document_hash'])?></code></footer><?php endif; ?>
    </article>
    <?php
}

function loanAgreementHash(array $loan, array $agreement, array $employee): string {
    return hash('sha256', json_encode(loanAgreementCanonical($loan, $agreement, $employee), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function loanAgreementEvent(PDO $db, int $agreementId, int $loanId, string $event, ?array $user, array $metadata = []): void {
    $stmt = $db->prepare("INSERT INTO loan_agreement_events (agreement_id,loan_id,event_type,actor_user_id,actor_role,metadata_json) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$agreementId, $loanId, $event, $user['id'] ?? null, $user['role'] ?? null, $metadata ? json_encode($metadata) : null]);
}

function loanAgreementNotify(PDO $db, int $employeeId, string $title, string $message, string $type = 'info', string $actionUrl = ''): void {
    $stmt = $db->prepare("SELECT id FROM users WHERE employee_id=? AND active=1 LIMIT 1");
    $stmt->execute([$employeeId]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        $db->prepare("INSERT INTO notifications (user_id,title,message,type,action_url) VALUES (?,?,?,?,?)")
           ->execute([(int)$userId, $title, $message, $type, $actionUrl ?: null]);
    }
}

function loanAgreementSchedule(PDO $db, int $agreementId, string $firstDate, float $principal, float $instalment): void {
    $db->prepare("DELETE FROM loan_repayment_schedule WHERE agreement_id=?")->execute([$agreementId]);
    if ($principal <= 0 || $instalment <= 0 || !$firstDate) return;
    $count = (int)ceil($principal / $instalment);
    $date = new DateTimeImmutable($firstDate);
    $remaining = round($principal, 2);
    $preferredDay = (int)$date->format('j');
    $monthEnd = $preferredDay === (int)$date->format('t');
    $insert = $db->prepare("INSERT INTO loan_repayment_schedule (agreement_id,instalment_no,due_date,amount) VALUES (?,?,?,?)");
    for ($i = 1; $i <= $count; $i++) {
        $amount = min($instalment, $remaining);
        $insert->execute([$agreementId, $i, $date->format('Y-m-d'), $amount]);
        $remaining = round($remaining - $amount, 2);
        $date = loanAgreementNextDeductionDate($date, $preferredDay, $monthEnd);
    }
}

function loanAgreementApplyRepayment(PDO $db, int $loanId, float $amount): void {
    $stmt = $db->prepare("SELECT id FROM loan_agreements WHERE loan_id=? AND status IN ('fully_signed','legacy_active') ORDER BY version_no DESC LIMIT 1");
    $stmt->execute([$loanId]); $agreementId = (int)$stmt->fetchColumn();
    if (!$agreementId || $amount <= 0) return;
    $rows = $db->prepare("SELECT * FROM loan_repayment_schedule WHERE agreement_id=? AND status!='paid' ORDER BY instalment_no FOR UPDATE");
    $rows->execute([$agreementId]); $remaining = round($amount,2);
    foreach ($rows->fetchAll() as $row) {
        if ($remaining <= 0) break;
        $due = round((float)$row['amount'] - (float)$row['paid_amount'],2);
        $applied = min($due,$remaining); $newPaid = round((float)$row['paid_amount']+$applied,2);
        $status = $newPaid >= (float)$row['amount'] ? 'paid' : 'part_paid';
        $db->prepare("UPDATE loan_repayment_schedule SET paid_amount=?,status=?,paid_at=IF(?='paid',NOW(),paid_at) WHERE id=?")
           ->execute([$newPaid,$status,$status,(int)$row['id']]);
        $remaining = round($remaining-$applied,2);
    }
}

function loanAgreementClientIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function loanAgreementUserAgent(): string {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function loanAgreementPdfText(string $text): string {
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted !== false) $text = $converted;
    }
    return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ')';
}

function loanAgreementPdfWrap(string $text, int $limit = 88): array {
    $words = preg_split('/\s+/', trim($text)); $lines = []; $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (strlen($candidate) > $limit && $line !== '') { $lines[] = $line; $line = $word; } else { $line = $candidate; }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

function renderLoanAgreementPdf(array $loan, array $agreement, array $employee, array $signatures): string {
    $canonical = loanAgreementDocumentData($loan, $agreement, $employee);
    $money = function ($value): string { return 'N$' . number_format((float)$value, 2); };
    $paragraphs = [
        ['EMPLOYEE LOAN AGREEMENT', true],
        ['Agreement Number: '.$canonical['agreement_number'].'    Agreement Date: '.loanAgreementFormatDate((string)$canonical['agreement_date']), false],
        ['Employer: '.$canonical['employer_name'], false],
        ['Employee: '.$canonical['employee_name'].' | '.$canonical['employee_number'].' | '.$canonical['job_title'], false],
        ['1. LOAN', true],
        ['The Employer agrees to advance to the Employee an employee loan in the principal amount of '.$money($canonical['loan_amount']).'. The Employee acknowledges the loan and agrees to repay it in accordance with this Agreement. Unless otherwise expressly stated in a signed version, the loan is interest-free and no administration fee or penalty is charged for granting it.', false],
        ['2. REPAYMENT TERMS', true],
        ['Principal: '.$money($canonical['loan_amount']).' | Interest: 0% | Total Repayable: '.$money($canonical['total_repayable']).' | Instalments: '.$canonical['number_of_instalments'].' | Monthly Instalment: '.$money($canonical['instalment_amount']).' | First Deduction: '.loanAgreementFormatDate((string)$canonical['first_deduction_date']), false],
        ['The loan will ordinarily be repaid through agreed deductions from remuneration in accordance with the repayment schedule forming part of this Agreement.', false],
        ['3. PAYROLL DEDUCTION AUTHORISATION', true],
        ['The Employee voluntarily and expressly authorises the Employer, in writing, to deduct the agreed loan instalments from remuneration to repay this employee loan. All deductions remain subject to the Labour Act, 2007 and other applicable Namibian law, including lawful limitations. An amount that cannot lawfully be deducted remains part of the outstanding balance.', false],
        ['4. REPAYMENT SCHEDULE', true],
    ];
    foreach ($canonical['schedule'] as $row) $paragraphs[] = [$row['instalment_no'].'. '.date('F Y',strtotime($row['due_date'])).' | '.$money($row['amount']).' | Balance '.$money($row['balance_after']), false];
    $paragraphs = array_merge($paragraphs, [
        ['Total scheduled repayment: '.$money($canonical['total_repayable']).'. The final instalment must never exceed the actual outstanding balance.', false],
        ['5. EARLY REPAYMENT', true],
        ['The Employee may repay all or part of the outstanding balance early without penalty. Any early payment reduces the outstanding balance and the remaining schedule should be recalculated where necessary.', false],
        ['6. TERMINATION OF EMPLOYMENT', true],
        ['If employment ends before full repayment, the outstanding balance remains due. Subject to applicable Namibian law and lawful deduction limits, the Employer may deduct any amount that may lawfully be deducted from final remuneration. Any remainder remains payable and may be governed by a further written repayment arrangement.', false],
        ['7. RECORD OF PAYMENTS', true],
        ['The Employer will maintain a record of payments and deductions. The Employee may view the balance and repayment history through the HR Portal where available.', false],
        ['8. CHANGES TO THIS AGREEMENT', true],
        ['Any material change to the amount, interest, period, instalment, deduction commencement date or repayment terms must be agreed in writing. A signed version may not be silently altered; a material amendment requires a new version and the necessary approvals and signatures.', false],
        ['9. ELECTRONIC AGREEMENT AND SIGNATURE', true],
        ['This Agreement may be concluded and signed electronically to the extent permitted by Namibian law. Each electronic signature identifies the signer and records approval of this exact immutable Agreement version.', false],
        ['10. EMPLOYEE ACKNOWLEDGEMENT AND CONSENT', true],
        ['The Employee confirms having read and understood this Agreement, the principal, instalment and deduction date; voluntarily authorises lawful payroll deductions; understands the termination balance; and had the opportunity to ask questions before signing.', false],
        ['11. ENTIRE AGREEMENT', true],
        ['This Agreement and its repayment schedule record the agreed terms. No verbal change alters them unless properly recorded and agreed in accordance with this Agreement.', false],
        ['SIGNATURES', true],
    ]);
    foreach ($signatures as $sig) $paragraphs[] = [ucfirst($sig['signer_role']).': '.$sig['signer_name'].' | Signed '.$sig['signed_at'], false];
    $paragraphs[] = ['Document SHA-256: '.($agreement['document_hash'] ?? ''), false];
    $lines = [];
    foreach ($paragraphs as $paragraph) {
        foreach (loanAgreementPdfWrap($paragraph[0], $paragraph[1] ? 74 : 88) as $wrapped) $lines[] = [$wrapped, $paragraph[1]];
        $lines[] = ['', false];
    }
    $pages = array_chunk($lines, 47);
    $pageCount = count($pages); $fontRegularId = 3 + ($pageCount * 2); $fontBoldId = $fontRegularId + 1;
    $kids = []; $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
    foreach ($pages as $pageIndex => $pageLines) {
        $pageId = 3 + ($pageIndex * 2); $contentId = $pageId + 1; $kids[] = $pageId.' 0 R';
        $content = ''; $y = 790;
        foreach ($pageLines as $line) { $font=$line[1]?'F2':'F1'; $size=$line[1]?11:9.5; $content .= "BT /$font $size Tf 1 0 0 1 54 $y Tm ".loanAgreementPdfText($line[0])." Tj ET\n"; $y -= $line[0]===''?7:14; }
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 '.$fontRegularId.' 0 R /F2 '.$fontBoldId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
        $objects[] = "<< /Length ".strlen($content).">>\nstream\n$content\nendstream";
    }
    $objects[1] = '<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$pageCount.' >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $i => $object) { $offsets[$i + 1] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
