import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/reports.php');
const endpoint = read('apps/operations/reports-data.php');
const script = read('assets/js/reports-business-health.js');
const styles = read('assets/css/performance-dashboard.css');

for (const heading of ['Business Health', 'Operations Team Overview', 'Recent Work Across the Portal']) {
  assert.match(page, new RegExp(heading), `${heading} must be present on the landing page`);
}
assert.match(page, /kpi-dashboard-operational-grid/, 'fulfilment and risks must share the operational overview');
assert.match(page, /data-kpi-team/, 'employee summaries must be rendered as a stacked landing-page section');
assert.match(page, /data-kpi-live-activity/, 'cross-portal activity must have a dedicated region');
assert.doesNotMatch(page, /Historical Packed By Audit|Presentation Mode|Print \/ PDF/, 'secondary utilities must not clutter the Performance dashboard');
assert.match(endpoint, /'live_activity'=>\$liveActivity/, 'the Business Health endpoint must provide live activity');
assert.match(endpoint, /kpi_performance_employee_predicate\('e','r'\)/, 'live activity must respect employee eligibility');
assert.match(script, /function renderLiveActivity/, 'live activity must be grouped and rendered by module');
assert.match(script, /Number\.isFinite\(Number\(person\.summary_score\)\)/, 'employee scores must guard against NaN');
assert.doesNotMatch(script, /presentationSections|setPresentationMode|data-kpi-management-present|data-kpi-management-print/, 'removed presentation utilities must not leave dead behaviour');
assert.match(script, /setInterval\(\(\) => \{ if \(!document\.hidden\) load\(true\); \}, 20000\)/, 'the visible dashboard must refresh current evidence every 20 seconds');
assert.match(endpoint, /NOT IN \('resolved','complete','completed','closed'\).*NULLIF\(TRIM\(COALESCE\(resolution,''\)\),''\) IS NULL/, 'resolved Error Log records must be excluded from current attention');
for (const category of ["'system_issues'", "'communications'", "'quality'"]) assert.match(endpoint, new RegExp(category), `${category} must contribute an evidence-gated operational score`);
assert.match(endpoint, /'operational_score_components'=>\$scores/, 'the score response must disclose its measured components');
assert.match(script, /operational_score_components/, 'the dashboard must expose the measured score components');
assert.match(styles, /grid-template-columns:repeat\(6,minmax\(0,1fr\)\)/, 'desktop employee metrics must align in one row');
assert.match(styles, /@media\(max-width:700px\)/, 'the consolidated dashboard must collapse responsively');

console.log('Performance dashboard consolidation checks passed.');
