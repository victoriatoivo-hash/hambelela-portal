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
        'employee_pending' => 'Pending Employee Signature',
        'employee_signed' => 'Employee Signed',
        'owner_signed' => 'Owner Signed',
        'fully_signed' => 'Fully Signed / Active',
        'legacy_active' => 'Legacy Active Loan',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function loanAgreementCanonical(array $loan, array $agreement, array $employee): array {
    return [
        'agreement_id' => (int)($agreement['id'] ?? 0),
        'version' => (int)($agreement['version_no'] ?? 1),
        'employee_id' => (int)($loan['employee_id'] ?? 0),
        'employee_name' => trim((string)($employee['emp_name'] ?? (($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')))),
        'employee_number' => (string)($employee['emp_number'] ?? ''),
        'loan_amount' => number_format((float)($loan['amount'] ?? 0), 2, '.', ''),
        'agreement_date' => (string)($agreement['agreement_date'] ?? ''),
        'first_deduction_date' => (string)($agreement['first_deduction_date'] ?? ''),
        'instalment_amount' => number_format((float)($agreement['instalment_amount'] ?? 0), 2, '.', ''),
        'number_of_instalments' => (int)($agreement['number_of_instalments'] ?? 0),
        'final_instalment_amount' => number_format((float)($agreement['final_instalment_amount'] ?? 0), 2, '.', ''),
        'repayment_method' => (string)($agreement['repayment_method'] ?? 'salary_deduction'),
        'purpose' => trim((string)($agreement['purpose'] ?? '')),
        'terms' => [
            'interest' => 'Interest-free unless a signed variation expressly states otherwise.',
            'deduction_authorisation' => 'The employee authorises the agreed payroll deductions until the balance is settled.',
            'early_repayment' => 'Early repayment is permitted without penalty and reduces future instalments.',
            'termination' => 'Any outstanding balance remains due on termination and may be deducted only to the extent permitted by law and written authorisation.',
            'variation' => 'No variation is valid unless recorded in a new agreement version and accepted by both parties.',
        ],
    ];
}

function loanAgreementHash(array $loan, array $agreement, array $employee): string {
    return hash('sha256', json_encode(loanAgreementCanonical($loan, $agreement, $employee), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function loanAgreementEvent(PDO $db, int $agreementId, int $loanId, string $event, ?array $user, array $metadata = []): void {
    $stmt = $db->prepare("INSERT INTO loan_agreement_events (agreement_id,loan_id,event_type,actor_user_id,actor_role,metadata_json) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$agreementId, $loanId, $event, $user['id'] ?? null, $user['role'] ?? null, $metadata ? json_encode($metadata) : null]);
}

function loanAgreementNotify(PDO $db, int $employeeId, string $title, string $message, string $type = 'info'): void {
    $stmt = $db->prepare("SELECT id FROM users WHERE employee_id=? AND active=1 LIMIT 1");
    $stmt->execute([$employeeId]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
           ->execute([(int)$userId, $title, $message, $type]);
    }
}

function loanAgreementSchedule(PDO $db, int $agreementId, string $firstDate, float $principal, float $instalment): void {
    $db->prepare("DELETE FROM loan_repayment_schedule WHERE agreement_id=?")->execute([$agreementId]);
    if ($principal <= 0 || $instalment <= 0 || !$firstDate) return;
    $count = (int)ceil($principal / $instalment);
    $date = new DateTimeImmutable($firstDate);
    $remaining = round($principal, 2);
    $insert = $db->prepare("INSERT INTO loan_repayment_schedule (agreement_id,instalment_no,due_date,amount) VALUES (?,?,?,?)");
    for ($i = 1; $i <= $count; $i++) {
        $amount = min($instalment, $remaining);
        $insert->execute([$agreementId, $i, $date->format('Y-m-d'), $amount]);
        $remaining = round($remaining - $amount, 2);
        $date = $date->modify('+1 month');
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
    $canonical = loanAgreementCanonical($loan, $agreement, $employee);
    $lines = [
        'HAMBELELA ORGANIC', 'EMPLOYEE LOAN AGREEMENT', '',
        'Agreement version: ' . $canonical['version'],
        'Employee: ' . $canonical['employee_name'] . ' (' . $canonical['employee_number'] . ')',
        'Agreement date: ' . $canonical['agreement_date'],
        'Principal amount: N$ ' . $canonical['loan_amount'],
        'Purpose: ' . ($canonical['purpose'] ?: 'Employee loan / advance'),
        'Repayment: N$ ' . $canonical['instalment_amount'] . ' per month from ' . $canonical['first_deduction_date'],
        'Instalments: ' . $canonical['number_of_instalments'] . ' (final instalment N$ ' . $canonical['final_instalment_amount'] . ')', '',
        'TERMS',
    ];
    foreach ($canonical['terms'] as $term) foreach (loanAgreementPdfWrap($term) as $wrapped) $lines[] = $wrapped;
    $lines[] = ''; $lines[] = 'SIGNATURES';
    foreach ($signatures as $sig) $lines[] = ucfirst($sig['signer_role']) . ': ' . $sig['signer_name'] . ' - ' . $sig['signed_at'];
    $lines[] = ''; $lines[] = 'Document SHA-256: ' . ($agreement['document_hash'] ?? '');
    $content = ''; $y = 790;
    foreach ($lines as $index => $line) {
        $size = $index < 2 ? ($index === 0 ? 15 : 13) : 10;
        $font = $index < 2 || $line === 'TERMS' || $line === 'SIGNATURES' ? 'F2' : 'F1';
        $content .= "BT /$font $size Tf 1 0 0 1 54 $y Tm " . loanAgreementPdfText($line) . " Tj ET\n";
        $y -= $index < 2 ? 22 : 15;
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
        "<< /Length " . strlen($content) . ">>\nstream\n$content\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $i => $object) { $offsets[$i + 1] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
