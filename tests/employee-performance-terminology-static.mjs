import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const sidebar = read('shared/sidebar.php');
const features = read('shared/employee-features.php');
const dashboard = read('index.php');
const reports = read('apps/operations/reports.php');
const employee = read('apps/operations/kpi-employee.php');
const employeeJs = read('assets/js/kpi-employee.js');
const employeesJs = read('assets/js/reports-employees.js');
const businessHealthJs = read('assets/js/reports-business-health.js');
const performanceJs = read('assets/js/reports-performance.js');
const exportApi = read('apps/operations/reports-performance-reports-data.php');

assert.match(sidebar, /'label' => 'Employee Performance'/);
assert.match(features, /'Employee Performance'/);
assert.match(dashboard, /'name' => 'Employee Performance'/);
assert.match(reports, /<p class="eyebrow">Employee Performance<\/p>/);
assert.match(reports, /aria-label="Employee Performance sections"/);
assert.match(reports, />Performance Settings<\/a>/);
assert.match(employee, /’s Performance Profile<\/h1>/);
assert.match(employee, /aria-label="Employee Performance sections"/);
assert.match(employeeJs, /Performance evidence/);
assert.match(exportApi, /employee-performance-evidence-/);

for (const [name, source] of Object.entries({ sidebar, features, dashboard, reports, employee, employeeJs, employeesJs, businessHealthJs, performanceJs })) {
  assert.doesNotMatch(source, />[^<]*\bKPI\b[^<]*</, `${name} contains visible KPI text`);
  assert.doesNotMatch(source, /['"`]KPI (?:data|server|request|response|settings|sections|evidence)/, `${name} contains generated KPI interface text`);
}

assert.match(reports, />Business Health<\/a>/, 'Business Health must remain a separate tab');
assert.match(reports, /\$tab === 'business-health'/, 'Business Health routing must remain intact');
assert.match(sidebar, /'id' => 'kpi'/, 'technical navigation identifier must remain stable');
assert.match(employeeJs, /kpi-employee-data\.php/, 'technical API route must remain stable');

console.log('Employee Performance terminology contracts passed.');
