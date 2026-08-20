import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const checklists = read('apps/operations/checklists.php');
const operations = read('apps/operations/operations.php');
const taskScheduling = read('shared/task-scheduling.php');

// The one-time backfill/index-creation statements for ops_checklist_tasks must only run once
// (guarded by the shared portal_schema_migrations table) instead of unconditionally on every page
// load, which risked lock contention with a concurrent task creation and made the Scheduled counter
// intermittently wrong right after saving a scheduled task.
assert.match(checklists, /CREATE TABLE IF NOT EXISTS portal_schema_migrations/);
assert.match(checklists, /\$checklistMigrationKey = '2026-08-19-checklist-scheduled-task-repair-v1'/);
assert.match(checklists, /if \(!\$checklistMigrationApplied\) \{[\s\S]*?UPDATE ops_checklist_tasks SET released_at = COALESCE\(released_at, date_assigned, created_at\) WHERE scheduled_at IS NULL AND released_at IS NULL[\s\S]*?INSERT IGNORE INTO portal_schema_migrations \(migration_key\) VALUES \(\?\)/);

// Creating a scheduled task must verify the scheduled state was genuinely persisted (direct read,
// not via ops_rows() which swallows query errors) before the owner is ever told it was scheduled.
assert.match(checklists, /if \(\$scheduledAt !== null\) \{[\s\S]{0,400}SELECT scheduled_at, released_at, employee_visible FROM ops_checklist_tasks WHERE id = \?/);
assert.match(checklists, /The task could not be confirmed as scheduled\. Nothing was saved/);

// The scheduled-create INSERT must still write scheduled_at, leave released_at NULL, and hide the
// task from the employee (employee_visible driven by $employeeVisible, 0 when scheduled).
assert.match(checklists, /\$employeeVisible = \$scheduledAt === null \? 1 : 0;/);
assert.match(checklists, /INSERT INTO ops_checklist_tasks\s*\n\s*\(checklist_type, task_name, priority, assigned_employee_id,assignment_mode,floating_eligible_group,floating_assigned_at,date_assigned, scheduled_at, released_at, deadline/);

// ops_rows() previously swallowed every query failure with zero trace; it must still degrade to an
// empty result (many call sites rely on that), but the failure must now be logged so a stat/list
// that silently comes back empty is diagnosable instead of invisible.
assert.match(operations, /catch \(Throwable \$e\) \{\s*\n\s*\/\/[\s\S]{0,300}error_log\('ops_rows query failed: '/);
assert.match(operations, /return \[\];\s*\n\s*\}\s*\n\}/);

// Release now / cancel / the Scheduled tab's counting query, and Windhoek-local release timing must
// be untouched by this fix.
assert.match(checklists, /if \(\$action === 'release_scheduled_task'\)/);
assert.match(checklists, /if \(\$action === 'cancel_scheduled_task'\)/);
assert.match(checklists, /SELECT COUNT\(\*\) AS total FROM ops_checklist_tasks WHERE scheduled_at IS NOT NULL AND released_at IS NULL AND archived_at IS NULL AND deleted_at IS NULL/);
assert.match(taskScheduling, /new DateTimeZone\('Africa\/Windhoek'\)/);
assert.match(taskScheduling, /WHERE scheduled_at IS NOT NULL AND scheduled_at <= \? AND released_at IS NULL/);

// The owner Scheduled tab is a release queue. It must not inherit an active-task
// date/status/overdue filter that can hide a correctly saved future task.
assert.match(checklists, /\$isScheduledOwnerView = \$canManage && \$filters\['task_view'\] === 'scheduled';/);
assert.match(checklists, /if \(!\$isScheduledOwnerView && \$filters\['date_from'\]/);
assert.match(checklists, /if \(!\$isScheduledOwnerView && \$filters\['status'\] !== ''\)/);
assert.match(checklists, /\['date_from', 'date_to', 'overdue_only', 'status'\]\.forEach\(\(name\) => requestUrl\.searchParams\.delete\(name\)\)/);
assert.match(checklists, /\? 't\.scheduled_at ASC, t\.id ASC'/);
assert.match(checklists, /function checklist_schedule_date_label/);

console.log('Checklist scheduled-task static checks passed.');
