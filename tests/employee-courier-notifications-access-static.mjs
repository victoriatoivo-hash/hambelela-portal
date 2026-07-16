import fs from 'node:fs';
import assert from 'node:assert/strict';

const features = fs.readFileSync('shared/employee-features.php', 'utf8');
const sidebar = fs.readFileSync('shared/sidebar.php', 'utf8');
const courier = fs.readFileSync('apps/operations/courier.php', 'utf8');
const notifications = fs.readFileSync('shared/notifications.php', 'utf8');

assert.match(features, /\$roleKey !== 'guest' && in_array\(\$featureKey, \['notifications', 'courier'\], true\)/, 'Notifications and Courier must be employee-wide features.');
assert.match(features, /'\/notifications\.php' => \['notifications'/, 'Notifications route must remain protected by the feature layer.');
assert.match(features, /'\/apps\/operations\/courier\.php' => \['courier'/, 'Courier route must remain protected by the feature layer.');
assert.match(sidebar, /'operations-courier' => 'courier'/, 'Courier sidebar item must use the employee-wide feature key.');
assert.match(sidebar, /\/notifications\.php/, 'Notifications must remain visible in the shared employee sidebar.');
assert.match(courier, /\$canUploadWaybills = \$currentEmployeeId > 0 && \$roleKey !== 'guest';/, 'Every authenticated employee must be able to upload waybills.');
assert.match(courier, /\$canSendWaybills = in_array/, 'Send permissions must remain role-controlled.');
assert.match(notifications, /return \['operations', 'packing', 'tasks', 'system'\];/, 'Custom employee roles must receive relevant account notifications by default.');

console.log('Employee Courier and Notifications access checks passed.');
