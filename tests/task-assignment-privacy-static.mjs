import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const tasks = read('apps/operations/checklists.php');
const operations = read('apps/operations/operations.php');
const proof = read('apps/operations/task-proof.php');
const notifications = read('shared/notifications.php');

assert.match(operations, /function ops_task_scope_for_current_user[\s\S]*user_has_role\('owner_admin'\)/,
  'Only the owner role may receive the all-task management scope.');
assert.match(tasks, /\$canManage = \$taskScope\['type'\] === 'all';/,
  'The Task page must consume the centralized scope.');
const scopeFunction = operations.match(/function ops_task_scope_for_current_user\(\): array\s*\{[\s\S]*?\n\}/)?.[0] ?? '';
assert.doesNotMatch(scopeFunction, /user_has_role\('owner_admin',/,
  'Operational employee roles must not be granted all-task scope.');
assert.match(tasks, /if \(!\$canManage\) \{\s*\$where\[\] = 't\.assigned_employee_id = \?';/s,
  'The active task query must use the authenticated employee id.');
assert.match(tasks, /\$historyWhere\[\] = 't\.assigned_employee_id = \?';/,
  'Completed history must use the same employee scope.');
assert.match(tasks, /\$historyTasks = \$ready \? ops_rows\(/,
  'Employees must be able to load their own task history.');
assert.match(tasks, /task-proof\.php\?task_id=/,
  'Proof links must use the authenticated download handler.');

assert.match(proof, /assigned_employee_id = \? AND employee_visible = 1/,
  'Proof downloads must reject tasks assigned to another employee.');
assert.match(proof, /realpath\(BASE_PATH \. '\/uploads\/checklist-proofs'\)/,
  'Proof downloads must be constrained to the proof upload directory.');

assert.match(notifications, /t\.assigned_employee_id = \?/,
  'Task notification feeds must exclude another employee\'s task.');
assert.match(notifications, /user_has_role\('owner_admin'\)/,
  'Only owners may bypass notification task assignment scope.');

console.log('Task assignment privacy static checks passed.');
