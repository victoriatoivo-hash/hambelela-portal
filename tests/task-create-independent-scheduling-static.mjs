import assert from 'node:assert/strict';
import fs from 'node:fs';

const page=fs.readFileSync(new URL('../apps/operations/checklists.php',import.meta.url),'utf8');
const recurring=fs.readFileSync(new URL('../apps/operations/partials/checklist-recurring-tasks.php',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../assets/css/portal.css',import.meta.url),'utf8');

assert.match(page,/name="assignment_type" value="specific"[\s\S]*Specific Employee/);
assert.match(page,/data-specific-assignment[\s\S]*data-floating-assignment hidden/);
assert.match(page,/name="delivery_mode" value="now"[\s\S]*name="delivery_mode" value="scheduled"/);
assert.match(page,/name="repeat_type" value="one_time"[\s\S]*name="repeat_type" value="recurring"/);
assert.doesNotMatch(page,/Scheduled release is available for manual tasks only/);
assert.match(page,/weekly_days:\s*['"`]?\s*\.\s*implode|weekly_days:\$\{days\.join/);
assert.match(page,/monthly_day:/);
assert.match(page,/repeat_weekdays\[\]/);
assert.match(page,/Recurring task scheduled\./);
assert.match(page,/taskViewCache\.clear\(\)/);
assert.match(page,/openTaskView\(view,\{root,content,force:true/);
assert.match(page,/completion_evidence_required[\s\S]*ops_checklist_recurring_templates/);
assert.match(recurring,/name="repeat_weekdays\[\]"/);
assert.match(recurring,/in_array\(\$day,\$savedDays,true\)\?' checked'/);
assert.match(css,/\.task-repeat-weekdays/);
assert.match(css,/width:min\(980px,calc\(100vw - 40px\)\)/);

console.log('Task creation assignment, release and recurrence static checks passed.');
