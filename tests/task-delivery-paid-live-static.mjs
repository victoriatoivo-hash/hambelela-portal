import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const operations = read('apps/operations/operations.php');
const tasks = read('apps/operations/checklists.php');
const notifications = read('shared/notifications.php');
const notificationApi = read('api/notifications.php');
const portal = read('assets/js/portal.js');
const orders = read('assets/js/orders-board.js');
const orderData = read('apps/operations/orders-board-data.php');
const orderAction = read('apps/operations/orders-board-action.php');
const orderPage = read('apps/operations/orders-board.php');

assert.match(operations, /function ops_task_scope_for_current_user/);
assert.match(operations, /return \$id > 0 \? \$id : null;/);
assert.doesNotMatch(operations, /INSERT INTO ops_employees[\s\S]*ON DUPLICATE KEY UPDATE/);
assert.match(tasks, /\$taskScope = ops_task_scope_for_current_user\(\)/);
assert.match(tasks, /beginTransaction\(\)[\s\S]*notifications_notify_task_assigned[\s\S]*commit\(\)/);
assert.match(notifications, /'required_delivery' => true/);
assert.match(notifications, /JOIN ops_checklist_tasks t[\s\S]*t\.assigned_employee_id = \?/);
assert.match(notificationApi, /notification_delivered.*notification_viewed.*notification_dismissed/s);

assert.equal((portal.match(/window\.setInterval\(/g) || []).length, 1, 'Portal updates must use one global interval.');
assert.match(portal, /window\.setInterval\(poll, 10000\)/);
assert.match(portal, /portal_last_seen_notification_id_\$\{portalUser\.id\}_\$\{portalUser\.role\}/);
assert.match(portal, /portal:task-update/);
assert.match(tasks, /addEventListener\('portal:task-update'/);
assert.match(orders, /addEventListener\('portal:live-tick', runLivePoll\)/);
assert.doesNotMatch(orders, /setTimeout\(runLivePoll/);

assert.match(orderData, /'can_edit_paid' => ops_can_update_order_paid_status\(\)/);
assert.match(orderAction, /hash_equals\(\$sessionCsrf, \$submittedCsrf\)/);
assert.match(orderAction, /paid_updated_by_employee_id = \?/);
assert.match(orderPage, /orders_csrf_token/);
assert.match(orders, /paidUpdatesInProgress/);
assert.match(orders, /form\.set\('csrf_token', config\.csrfToken/);

console.log('Task delivery, scoped live updates, and Paid permission static checks passed.');
