import assert from 'node:assert/strict';
import fs from 'node:fs';

const data = fs.readFileSync(new URL('../apps/operations/packing-list-data.php', import.meta.url), 'utf8');
const action = fs.readFileSync(new URL('../apps/operations/packing-list-action.php', import.meta.url), 'utf8');
const notifications = fs.readFileSync(new URL('../shared/notifications.php', import.meta.url), 'utf8');
const sidebar = fs.readFileSync(new URL('../shared/sidebar.php', import.meta.url), 'utf8');
const packingJs = fs.readFileSync(new URL('../assets/js/packing-list.js', import.meta.url), 'utf8');
const viewBar = fs.readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const dashboard = fs.readFileSync(new URL('../index.php', import.meta.url), 'utf8');

for (const role of ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager']) {
  assert.match(data, new RegExp(role));
}
assert.match(data, /pt\.assigned_employee_id = \?/);
assert.match(data, /can_view_all_items/);
assert.match(packingJs, /!currentUser\.can_view_all_items/);
assert.doesNotMatch(sidebar, /consignments\.php\?assigned=me&unread=1/);
assert.match(dashboard, /dashboardPackingHref/);
assert.match(dashboard, /front_desk_admin_employee/);

assert.match(notifications, /related_type' => 'packing_loaded'/);
assert.match(notifications, /packing-loaded:' \. \$taskId/);
assert.match(notifications, /notifications_role_recipients\(\['front_desk_admin', 'front_desk_admin_employee'\]\)/);
assert.match(notifications, /SELECT DISTINCT n\.related_id/);
assert.match(notifications, /n\.related_type = 'packing_assignment'/);
assert.match(notifications, /n\.related_type = 'packing_loaded'/);
assert.match(action, /notifications_notify_packing_loaded\(\$newId\)/);
assert.match(action, /notifications_notify_packing_loaded\(\$importedId\)/);
assert.match(action, /notifications_close_packing_item_notifications/);

assert.match(viewBar, /type === 'packing'/);
assert.match(viewBar, /\[data-packing-refresh\]/);
assert.match(viewBar, /Packing List synchronized\./);
assert.match(viewBar, /\['\.stat-grid', '\.ledger-board'\]/);

console.log('Packing role visibility, Sync, and notification contracts passed.');
