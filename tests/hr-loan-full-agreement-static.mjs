import fs from 'node:fs';
import assert from 'node:assert/strict';

const agreements = fs.readFileSync('apps/hr-portal/includes/loan-agreements.php', 'utf8');
const owner = fs.readFileSync('apps/hr-portal/loan-view.php', 'utf8');
const employee = fs.readFileSync('apps/hr-portal/my-loans.php', 'utf8');
const loans = fs.readFileSync('apps/hr-portal/loans.php', 'utf8');
const pdf = fs.readFileSync('apps/hr-portal/loan-agreement.php', 'utf8');
const css = fs.readFileSync('apps/hr-portal/includes/loan-agreement-document.css', 'utf8');

for (const clause of ['1. LOAN','2. REPAYMENT TERMS','3. PAYROLL DEDUCTION AUTHORISATION','4. REPAYMENT SCHEDULE','5. EARLY REPAYMENT','6. TERMINATION OF EMPLOYMENT','7. RECORD OF PAYMENTS','8. CHANGES TO THIS AGREEMENT','9. ELECTRONIC AGREEMENT AND SIGNATURE','10. EMPLOYEE ACKNOWLEDGEMENT AND CONSENT','11. ENTIRE AGREEMENT']) {
  assert.ok(agreements.includes(clause), `missing full clause ${clause}`);
}
assert.ok(agreements.includes('loanAgreementRenderDocument'), 'shared full agreement renderer missing');
assert.ok(owner.includes("loanAgreementRenderDocument($agreementData"), 'owner does not use shared renderer');
assert.ok(employee.includes('loanAgreementRenderDocument($employeeAgreementData'), 'employee does not use shared renderer');
assert.ok(owner.includes('Preview as Employee'), 'employee preview action missing');
assert.ok(owner.includes('Send Loan Agreement') && owner.includes('Send Agreement'), 'send confirmation missing');
assert.ok(loans.includes("hash('sha256', $snapshot)"), 'hash must bind exact snapshot bytes');
assert.ok(employee.includes("$l['agreement_status'] !== 'draft'"), 'employee draft visibility is not blocked');
assert.ok(!loans.includes('> Send</button>'), 'loan list still bypasses review and confirmation');
assert.ok(pdf.includes('requireAdmin()'), 'PDF is not owner-only');
assert.ok(agreements.includes("array_chunk($lines, 47)"), 'full PDF pagination missing');
assert.ok(css.includes('max-width:960px') && css.includes('@media(max-width:430px)'), 'document width/mobile rules missing');
assert.ok(agreements.includes('loanAgreementNextDeductionDate'), 'payroll month-end schedule logic missing');

console.log('HR loan full agreement checks passed.');
