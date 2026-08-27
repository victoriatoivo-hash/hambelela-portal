import assert from 'node:assert/strict';
import fs from 'node:fs';

const page=fs.readFileSync(new URL('../apps/operations/checklists.php',import.meta.url),'utf8');
const templates=fs.readFileSync(new URL('../apps/operations/task-templates.php',import.meta.url),'utf8');
const recurring=fs.readFileSync(new URL('../apps/operations/partials/checklist-recurring-tasks.php',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../assets/css/portal.css',import.meta.url),'utf8');

for(const mode of ['one_off','scheduled','recurring']) assert.match(page,new RegExp(`name="task_mode" value="${mode}"`));
assert.match(page,/const currentTaskMode = \(\) =>/);
assert.match(page,/document\.querySelector\('\[data-task-mode-badge\]'\)/, 'mode badge must be resolved from the page header, not the nested form');
assert.doesNotMatch(page,/form\.querySelector\('\[data-task-mode-badge\]'\)/);
assert.doesNotMatch(page,/panel\.querySelector\('\[data-task-mode-badge\]'\)/);
assert.match(page,/modeSections\.forEach/);
assert.match(page,/dueAtInput\.disabled = mode !== 'one_off'/);
assert.match(page,/scheduledAtInput\.disabled = !scheduled/);
assert.match(page,/scheduledDueInput\.disabled = !scheduled/);
assert.match(page,/recurrenceSelect\.disabled=!recurring/);
assert.match(page,/monthDayInput\.disabled=!usesMonthDay/);
assert.match(page,/Due time must be after the task release time\./);
assert.match(page,/The release time must be in the future\./);
assert.match(page,/recurrence_release_mode/);
assert.match(page,/recurrence_due_time/);
assert.match(page,/recurrence_due_days/);
assert.match(page,/\$releaseAt > \$now/);
assert.match(page,/\$occurrenceAt->modify\('\+1 second'\)/);
assert.match(recurring,/Release Rule/);
assert.match(recurring,/Due Rule/);
assert.match(templates,/taskMode.*scheduled/s);
assert.match(templates,/recurrence_release_minutes/);
assert.match(css,/\.task-mode-segments/);
assert.match(css,/@media \(max-width:390px\)/);
assert.match(css,/prefers-reduced-motion:reduce/);

console.log('Authoritative task-mode architecture checks passed.');
