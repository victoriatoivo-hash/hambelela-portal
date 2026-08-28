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
for (const label of ['Average operational fulfilment', 'Average payment settlement', 'Average final closure']) assert.match(script, new RegExp(label), `${label} must be shown separately`);
assert.match(endpoint, /settlement_minutes.*final_closure_minutes.*settled_n/, 'payment settlement and final closure must use separately measured paid-order evidence');
assert.match(endpoint, /GREATEST\(completed_at,\{\$paidTimestampExpr\}\)/, 'final closure must stop at the later of completion and paid confirmation');
assert.match(endpoint, /status IN \('completed','packed','verified'\).*completed_at >= created_at/, 'all order timing clocks must exclude invalid and non-completed records');
assert.match(script, /kpi-flow-track/, 'order timing must render as colour-coded flow bars');
assert.match(script, /Oldest waiting in New/, 'the active New-order age must have a clear label');
assert.match(styles, /\.kpi-flow-metric\.is-green i\{background:#A8CA19\}/, 'flow timing metrics must use the portal colour language');
assert.match(endpoint, /\['new','new_order','new-order','new order','pending','assigned'\]/, 'Oldest New must recognise every Orders Board new-order status');
assert.match(endpoint, /\$frontStart=\$isWalkIn\?\$createdAt:\(\$progressEvent\['occurred_at'\]\?\?null\)/, 'Front Desk timing must start at responsibility handoff except for walk-ins');
assert.match(endpoint, /front_desk_slowest_order/, 'the slowest Front Desk order must carry an auditable reference');
assert.match(script, /Ready \/ In Progress.*Walk-ins are measured from New to Complete/s, 'the Front Desk flow must explain its responsibility window');
assert.match(styles, /grid-template-columns:repeat\(6,minmax\(0,1fr\)\)/, 'desktop employee metrics must align in one row');
assert.match(styles, /@media\(max-width:700px\)/, 'the consolidated dashboard must collapse responsively');

console.log('Performance dashboard consolidation checks passed.');
