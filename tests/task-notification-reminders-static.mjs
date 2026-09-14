import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const reminders = read('shared/task-reminders.php');
const notifications = read('shared/notifications.php');
const portal = read('assets/js/portal.js');
const css = read('assets/css/portal.css');

assert.match(reminders, /Africa\/Windhoek/);
assert.match(reminders, /deduplication_key/);
assert.match(reminders, /intdiv\(\(int\)\$now->format\('H'\), 2\)/);
assert.match(reminders, /audible<3/);
assert.match(reminders, /t\.assigned_employee_id<>nr\.employee_id/);
assert.match(notifications, /notifications_claim_task_delivery/);
assert.match(notifications, /nr\.delivered_at IS NULL/);
assert.match(notifications, /t\.status IS NOT NULL AND t\.status NOT IN \('complete','completed','done','archived','deleted','trashed','cancelled'\)/, 'completed and cancelled task notifications are excluded from the employee feed');
assert.equal((notifications.match(/t\.status IS NOT NULL AND t\.status NOT IN/g) || []).length, 2, 'both notification feed queries apply the completed-occurrence guard');
assert.match(portal, /notification_claim/);
assert.match(portal, /task-due-today\.mp3/);
assert.match(portal, /data-toast-snooze/);
assert.match(css, /rgba\(240, 116, 32, \.12\)/);
assert.match(css, /rgba\(187, 27, 33, \.11\)/);

for (const name of ['assigned', 'due-today', 'overdue', 'urgent']) {
  const file = new URL(`../assets/audio/task-${name}.mp3`, import.meta.url);
  const bytes = fs.readFileSync(file);
  assert.ok(bytes.length > 4000, `${name} sound is unexpectedly small`);
  assert.equal(bytes[0], 0xff, `${name} is not an MPEG audio stream`);
}

console.log('Task notification reminder static checks passed.');
