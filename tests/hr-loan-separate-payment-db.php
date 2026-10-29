<?php
// Synthetic local-only regression; never load portal credentials or production config.
require_once dirname(__DIR__).'/apps/hr-portal/includes/loan-agreements.php';
date_default_timezone_set('Africa/Windhoek');
$db = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'hr_loan_test_'.bin2hex(random_bytes(6));
$db->exec('CREATE DATABASE `'.$schema.'`');
$db->exec('USE `'.$schema.'`');
$checks = 0;
function verify($expected, $actual, string $label): void {
    global $checks;
    if ($expected !== $actual) throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));
    $checks++;
    echo "PASS $label\n";
}
function value(string $query) { global $db; return $db->query($query)->fetchColumn(); }
function rejected(callable $action): bool {
    try { $action(); return false; } catch (DomainException $error) { return true; }
}
try {
    $db->exec("CREATE TABLE loans(id INT PRIMARY KEY,employee_id INT,amount DECIMAL(10,2),balance DECIMAL(10,2),repayment_amount DECIMAL(10,2),loan_date DATE,status VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE loan_agreements(id INT PRIMARY KEY,loan_id INT,version_no INT,status VARCHAR(40),document_hash VARCHAR(64)) ENGINE=InnoDB;
        CREATE TABLE loan_repayment_schedule(id INT PRIMARY KEY,agreement_id INT,amount DECIMAL(10,2),due_date DATE,status VARCHAR(24)) ENGINE=InnoDB;
        CREATE TABLE loan_agreement_events(id INT AUTO_INCREMENT PRIMARY KEY,agreement_id INT,loan_id INT,event_type VARCHAR(60),actor_user_id INT,actor_role VARCHAR(30),metadata_json TEXT) ENGINE=InnoDB;
        CREATE TABLE payroll_runs(id INT PRIMARY KEY,period_month INT,period_year INT,status VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE payslips(id INT PRIMARY KEY,run_id INT,employee_id INT,loan_disbursement DECIMAL(10,2),loan_deduction DECIMAL(10,2),net_salary DECIMAL(10,2),basic_salary DECIMAL(10,2)) ENGINE=InnoDB;");
    $year = (int)date('Y') - 1;
    $loanDate = "$year-10-23";
    $db->exec("INSERT INTO loans VALUES (1,101,5600,5600,1120,'$loanDate','active'),(2,102,2000,2000,400,'$loanDate','active');
        INSERT INTO loan_agreements VALUES(1,1,1,'fully_signed','unchanged-signed-hash'),(2,2,1,'fully_signed','other-hash');
        INSERT INTO loan_repayment_schedule VALUES(1,1,1120,'$year-10-30','scheduled'),(2,1,1120,'$year-11-30','scheduled'),(3,2,400,'$year-10-30','scheduled');
        INSERT INTO payroll_runs VALUES(1,10,$year,'finalised');
        INSERT INTO payslips VALUES(1,1,101,5600,1120,14480,10000),(2,1,102,2000,400,6600,5000);");
    $actor = ['id'=>900,'role'=>'admin'];
    verify(['disbursement'=>5600.0,'deduction'=>1120.0], loanAgreementPayrollAmounts($db,101,10,$year), 'reproduces principal plus instalment');
    verify(0.0, loanAgreementPayrollAmounts($db,101,9,$year)['deduction'], 'no deduction before schedule');
    verify(true, rejected(function() use($db,$loanDate) { loanAgreementRecordSeparatePayment($db,1,$loanDate,'Test',['id'=>101,'role'=>'employee']); }), 'employee cannot correct payroll');
    verify(true, rejected(function() use($db,$actor) { loanAgreementRecordSeparatePayment($db,1,'2099-10-23','Test',$actor); }), 'future payment rejected');
    verify(true, rejected(function() use($db,$actor) { loanAgreementRecordSeparatePayment($db,1,'2025-02-30','Test',$actor); }), 'invalid date rejected');
    verify(true, rejected(function() use($db,$actor,$loanDate) { loanAgreementRecordSeparatePayment($db,1,$loanDate,' ',$actor); }), 'empty confirmation rejected');
    loanAgreementRecordSeparatePayment($db,1,$loanDate,'Synthetic bank transfer confirmation',$actor);
    verify(['disbursement'=>0.0,'deduction'=>1120.0], loanAgreementPayrollAmounts($db,101,10,$year), 'separate payment removes payout only');
    verify('0.00', value('SELECT loan_disbursement FROM payslips WHERE id=1'), 'existing generated payout corrected');
    verify('8880.00', value('SELECT net_salary FROM payslips WHERE id=1'), 'net reduced by principal exactly once');
    verify('1120.00', value('SELECT loan_deduction FROM payslips WHERE id=1'), 'deduction retained');
    verify('10000.00', value('SELECT basic_salary FROM payslips WHERE id=1'), 'salary retained');
    verify('finalised', value('SELECT status FROM payroll_runs WHERE id=1'), 'run status retained');
    verify('5600.00', value('SELECT balance FROM loans WHERE id=1'), 'payment is not a repayment');
    verify('unchanged-signed-hash', value('SELECT document_hash FROM loan_agreements WHERE id=1'), 'signed agreement preserved');
    verify(3, (int)value('SELECT COUNT(*) FROM loan_repayment_schedule'), 'schedule preserved');
    verify('6600.00', value('SELECT net_salary FROM payslips WHERE id=2'), 'other employee unchanged');
    verify(1120.0, loanAgreementPayrollAmounts($db,101,11,$year)['deduction'], 'future instalment retained');
    $audit = json_decode(value("SELECT metadata_json FROM loan_agreement_events WHERE event_type='separate_payment_payslip_corrected'"),true);
    verify(14480, $audit['before']['net_salary'], 'original payslip audited');
    verify(8880, $audit['after']['net_salary'], 'corrected payslip audited');
    verify(900, (int)value("SELECT actor_user_id FROM loan_agreement_events WHERE event_type='loan_paid_separately'"), 'real correcting actor recorded');
    loanAgreementRecordSeparatePayment($db,1,$loanDate,'Retry',$actor);
    verify(2, (int)value('SELECT COUNT(*) FROM loan_agreement_events'), 'retry no duplicate events');
    verify('8880.00', value('SELECT net_salary FROM payslips WHERE id=1'), 'retry no double reduction');
    $db->exec('UPDATE payslips SET loan_disbursement=999 WHERE id=2');
    verify(true, rejected(function() use($db,$actor,$loanDate) { loanAgreementRecordSeparatePayment($db,2,$loanDate,'Test',$actor); }), 'ambiguous payout refuses correction');
    verify(null, loanAgreementSeparatePayment($db,2), 'ambiguous correction leaves no marker');
    verify('6600.00', value('SELECT net_salary FROM payslips WHERE id=2'), 'ambiguous correction no net change');
    $db->exec('UPDATE payslips SET loan_disbursement=0 WHERE id=2');
    loanAgreementRecordSeparatePayment($db,2,$loanDate,'Already excluded in payroll',$actor);
    verify('6600.00', value('SELECT net_salary FROM payslips WHERE id=2'), 'zero payout not subtracted again');
    $db->exec("INSERT INTO loans VALUES (3,103,600,600,100,'$loanDate','active'),(4,103,400,400,80,'$loanDate','active');
        INSERT INTO loan_agreements VALUES(3,3,1,'fully_signed','hash3'),(4,4,1,'fully_signed','hash4');
        INSERT INTO payslips VALUES(3,1,103,1000,180,5820,5000);");
    loanAgreementRecordSeparatePayment($db,3,$loanDate,'Only one of two loans paid separately',$actor);
    verify('400.00', value('SELECT loan_disbursement FROM payslips WHERE id=3'), 'other loan payout retained');
    verify('5220.00', value('SELECT net_salary FROM payslips WHERE id=3'), 'only selected loan removed');
    $db->exec("INSERT INTO loans VALUES (5,105,300,300,100,'$loanDate','active'); INSERT INTO loan_agreements VALUES(5,5,1,'fully_signed','hash5');");
    loanAgreementRecordSeparatePayment($db,5,$loanDate,'Paid before payroll generation',$actor);
    verify(0.0, loanAgreementPayrollAmounts($db,105,10,$year)['disbursement'], 'new payroll cannot reissue paid loan');
    echo "$checks checks passed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if (!preg_match('/^hr_loan_test_[a-f0-9]{12}$/D', $schema)) throw new RuntimeException('Unsafe fixture cleanup target');
    $db->exec('DROP DATABASE `'.$schema.'`');
}
