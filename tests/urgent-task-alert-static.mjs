import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8');
const portal = read('assets/js/portal.js');
const api = read('api/notifications.php');
const tasks = read('apps/operations/checklists.php');
const notifications = read('shared/notifications.php');

assert.match(portal, /\?mode=urgent/);
assert.match(portal, /window\.setInterval\(check, 12000\)/);
assert.match(portal, /urgent_delivered|urgent_\$\{state\}/);
assert.match(portal, /View Task/);
assert.match(portal, /textContent = active\.title/);
assert.match(portal, /history\.replaceState/);
assert.match(portal, /task_view=manual&task_id=/);
assert.match(portal, /onManualTasks/);
assert.match(tasks, /checklists\.php\?task_view=manual&task_id=/);
assert.match(api, /notifications_urgent_tasks_for_current_user/);
assert.match(notifications, /nr\.employee_id = \?/);
assert.match(notifications, /delivered_at.*read_at.*cleared_at/s);
assert.match(tasks, /resend_urgent_alert/);
assert.match(tasks, /Only management can resend urgent task alerts/);
assert.match(tasks, /urgent_alert_recipients\[\]/);

console.log('Urgent task alert static checks passed.');
