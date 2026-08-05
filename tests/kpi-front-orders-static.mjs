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
assert.match(service, /actor_user_id/);
assert.match(service, /Invalid timestamp sequence/);
assert.match(service, /kpi_front_order_completion_event/);
assert.match(service, /Status-change Activity Log — Complete transition/);
assert.match(service, /Unresolved Historical Attribution — Packed By fallback only/);
assert.match(service, /elseif\(\$completedById>0\)\{\$counts\['packer_only_removed'\]\+\+;continue;\}/, 'orders completed by someone else must be excluded');
assert.match(service, /kpi_business_minutes\(\$created,\$completed,\$holidays\)/, 'front-desk time must be New Order to Complete');
assert.doesNotMatch(service, /kpi_business_minutes\(\$inProgress,\$completed/, 'front-desk time must not start at In Progress');
assert.match(service, /orders_walk_in_identifiers/);
assert.match(service, /front_orders_walkin_grace_minutes/);
assert.match(service, /front_orders_walkin_weight/);
assert.match(service, /front_orders_nonwalk_weight/);
assert.match(settings, /front_orders_walkin_weight/);
assert.match(settings, /orders_walk_in_identifiers/);
assert.match(settings, /front_orders_walkin_grace_minutes/);
assert.match(settings, /front-orders component shares must total 100/);
for (const label of ['Total Applicable Orders','Walk-in Orders','Other Front Desk Orders','Completed Orders','Completed On Time','Completed Late','Outstanding Orders','Completion Compliance','Average New-to-Complete','Median New-to-Complete','Fastest Completion','Slowest Completion','Oldest Outstanding','Unresolved Historical Attribution']) assert.match(service, new RegExp(label));
for (const label of ['Walk-in and Front Desk Order Completion','Walk-in identifier','Packed By','Status before','Status after','New Order','Complete','Completed By','New-to-Complete business duration','Attribution source','Evidence ID']) assert.match(client, new RegExp(label));
assert.match(backend, /Evidence-first Front Desk attribution — unresolved history excluded from timing and score/);
assert.match(backend, /'counts'=>\$frontOrders\['counts'\]/);
assert.match(backend, /You may view only order evidence affecting your own KPI/);

console.log('KPI front-orders static checks passed.');
