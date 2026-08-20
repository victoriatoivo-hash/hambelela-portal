import fs from 'node:fs';
import assert from 'node:assert/strict';

const checklistPage = fs.readFileSync('apps/operations/checklists.php', 'utf8');
const taskTable = fs.readFileSync('apps/operations/partials/checklist-task-table.php', 'utf8');
const stylesheet = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(checklistPage, /'completed_year'\s*=>/);
assert.match(checklistPage, /'completed_month'\s*=>/);
assert.match(checklistPage, /YEAR\(\{\$completedAtSql\}\) = \?/);
assert.match(checklistPage, /DATE_FORMAT\(\{\$completedAtSql\}, '%Y-%m'\) = \?/);
assert.match(checklistPage, /COALESCE\(t\.date_completed,t\.completed_at,t\.created_at\) DESC/);
assert.match(checklistPage, /new DateTimeImmutable\(\$completedAt, new DateTimeZone\('Africa\/Windhoek'\)\)/);
assert.match(checklistPage, /\$completedDate->format\('Y-m'\)/);
assert.match(checklistPage, /completed-employee-group/);
assert.match(checklistPage, /completed-month-group/);
assert.match(checklistPage, /\$hideAssignedColumn = true/);
assert.match(taskTable, /\$hideAssignedColumn = !empty\(\$hideAssignedColumn\)/);
assert.match(taskTable, /if \(!\$hideAssignedColumn\): \?><th>Assigned<\/th>/);
assert.match(taskTable, /checklist_completed_date_label/);
assert.match(stylesheet, /#completed-tasks-section\{display:grid;gap:12px\}/);
assert.match(stylesheet, /prefers-reduced-motion:reduce/);

console.log('Completed task employee/month grouping static checks passed.');
