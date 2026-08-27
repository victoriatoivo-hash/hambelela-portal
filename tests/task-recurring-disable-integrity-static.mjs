import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/checklists.php');
const notifications = read('shared/notifications.php');
const scheduling = read('shared/task-scheduling.php');
const reminders = read('shared/task-reminders.php');

assert.match(page, /function checklist_deactivate_recurring_occurrences/);
assert.match(page, /status='cancelled', employee_visible=0, performance_scored=0/);
assert.match(page, /status NOT IN \('complete','completed','done','approved'\)/, 'completed history is excluded from cancellation');
assert.match(page, /historical_completed_occurrences_preserved' => true/);
assert.match(page, /SET nr\.read_at=COALESCE\(nr\.read_at,NOW\(\)\), nr\.cleared_at=COALESCE/);
assert.match(page, /recurring_schedule_paused/);
assert.match(page, /recurring_schedule_ended/);
assert.match(page, /inactive_parent_migration/);
assert.match(page, /status NOT IN \('new', 'in_progress', 'complete', 'cancelled'\)/, 'cancelled children cannot be migrated back to new');

for (const source of [notifications, scheduling, reminders]) {
  assert.match(source, /recurring_template_id IS NULL/);
  assert.match(source, /rt\.is_active=1/);
  assert.match(source, /COALESCE\(rt\.status,'active'\)='active'/);
  assert.match(source, /cancelled/);
}

assert.match(notifications, /function notifications_notify_task_assigned[\s\S]*if \(!\$eligible\) return null;/);
assert.match(scheduling, /task_release_due_scheduled_tasks[\s\S]*LEFT JOIN ops_checklist_recurring_templates/);
assert.match(reminders, /notifications_schedule_task_reminders[\s\S]*LEFT JOIN ops_checklist_recurring_templates/);

console.log('Recurring disable lifecycle integrity checks passed.');
