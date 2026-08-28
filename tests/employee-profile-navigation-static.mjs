import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/kpi-employee.php', 'utf8');
const index = fs.readFileSync('apps/operations/reports.php', 'utf8');
const client = fs.readFileSync('assets/js/kpi-employee.js', 'utf8');
const indexClient = fs.readFileSync('assets/js/reports-employees.js', 'utf8');
const data = fs.readFileSync('apps/operations/kpi-employee-data.php', 'utf8');

for (const label of ['Order & Packing Performance','Bookkeeping','Waybill Status Management','HR and Leave','Website Updates','Errors and Quality','Activity Log']) {
  assert.match(page, new RegExp(`'[^']+'=>'${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`), `${label} must be an employee profile tab`);
}
assert.match(index, /data-kpi-employee-tabs/, 'Employees must expose employee-name tabs');
assert.match(indexClient, /data-kpi-employee-tabs/, 'employee-name tabs must use the existing Employees response');
assert.doesNotMatch(indexClient, /Weighted points|kpi-employee-card|kpi-sparkline/, 'the Employees tab must not retain obsolete duplicate summary cards');
assert.match(indexClient, /kpi-employee-directory-card/, 'employee tabs must render as portal-themed profile selectors');
assert.match(index, /Choose an employee/, 'the Employees page must introduce the employee directory clearly');
assert.doesNotMatch(index, /\$tab === 'employees'[\s\S]{0,500}data-kpi-period/, 'the employee directory must not show a reporting-period selector');
assert.match(page, /kpi_performance_employee_predicate/, 'employee tabs must use performance eligibility');
assert.match(page, /require_once __DIR__ \. '\/kpi-reporting\.php'/, 'employee profile must load the shared performance eligibility helper');
for (const label of ['Business Health','Employees','Performance Reports','Business Activity Timeline','Audit Log','Performance Settings']) {
  assert.match(page, new RegExp(`>${label}<`), `${label} must remain available inside an employee profile`);
}
assert.match(client, /activateEmployeeSection/, 'employee profile tabs must switch focused sections');
assert.match(client, /front_packing_list_kpi/, 'front-desk website work must be routed to Website Updates instead of duplicated under packing');
assert.match(client, /ensurePerformanceNavigation/, 'the profile must recover the persistent Performance navigation when production PHP markup is stale');
assert.match(client, /presentationSection\('hr-leave',s\.hr_leave\)/, 'HR and Leave must render from the existing employee response');
assert.match(data, /'hr_leave'=>/, 'employee response must expose linked HR leave evidence');
assert.match(data, /'website_updates'=>\['metrics'=>/, 'employee response must expose attributed Website Update evidence');
assert.doesNotMatch(page, /Presentation Mode|Export Report|>Print<|>Refresh</, 'obsolete employee header utilities must be removed');

console.log('Employee profile navigation checks passed.');
