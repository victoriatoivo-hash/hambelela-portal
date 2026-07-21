import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const tasks = readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const dashboard = readFileSync(new URL('../index.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(css, /\.digital-task-page \.task-board-table \{ width:100%; min-width:1450px;/);
assert.match(tasks, /CREATE TABLE IF NOT EXISTS ops_checklist_recurring_templates/);
assert.match(tasks, /'daily-stock'.*'08:00:00'/s);
assert.match(tasks, /name="recurring_rule"/);
assert.match(tasks, /name="employee_visible"/);
assert.match(tasks, /task_cancel_recurrence/);
assert.match(tasks, /UPDATE ops_checklist_recurring_templates SET is_active = 0/);
assert.match(dashboard, /class="employee-task-count"/);
assert.match(dashboard, /data-dashboard-task-reminder/);
assert.match(dashboard, /status <> 'complete'/);

console.log('Task recurrence and dashboard reminder checks passed.');
