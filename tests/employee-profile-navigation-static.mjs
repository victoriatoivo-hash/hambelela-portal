import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/kpi-employee.php', 'utf8');
const index = fs.readFileSync('apps/operations/reports.php', 'utf8');
const client = fs.readFileSync('assets/js/kpi-employee.js', 'utf8');
const indexClient = fs.readFileSync('assets/js/reports-employees.js', 'utf8');
const data = fs.readFileSync('apps/operations/kpi-employee-data.php', 'utf8');

for (const label of ['Orders Performance','Packing','Task Management','Bookkeeping','HR, Leave & Attendance','Errors and Quality','Activity Log']) {
  assert.match(page, new RegExp(`'[^']+'=>'${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`), `${label} must be an employee profile tab`);
}
assert.match(page, /'waybills'=>\$isPacker\?'Waybill Uploads':'Waybill Status Management'/, 'Waybill tab wording must follow the employee role');
assert.match(page, /\$showWebsiteUpdates\s*=\s*!\$isPacker\s*&&\s*\$employeeId\s*!==\s*2/, 'Cecilia and packers must not receive the duplicate Website Updates profile tab');
assert.match(page, /if\(\$showWebsiteUpdates\).*\['website'=>'Website Updates'\]/, 'other eligible profiles may retain Website Updates');
assert.match(page, /if\(!\$isPacker\).*\['bookkeeping'=>'Bookkeeping'\]/, 'Bookkeeping must not be shown on packer profiles');
assert.match(client, /showWebsiteUpdates\?presentationSection\('website',s\.website_updates\):''/, 'the duplicate Website Updates panel must not render for Cecilia');
assert.match(index, /data-kpi-employee-tabs/, 'Employees must expose employee-name tabs');
assert.match(indexClient, /data-kpi-employee-tabs/, 'employee-name tabs must use the existing Employees response');
assert.doesNotMatch(indexClient, /Weighted points|kpi-employee-card|kpi-sparkline/, 'the Employees tab must not retain obsolete duplicate summary cards');
assert.match(indexClient, /kpi-employee-directory-card/, 'employee tabs must render as portal-themed profile selectors');
assert.match(index, /Choose an employee/, 'the Employees page must introduce the employee directory clearly');
assert.doesNotMatch(index, /\$tab === 'employees'[\s\S]{0,500}data-kpi-period/, 'the employee directory must not show a reporting-period selector');
assert.match(page, /kpi-employee-breadcrumb/, 'employee profiles must show an Employees-to-person breadcrumb');
assert.match(page, /kpi-score-placeholder/, 'employee profiles must reserve a compact overall-score summary above the detailed sections');
assert.match(page, /Calculating verified score/, 'the reserved score summary must wait for verified live evidence');
assert.match(page, /Missing evidence is not counted as zero/, 'the score placeholder must explain how incomplete evidence is handled');
assert.doesNotMatch(page, /reports\.php\?tab=performance-reports|reports\.php\?tab=business-activity|reports\.php\?tab=audit-log|reports\.php\?tab=settings/, 'employee profiles must not repeat the top-level Performance navigation');
assert.match(client, /activateEmployeeSection/, 'employee profile tabs must switch focused sections');
assert.match(client, /packing:\['packing'\]/, 'Packing must have its own employee-profile tab');
assert.match(client, /Packing — Live Website Confirmation/, 'front-desk Packing must explain the live website confirmation duty');
assert.match(client, /8 counted business hours/, 'front-desk Packing must explain its counted-hours target');
assert.match(client, /presentationSection\('tasks',s\.tasks\)/, 'Task Management must reuse the existing employee task evidence');
assert.match(client, /presentationSection\('attendance',s\.attendance,data\.presence_summary/, 'HR, Leave and Attendance must surface the existing attendance evidence');
assert.match(client, /presentationSection\('hr-leave',s\.hr_leave\)/, 'HR and Leave must render from the existing employee response');
assert.match(data, /'hr_leave'=>/, 'employee response must expose linked HR leave evidence');
assert.match(data, /'website_updates'=>\['metrics'=>/, 'employee response must expose attributed Website Update evidence');
assert.doesNotMatch(page, /Presentation Mode|Export Report|>Print<|>Refresh</, 'obsolete employee header utilities must be removed');

console.log('Employee profile navigation checks passed.');
