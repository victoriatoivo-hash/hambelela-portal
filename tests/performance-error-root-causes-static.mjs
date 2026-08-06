import fs from 'node:fs';
import assert from 'node:assert/strict';

const endpoint=fs.readFileSync('apps/operations/reports-performance-reports-data.php','utf8');
const client=fs.readFileSync('assets/js/reports-performance.js','utf8');
const css=fs.readFileSync('assets/css/portal.css','utf8');

for(const token of ['root_causes','root_causes_by_employee','eligible_rows','excluded_rows','confirmed_impact','repeat_rate'])assert.ok(endpoint.includes(token),`missing endpoint token ${token}`);
for(const title of ['Top 5 Root Causes','Root Cause Frequency Bar Chart','Financial Impact Bar Chart','Root Causes by Employee','Quality Timeline','Evidence Summary'])assert.ok(client.includes(title),`missing report section ${title}`);
assert.match(client,/data-root-ranking/);
assert.match(client,/data-root-evidence/);
assert.match(css,/\.epi-quality-vertical\{display:grid;grid-template-columns:minmax\(0,1fr\)/);
assert.match(css,/@media print\{#kpi-reports \.epi-quality-vertical\{display:block\}/);

console.log('Performance Error & Quality root-cause checks passed.');
