import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/checklists.php');
const table = read('apps/operations/partials/checklist-task-table.php');
const css = read('assets/css/portal.css');

assert.match(page, /\$_GET\['task_view'\] \?\? 'active'/, 'The default view must be the combined active task page.');
assert.match(page, /'manual' => \['title' => 'Manual Tasks'.*'recurring' => \['title' => 'Recurring Tasks'/s, 'Manual Tasks must render before Recurring Tasks.');
assert.doesNotMatch(page, /\$tabLabels = \[[^\]]*'recurring'[^\]]*'manual'/, 'Manual and Recurring must not remain navigation tabs.');
assert.match(page, /\$tabLabels = \['tasks' => 'Tasks', 'completed' => 'Completed Tasks', 'history' => 'Task History'\]/, 'Completed Tasks and Task History must remain available.');
assert.match(page, /data-task-create-kind="manual"/, 'The main New Task button must default to manual.');
assert.match(page, /data-task-create-kind="recurring"/, 'Recurring creation must remain in the Recurring section.');
assert.match(page, /allowedRecurringRules/, 'The backend must validate submitted recurrence rules.');
assert.match(page, /assigned_employee_id = \?/, 'The authenticated employee scope must remain server-side.');
assert.match(page, /include __DIR__ \. '\/partials\/checklist-task-table\.php'/, 'Both sections must share one table renderer.');
assert.match(table, /foreach \(\$displayTasks as \$task\)/, 'The shared renderer must receive an already scoped dataset.');
assert.match(css, /\.task-management-page \{ display: grid;[^}]*gap: 18px;/, 'The shared section layout must be styled.');
assert.match(css, /@media \(max-width: 760px\)[\s\S]*\.task-management-page \{ gap: 12px;/, 'The section layout must remain responsive.');

console.log('Task Management section order static checks passed.');
