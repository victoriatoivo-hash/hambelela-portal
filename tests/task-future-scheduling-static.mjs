import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const scheduler = fs.readFileSync(new URL('../shared/task-scheduling.php', import.meta.url), 'utf8');
const reminders = fs.readFileSync(new URL('../shared/task-reminders.php', import.meta.url), 'utf8');
const attachment = fs.readFileSync(new URL('../apps/operations/task-attachment.php', import.meta.url), 'utf8');
const proof = fs.readFileSync(new URL('../apps/operations/task-proof.php', import.meta.url), 'utf8');
const kpi = fs.readFileSync(new URL('../apps/operations/kpi-task-management-performance.php', import.meta.url), 'utf8');

assert.match(page, /name="task_mode" value="one_off"/);
assert.match(page, /name="task_mode" value="scheduled"/);
assert.match(page, /name="scheduled_at"/);
assert.match(page, /Due time must be after the task release time/);
assert.match(page, /release_scheduled_task/);
assert.match(page, /cancel_scheduled_task/);
assert.match(page, /'scheduled' => 'Scheduled'/);
assert.match(scheduler, /UPDATE ops_checklist_tasks SET released_at = \?, date_assigned = CASE WHEN \? = 1 THEN COALESCE\(date_assigned, \?\) ELSE date_assigned END, employee_visible = \?/);
assert.match(scheduler, /notifications_notify_task_assigned/);
assert.match(reminders, /releaseFilter[\s\S]*t\.scheduled_at IS NULL OR t\.released_at IS NOT NULL/);
assert.match(attachment, /t\.employee_visible = 1 AND \(t\.scheduled_at IS NULL OR t\.released_at IS NOT NULL\)/);
assert.match(proof, /employee_visible = 1 AND \(scheduled_at IS NULL OR released_at IS NOT NULL\)/);
assert.match(kpi, /COALESCE\(t\.released_at,t\.date_assigned\) BETWEEN/);
assert.match(kpi, /t\.scheduled_at IS NULL OR t\.released_at IS NOT NULL/);
assert.match(kpi, /\$releasedSource = !empty\(\$task\['released_at'\]\)/);
assert.match(page, /'overdue' => false,[\s\S]*'outcome' => 'Scheduled for release'/);
assert.match(page, /data-stat="scheduled"/);

console.log('Task future scheduling static checks passed.');
