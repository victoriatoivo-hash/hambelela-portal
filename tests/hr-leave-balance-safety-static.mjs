import fs from 'node:fs';
import assert from 'node:assert/strict';

const leave = fs.readFileSync(new URL('../apps/hr-portal/leave.php', import.meta.url), 'utf8');
const settings = fs.readFileSync(new URL('../apps/hr-portal/settings.php', import.meta.url), 'utf8');
const accrual = fs.readFileSync(new URL('../apps/hr-portal/accrue-leave.php', import.meta.url), 'utf8');
const service = fs.readFileSync(new URL('../apps/hr-portal/includes/leave-balance-service.php', import.meta.url), 'utf8');
const install = fs.readFileSync(new URL('../apps/hr-portal/install.php', import.meta.url), 'utf8');

for (const source of [leave, settings, accrual]) {
  assert.match(source, /leave-balance-service\.php/);
}

assert.match(service, /leave_accrual_ledger/);
assert.match(service, /UNIQUE KEY employee_type_period/);
assert.match(service, /INSERT IGNORE INTO leave_accrual_ledger/);
assert.match(service, /SUM\(days\)/);
assert.doesNotMatch(service, /days_taken/);
assert.doesNotMatch(accrual, /days_taken/);
assert.match(accrual, /hrLeaveRecoveryLocked/);
assert.match(accrual, /http_response_code\(423\)/);

assert.match(settings, /Yearly Reset Disabled/);
assert.doesNotMatch(settings, /DELETE FROM leave_balances/);
assert.match(settings, /hrSyncAnnualLeaveAccrual/);
assert.match(settings, /hash_equals\(\$settingsCsrfToken, \$csrf\)/);

assert.match(leave, /Approved leave is protected and cannot be deleted/);
assert.doesNotMatch(leave, /used_days\s*=\s*GREATEST\(0,used_days-\?\),\s*balance_days\s*=\s*balance_days\+\?/);
assert.match(leave, /WHERE id=\? AND status='pending'/);
assert.match(leave, /hrRefreshUsedLeave/);
assert.match(leave, /beginTransaction\(\)/);
assert.match(leave, /hash_equals\(\$leaveCsrfToken, \$csrf\)/);

assert.match(install, /'leave_accrual_ledger'/);

console.log('HR leave balance safety static checks passed.');
