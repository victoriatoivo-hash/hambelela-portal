import assert from 'node:assert/strict';
import fs from 'node:fs';

const taskPage = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const taskTable = fs.readFileSync(new URL('../apps/operations/partials/checklist-task-table.php', import.meta.url), 'utf8');
const templates = fs.readFileSync(new URL('../apps/operations/task-templates.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(taskPage, /<option value="all">All Employees<\/option>/, 'owner selector exposes All Employees');
assert.match(taskPage, /r\.role_key <> 'owner_admin'/, 'batch eligibility excludes owner/admin role');
assert.match(taskPage, /e\.status='active'/, 'batch eligibility requires active employees');
assert.match(taskPage, /beginTransaction\(\)[\s\S]+foreach \(\$targetEmployeeIds as \$targetEmployeeId\)[\s\S]+commit\(\)/, 'children are created atomically');
assert.match(taskPage, /batch_id[\s\S]+batch_size/, 'child tasks retain batch metadata');
assert.match(taskPage, /notifications_notify_task_assigned\(\$createdTaskId, \$targetEmployeeId/, 'each child receives its own notification');
assert.match(taskPage, /\$employeeVisible = \$scheduledAt === null \? 1 : 0/, 'scheduled children stay private until release');
assert.match(taskPage, /strip_tags\(\$html, '<p><br><strong><b><em><i><u><ul><ol><li>'\)/, 'server allow-list is explicit');
assert.match(taskPage, /script\|style\|iframe\|object\|embed\|form\|input\|button/, 'unsafe element families are removed');
assert.match(taskPage, /data-task-rich-editor/, 'rich editor is rendered');
assert.match(taskPage, /data-task-edit-rich/, 'existing task instructions use the rich editor');
assert.match(taskPage, /data-task-instructions-modal/, 'expanded editor is rendered');
assert.match(taskPage, /Assign this task to all \$\{employeeCount\} active employees\?/, 'batch confirmation uses live employee count');
assert.match(taskTable, /Assigned to: All Employees/, 'owner group summary identifies the batch');
assert.match(taskTable, /Complete.*Outstanding/, 'owner group summary reconciles child states');
assert.match(templates, /checklist_sanitize_instructions/, 'templates use the same rich-text sanitizer');
assert.match(css, /height:78dvh/, 'desktop expanded editor uses focused viewport height');
assert.match(css, /height:100dvh/, 'mobile editor is full-height');
assert.match(css, /task-instructions-modal__body\{min-height:0;overflow:hidden/, 'expanded editor has one internal scrolling region');

console.log('All Employees and rich-instructions static checks passed.');
