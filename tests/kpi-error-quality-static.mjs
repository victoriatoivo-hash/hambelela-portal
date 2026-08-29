import assert from 'node:assert/strict';
import fs from 'node:fs';

const helper=fs.readFileSync(new URL('../apps/operations/kpi-error-quality.php',import.meta.url),'utf8');
const endpoint=fs.readFileSync(new URL('../apps/operations/kpi-employee-data.php',import.meta.url),'utf8');
const client=fs.readFileSync(new URL('../assets/js/kpi-employee.js',import.meta.url),'utf8');

assert.match(helper,/accuracy_verified_by/,'confirmed attribution must require owner verification');
assert.match(helper,/responsible_employee_id/,'responsible employee must be separate from reporter');
assert.match(helper,/logged_by/,'reporter identity must remain separate');
assert.match(helper,/new_status.*resolved/s,'resolution must use the first reliable resolved event');
assert.match(helper,/recurrence_interval.*preceding confirmed incident/s,'recurrence must be an incident-to-incident interval');
assert.match(helper,/unresolved_age.*Current time minus logged_at/s,'unresolved age must remain separate');
assert.match(helper,/financial_impact_verified'=>null/,'unavailable verified financial impact must not be inferred');
assert.match(helper,/reporting_summary/,'employee quality response must separate reporting performance');
assert.match(helper,/reporting_breakdown/,'employee quality response must group logged error types');
assert.match(client,/Errors Logged by Cecilia/,'reporting activity must be labelled clearly');
assert.match(helper,/Confirmed Errors Attributed to Cecilia/,'confirmed responsibility must be distinct from reporting');
assert.match(helper,/Only recorded amounts are summed/,'missing financial impact must not be inferred');
assert.match(endpoint,/'error'=>'error_log'/,'error Activity Log timeline must be available');
assert.match(endpoint,/kpi_error_quality_performance/,'employee KPI must use the dedicated calculation');
assert.match(client,/Errors and Quality/,'the dedicated section must be visible');
assert.match(client,/Recurrence interval/,'the focused evidence table must expose recurrence separately');
console.log('KPI Error & Quality static checks passed.');
