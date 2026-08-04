import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');
const [migration, operations, employee, reporting, ui, reports] = await Promise.all([
  read('operations-kpi-evidence-migration.sql'),
  read('apps/operations/operations.php'),
  read('apps/operations/kpi-employee-data.php'),
  read('apps/operations/kpi-reporting.php'),
  read('assets/js/kpi-employee.js'),
  read('apps/operations/reports.php'),
]);

for (const field of ['portal_section','record_type','record_id','employee_id','employee_name','action_key','previous_status','new_status','occurred_at','due_at','assigned_at','started_at','completed_at','related_reference','source_page','reason_note']) {
  assert.match(migration, new RegExp(`\\b${field}\\b`), `activity evidence must store ${field}`);
}
assert.match(operations, /function ops_kpi_record_event/);
assert.match(operations, /UTC_TIMESTAMP\(\)/, 'activity timestamps must be server generated');
assert.match(employee, /orders_attribution_adoption_date','2026-07-10'/);
assert.match(employee, /packing_list_adoption_date','2026-07-01'/);
assert.match(employee, /logged_by_name/);
assert.match(employee, /responsible_employee_name/);
assert.match(employee, /Errors reported by employee/);
assert.match(employee, /activity_timeline/);
assert.match(reporting, /function kpi_calculate_role_score/);
assert.match(reporting, /missing data is not scored as zero/);
assert.match(ui, /Complete Activity Timeline/);
assert.match(ui, /data-kpi-help/);
assert.match(ui, /Business handling/);
assert.match(ui, /Completed late/);
assert.match(reports, /packing_list_adoption_date/);
assert.match(reports, /activity_event_adoption_date/);

console.log('Defensible KPI evidence checks passed.');
