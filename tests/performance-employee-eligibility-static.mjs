import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const policy = read('apps/operations/kpi-reporting.php');
const overview = read('apps/operations/reports-data.php');
const index = read('apps/operations/reports-employees-data.php');
const page = read('apps/operations/reports.php');
const score = read('shared/epi/PerformanceScore.php');
const attendance = read('shared/epi/AttendancePerformance.php');

assert.match(policy, /NOT IN \('owner_admin', 'accountant'\)/, 'central policy must exclude non-employee roles');
assert.match(policy, /karina\|kaarina\|test\|preview/, 'central policy must exclude alias and preview identities');
assert.match(overview, /kpi_performance_employee_predicate\('e','r'\)/, 'overview and attendance denominator must use eligibility policy');
assert.match(index, /kpi_performance_employee_predicate\('e', 'r'\)/, 'employee comparison must use eligibility policy');
assert.match(page, /kpi_performance_employee_predicate\('e', 'r'\)/, 'performance settings must use eligibility policy');
assert.match(score, /This account is excluded from employee performance tracking/, 'direct score calculation must reject excluded accounts');
assert.match(attendance, /NOT IN \('owner_admin','accountant'\)/, 'attendance views must exclude non-employees');

console.log('Performance employee eligibility checks passed.');
