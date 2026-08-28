import assert from 'node:assert/strict';
import fs from 'node:fs';

const backend=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const service=fs.readFileSync('apps/operations/kpi-front-orders.php','utf8');
const client=fs.readFileSync('assets/js/kpi-employee.js','utf8');
const css=fs.readFileSync('assets/css/portal.css','utf8');

assert.match(backend,/require_once __DIR__ \. '\/kpi-front-orders\.php'/);
assert.match(backend,/kpi_front_orders_dashboard/);
assert.match(service,/ops_order_display_datetime_expr\('o'\)/);
assert.match(service,/kpi_front_order_completion_event/);
assert.match(service,/kpi_front_order_ready_event/);
assert.match(service,/\$clockStart=!empty\(\$row\['clock_start'\]\)/, 'the dashboard must preserve the mode-specific evidence clock');
assert.match(service,/New → Complete/);
assert.match(service,/Ready\/In Progress → Complete/);
assert.match(service,/payment_status/);
assert.match(service,/Complete but not paid/);
assert.match(service,/front_orders_walkin_weight/);
assert.match(service,/front_orders_nonwalk_weight/);
for(const label of ['Front Desk Orders in Scope','Walk-ins Assigned','Other Orders Completed','Orders Still Pending Completion','Completion Compliance','Unclear Historical Responsibility','Paid and Status Exceptions'])assert.match(service,new RegExp(label));
assert.match(service,/\$walkIn&&\$packedByEmployee/, 'walk-ins must use the assigned employee shown on Orders');
assert.match(service,/Every widget uses this same in-scope order set/, 'the dashboard must disclose its common reconciliation set');
assert.doesNotMatch(service,/\$base\['metrics'\]=\[\['label'=>'Total Applicable Orders'/, 'the final dashboard layer must not restore the obsolete Total Applicable Orders label');
assert.doesNotMatch(service,/\$base\['metrics'\]=/, 'the dashboard presentation layer must not override the reconciled evidence metrics');
assert.match(service,/\$eventsByOrder=\$base\['_events_by_order'\]/, 'the dashboard must reuse the reconciliation event map instead of loading all events twice');
assert.match(service,/LIMIT 2000/, 'monthly Front Desk reconciliation must not truncate at the former 500-order cap');
for(const evidence of ['portal_paid_decided_at','paid_updated_at','payment_updated_at','payment_status_updated'])assert.match(service,new RegExp(evidence),`payment audit must use ${evidence}`);
for(const state of ['Awaiting driver payment','Paid — awaiting completion','Paid — completion overdue','Awaiting customer collection','cancellation review','Complete but not paid — Requires review'])assert.match(service,new RegExp(state.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')),`mode-aware result must include ${state}`);
assert.match(service,/front_orders_close_after_payment_minutes'\]\?\?120/, 'delivery close timing must default to a two-business-hour target');
assert.match(client,/Paid at.*Paid by.*Complete.*Completed By/s, 'order evidence must show payment and completion actors and timestamps together');
assert.match(client,/cache:'no-store'/, 'employee performance loads must bypass stale browser JSON');
assert.match(client,/_:String\(Date\.now\(\)\)/, 'employee performance loads must use a cache-busting request key');
for(const label of ['Front Desk Orders in Scope','Walk-ins Assigned','Other Orders Completed','Clock start','Business duration','Applicable Orders by Fulfilment Mode','Completion Performance by Fulfilment Mode','Orders score explanation','Risk flags'])assert.match(client,new RegExp(label));
assert.match(css,/kpi-front-score__ring/);
assert.match(css,/@media\(prefers-reduced-motion:reduce\)/);
assert.match(backend,/You may view only order evidence affecting your own KPI/);

console.log('KPI front-orders static checks passed.');
