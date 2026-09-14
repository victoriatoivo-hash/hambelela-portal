import assert from 'node:assert/strict';import fs from 'node:fs';
const reconciliation=fs.readFileSync('apps/operations/system-issue-reconciliation.php','utf8');const workflow=fs.readFileSync('shared/system-issue-workflow.php','utf8');const webhook=fs.readFileSync('apps/operations/system-issues-webhook.php','utf8');
assert.match(reconciliation,/system_issue_is_owner\(\)/);assert.match(reconciliation,/mode.*dry-run/);assert.match(reconciliation,/changes_applied.*false/);
for(const legacy of ['codex_running','tests_failed','deployment_failed','verification_failed'])assert.match(workflow,new RegExp(`'${legacy}'`));assert.match(webhook,/http_response_code\(410\)/);console.log('State reconciliation and retired automation checks passed.');
