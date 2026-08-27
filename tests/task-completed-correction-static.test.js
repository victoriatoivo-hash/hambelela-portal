const fs = require('fs');
const assert = require('assert');

const page = fs.readFileSync('apps/operations/checklists.php', 'utf8');
const notifications = fs.readFileSync('shared/notifications.php', 'utf8');
const bridge = fs.readFileSync('shared/epi/TaskActivityBridge.php', 'utf8');
const performance = fs.readFileSync('shared/epi/TaskPerformance.php', 'utf8');

assert.match(page, /CREATE TABLE IF NOT EXISTS ops_checklist_corrections/);
assert.match(page, /UNIQUE KEY uq_task_open_correction \(open_token\)/);
assert.match(page, /Only the Owner\/Admin may request or manage task corrections/);
assert.match(page, /Only a completed task can be reopened for correction/);
assert.match(page, /status='new'.*active_correction_id=/s);
assert.match(page, /completion_snapshot_json/);
assert.match(page, /task_correction_completed/);
assert.match(page, /data-task-correction-form/);
assert.match(page, /Correction history/);
assert.match(notifications, /function notifications_notify_task_correction/);
assert.match(notifications, /c\.status IN \('open','in_progress'\)/);
assert.match(bridge, /task_correction_requested/);
assert.match(bridge, /correction_round_count.*===0/);
assert.match(performance, /corrections_completed_on_time/);
assert.match(performance, /repeat_corrections/);

console.log('Completed-task correction static contract passed.');
