import fs from 'node:fs';
import assert from 'node:assert/strict';

const service=fs.readFileSync(new URL('../apps/operations/kpi-task-management-performance.php',import.meta.url),'utf8');
const endpoint=fs.readFileSync(new URL('../apps/operations/kpi-employee-data.php',import.meta.url),'utf8');
const handlers=fs.readFileSync(new URL('../apps/operations/checklists.php',import.meta.url),'utf8');
const ui=fs.readFileSync(new URL('../assets/js/kpi-employee.js',import.meta.url),'utf8');

assert.match(service,/t\.date_assigned BETWEEN \? AND \?/,'cohort must use authoritative assignment time');
assert.doesNotMatch(service,/COALESCE\(t\.date_assigned,t\.created_at\)/,'created time must never replace missing assignment evidence');
assert.match(service,/kpi_business_minutes\(\$assigned,\s*\$started/,'assignment-to-start must use scheduled business time');
assert.match(service,/\$completed < \$deadline/,'completed early must compare exact completion and due timestamps');
assert.match(service,/kpi_business_minutes\(\$deadline,\s*\$now/,'open overdue duration must use due timestamp and current time');
assert.match(service,/kpi_business_minutes\(\$deadline,\s*\$completed/,'completed-late duration must use due and completion timestamps');
assert.match(service,/\$task\['started_at'\]/,'stored task start must be a valid fallback when the activity event is absent');
assert.match(service,/duration_stats/,'duration statistics must be exposed to the report renderer');
assert.match(service,/risk_rows/,'task risks must use the same authoritative evidence rows');
assert.match(service,/task_score/,'the task section must expose its central score');
assert.match(service,/Optional proof — evidence only/,'optional proof must not create a score or bonus');
assert.match(service,/Attribution conflict/,'actor conflicts must be surfaced');
assert.match(service,/Completed without In Progress/,'direct completion must remain visible');
assert.match(service,/No eligible due tasks|unmeasured/,'empty denominators must not become zero scores');
assert.match(endpoint,/task_management_performance/,'employee KPI must use dedicated task performance data');
assert.match(ui,/Task Management Performance/,'employee UI must identify the dedicated section');
assert.match(ui,/Creation-to-assignment is owner allocation time/,'UI must explain allocation-delay exclusion');
assert.match(ui,/Task Errors, Overdue Work and Current Risks/,'task risk block must render');
assert.match(ui,/How This Task Score Was Calculated/,'task score working must render');
assert.match(ui,/taskPerformanceHtml/,'dedicated task performance renderer must be used');
assert.match(handlers,/task_reassigned/,'reassignment must create a specific immutable event');
assert.match(handlers,/date_assigned = CASE WHEN COALESCE\(assigned_employee_id,0\) <> \? THEN NOW\(\)/,'assignment clock must reset only when responsibility changes');

console.log('Task Management Performance static checks passed.');
