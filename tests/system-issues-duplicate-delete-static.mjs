import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const migration=read('operations-system-issues-migration.sql');
const shared=read('shared/system-issues.php');
const page=read('apps/operations/system-issues.php');
const endpoint=read('apps/operations/system-issue-report.php');
const client=read('assets/js/system-issue-report.js');

for(const field of ['duplicate_signature','submission_token','deleted_at','deleted_by','deletion_reason'])assert.match(migration,new RegExp(field));
assert.match(migration,/UNIQUE INDEX IF NOT EXISTS uq_system_issues_submission_token/);
assert.match(shared,/function system_issue_report_signature/);
assert.match(shared,/DATE_SUB\(NOW\(\),INTERVAL 15 MINUTE\)/);
assert.match(page,/i\.deleted_at IS NULL/);
assert.match(page,/data-system-issue-report-form/);
assert.match(page,/data-delete-system-issue/);
assert.match(endpoint,/Only an owner or administrator can delete/);
assert.match(endpoint,/system_issue_recent_duplicate/);
assert.match(endpoint,/possible_duplicate/);
assert.match(endpoint,/system_issue_triage\(\$id\)/);
assert.ok(endpoint.indexOf('system_issue_recent_duplicate')<endpoint.indexOf('system_issue_triage($id)'),'duplicate check must run before AI triage');
assert.match(client,/Submitting…/);
assert.match(client,/attach_duplicate_evidence/);
assert.match(client,/Submit anyway/);
assert.match(client,/countDelta\(-1\)/);
assert.doesNotMatch(endpoint,/errors\.php|employee_errors|performance_error/i,'must not couple to the Error Log');
console.log('System Issues duplicate/delete static checks passed.');
