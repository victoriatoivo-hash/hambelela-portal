import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const allocator = fs.readFileSync(new URL('../shared/task-floating.php', import.meta.url), 'utf8');
const scheduler = fs.readFileSync(new URL('../shared/task-scheduling.php', import.meta.url), 'utf8');
const partial = fs.readFileSync(new URL('../apps/operations/partials/checklist-floating-tasks.php', import.meta.url), 'utf8');
const kpi = fs.readFileSync(new URL('../apps/operations/kpi-task-management-performance.php', import.meta.url), 'utf8');

assert.match(page, /name="assignment_type" value="floating"/);
assert.match(page, /name="floating_eligible_role"/);
assert.match(allocator, /e\.status='active'/);
assert.match(allocator, /t\.status IN \('new','in_progress'\)/);
assert.match(allocator, /ORDER BY active_workload ASC/);
assert.match(allocator, /last_floating_at ASC/);
assert.match(allocator, /GET_LOCK\(\?, 5\)/);
assert.match(allocator, /assigned_employee_id IS NULL/);
assert.match(allocator, /waiting_eligible_employee/);
assert.match(scheduler, /task_floating_allocate\(\(int\) \$row\['id'\], 'automatic'/);
assert.match(page, /task_floating_allocate\(\$occurrenceId, 'automatic'/);
assert.match(partial, /data-floating-retry/);
assert.match(partial, /Owner allocation monitor/);
assert.match(kpi, /floating_assigned/);
assert.match(kpi, /Floating Tasks Allocated/);

console.log('Floating Task assignment contracts passed.');
