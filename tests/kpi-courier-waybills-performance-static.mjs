import fs from 'node:fs';
import assert from 'node:assert/strict';

const service = fs.readFileSync('apps/operations/kpi-courier-waybills-performance.php', 'utf8');
const employee = fs.readFileSync('apps/operations/kpi-employee-data.php', 'utf8');
const reports = fs.readFileSync('apps/operations/reports-section-data.php', 'utf8');
const settings = fs.readFileSync('apps/operations/reports.php', 'utf8');
const reportUi = fs.readFileSync('assets/js/reports-section.js', 'utf8');

assert.match(service, /Africa\/Windhoek/);
assert.match(service, /availableBeforeDeadline/);
assert.match(service, /blocked_late_upload/);
assert.match(service, /Sent after late availability/);
assert.match(service, /late_availability_response_minutes/);
assert.match(service, /following_applicable_day_rule/);
assert.match(service, /morning_inference_enabled/);
assert.match(service, /Combined waybill batch; waybill count unavailable/);
assert.doesNotMatch(service, /updated_at/);
assert.match(employee, /kpi_courier_waybills_performance/);
assert.match(reports, /kpi_courier_waybills_performance\(null/);
assert.match(settings, /courier_late_response_target_minutes[^\n]+'number', '0'/);
assert.match(reportUi, /format==='time'/);
console.log('Courier Waybills Performance fairness and integration checks passed.');
