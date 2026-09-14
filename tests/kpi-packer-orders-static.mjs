import assert from 'node:assert/strict';
import fs from 'node:fs';

const service=fs.readFileSync('apps/operations/kpi-packer-orders.php','utf8');
const employee=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const owner=fs.readFileSync('apps/operations/reports-section-data.php','utf8');
const client=fs.readFileSync('assets/js/kpi-employee.js','utf8');
const settings=fs.readFileSync('apps/operations/reports.php','utf8');

for(const source of ["ops_order_display_datetime_expr('o')",'portal_imported_at','assigned_packer_id','assigned_at','packing_started_at','packed_at','completed_at','ops_order_items','kpi_sessions','ops_error_logs','Insufficient historical data','14:00:00']) assert.ok(service.includes(source),`missing ${source}`);
assert.match(employee,/kpi_packer_orders_evidence/);
assert.match(owner,/total_packed/);
assert.match(owner,/courier_on_time/);
assert.match(settings,/Minimum courier packing lead time/);
for(const label of ['Distinct Orders Packed','Total Items Packed','Collection Orders','Delivery Orders','Courier Orders','Average New → Packed Turnaround','Median New → Packed Turnaround','Average Assignment → Packing Start','Average Packing Time','Courier Ready Before 12:00','Courier Ready Before 14:00','Missed Courier Cut-off','Average Courier Ready Time','Orders Per Hour','Average Items Per Order','Heavy Orders Packed','Light Orders Packed','Packing Errors','Missing Items','Wrong Items','Returns Caused by Packing','Customer Complaints'])assert.ok(service.includes(label),`missing ${label}`);
for(const label of ['Assigned Time','Started Packing','Completed Packing','Total Weight','Packing Duration','New → Packed Duration'])assert.ok(client.includes(label),`missing ${label}`);
console.log('KPI historical packer-order checks passed.');
