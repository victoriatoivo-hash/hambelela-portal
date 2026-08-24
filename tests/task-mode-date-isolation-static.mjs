import fs from 'node:fs';
import assert from 'node:assert/strict';

const tasks=fs.readFileSync(new URL('../apps/operations/checklists.php',import.meta.url),'utf8');

assert.match(tasks,/if \(\$taskMode !== 'scheduled'\) return null;/,'scheduled timing must be controlled only by task_mode');
assert.doesNotMatch(tasks,/recurrence start date must be on or after the scheduled release date/i,'recurring mode must not compare against scheduled_at');
assert.doesNotMatch(tasks,/\$recurrenceStartDate = \$scheduledAt \? substr/,'recurring defaults must not inherit scheduled_at');
assert.match(tasks,/const setTaskMode = \(mode, clearInactive = false\) =>/,'mode state must have one canonical transition function');
assert.match(tasks,/clearDateControl\(scheduledAtInput,'#create-task-schedule-display'\)/,'leaving Scheduled must clear its release value');
assert.match(tasks,/clearDateControl\(scheduledDueInput,'#create-task-scheduled-due-display'\)/,'leaving Scheduled must clear its due value');
assert.match(tasks,/scheduledAtInput\.disabled = !scheduled/);
assert.match(tasks,/scheduledDueInput\.disabled = !scheduled/);
assert.match(tasks,/recurrenceTimeInput\.disabled=!recurring/);
assert.match(tasks,/recurrenceStartInput\.disabled=!recurring/);
assert.match(tasks,/const validateActiveTiming = \(\) =>/,'validation must dispatch by active mode');
assert.match(tasks,/if\(mode==='one_off'\)/);
assert.match(tasks,/if\(mode==='scheduled'\)/);
assert.match(tasks,/if\(mode==='recurring'\)/);
assert.match(tasks,/data-task-timing-errors/,'timing errors must render inline');
assert.doesNotMatch(tasks,/window\.alert\('Release time must be in the future\.'/);
assert.doesNotMatch(tasks,/window\.alert\('Due time must be after the task release time\.'/);
assert.match(tasks,/function firstRecurringOccurrence\(/,'the summary must calculate the first eligible occurrence');
assert.match(tasks,/if\(!validateActiveTiming\(\)\)/,'submission must run complete inline timing validation first');

console.log('Task mode date isolation checks passed.');
