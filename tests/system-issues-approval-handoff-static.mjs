import fs from 'node:fs';
import assert from 'node:assert/strict';

const workflow = fs.readFileSync('shared/system-issue-workflow.php', 'utf8');
const client = fs.readFileSync('assets/js/system-issue-workflow.js', 'utf8');
const copy = fs.readFileSync('assets/js/system-issue-copy.js', 'utf8');
const endpoint = fs.readFileSync('apps/operations/system-issue-brief-copy.php', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(workflow, /'approve_brief'=>\['from'=>\['brief_ready'\],'to'=>'approved_for_codex'/);
assert.match(workflow, /approved_brief_id=\?,approved_brief_version=\?,approved_at=NOW\(\),approved_by=\?/);
for (const field of ['approved_brief_id', 'approved_brief_version', 'approved_at', 'approved_by']) assert.match(workflow, new RegExp(`'${field}'`));
assert.match(workflow, /'form_mode'=>\$formMode/);
assert.match(workflow, /copy_to_codex/);
assert.match(workflow, /Copy & Send to Codex/);
assert.match(workflow, /'approved_for_codex'=>\['label'=>'Approved for Codex','employee'=>'under_review','step'=>3/);
assert.match(client, /renderApprovalSummary/);
assert.match(client, /data-approved-brief-summary/);
assert.match(client, /data-approval-confirmation/);
assert.match(client, /requestPending/);
assert.match(copy, /_refreshWorkflow/);
assert.match(endpoint, /OWNER AUTHORISATION/);
assert.match(endpoint, /Only the immutable approved brief may be copied/);
assert.match(css, /system-issue-approved-brief/);
assert.match(css, /copy_codex_brief/);

console.log('System Issues approval-to-handoff safeguards passed.');
