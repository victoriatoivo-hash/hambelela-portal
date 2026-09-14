import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync('assets/js/system-issue-workflow.js', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(js, /function renderWorkflowStage\(root,data\)/, 'One authoritative stage renderer must consume every workflow response.');
assert.match(js, /function ensureStageFields\(root\)/, 'Stage fields must be creatable when PHP did not render them initially.');
assert.match(js, /stageFieldsMarkup\(\)[\s\S]*testing_decision|fieldModes[\s\S]*testing_decision/, 'Testing mode must have dynamically available fields.');
assert.match(js, /data\.form_mode\|\|root\.dataset\.formMode/, 'The authoritative form_mode must drive the stage UI.');
assert.match(js, /root\.dataset\.formMode=mode[\s\S]*ensureStageFields\(root\)[\s\S]*syncFields\(root\)/, 'A successful response must mount and reveal its stage fields in the same render pass.');
assert.match(js, /root\.querySelector\('\[data-workflow-actions\]'\)[\s\S]*actionMarkup\(data\.permitted_actions\)|actions\.innerHTML=actionMarkup\(data\.permitted_actions\)/, 'Old action buttons must be replaced from the fresh response.');
assert.match(js, /prependActivity\(root,data\.activity_event\)/, 'The transition event must be inserted immediately.');
assert.match(js, /data-issue-employee-status/, 'List and selected-issue badges must update in place.');
assert.doesNotMatch(js, /location\.reload\(|window\.location\s*=|location\.assign\(/, 'Workflow transitions must not reload or navigate the page.');
assert.match(css, /\[data-workflow-actions\]\.is-transitioning/, 'The stage replacement must use subtle transition feedback.');
assert.match(css, /prefers-reduced-motion:reduce[^{]*\{#system-issues-page \[data-workflow-actions\]/, 'Transition feedback must respect reduced motion.');

console.log('System Issues in-place stage rendering checks passed.');
