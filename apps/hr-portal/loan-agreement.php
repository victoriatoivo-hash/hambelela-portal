<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/loan-agreements.php';
requireAdmin();

$db = db();
loanAgreementEnsureSchema($db);
$loanId = (int)($_GET['loan_id'] ?? 0);
$stmt = $db->prepare("SELECT l.*,a.*,a.id AS agreement_id,l.id AS loan_record_id,CONCAT(e.first_name,' ',e.last_name) AS emp_name,e.emp_number,e.first_name,e.last_name FROM loans l JOIN loan_agreements a ON a.loan_id=l.id JOIN employees e ON e.id=l.employee_id WHERE l.id=? AND a.status='fully_signed' ORDER BY a.version_no DESC LIMIT 1");
$stmt->execute([$loanId]);
$agreement = $stmt->fetch();
if (!$agreement) {
    http_response_code(404);
    exit('A fully signed agreement was not found.');
}
$signaturesStmt = $db->prepare("SELECT signer_role,signer_name,signed_at FROM loan_agreement_signatures WHERE agreement_id=? ORDER BY signed_at");
$signaturesStmt->execute([(int)$agreement['agreement_id']]);
$signatures = $signaturesStmt->fetchAll();
$pdf = renderLoanAgreementPdf($agreement,$agreement,$agreement,$signatures);
loanAgreementEvent($db,(int)$agreement['agreement_id'],$loanId,'owner_downloaded_pdf',currentUser());
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="loan-agreement-' . $loanId . '-v' . (int)$agreement['version_no'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;

