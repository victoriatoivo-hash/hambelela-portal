import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const reserve = read('apps/hr-portal/includes/leave-reserve.php');
const service = read('apps/hr-portal/includes/leave-balance-service.php');
const employeeLeave = read('apps/hr-portal/my-leave.php');
const employeeDashboard = read('apps/hr-portal/self-service.php');
const ownerEmployees = read('apps/hr-portal/employees.php');
const ownerLeave = read('apps/hr-portal/leave.php');
const saveEmployee = read('apps/hr-portal/save-employee.php');

assert.match(reserve, /function hrEmployeeIsOnProbation/);
assert.match(reserve, /function hrAnnualLeaveRequestAllowed/);
assert.match(reserve, /Accruing — available to request after successful completion of probation\./);
assert.match(reserve, /'sick_leave' => \['available' => true/);
assert.match(reserve, /'compassionate_leave' => \['available' => true/);

assert.match(service, /function hrAnnualAccruedForEmployee/);
assert.match(service, /function hrProbationAnnualAccrualStart/);
assert.match(service, /probation_annual_accrual_start_/);
assert.match(service, /employmentStart/);
assert.match(service, /function hrReconcileProbationAnnualLeave/);
assert.match(service, /approved usage .* preserved/);
assert.doesNotMatch(service, /DELETE FROM (?:leave_balances|leave_requests|audit_log)/i);

assert.match(employeeLeave, /Annual Leave is accruing but cannot be requested until probation has been completed successfully\./);
assert.match(employeeLeave, /\$lt==='Annual Leave'&&\$isProbation\?'disabled':''/);
assert.match(employeeLeave, /Employment Status: <\?=\$isProbation\?'Probation':'Active'\?>/);
assert.match(employeeLeave, /Sick Leave/);
assert.match(employeeLeave, /Compassionate Leave/);

assert.match(ownerLeave, /probation_annual_blocked/);
assert.match(ownerLeave, /\$req\['leave_type'\] === 'Annual Leave' && hrEmployeeIsOnProbation\(\$req\)/);
assert.match(ownerLeave, /Probation affects when leave may be requested, not whether entitlement accrues\./);

assert.match(ownerEmployees, /Leave Entitlements/);
assert.match(ownerEmployees, /Employment Status/);
assert.match(employeeDashboard, /Leave Entitlements/);
assert.match(saveEmployee, /hrAnnualAccruedForEmployee/);
assert.match(saveEmployee, /hrReconcileProbationAnnualLeave/);

console.log('HR probation leave entitlement static checks passed.');
