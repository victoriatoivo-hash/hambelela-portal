import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const schema = read('apps/hr-portal/includes/overtime-review.php');
const admin = read('apps/hr-portal/overtime.php');
const employee = read('apps/hr-portal/my-overtime.php');
const payroll = read('apps/hr-portal/payroll.php');
const install = read('apps/hr-portal/install.sql');

for (const field of ['approved_start_time','approved_end_time','approved_hours','approved_amount','review_outcome','adjustment_reason','payroll_run_id','payroll_processed_at']) {
  assert.match(schema, new RegExp(field), `runtime migration contains ${field}`);
  assert.match(install, new RegExp(field), `fresh install contains ${field}`);
}
assert.match(schema, /CREATE TABLE IF NOT EXISTS overtime_review_audit/);
assert.match(schema, /WHERE status='approved'/);
assert.doesNotMatch(schema, /DROP TABLE|TRUNCATE|DELETE FROM overtime/i);

assert.match(admin, /adjust_approve/);
assert.match(admin, /Approve as submitted/);
assert.match(admin, /Save adjustment & approve/);
assert.match(admin, /payroll_run_id/);
assert.match(admin, /hrLogOvertimeReview/);
assert.match(admin, /SUM\(approved_hours\)/);
assert.match(admin, /SUM\(approved_amount\)/);

assert.match(employee, /Adjusted &amp; approved/);
assert.match(employee, /approved_start_time/);
assert.match(employee, /approved_amount/);

assert.match(payroll, /SUM\(approved_amount\)/);
assert.match(payroll, /approved_amount IS NOT NULL/);
assert.match(payroll, /payroll_processed_at=NOW\(\)/);
assert.match(payroll, /payroll_run_id=NULL, payroll_processed_at=NULL/);

console.log('HR overtime review static safeguards passed.');
