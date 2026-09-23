import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/marketing/employee-workspace.php', 'utf8');

assert.match(page, /assigned_employee_id=\?/);
assert.match(page, /employee_visible=1/);
assert.match(page, /scheduled_at IS NULL OR released_at IS NOT NULL/);
assert.match(page, /status NOT IN\('complete','completed','done','cancelled'\)/);
assert.match(page, /My Assigned Tasks/);
assert.match(page, /Portal tasks assigned to me/);
assert.match(page, /task_view=active&amp;task_id=/);
assert.match(page, /\$badges\['tasks'\]\+=count\(\$portalTasks\)/);
assert.match(page, /count\(array_filter\(\$myWork,\$pending\)\)\+count\(\$portalTasks\)/);

console.log('Marketing employee assigned-task visibility contracts passed.');
