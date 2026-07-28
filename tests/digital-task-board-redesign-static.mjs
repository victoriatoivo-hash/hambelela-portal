import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(page, /\$statuses = \['new' => 'New', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'blocked' => 'Blocked', 'complete' => 'Complete', 'cancelled' => 'Cancelled'\]/);
assert.match(page, /Legacy Started status consolidated into In Progress/);
assert.match(page, /if \(\$action === 'update_task_status'\)/);
assert.match(page, /checklist_require_completion\(\$beforeRows\[0\]\)/);
assert.match(page, /foreach \(\$beforeBulkStatuses as \$bulkTask\) checklist_require_completion/);
assert.match(page, /data-task-completion-error/);
assert.match(page, /data-task-complete-confirm/);
assert.match(page, /class="task-board" data-task-board/);
assert.match(page, /data-task-status-trigger/);
assert.match(page, /data-task-panel-jump="task-checklist-/);
assert.doesNotMatch(page, /data-collapsible-task-section/);
assert.doesNotMatch(page, /data-status-key="overdue"/);
assert.doesNotMatch(page, /data-status-key="started"/);
assert.match(css, /\.digital-task-page \.task-status-trigger \{[^}]*height:35px/);
assert.match(css, /\.digital-task-page \.task-board-table tbody tr \{[^}]*height:35px/);

console.log('Digital Task Board redesign checks passed.');
