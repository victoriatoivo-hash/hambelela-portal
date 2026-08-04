import assert from 'node:assert/strict';
import fs from 'node:fs';

const backend = fs.readFileSync('apps/operations/kpi-employee-data.php', 'utf8');
const service = fs.readFileSync('apps/operations/kpi-front-orders.php', 'utf8');
const client = fs.readFileSync('assets/js/kpi-employee.js', 'utf8');
const settings = fs.readFileSync('apps/operations/reports.php', 'utf8');

assert.match(backend, /require_once __DIR__ \. '\/kpi-front-orders\.php'/);
assert.match(service, /ops_order_display_datetime_expr\('o'\)/);
assert.match(service, /authoritative_created_at/);
assert.match(service, /portal_imported_at/);
assert.match(service, /in_progress_at/);
assert.match(service, /actor_user_id/);
assert.match(service, /Walk-in data mismatch/);
assert.match(service, /Invalid timestamp sequence/);
assert.match(service, /Completed by another employee - substitution not recorded/);
assert.match(service, /Approved leave/);
assert.match(service, /front_orders_walkin_weight/);
assert.match(service, /front_orders_nonwalk_weight/);
assert.match(settings, /front_orders_walkin_weight/);
assert.match(settings, /front-orders component shares must total 100/);
for (const label of ['Walk-ins Handled','Walk-ins Closed Correctly','Walk-in Compliance','Orders Finalised','Finalised Within One Working Day','Average Finalisation Time','Overdue Orders','Payment\/Status Exceptions']) assert.match(service, new RegExp(label));
for (const label of ['Packed By','Completed By','Front Queue Owner','View Activity']) assert.match(client, new RegExp(label));
assert.match(backend, /Provisional — timestamp sources under verification/);
assert.match(backend, /You may view only order evidence affecting your own KPI/);

console.log('KPI front-orders static checks passed.');
