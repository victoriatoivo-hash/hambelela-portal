import assert from 'node:assert/strict';
import fs from 'node:fs';

const service=fs.readFileSync('apps/operations/kpi-packer-orders.php','utf8');
const employee=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const owner=fs.readFileSync('apps/operations/reports-section-data.php','utf8');
const client=fs.readFileSync('assets/js/kpi-employee.js','utf8');
const settings=fs.readFileSync('apps/operations/reports.php','utf8');

assert.match(service,/ops_order_display_datetime_expr\('o'\)/);
assert.match(service,/portal_imported_at/);
assert.match(service,/previous_status/);
assert.match(service,/new_status/);
assert.match(service,/firstInProgress/);
assert.match(service,/actor_user_id/);
assert.match(service,/assigned_packer_id/);
assert.match(service,/Attribution mismatch/);
assert.match(service,/Invalid timestamp sequence/);
assert.match(service,/Packing timestamp unavailable/);
assert.match(service,/minimum_courier_packing_lead_minutes/);
assert.match(service,/14:00:00/);
assert.match(service,/kpi_business_minutes/);
assert.match(service,/walk_in/);
assert.match(employee,/kpi_packer_orders_evidence/);
assert.match(owner,/total_packed/);
assert.match(owner,/courier_on_time/);
assert.match(settings,/Minimum courier packing lead time/);
for(const label of ['Distinct Orders Packed','Average New-to-Packed Turnaround','Median New-to-Packed Turnaround','Courier Orders Packed','Courier Ready by 14:00','Deliveries Packed','Non-walk-in Collections Packed','Orders Completed Same Working Day','Orders Requiring Review'])assert.match(service,new RegExp(label));
for(const label of ['New → In Progress','Status changed by','Courier deadline','Deadline result','Attribution'])assert.match(client,new RegExp(label));

console.log('KPI packer-orders static checks passed.');
