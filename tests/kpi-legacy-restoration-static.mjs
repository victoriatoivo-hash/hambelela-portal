import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {execFileSync} from 'node:child_process';

const read = path => fs.readFileSync(path, 'utf8');
const api = read('apps/operations/kpi-employee-data.php');
const profile = read('apps/operations/kpi-employee.php');
const ui = read('assets/js/kpi-employee.js');
const health = read('assets/js/reports-business-health.js');
const reports = read('apps/operations/reports.php');
const baseline = path => execFileSync('git', ['show', `b93ee80d6731:${path}`], {encoding:'utf8'}).replace(/\r\n/g,'\n');

// Restore presentation/default calculation, not schema or newer saved results.
assert.match(api, /in_array\(\$requestedAction,\['performance','performance_export'\],true\)/);
assert.doesNotMatch(api, /in_array\(\$requestedAction,\['','performance'/);
assert.match(api, /You may view only your own performance report/);
assert.match(profile, /You may view only your own performance report/);
assert.match(profile, /kpi-score-placeholder/);
assert.doesNotMatch(profile, /epi-profile-v5|performance-month/);
assert.equal(reports.replace(/\r\n/g,'\n'), baseline('apps/operations/reports.php'));
assert.equal(read('assets/css/performance-dashboard.css').replace(/\r\n/g,'\n'), baseline('assets/css/performance-dashboard.css'));
for (const helper of ['kpi-reporting.php','kpi-front-orders.php','kpi-packer-orders.php','kpi-packing-list-performance.php','kpi-task-management-performance.php','kpi-courier-waybills-performance.php','kpi-error-quality.php','kpi-bookkeeping-reconciliation.php']) {
  const path = `apps/operations/${helper}`;
  assert.equal(read(path).replace(/\r\n/g,'\n'), baseline(path), `${helper}: approved old calculation preserved`);
}
assert.match(read('apps/operations/kpi-front-orders.php'), /front_orders_walkin_completion_minutes'\]\?\?120/);
assert.match(read('apps/operations/kpi-packer-orders.php'), /target_fulfilment_hours'\]\?\?6/);

const monthAdapter = ui.slice(ui.indexOf('const bookmarkedMonth='), ui.indexOf('  const selected=query.get'));
for (const [search, expected] of [
  ['month=2026-11', ['custom','2026-11-01','2026-11-30']],
  ['month=2024-02', ['custom','2024-02-01','2024-02-29']],
  ['month=2026-02', ['custom','2026-02-01','2026-02-28']],
  ['month=2026-13', [null,null,null]],
  ['month=2026-11&period=this_month', ['this_month',null,null]],
]) {
  const query = new URLSearchParams(search);
  vm.runInNewContext(monthAdapter, {query, Date});
  assert.deepEqual(['period','date_from','date_to'].map(key=>query.get(key)), expected);
}
const scoreExpression = health.match(/const score = (person\.summary_score[^;]+);/)[1];
for (const [input, expected] of [[null,null],[undefined,null],['',null],[0,0],[88.1,88.1],['96.9',96.9],['invalid',null]]) {
  assert.equal(vm.runInNewContext(scoreExpression, {person:{summary_score:input},Number}), expected);
}
assert.match(ui, /data.employee.name = data.employee.name \|\| data.employee.full_name/);
assert.match(ui, /data.employee.role = data.employee.role \|\| data.employee.role_name/);
console.log('Legacy restoration: baseline formulas, 2h/6h defaults, access control, preserved V2 reads, month bookmarks and unavailable scores passed.');
