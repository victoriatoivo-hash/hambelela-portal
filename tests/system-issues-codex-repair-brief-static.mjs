import fs from 'node:fs';
import assert from 'node:assert/strict';

const service = fs.readFileSync('shared/system-issues.php', 'utf8');
const page = fs.readFileSync('apps/operations/system-issues.php', 'utf8');
const endpoint = fs.readFileSync('apps/operations/system-issue-recommendation.php', 'utf8');
const copy = fs.readFileSync('apps/operations/system-issue-brief-copy.php', 'utf8');
const script = fs.readFileSync('assets/js/system-issue-recommendations.js', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

for (const key of ['title','summary','employee_report','owner_requirements_business_context','observed_behaviour','expected_behaviour','known_technical_context','codex_must_investigate','affected_modules_routes','implementation_requirements','data_field_mapping','do_not_change','error_edge_cases','required_tests','acceptance_criteria','implementation_authority','deployment_live_verification','owner_decision_required','completion_report_required']) assert.match(service, new RegExp("'" + key + "'"));
assert.match(service, /Regenerate the entire brief/);
assert.match(service, /do not append to the previous brief/i);
assert.match(service, /Do not include a Missing Information section/);
assert.match(service, /trim\(\(string\)\$previous\['recommendation_text'\]\)===\$text/);
assert.match(page, /Codex Repair Brief/);
assert.match(script, /Legacy Triage Brief/);
assert.match(page, /Copy Codex Brief/);
assert.match(endpoint, /system_issue_brief_sections/);
assert.match(script, /codex_must_investigate/);
assert.match(script, /deployment_live_verification/);
assert.match(copy, /OWNER AUTHORISATION/);
assert.match(copy, /Do not write directly to main/);
assert.match(css, /\.sil-ai-brief\.is-repair-brief/);
assert.doesNotMatch(service, /Return JSON only[^\n]+missing_information/);

console.log('System Issues Codex Repair Brief structure and safeguards passed.');
