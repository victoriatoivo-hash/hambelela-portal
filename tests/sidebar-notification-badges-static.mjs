import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const notifications = read('shared/notifications.php');
const sidebar = read('shared/sidebar.php');
const api = read('api/notifications.php');
const portal = read('assets/js/portal.js');

assert.match(notifications, /function notifications_sidebar_counts_for_current_user\(\): array/);
assert.match(notifications, /nr\.employee_id = \? AND nr\.read_at IS NULL AND nr\.cleared_at IS NULL/);
assert.match(notifications, /GROUP BY n\.module/);
assert.match(notifications, /courier_waybill/);
assert.match(notifications, /\['order', 'order_sync'\]/);
assert.match(notifications, /task\.assigned_employee_id = \?/);
assert.match(notifications, /packing\.assigned_employee_id = \?/);
assert.match(api, /\$summary\['sidebar_counts'\] = notifications_sidebar_counts_for_current_user\(\)/);
assert.match(sidebar, /data-sidebar-notification-badge=/);
assert.match(sidebar, /\$isEmployeeSidebar/);
assert.match(sidebar, /'kpi' => 'kpi_dashboard'/);
assert.doesNotMatch(sidebar, /'kpi' => 'performance'/);
assert.match(portal, /updateSidebarModuleBadges/);
assert.match(portal, /nextCount > 99 \? '99\+'/);
assert.match(portal, /data\.sidebar_counts \|\| \{\}/);

console.log('Per-user sidebar notification badge safeguards passed.');
