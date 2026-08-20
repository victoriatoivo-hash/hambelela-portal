import fs from 'node:fs';
import assert from 'node:assert/strict';

const checklistPage = fs.readFileSync('apps/operations/checklists.php', 'utf8');
const taskTable = fs.readFileSync('apps/operations/partials/checklist-task-table.php', 'utf8');
const stylesheet = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(checklistPage, /'completed_year'\s*=>/);
assert.match(checklistPage, /'completed_month'\s*=>/);
assert.match(checklistPage, /\$completedYearRequested = array_key_exists\('completed_year', \$_GET\)/);
assert.match(checklistPage, /\$completedMonthRequested = array_key_exists\('completed_month', \$_GET\)/);
assert.match(checklistPage, /if \(!\$completedMonthRequested && \$filters\['completed_month'\] === ''\) \$filters\['completed_month'\] = date\('Y-m'\)/);
assert.match(checklistPage, /YEAR\(\{\$completedAtSql\}\) = \?/);
assert.match(checklistPage, /DATE_FORMAT\(\{\$completedAtSql\}, '%Y-%m'\) = \?/);
assert.match(checklistPage, /COALESCE\(t\.date_completed,t\.completed_at,t\.created_at\) DESC/);
assert.match(checklistPage, /new DateTimeImmutable\(\$completedAt, new DateTimeZone\('Africa\/Windhoek'\)\)/);
assert.match(checklistPage, /\$completedDate->format\('Y-m'\)/);
assert.match(checklistPage, /data-completed-task-navigation/);
assert.match(checklistPage, /completed-task-controls/);
assert.match(checklistPage, /completed-year-nav/);
assert.match(checklistPage, /completed-month-nav/);
assert.match(checklistPage, /completed_employee_id/);
assert.match(checklistPage, /\$completedPanelTasks/);
assert.match(checklistPage, /\$hideAssignedColumn = \$selectedCompletedEmployeeId !== 'all'/);
assert.match(taskTable, /\$hideAssignedColumn = !empty\(\$hideAssignedColumn\)/);
assert.match(taskTable, /if \(!\$hideAssignedColumn\): \?><th>Assigned<\/th>/);
assert.match(taskTable, /checklist_completed_date_label/);
assert.match(stylesheet, /#completed-tasks-section\{display:grid;gap:16px\}/);
assert.match(stylesheet, /completed-task-controls/);
assert.match(stylesheet, /completed-employee-nav__item\.is-active/);
assert.match(stylesheet, /prefers-reduced-motion:reduce/);

console.log('Completed task employee/month grouping static checks passed.');
