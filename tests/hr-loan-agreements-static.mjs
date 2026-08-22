import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const helper = read('apps/hr-portal/includes/loan-agreements.php');
const owner = read('apps/hr-portal/loans.php');
const employee = read('apps/hr-portal/my-loans.php');
const payroll = read('apps/hr-portal/payroll.php');
const pdf = read('apps/hr-portal/loan-agreement.php');
const install = read('apps/hr-portal/install.sql');

assert.match(helper, /CREATE TABLE IF NOT EXISTS loan_agreements/);
assert.match(helper, /CREATE TABLE IF NOT EXISTS loan_agreement_signatures/);
assert.match(helper, /CREATE TABLE IF NOT EXISTS loan_repayment_schedule/);
assert.match(helper, /CREATE TABLE IF NOT EXISTS loan_agreement_events/);
assert.match(helper, /hash\('sha256'/);
assert.match(helper, /UNIQUE KEY agreement_signer/);
assert.match(helper, /legacy_active/);

assert.match(owner, /requireAdmin\(\)/);
assert.match(owner, /one_third/);
assert.match(owner, /send_agreement/);
assert.match(owner, /owner_sign/);
assert.match(owner, /loan-agreement\.php\?loan_id=/);

assert.match(employee, /\$user\['role'\] !== 'employee'/);
assert.match(employee, /l\.employee_id=\?/);
assert.match(employee, /employee_sign/);
assert.match(employee, /accept_terms/);
assert.doesNotMatch(employee, /download=1/);

assert.match(pdf, /requireAdmin\(\)/);
assert.match(pdf, /a\.status='fully_signed'/);
assert.match(pdf, /Content-Type: application\/pdf/);

assert.match(payroll, /a\.status IN \('fully_signed','legacy_active'\)/);
assert.doesNotMatch(payroll, /FROM loans WHERE employee_id=\? AND status='active'/);

for (const table of ['loan_agreements','loan_agreement_signatures','loan_repayment_schedule','loan_agreement_events']) {
  assert.ok(install.includes(`CREATE TABLE IF NOT EXISTS \`${table}\``), `${table} must be installable`);
}

function schedule(principal, instalment) {
  const count = Math.ceil(principal / instalment);
  return Array.from({length: count}, (_, i) => i === count - 1 ? +(principal - instalment * (count - 1)).toFixed(2) : instalment);
}
assert.deepEqual(schedule(1000, 300), [300, 300, 300, 100]);
assert.deepEqual(schedule(900, 300), [300, 300, 300]);
assert.deepEqual(schedule(1200, 240), [240, 240, 240, 240, 240]);
assert.equal(schedule(1000, 300).reduce((a,b)=>a+b,0), 1000);
assert.match(owner, /first_deduction_date/);
assert.match(helper, /modify\('\+1 month'\)/);
assert.match(helper, /termination/);
assert.match(helper, /early_repayment/);
assert.match(helper, /loanAgreementRequireCsrf/);

console.log('HR loan agreement static and schedule checks passed.');
