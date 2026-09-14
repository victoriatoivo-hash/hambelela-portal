import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');

assert.match(page, /<input type="hidden" name="status" value="complete">/,
  'The assigned employee Save Task form must explicitly submit the complete status.');
assert.match(page, /data-save-task[^>]*><\?= \$canManage \? 'Save progress' : 'Save Task'/,
  'The employee completion action must be labelled Save Task.');
assert.match(page, /SELECT \* FROM ops_checklist_tasks WHERE \{\$scope\} LIMIT 1 FOR UPDATE/,
  'Completion must lock and authorize the task inside a transaction.');
assert.match(page, /beginTransaction\(\)[\s\S]*checklist_require_completion[\s\S]*ops_activity_log[\s\S]*commit\(\)/,
  'Checklist validation, completion and activity logging must be atomic.');
assert.match(page, /This task is not assigned to your account\./,
  'Assigned-task authorization failures must be explicit.');
assert.match(page, /form\.dataset\.submitting === 'true'/,
  'Duplicate completion submissions must be blocked.');
assert.match(page, /Task completed and saved successfully\./,
  'The employee must receive visible completion success feedback.');
assert.match(page, /showTaskCompletionError\(form\.dataset\.taskId, error\.message/,
  'Server and validation failures must remain visible in the task panel.');
assert.doesNotMatch(page, /window\.location\.reload\(\)/,
  'Completion must update the interface without a full page refresh.');

console.log('Employee task completion workflow static checks passed.');
