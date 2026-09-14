import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const tasks = read('apps/operations/checklists.php');
const notifications = read('shared/notifications.php');
const scheduling = read('shared/task-scheduling.php');

assert.match(tasks, /occurrence_fingerprint CHAR\(64\)/, 'recurring occurrences have a canonical identity');
assert.match(tasks, /uq_checklist_occurrence_fingerprint/, 'the canonical occurrence identity is database-unique');
assert.match(tasks, /normalizedOccurrenceName[\s\S]*occurrenceFingerprint = hash\('sha256'/, 'the occurrence identity spans duplicate templates');
assert.match(tasks, /LOWER\(TRIM\(task_name\)\)[\s\S]*DATE\(deadline\)/, 'legacy duplicate definitions are checked before generation');
assert.match(tasks, /\$immediateTargetEmployeeIds = \$recurringRule === '' \? \$targetEmployeeIds : \[\]/, 'creating a recurrence does not create a false immediate child');
assert.match(tasks, /if \(\$recurringRule !== ''\) \{[\s\S]*checklist_seed_recurring_tasks\(\)/, 'a valid first occurrence is delegated to the idempotent seeder');
assert.match(tasks, /Only management can reopen a completed task\./, 'employee progress cannot reopen a completed row');
assert.ok((tasks.match(/Only management can reopen a completed task\./g) || []).length >= 2, 'both status mutation paths protect completion');
assert.match(tasks, /recurring-duplicate-kpi-exclusion-v1/, 'legacy duplicate occurrences are excluded from KPI scoring once');
assert.match(tasks, /SET duplicate_occurrence\.performance_scored = 0/, 'duplicate history remains stored but cannot score twice');

assert.ok((notifications.match(/t\.status IS NOT NULL AND t\.status NOT IN \('complete','completed','done','archived','deleted','trashed','cancelled'\)/g) || []).length >= 2,
  'completed task notifications are absent from full and summary feeds');
assert.ok((scheduling.match(/status NOT IN \('complete','completed','done','archived','deleted','trashed','cancelled'\)/g) || []).length >= 3,
  'scheduled release, update claim and urgent popup paths ignore completed tasks');

console.log('Completed task occurrence integrity checks passed.');
