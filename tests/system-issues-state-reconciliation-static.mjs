import assert from 'node:assert/strict';
import fs from 'node:fs';

const workflow=fs.readFileSync(new URL('../shared/system-issue-workflow.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../apps/operations/system-issues.php',import.meta.url),'utf8');
const endpoint=fs.readFileSync(new URL('../apps/operations/system-issue-action.php',import.meta.url),'utf8');
const reconciliation=fs.readFileSync(new URL('../apps/operations/system-issue-reconciliation.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-system-issues-migration.sql',import.meta.url),'utf8');
const js=fs.readFileSync(new URL('../assets/js/system-issue-workflow.js',import.meta.url),'utf8');

assert.match(migration,/audience VARCHAR\(20\).*DEFAULT 'employee'/s);
assert.match(migration,/is_blocking TINYINT\(1\).*DEFAULT 1/s);
assert.match(workflow,/audience='employee'.*is_blocking=1.*status='pending'/s);
assert.match(workflow,/Waiting for employee information/);
assert.match(workflow,/approval_allowed/);
assert.match(endpoint,/pending_information/);
assert.match(page,/blocking_request_count/);
assert.match(page,/\$rowView=siw_view\(\$row\)/);
assert.match(reconciliation,/mode.*dry-run/s);
assert.match(reconciliation,/changes_applied.*false/s);
assert.doesNotMatch(reconciliation,/UPDATE system_issues/i);
assert.match(js,/data-issue-employee-status/);
assert.match(js,/next_required_action/);
console.log('System Issues state reconciliation safeguards passed.');
