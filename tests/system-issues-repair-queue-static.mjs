import fs from 'node:fs';
import assert from 'node:assert/strict';

const workflow=fs.readFileSync('shared/system-issue-workflow.php','utf8');
const status=fs.readFileSync('apps/operations/system-issue-workflow-status.php','utf8');
const client=fs.readFileSync('assets/js/system-issue-workflow.js','utf8');
const css=fs.readFileSync('assets/css/portal.css','utf8');

assert.match(workflow,/function siw_automation_configured\(\)/);
assert.match(workflow,/automated repair processing has not been configured/);
assert.match(workflow,/SELECT \* FROM system_issue_workflow_outbox WHERE issue_id=\? AND event_type='approve_repair'.*FOR UPDATE/);
assert.match(workflow,/UPDATE system_issue_workflow_outbox SET status='retry',attempts=attempts\+1/);
const retryBlock=workflow.slice(workflow.indexOf("if($command==='retry_queue')"),workflow.indexOf("$to=$rule['to']"));
assert.doesNotMatch(retryBlock,/INSERT INTO system_issue_workflow_outbox/);
assert.match(status,/system_issue_find_visible/);
assert.match(status,/if\(!\$owner\).*permitted_actions/);
assert.match(client,/setInterval\(\(\)=>poll\(root\),12000\)/);
assert.match(client,/copy_codex_brief/);
assert.match(css,/\.system-issue-queue-status/);
console.log('System Issues repair queue static checks passed.');
