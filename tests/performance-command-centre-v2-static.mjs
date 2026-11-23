import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL('../' + path, import.meta.url),'utf8');
const index = read('apps/operations/reports.php');
const profile = read('apps/operations/kpi-employee.php');
const source = read('assets/js/reports-business-health.js');
const command = read('assets/js/performance-command-v2.js');
const styles = read('assets/css/performance-command-v2.css');
const rail = read('shared/performance-command-rail.php');

// Keep the existing PHP endpoints, permission checks and data hooks intact.
assert.match(index, /require_role\('owner_admin'\)/);
assert.match(index, /reports\.php\?tab=employees/);
assert.match(index, /data-kpi-orders-overview/);
assert.match(index, /data-kpi-attention/);
assert.match(index, /data-kpi-team/);
assert.match(index, /data-kpi-business-errors/);
assert.match(index, /data-kpi-live-activity/);
assert.match(index, /<script defer src="[^"]*performance-command-v2\.js/);
assert.match(index, /<\?php include BASE_PATH \. '\/shared\/footer\.php'; \?>/);
assert.doesNotMatch(index, /<\?php\s*<script/);
assert.match(source, /window\.HambelelaLatestBusinessHealthData = data/);
assert.match(command, /if \(window\.HambelelaLatestBusinessHealthData\)/);
assert.match(index, /data-pc-score-ring/);
assert.match(index, /data-pc-action-list/);
assert.match(index, /data-pc-stages/);
assert.match(index, /performance-command-v2\.css/);
assert.match(index, /performance-command-v2\.js/);
assert.match(index, /shared\/performance-command-rail\.php/);

assert.match(source, /pc:business-data/);
assert.match(source, /renderCards\(data\.cards\)/);
assert.match(source, /renderOrdersOverview\(data\.orders_overview/);
assert.match(source, /renderRecognition\(data\.recognition\)/);
assert.match(command, /data\.orders_overview\?\.counts/);
assert.match(command, /data\.operational_score/);
assert.match(command, /data\.team/);
assert.match(command, /data\.attention/);
assert.match(command, /data\.business_errors/);
assert.match(command, /Not measured/);
assert.doesNotMatch(command, /(?:Dinelao|Claudia|Cecilia|Hope|Moses)\s*[:=]/);

assert.match(profile, /data-employee-id/);
assert.match(profile, /data-kpi-employee-content/);
assert.match(profile, /data-kpi-score-evidence|kpi-score-placeholder/);
assert.match(profile, /performance-command-v2\.css/);
assert.match(profile, /shared\/performance-command-rail\.php/);
assert.match(styles, /Jost/);
assert.match(styles, /@media\(max-width:760px\)/);
assert.match(rail, /\$pcIsOwner/);
assert.match(rail, /view=performance/);
console.log('Concept 02 integration invariants passed');
