import assert from 'node:assert/strict';
import fs from 'node:fs';

const service=fs.readFileSync('apps/operations/kpi-packing-list-performance.php','utf8');
const employee=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const owner=fs.readFileSync('apps/operations/reports-section-data.php','utf8');
const client=fs.readFileSync('assets/js/kpi-employee.js','utf8');
const action=fs.readFileSync('apps/operations/packing-list-action.php','utf8');

assert.match(service,/date_loaded/);
assert.match(service,/portal_created_at/);
assert.match(service,/previous_status/);
assert.match(service,/new_status/);
assert.match(service,/firstInProgress|startEvent/);
assert.match(service,/doneEvent/);
assert.match(service,/packedlabelneeded/);
assert.match(service,/needscorrection/);
assert.match(service,/actor_user_id/);
assert.match(service,/Attribution conflict/);
assert.match(service,/Overpacked — Requires owner approval/);
assert.match(service,/Underpacked — Requires owner approval/);
assert.match(service,/kpi_business_minutes/);
assert.match(service,/packing_website_confirmed/);
assert.match(service,/frontdesk_website_updated/);
assert.match(service,/\$amount\*=1000/);
assert.match(action,/packing_website_completed_at/);
assert.match(action,/frontdesk_website_updated_at/);
assert.match(employee,/packing_list_performance/);
assert.match(owner,/exact_quantity_accuracy/);
for(const label of ['Items Assigned','Items Completed','Weight Packed','Liquid Volume Packed','Pieces Packed','Planned Package Weight','Recorded Package Weight','Planned Package Volume','Recorded Package Volume','Planned Package Pieces','Recorded Package Pieces','Quantity Matches','Supplier Variances Awaiting Review','Average Queue Time','Median Active Packing Time','Average Total Turnaround','Large-Volume Items','Needs Label','Confirmed Corrections','Packer Website Confirmations','Items Requiring Review'])assert.match(service,new RegExp(label));
assert.doesNotMatch(service,/label'=>'Units Requested'/);
assert.doesNotMatch(service,/label'=>'Units Packed'/);
for(const label of ['Packing Output and Accuracy','Queue time','Packing time','Total time','Website update'])assert.match(client,new RegExp(label));

console.log('KPI Packing List Performance static checks passed.');
