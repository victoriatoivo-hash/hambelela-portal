import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const reporting = read('apps/operations/kpi-reporting.php');
const business = read('apps/operations/reports-data.php');
const sections = read('apps/operations/reports-section-data.php');
const employees = read('apps/operations/reports-employees-data.php');
const employee = read('apps/operations/kpi-employee-data.php');
const ordersAction = read('apps/operations/orders-board-action.php');
const ordersReport = read('apps/operations/orders.php');
const packingAction = read('apps/operations/packing-list-action.php');
const errorsPage = read('apps/operations/errors.php');
const reportsPage = read('apps/operations/reports.php');

for (const period of ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom']) {
  assert.match(reporting, new RegExp(`'${period}'`), `central resolver must support ${period}`);
}

for (const source of [business, sections, employees, employee]) {
  assert.match(source, /kpi-reporting\.php/, 'every KPI endpoint must load the shared reporting definitions');
  assert.match(source, /kpi_resolve_reporting_period/, 'every KPI endpoint must use the central period resolver');
}

assert.match(business, /kpi_paid_revenue_condition/, 'Business Health must use the shared paid-revenue definition');
assert.match(sections, /kpi_paid_revenue_condition/, 'Orders detail must use the shared paid-revenue definition');
assert.match(reporting, /payment_status\s*=\s*'paid'/, 'revenue must mean paid orders');
assert.match(reporting, /refunded/, 'revenue must exclude refunded orders');
assert.match(ordersReport, /kpi_paid_revenue_condition/, 'the Orders report must share the Business Health revenue definition');
assert.match(ordersReport, /status IN \('completed','packed','verified'\)/, 'the Orders report must share the completed-order definition');

for (const source of [employees, employee]) {
  assert.match(source, /assigned_packer_id/, 'packer credit must use the authoritative Packed By assignment');
  assert.match(source, /COUNT\(DISTINCT id\)/, 'order-board pack credit must count distinct orders');
}

assert.match(ordersAction, /\$oldKpiStatus === \$value/, 'repeated single-order status saves must short-circuit');
assert.match(ordersAction, /\$oldKpiStatus === \(string\) \$value/, 'repeated bulk status saves must not write KPI events');

assert.match(sections, /date_completed BETWEEN/, 'packing output must be selected by completion date');
assert.match(sections, /date_loaded<=p\.date_started AND p\.date_started<=p\.date_completed/, 'packing timings must enforce a valid timestamp sequence');
assert.match(sections, /Median elapsed packing time/, 'packing timing must expose a median');
assert.match(reporting, /coverage_percent/, 'shared KPI evidence envelopes must expose coverage');
assert.match(sections, /kpi_metric\(\$validCount/, 'packing timing must use the shared evidence envelope');
assert.match(packingAction, /workload_package_count/, 'workload calculation must store package count');
assert.match(packingAction, /workload_weight_grams/, 'workload calculation must store normalized weight');
assert.match(packingAction, /workload_volume_ml/, 'workload calculation must store normalized volume');
assert.match(packingAction, /pending_review/, 'unparseable workload must be flagged for review');
assert.match(packingAction, /packageEffort/, 'workload points must vary by package count');
assert.match(packingAction, /bulkEffort/, 'workload points must vary by weight or volume');

assert.match(sections, /TIMESTAMPDIFF\(MINUTE,p\.date_loaded,p\.frontdesk_website_updated_at\)/, 'website lag must start at date loaded');
assert.match(sections, /frontdesk_website_updated_at>=p\.date_loaded/, 'negative website lag must be excluded');
assert.match(sections, /Completed late/, 'task reports must distinguish completed-late work');
assert.match(sections, /Open overdue/, 'task reports must distinguish currently overdue work');
assert.match(sections, /Sent late/, 'waybill reports must distinguish late-sent work');
assert.match(sections, /Currently overdue/, 'waybill reports must distinguish currently overdue work');
assert.match(reporting, /function kpi_business_minutes/, 'business-hours duration must use one shared calendar calculation');
assert.match(sections, /Median business-hours handling time/, 'waybill reports must expose business-hours handling separately from elapsed time');
assert.match(sections, /Portal active time/, 'session-derived time must be labelled as portal active time');
assert.match(reporting, /kpi_merge_presence_rows/, 'attendance must merge overlapping portal-presence sessions');
assert.match(sections, /kpi_merge_presence_rows/, 'team attendance must use merged presence intervals');
assert.match(employee, /kpi_merge_presence_rows/, 'employee attendance must use merged presence intervals');
assert.doesNotMatch(sections, /\['label'=>'Total hours'/, 'session-derived time must not be labelled as attendance hours');
assert.match(employee, /scheduleConfigured/, 'lateness must require an employee schedule');
assert.match(employee, /kpi_employee_schedules/, 'attendance must load per-weekday employee schedules');
assert.match(employee, /\$employeeSchedule\[\$weekday\]/, 'lateness must resolve the schedule for the actual weekday');
assert.match(reportsPage, /saturday_shift_start/, 'KPI Settings must support a distinct Saturday shift');
assert.match(reportsPage, /weekday === 6/, 'Saturday must use its own configured shift');
assert.match(reportsPage, /frontdesk_weight_website/, 'KPI Settings must expose role-specific front-desk weights');
assert.match(reportsPage, /packer_weight_productivity/, 'KPI Settings must preserve the approved packer weight model');
assert.match(reportsPage, /composite_score_enabled', '0'/, 'saving weights must keep composite scoring disabled');
assert.match(sections, /ops_cash_book_entries/, 'Bookkeeping KPI evidence must use the live ledger entries table');
assert.match(sections, /Missing cash-ups/, 'Bookkeeping must report missing cash-ups instead of treating zero reconciliations as success');
assert.match(sections, /ops_hr_rows/, 'leave reporting must use the authoritative HR data source');
assert.match(sections, /workload_points_override,p\.workload_points/, 'packing breakdowns must use stored workload evidence, including approved overrides');
assert.match(employee, /workload_points_override,workload_points/, 'employee packing trends must use stored workload evidence');
assert.match(errorsPage, /responsible_employee_id/, 'error attribution must store a responsible employee separately from the logger');
assert.match(errorsPage, /affects_kpi_accuracy/, 'error attribution must explicitly control personal accuracy impact');
assert.match(errorsPage, /accuracy_verified_by/, 'personal accuracy attribution must require owner verification');
assert.match(sections, /l\.responsible_employee_id/, 'quality reports must use the responsible employee, not the logger');
assert.match(business, /scores_disabled'=>true/, 'Business Health rankings must remain disabled');
assert.match(employee, /'visible'=>false/, 'employee composite scores must remain hidden');

console.log('KPI data-integrity foundation checks passed.');
