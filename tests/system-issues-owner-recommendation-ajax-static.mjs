import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const page = read('apps/operations/system-issues.php');
const endpoint = read('apps/operations/system-issue-recommendation.php');
const script = read('assets/js/system-issue-recommendations.js');
const service = read('shared/system-issues.php');
const css = read('assets/css/portal.css');

assert.match(page, /data-owner-recommendation-form/);
assert.match(page, /data-recommendation-url=/);
assert.match(page, /data-recommendation-saved/);
assert.match(page, /latestOwnerRecommendation\['recommendation_text'\]/);
assert.match(page, /system-issue-recommendations\.js/);

assert.match(endpoint, /Content-Type: application\/json/);
assert.match(endpoint, /system_issue_verify_csrf/);
assert.match(endpoint, /system_issue_is_owner\(\)/);
assert.match(endpoint, /system_issue_find_visible/);
assert.match(endpoint, /save_owner_recommendation/);
assert.match(endpoint, /update_ai_brief/);
assert.match(endpoint, /system_issue_save_owner_recommendation/);
assert.match(endpoint, /system_issue_regenerate_brief/);
assert.match(endpoint, /shared\/system-issue-workflow\.php/);
assert.match(endpoint, /brief_html/);
assert.match(endpoint, /Save the latest recommendation before updating the AI brief\./);
assert.match(endpoint, /Recommendation saved\./);
assert.match(endpoint, /AI Brief updated successfully\./);
assert.match(endpoint, /recommendation_incorporated/);
assert.match(endpoint, /updated_by/);
assert.doesNotMatch(endpoint, /UPDATE system_issues/);

assert.match(script, /event\.preventDefault\(\)/);
assert.match(script, /new FormData\(form\)/);
assert.match(script, /fetch\(endpoint/);
assert.match(script, /credentials: 'same-origin'/);
assert.match(script, /application\/json/);
assert.match(script, /Saving\.\.\./u);
assert.match(script, /Updating AI Brief\.\.\./u);
assert.match(script, /renderSavedState/);
assert.match(script, /renderBrief/);
assert.match(script, /Owner Recommendations & Business Context/);
assert.match(script, /Recommendation incorporated:/);
assert.match(script, /aria-busy/);

assert.match(service, /system_issue_owner_recommendations/);
assert.match(service, /owner_recommendations_json/);
assert.match(service, /\$brief\['owner_requirements_business_context'\]=array_values/);
assert.match(service, /previous brief remains available/i);
assert.match(service, /system-issue-workflow\.php/);
assert.match(service, /siw_normalise_stage/);
for (const section of ['issue_summary','employee_reported_problem','owner_requirements_business_context','known_technical_context','codex_investigation','implementation_requirements','data_field_mapping','error_edge_cases','regression_tests','implementation_authority','deployment_requirements','owner_decision_required','completion_report_required']) assert.match(service, new RegExp(section));
assert.match(css, /\.sil-recommendation-saved/);
assert.match(css, /@media\(max-width:430px\).*\.sil-owner-actions\{display:grid;grid-template-columns:1fr\}/s);

console.log('System Issues owner recommendation AJAX safeguards passed.');
